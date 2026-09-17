<?php

namespace Sgrjr\Dispatch\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Sgrjr\Dispatch\Models\Label;
use Sgrjr\Dispatch\Models\LabelAlias;
use Sgrjr\Dispatch\Models\TaskComment;

/**
 * Label vocabulary cleanup: the one place a label is REPLACED (folded into a
 * canonical label) or RETIRED (dropped from every task). The staff `/labels`
 * page and the `dispatch:labels:*` commands both call this, so the rules below
 * hold on every surface.
 *
 * Labels are minted freely — every `--label`, batch op, and create form
 * auto-creates — so a long-lived backlog accumulates near-duplicates and
 * one-off labels. Cleanup works on the pivot, across ALL tasks (closed and
 * soft-deleted included, so a restore can't resurrect a stale label):
 *
 *  - replace: every task carrying a source label carries the target instead.
 *    A missing target is created by RENAMING the most-used source in place, so
 *    it keeps that label's color/description/kind. Each replaced name becomes a
 *    {@see LabelAlias} of the target, so the old name keeps resolving and never
 *    re-mints. Focus filters naming a replaced label are rewritten.
 *  - retire: the labels are detached everywhere and deleted, no alias left
 *    behind. A focus whose label axis retiring would EMPTY is deactivated —
 *    an empty axis means "all labels" (Focus storage rule), so leaving it
 *    active would silently widen the focus to the whole backlog.
 *
 * Every live task whose chips change gets ONE internal timeline event — the
 * record of why a label vanished from it — without touching `updated_at`, so
 * a cleanup doesn't make half the backlog look freshly worked.
 */
class LabelCleanupService
{
    protected const PIVOT = 'dispatch_task_label';

    /** Chunk size for whereIn lists — stays under SQL Server's 2100-parameter cap. */
    protected const CHUNK = 500;

