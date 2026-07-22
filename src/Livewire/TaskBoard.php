<?php

namespace Sgrjr\Dispatch\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Contracts\DispatchNotifier;
use Sgrjr\Dispatch\Livewire\Concerns\HasVocabMultiFilters;
use Sgrjr\Dispatch\Models\Focus;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Support\LabelFacets;

/**
 * Full-page Kanban board. Columns are Task::statuses() in configured order;
 * cards within a column are ordered by priority (blocker>high>medium>low,
 * per Task::prioritySql()) then position/code — or by position/code alone
 * when `dispatch.board.manual_order` is enabled, so a manual drag sticks
 * instead of being resorted by priority on every render.
 *
 * DECISION: DispatchGate::isStaff()'s doc comment explicitly names "board,
 * list" as the staff surfaces it gates, so mount() enforces the `viewAny`
 * ability (-> DispatchGate::isStaff()) rather than leaving the board open to
 * any authenticated user. Non-staff submitters use MySubmissions instead.
 */
class TaskBoard extends Component
{
    use HasVocabMultiFilters;

    /**
     * Checkbox multi-filters (see HasVocabMultiFilters for the []/['']/subset
     * state contract). Aliases are plural so a pre-multi-select scalar
     * bookmark (?type=bug) is ignored instead of fatally hydrating an array
     * property, and match TaskList's so a filtered URL transfers between the
     * board and the list.
     */
    #[Url(as: 'types', except: [])]
    public array $typeFilter = [];

    #[Url(as: 'priorities', except: [])]
    public array $priorityFilter = [];

    #[Url(as: 'labels', except: [])]
    public array $labelFilter = [];

    /** Due-date window buckets (see Task::dueBuckets(); mirrors TaskList). */
    #[Url(as: 'due', except: [])]
    public array $dueFilter = [];

    /**
     * Column visibility — hides whole board columns (e.g. backburner or
     * declined) rather than filtering tasks within them, so it composes with
     * the three axes above.
     */
    #[Url(as: 'columns', except: [])]
    public array $columnFilter = [];

    /** Activity window: '', 'today', 'week', 'month', or 'older' (mirrors TaskList). */
    #[Url(as: 'updated', except: '')]
    public string $updatedFilter = '';

    /**
     * Focus steering lens (W8-2): the id of an active Focus, or '' for "all".
     * Only a value matching an ACTIVE focus constrains the board — a stale or
     * unknown id falls through to no constraint (see render()).
     */
    #[Url(as: 'focus')]
    public string $focusFilter = '';

    /**
     * Swimlanes (W8-5): group each column a second time by the task's elevated
     * lane (LabelFacets::laneKey). Off (default) renders the single-grid board.
     */
    #[Url(as: 'lanes')]
    public bool $swimlanes = false;

    /** Draft name for "save current filters as a Focus" (W8-2). */
    public string $newFocusName = '';

    /**
     * "Load all" override for the done column's `board.done_limit` cap (F8).
     */
    public bool $showAllDone = false;

    /**
     * Opt-in multi-select mode for bulk actions (F3 — minimal slice: bulk
     * status set + bulk decline only, no bulk label/assign yet). Cards are
     * rendered non-draggable while this is on (see the blade's `draggable`
     * attribute), so select-mode can never race with the native HTML5 DnD
     * dispatch.js wires up — that JS file is untouched by this feature.
     */
    public bool $selectMode = false;

    /** @var array<int,int|string> */
    public array $selectedIds = [];

    public string $bulkStatus = '';

    public function mount(): void
    {
        // Non-staff have no board — redirect them to their own submissions
        // instead of 403 (staff-only surface; the portal is the non-staff view).
        if (! app(DispatchGate::class)->isStaff(Auth::user())) {
            $this->redirect(route(config('dispatch.routes.name_prefix', 'dispatch.').'portal'));

            return;
        }
    }

    /**
     * Persist a drag-and-drop move fired from dispatch.js's native HTML5 DnD
     * handler. $toPosition is the zero-based index the card was dropped at
     * within the (visible, currently rendered) target column.
     *
     * DECISION: the contract's signature is (taskId, toStatus, toPosition) —
     * a single target index — unlike rupkeep's (code, toStatus, orderedCodes)
     * full-list approach. To honor position as a contiguous 0..n sequence we
     * recompute the whole target column's ordering here: pull every OTHER
     * visible card in that column (scoped via DispatchGate, matching how the
     * board itself is rendered), splice the moved task in at $toPosition, and
     * renumber sequentially. This keeps `position` consistent without trusting
     * the client to enumerate the full column.
     */
    public function moveCard($taskId, string $toStatus, int $toPosition): void
    {
        /** @var class-string<\Sgrjr\Dispatch\Models\Task> $taskClass */
        $taskClass = config('dispatch.models.task');

        if (! in_array($toStatus, $taskClass::statuses(), true)) {
            return;
        }

        $gate = app(DispatchGate::class);
        $user = Auth::user();

        $query = $taskClass::query()->whereKey($taskId);
        $gate->scopeVisible($query, $user);
        $task = $query->first();

        if (! $task) {
            return;
        }

        Gate::authorize('update', $task);

        $fromStatus = $task->status;

        $siblingsQuery = $taskClass::query()->where('status', $toStatus);
        if ($fromStatus === $toStatus) {
            $siblingsQuery->whereKeyNot($task->getKey());
        }
        $gate->scopeVisible($siblingsQuery, $user);
        $siblings = $siblingsQuery->orderBy('position')->get()->all();

        $toPosition = max(0, min($toPosition, count($siblings)));
        array_splice($siblings, $toPosition, 0, [$task]);

        foreach ($siblings as $index => $sibling) {
            $sibling->position = $index;
            if ($sibling->is($task)) {
                $sibling->status = $toStatus;
            }
            $sibling->save();
        }

        if ($fromStatus !== $toStatus) {
            $task->recordEvent(
                TaskComment::EVENT_STATUS_CHANGE,
                Auth::id(),
                ['from' => $fromStatus, 'to' => $toStatus],
                "Status changed from `{$fromStatus}` to `{$toStatus}` (via board).",
            );

            // N1 gap fix: recording the timeline event above notified nobody —
            // this is the same taskStatusChanged hook every other status-change
            // path in the package fires (see TaskShow::saveMeta()), now wired
            // up for a board-driven move too. DispatchNotifier never throws.
            app(DispatchNotifier::class)->taskStatusChanged($task, $fromStatus, $toStatus, $user);
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['typeFilter', 'priorityFilter', 'labelFilter', 'dueFilter', 'columnFilter', 'updatedFilter', 'focusFilter']);
    }

    /** @var array<int,string>|null Per-request memo (protected: not Livewire state). */
    protected ?array $labelNamesCache = null;

    protected function filterVocabs(): array
    {
        /** @var class-string<\Sgrjr\Dispatch\Models\Task> $taskClass */
        $taskClass = config('dispatch.models.task');

        return [
            'typeFilter' => $taskClass::types(),
            'priorityFilter' => $taskClass::priorities(),
            'labelFilter' => $this->labelNames(),
            'dueFilter' => $taskClass::dueBuckets(),
            'columnFilter' => $taskClass::statuses(),
        ];
    }

    /** @return array<int,string> */
    protected function labelNames(): array
    {
        if ($this->labelNamesCache === null) {
            $labelClass = config('dispatch.models.label');
            $this->labelNamesCache = $labelClass::orderBy('name')->pluck('name')->all();
        }

        return $this->labelNamesCache;
    }

    /**
     * Grouped label-filter sections (elevated namespaces first, then a plain
     * 'Labels' section, then 'Meta') for the grouped filter partial (W8-1a). A
     * pure derivation of the same full Label models render() already fetched —
     * passed in so this never issues a second query. filterVocabs()/labelNames()
     * stay flat: the grouping is render-only, the trait still validates
     * selections against the flat name vocab.
     *
     * @param  iterable<int,\Sgrjr\Dispatch\Models\Label>  $labels
     * @return array<int,array{title:string,options:array<string,string>}>
     */
    public function labelFilterGroups(iterable $labels): array
    {
        return LabelFacets::grouped($labels);
    }