    /**
     * Every label with its usage: `tasks_count` (live tasks), `last_used_at`
     * (newest attach on a live task, raw timestamp or null), and its aliases.
     *
     * @return Collection<int,Label>
     */
    public function usage(): Collection
    {
        return $this->labelModel()::query()
            ->withCount('tasks')
            ->withMax('tasks as last_used_at', self::PIVOT.'.created_at')
            ->with(['aliases' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get();
    }

    /**
     * Resolve names (CLI input) to labels. A name that is already an alias is
     * reported separately, so the caller can say where it now points instead
     * of a bare "not found".
     *
     * @param  array<int,string>  $names
     * @return array{labels:Collection<int,Label>, aliased:array<string,string>, missing:array<int,string>}
     */
    public function findByNames(array $names): array
    {
        $names = array_values(array_unique(array_filter(
            array_map(fn ($n) => trim((string) $n), $names),
            fn ($n) => $n !== '',
        )));

        $labels = $names === []
            ? new Collection()
            : $this->labelModel()::query()->whereIn('name', $names)->orderBy('name')->get();
        $found = $labels->map(fn ($l) => mb_strtolower($l->name))->all();

        $aliased = [];
        $missing = [];
        foreach ($names as $name) {
            if (in_array(mb_strtolower($name), $found, true)) {
                continue;
            }

            $canonical = LabelAlias::canonicalize([$name])[0];
            if ($canonical !== $name) {
                $aliased[$name] = $canonical;
            } else {
                $missing[] = $name;
            }
        }

        return ['labels' => $labels, 'aliased' => $aliased, 'missing' => $missing];
    }

    /**
     * What a replace (when $targetName is given) or a retire (when it is null)
     * of these labels would touch — the numbers the page and `--dry-run` show
     * before anything is written. Same arithmetic as the write it previews.
     *
     * @param  array<int,int>  $labelIds
     * @return array{labels:array<int,string>, tasks:int, focuses:int, target:?string, target_exists:bool, already_on_target:int, focuses_emptied:array<int,string>}
     */
    public function preview(array $labelIds, ?string $targetName = null): array
    {
        $sources = $this->sources($labelIds);
        $names = $sources->pluck('name')->all();

        $result = [
            'labels' => $names,
            'tasks' => count($this->liveTaskIds($sources->modelKeys())),
            'focuses' => $this->focusesNaming($names)->count(),
            'target' => null,
            'target_exists' => false,
            'already_on_target' => 0,
            'focuses_emptied' => [],
        ];

        if ($targetName === null) {
            $lower = array_map('mb_strtolower', $names);
            $result['focuses_emptied'] = $this->focusesNaming($names)
                ->filter(fn ($focus) => array_diff(array_map('mb_strtolower', $this->focusLabels($focus)), $lower) === [])
                ->pluck('name')->values()->all();

            return $result;
        }

        $targetName = trim($targetName);
        $target = $this->resolveTarget($targetName);
        $result['target'] = $target?->name ?? $targetName;
        $result['target_exists'] = $target !== null;

        if ($target !== null && $sources->isNotEmpty()) {
            // A selected label that IS the target (and isn't just being
            // re-cased) changes nothing on its own tasks — don't count them.
            $others = $sources->reject(fn ($l) => $l->is($target))->values();
            $changing = $this->isCaseFix($sources, $target, $targetName) ? $sources : $others;

            $result['tasks'] = count($this->liveTaskIds($changing->modelKeys()));
            $result['already_on_target'] = $this->alreadyOnTarget($others, $target);
        }

        return $result;
    }

    /**
     * Fold the given labels into $targetName on every task. See the class
     * docblock for the rules.
     *
     * @param  array<int,int>  $labelIds
     * @return array{target:string, target_created:bool, replaced:array<int,string>, tasks:int, already_on_target:int, focuses_updated:int}
     */
    public function replace(array $labelIds, string $targetName, ?int $actorId = null): array
    {
        $targetName = trim($targetName);
        if ($targetName === '') {
            throw new InvalidArgumentException('A replacement label name is required.');
        }

        return DB::transaction(function () use ($labelIds, $targetName, $actorId) {
            $sources = $this->sources($labelIds);
            if ($sources->isEmpty()) {
                throw new InvalidArgumentException('No labels selected to replace.');
            }

            // Old names per live task, captured BEFORE anything is renamed or
            // moved — they are what the timeline event has to say.
            $carried = $this->liveNamesByTask($sources);

            $target = $this->resolveTarget($targetName);
            $targetCreated = $target === null;
            $renamed = [];

            if ($targetCreated) {
                // No such label yet: rename the most-used source into it (ties
                // -> oldest), keeping its color/description/kind and its rows.
                $target = $sources->sortBy([['tasks_count', 'desc'], ['id', 'asc']])->first();
            }

            $others = $sources->reject(fn ($l) => $l->is($target))->values();

            if ($targetCreated || $this->isCaseFix($sources, $target, $targetName)) {
                $renamed[] = $target->name;
                $target->name = $targetName;
                $target->save();
            } elseif ($sources->contains(fn ($l) => $l->is($target))) {
                // Selected AND the target, unchanged: its own tasks see no
                // difference, so they get no event.
                $carried = array_filter(array_map(
                    fn ($names) => array_values(array_diff($names, [$target->name])),
                    $carried,
                ));
            }

            $alreadyOnTarget = $targetCreated ? 0 : $this->alreadyOnTarget($others, $target);

            foreach ($others as $source) {
                $this->movePivotRows($source, $target);
            }

            // Aliases that pointed at a folded label now point at the target;
            // then every replaced name becomes an alias of the target itself.
            if ($others->isNotEmpty()) {
                LabelAlias::query()->whereIn('label_id', $others->modelKeys())->update(['label_id' => $target->getKey()]);
                $this->labelModel()::query()->whereKey($others->modelKeys())->delete();
            }

            $replaced = array_values(array_unique(array_merge($renamed, $others->pluck('name')->all())));

            foreach ($replaced as $oldName) {
                if ($oldName !== $target->name) {
                    LabelAlias::query()->updateOrCreate(['name' => $oldName], ['label_id' => $target->getKey()]);
                }
            }

            $focusesUpdated = $this->rewriteFocuses($replaced, $target->name);

            foreach ($carried as $taskId => $names) {
                $from = implode('`, `', $names);
                $this->record($taskId, TaskComment::EVENT_LABEL_REPLACED, $actorId, [
                    'from' => $names,
                    'to' => $target->name,
                ], "Label `{$from}` replaced by `{$target->name}` (label cleanup).");
            }

            return [
                'target' => $target->name,
                'target_created' => $targetCreated,
                'replaced' => $replaced,
                'tasks' => count($carried),
                'already_on_target' => $alreadyOnTarget,
                'focuses_updated' => $focusesUpdated,
            ];
        });
    }

    /**
     * Detach the given labels from every task and delete them.
     *
     * @param  array<int,int>  $labelIds
     * @return array{retired:array<int,string>, tasks:int, focuses_updated:int, focuses_deactivated:array<int,string>}
     */
    public function retire(array $labelIds, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($labelIds, $actorId) {
            $sources = $this->sources($labelIds);
            if ($sources->isEmpty()) {
                throw new InvalidArgumentException('No labels selected to retire.');
            }

            $names = $sources->pluck('name')->all();
            $carried = $this->liveNamesByTask($sources);

            [$focusesUpdated, $deactivated] = $this->stripFocuses($names);

            // Explicit rather than trusting ON DELETE CASCADE, which SQLite
            // only honours with foreign keys switched on.
            $ids = $sources->modelKeys();
            DB::table(self::PIVOT)->whereIn('label_id', $ids)->delete();
            LabelAlias::query()->whereIn('label_id', $ids)->delete();
            $this->labelModel()::query()->whereKey($ids)->delete();

            foreach ($carried as $taskId => $taskNames) {
                $list = implode('`, `', $taskNames);
                $this->record($taskId, TaskComment::EVENT_LABEL_REMOVED, $actorId, [
                    'labels' => $taskNames,
                    'retired' => true,
                ], "Label `{$list}` retired (label cleanup).");
            }

            return [
                'retired' => $names,
                'tasks' => count($carried),
                'focuses_updated' => $focusesUpdated,
                'focuses_deactivated' => $deactivated,
            ];
        });
    }

    /**
     * Drop one alias, freeing its name to become an ordinary label again.
     */
    public function removeAlias(int $aliasId): void
    {
        LabelAlias::query()->whereKey($aliasId)->delete();
    }

    // ---------------------------------------------------------------------

    /**
     * @param  array<int,int>  $labelIds
     * @return Collection<int,Label>
     */
    protected function sources(array $labelIds): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $labelIds)));

        return $ids === []
            ? new Collection()
            : $this->labelModel()::query()->whereKey($ids)->withCount('tasks')->orderBy('name')->get();
    }

    /**
     * The existing label $name names — directly, or through an alias.
     */
    protected function resolveTarget(string $name): ?Label
    {
        $canonical = LabelAlias::canonicalize([$name])[0] ?? trim($name);

        return $this->labelModel()::query()->where('name', $canonical)->first();
    }

    /**
     * The target is itself one of the sources, matched only case-insensitively
     * (MySQL's default collation) — i.e. the ask is to re-case that label. A
     * target reached through an alias is never a case fix.
     *
     * @param  Collection<int,Label>  $sources
     */
    protected function isCaseFix(Collection $sources, Label $target, string $targetName): bool
    {
        return $target->name !== $targetName
            && $sources->contains(fn ($l) => $l->is($target))
            && LabelAlias::canonicalize([$targetName])[0] === $targetName;
    }

    /**
     * Re-point $source's pivot rows at $target. A task already carrying the
     * target keeps that row and just loses the source row; every other row is
     * UPDATED rather than re-inserted, so its original attach time survives.
     * Soft-deleted tasks move too — a restore must not resurrect the source.
     */
    protected function movePivotRows(Label $source, Label $target): void
    {
        $onTarget = array_flip($this->allTaskIds([$target->getKey()]));
        $conflicts = array_values(array_filter(
            $this->allTaskIds([$source->getKey()]),
            fn ($id) => isset($onTarget[$id]),
        ));

        foreach (array_chunk($conflicts, self::CHUNK) as $chunk) {
            DB::table(self::PIVOT)->where('label_id', $source->getKey())->whereIn('task_id', $chunk)->delete();
        }

        DB::table(self::PIVOT)->where('label_id', $source->getKey())->update([
            'label_id' => $target->getKey(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Live tasks that carry the target AND one of the labels being folded into
     * it — for them the replace only removes a duplicate chip.
     *
     * @param  Collection<int,Label>  $others
     */
    protected function alreadyOnTarget(Collection $others, Label $target): int
    {
        if ($others->isEmpty()) {
            return 0;
        }

        return count(array_intersect(
            $this->liveTaskIds($others->modelKeys()),
            $this->liveTaskIds([$target->getKey()]),
        ));
    }

    /**
     * Task ids on the pivot for these labels, soft-deleted tasks included.
     *
     * @param  array<int,int>  $labelIds
     * @return array<int,int>
     */
    protected function allTaskIds(array $labelIds): array
    {
        return DB::table(self::PIVOT)->whereIn('label_id', $labelIds)
            ->distinct()->pluck('task_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Ids of LIVE (not soft-deleted) tasks carrying any of these labels.
     *
     * @param  array<int,int>  $labelIds
     * @return array<int,int>
     */
    protected function liveTaskIds(array $labelIds): array
    {
        if ($labelIds === []) {
            return [];
        }

        return $this->taskModel()::query()
            ->whereHas('labels', fn ($q) => $q->whereKey($labelIds))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Live task id → the names of these labels it carries.
     *
     * @param  Collection<int,Label>  $labels
     * @return array<int,array<int,string>>
     */
    protected function liveNamesByTask(Collection $labels): array
    {
        $namesById = $labels->mapWithKeys(fn ($l) => [$l->getKey() => $l->name])->all();
        $live = array_flip($this->liveTaskIds(array_keys($namesById)));

        $byTask = [];
        $rows = DB::table(self::PIVOT)->whereIn('label_id', array_keys($namesById))->get(['task_id', 'label_id']);
        foreach ($rows as $row) {
            if (isset($live[(int) $row->task_id])) {
                $byTask[(int) $row->task_id][] = $namesById[(int) $row->label_id];
            }
        }

        ksort($byTask);
        foreach ($byTask as &$names) {
            sort($names);
        }

        return $byTask;
    }

    /**
     * Focuses whose label axis names any of $names (case-insensitively).
     *
     * @param  array<int,string>  $names
     */
    protected function focusesNaming(array $names): Collection
    {
        if ($names === []) {
            return new Collection();
        }

        $lower = array_map('mb_strtolower', $names);

        return $this->focusModel()::query()->ranked()->get()
            ->filter(fn ($focus) => array_intersect(array_map('mb_strtolower', $this->focusLabels($focus)), $lower) !== [])
            ->values();
    }

    /**
     * @return array<int,string>
     */
    protected function focusLabels($focus): array
    {
        return array_values(array_map('strval', (array) (((array) $focus->filters)['labels'] ?? [])));
    }

    /**
     * Swap $oldNames for $newName in every focus's label axis.
     *
     * @param  array<int,string>  $oldNames
     */
    protected function rewriteFocuses(array $oldNames, string $newName): int
    {
        $lower = array_map('mb_strtolower', $oldNames);
        $updated = 0;

        foreach ($this->focusesNaming($oldNames) as $focus) {
            $filters = (array) $focus->filters;
            $filters['labels'] = array_values(array_unique(array_map(
                fn ($name) => in_array(mb_strtolower($name), $lower, true) ? $newName : $name,
                $this->focusLabels($focus),
            )));
            $focus->filters = $filters;
            $focus->save();
            $updated++;
        }

        return $updated;
    }

    /**
     * Remove $names from every focus's label axis; deactivate any focus left
     * with no labels (see the class docblock for why).
     *
     * @param  array<int,string>  $names
     * @return array{0:int, 1:array<int,string>}
     */
    protected function stripFocuses(array $names): array
    {
        $lower = array_map('mb_strtolower', $names);
        $updated = 0;
        $deactivated = [];

        foreach ($this->focusesNaming($names) as $focus) {
            $filters = (array) $focus->filters;
            $remaining = array_values(array_filter(
                $this->focusLabels($focus),
                fn ($name) => ! in_array(mb_strtolower($name), $lower, true),
            ));

            if ($remaining === []) {
                unset($filters['labels']);
                if ($focus->is_active) {
                    $focus->is_active = false;
                    $deactivated[] = $focus->name;
                }
            } else {
                $filters['labels'] = $remaining;
            }

            $focus->filters = $filters;
            $focus->save();
            $updated++;
        }

        return [$updated, $deactivated];
    }

    /**
     * One internal system event on a task's timeline. Written through the
     * comment model directly (not Task::recordEvent) so a soft-deleted task
     * needn't be hydrated — and, like recordEvent, it never touches the task's
     * `updated_at`.
     */
    protected function record(int $taskId, string $event, ?int $actorId, array $meta, string $body): void
    {
        $this->commentModel()::query()->create([
            'task_id' => $taskId,
            'user_id' => $actorId,
            'body' => $body,
            'event_type' => $event,
            'meta' => $meta,
            // Vocabulary maintenance, not news for the submitter.
            'is_internal' => true,
        ]);
    }

    /** @return class-string<Label> */
    protected function labelModel(): string
    {
        return config('dispatch.models.label', Label::class);
    }

    /** @return class-string<\Sgrjr\Dispatch\Models\Task> */
    protected function taskModel(): string
    {
        return config('dispatch.models.task');
    }

    /** @return class-string<TaskComment> */
    protected function commentModel(): string
    {
        return config('dispatch.models.task_comment', TaskComment::class);
    }

    /** @return class-string<\Sgrjr\Dispatch\Models\Focus> */
    protected function focusModel(): string
    {
        return config('dispatch.models.focus', \Sgrjr\Dispatch\Models\Focus::class);
    }
}