    /**
     * Save the CURRENT checkbox filter state as a new steering Focus (W8-2).
     * Only constrained axes are persisted: activeSelection() returns null for
     * an "all"/"none" axis, and a null axis is omitted from the filters JSON
     * (Focus's storage rule — an absent axis means "all", never "match none").
     * A blank name is a silent no-op. The board is already staff-only (mount
     * redirect), so no extra gate is needed here.
     */
    public function saveCurrentAsFocus(): void
    {
        $name = trim($this->newFocusName);
        if ($name === '') {
            return;
        }

        /** @var class-string<\Sgrjr\Dispatch\Models\Task> $taskClass */
        $taskClass = config('dispatch.models.task');
        /** @var class-string<Focus> $focusClass */
        $focusClass = config('dispatch.models.focus', Focus::class);

        $filters = [];
        if (null !== ($sel = $this->activeSelection($this->labelFilter, $this->labelNames()))) {
            $filters['labels'] = $sel;
        }
        if (null !== ($sel = $this->activeSelection($this->typeFilter, $taskClass::types()))) {
            $filters['types'] = $sel;
        }
        if (null !== ($sel = $this->activeSelection($this->priorityFilter, $taskClass::priorities()))) {
            $filters['priorities'] = $sel;
        }

        $focusClass::create([
            'name' => $name,
            'filters' => $filters,
            'rank' => (int) $focusClass::query()->max('rank') + 1,
            'is_active' => true,
        ]);

        $this->newFocusName = '';
    }

    /**
     * Toggle select-mode on/off, clearing any in-flight selection/bulk choice.
     */
    public function toggleSelectMode(): void
    {
        $this->selectMode = ! $this->selectMode;
        $this->selectedIds = [];
        $this->bulkStatus = '';
    }

    /**
     * Toggle the done column between its `board.done_limit` cap and showing
     * every visible done task (F8's "load all" override).
     */
    public function toggleShowAllDone(): void
    {
        $this->showAllDone = ! $this->showAllDone;
    }

    /**
     * Bulk-set status on every selected task the acting user may `update`.
     */
    public function bulkApplyStatus(): void
    {
        /** @var class-string<\Sgrjr\Dispatch\Models\Task> $taskClass */
        $taskClass = config('dispatch.models.task');

        if ($this->bulkStatus === '' || ! in_array($this->bulkStatus, $taskClass::statuses(), true)) {
            return;
        }

        $this->applyBulkStatus($this->bulkStatus, 'update');
    }

    /**
     * Bulk-decline every selected task the acting user may `delete` — reusing
     * the package's existing convention (TaskPolicy::delete === canSeeAll)
     * for who may take a task out of play, rather than the softer `update`.
     */
    public function bulkDecline(): void
    {
        /** @var class-string<\Sgrjr\Dispatch\Models\Task> $taskClass */
        $taskClass = config('dispatch.models.task');

        if (! in_array('declined', $taskClass::statuses(), true)) {
            return;
        }

        $this->applyBulkStatus('declined', 'delete');
    }

    /**
     * Shared bulk status-change path for bulkApplyStatus()/bulkDecline().
     * Unauthorized/no-op tasks are silently skipped rather than aborting the
     * whole batch, matching moveCard()'s "silent no-op on disallowed" style.
     */
    private function applyBulkStatus(string $toStatus, string $ability): void
    {
        if ($this->selectedIds === []) {
            return;
        }

        /** @var class-string<\Sgrjr\Dispatch\Models\Task> $taskClass */
        $taskClass = config('dispatch.models.task');
        $gate = app(DispatchGate::class);
        $user = Auth::user();
        $notifier = app(DispatchNotifier::class);

        $query = $taskClass::query()->whereIn('id', $this->selectedIds);
        $gate->scopeVisible($query, $user);

        foreach ($query->get() as $task) {
            if ($task->status === $toStatus || ! Gate::allows($ability, $task)) {
                continue;
            }

            $fromStatus = $task->status;
            $task->status = $toStatus;
            $task->save();

            $task->recordEvent(
                TaskComment::EVENT_STATUS_CHANGE,
                Auth::id(),
                ['from' => $fromStatus, 'to' => $toStatus],
                "Status changed from `{$fromStatus}` to `{$toStatus}` (bulk board action).",
            );

            $notifier->taskStatusChanged($task, $fromStatus, $toStatus, $user);
        }

        $this->selectedIds = [];
        $this->bulkStatus = '';
    }

    public function render()
    {
        /** @var class-string<\Sgrjr\Dispatch\Models\Task> $taskClass */
        $taskClass = config('dispatch.models.task');
        $labelClass = config('dispatch.models.label');
        $gate = app(DispatchGate::class);
        $user = Auth::user();

        // One fetch feeds both the label filter's vocab and the view.
        $labels = $labelClass::orderBy('name')->get();
        $this->labelNamesCache = $labels->pluck('name')->all();
        $labelNames = $this->labelNamesCache;

        // Focus steering (W8-2). Only a value matching an ACTIVE focus id
        // constrains the board; '' or a stale/unknown id falls through to no
        // constraint (all tasks). The switcher only ever lists active focuses.
        $focusClass = config('dispatch.models.focus', Focus::class);
        $focuses = $focusClass::query()->active()->ranked()->get();
        $activeFocus = $this->focusFilter !== ''
            ? $focuses->first(fn ($f) => (string) $f->getKey() === $this->focusFilter)
            : null;

        $applyScopeAndFilters = function ($query) use ($taskClass, $gate, $user, $labelNames, $activeFocus) {
            $gate->scopeVisible($query, $user);

            if (null !== ($sel = $this->activeSelection($this->typeFilter, $taskClass::types()))) {
                $query->whereIn('type', $sel);
            }
            if (null !== ($sel = $this->activeSelection($this->priorityFilter, $taskClass::priorities()))) {
                $query->whereIn('priority', $sel);
            }
            if (null !== ($sel = $this->activeSelection($this->labelFilter, $labelNames))) {
                $query->whereHas('labels', fn ($q) => $q->whereIn('name', $sel));
            }
            if (null !== ($sel = $this->activeSelection($this->dueFilter, $taskClass::dueBuckets()))) {
                $query->dueInBuckets($sel);
            }

            // Cumulative activity windows (today ⊂ week ⊂ month); 'older' is
            // the remainder — mirrors TaskList so board and list read the same.
            match ($this->updatedFilter) {
                'today' => $query->where('updated_at', '>=', now()->startOfDay()),
                'week' => $query->where('updated_at', '>=', now()->subWeek()),
                'month' => $query->where('updated_at', '>=', now()->subMonth()),
                'older' => $query->where('updated_at', '<', now()->subMonth()),
                default => null,
            };

            // Focus steering: narrow BOTH the grouped non-done query and the
            // capped done query by the selected focus's stored axes, through
            // this one shared closure so the two never diverge.
            if ($activeFocus !== null) {
                $activeFocus->applyTo($query);
            }

            return $query;
        };

        // Column visibility: a hidden column isn't just unrendered — its cards
        // are never fetched. Bulk-status options still come from the full
        // vocab (statusLabels), so hiding a column doesn't shrink those.
        $visibleColumns = $this->activeSelection($this->columnFilter, $taskClass::statuses()) ?? $taskClass::statuses();

        $manualOrder = (bool) config('dispatch.board.manual_order', false);

        $applyColumnOrder = function ($query) use ($taskClass, $manualOrder) {
            if ($manualOrder) {
                return $query->orderBy('position')->orderBy('code');
            }

            return $query->orderByRaw($taskClass::prioritySql())->orderBy('position')->orderBy('code');
        };

        // Non-done columns: the same single grouped query as before, minus
        // whatever the done column claims below — restricted to the VISIBLE
        // columns so hidden ones cost nothing.
        $nonDoneQuery = $applyScopeAndFilters(
            $taskClass::query()->with(['labels', 'assignee'])->whereIn('status', array_values(array_diff($visibleColumns, ['done'])))
        );
        $byStatus = $applyColumnOrder($nonDoneQuery)->get()->groupBy('status');

        // Done column (F8): capped to `board.done_limit` most-recently-touched
        // tasks so a long-lived board doesn't drag in years of history. The
        // cap is a SELECTION step (pick the N freshest by updated_at); once
        // selected, that subset is re-ordered with the exact same rule as
        // every other column (manual position, or priority) so `manual_order`
        // still means something inside "done" too. Visibility/filters are
        // scoped identically to the non-done query above via the same closure.
        $doneLimit = (int) config('dispatch.board.done_limit', 50);
        $hasDoneStatus = in_array('done', $visibleColumns, true);

        $doneTotal = 0;
        $doneItems = collect();

        if ($hasDoneStatus) {
            $doneScoped = $applyScopeAndFilters($taskClass::query()->where('status', 'done'));
            $doneTotal = (clone $doneScoped)->count();

            $doneSelectQuery = (clone $doneScoped)->orderByDesc('updated_at');
            if (! $this->showAllDone && $doneLimit > 0) {
                $doneSelectQuery->limit($doneLimit);
            }
            $doneIds = $doneSelectQuery->pluck('id')->all();

            if ($doneIds !== []) {
                $doneItems = $applyColumnOrder(
                    $taskClass::query()->with(['labels', 'assignee'])->whereIn('id', $doneIds)
                )->get();
            }
        }

        $byStatus->put('done', $doneItems);

        // Swimlanes (W8-5): a second grouping axis over the already-ordered,
        // already-capped columns. Each task's lane is its first elevated
        // label's value (LabelFacets::laneKey; '—' when it has none). Per-column
        // order is preserved because we push in the existing iteration order.
        // The done column's GLOBAL cap is untouched — lanes just split whatever
        // the capped set already contains. Off = one unlabeled lane row that
        // reproduces the original single-grid board, so the blade has one path.
        if ($this->swimlanes) {
            $byLaneStatus = [];
            $laneSeen = [];
            foreach ($byStatus as $status => $cards) {
                foreach ($cards as $task) {
                    $lane = LabelFacets::laneKey($task) ?? '—';
                    $laneSeen[$lane] = true;
                    $byLaneStatus[$lane][$status] = ($byLaneStatus[$lane][$status] ?? collect())->push($task);
                }
            }

            // Sorted lane order, '—' (no elevated label) always last.
            $lanes = array_values(array_diff(array_keys($laneSeen), ['—']));
            sort($lanes, SORT_NATURAL | SORT_FLAG_CASE);
            if (isset($laneSeen['—'])) {
                $lanes[] = '—';
            }

            $laneRows = array_map(
                fn ($lane) => ['label' => $lane, 'byStatus' => collect($byLaneStatus[$lane])],
                $lanes,
            );
        } else {
            $lanes = [];
            $laneRows = [['label' => null, 'byStatus' => $byStatus]];
        }

        return view('dispatch::livewire.task-board', [
            'columns' => $visibleColumns,
            'statusLabels' => $taskClass::statusLabels(),
            'byStatus' => $byStatus,
            'lanes' => $lanes,
            'laneRows' => $laneRows,
            'labels' => $labels,
            'labelFilterGroups' => $this->labelFilterGroups($labels),
            'focuses' => $focuses,
            'typeLabels' => $taskClass::typeLabels(),
            'priorityLabels' => $taskClass::priorityLabels(),
            'dueBucketLabels' => $taskClass::dueBucketLabels(),
            'doneTotal' => $doneTotal,
            'doneShowing' => $doneItems->count(),
            'doneLimit' => $doneLimit,
            'stalenessEnabled' => (bool) config('dispatch.staleness.enabled', true),
            'staleThresholdDays' => (int) config('dispatch.staleness.threshold_days', 42),
        ])->layout('dispatch::components.layout');
    }
}
