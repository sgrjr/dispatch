<?php

namespace Sgrjr\Dispatch\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Sgrjr\Dispatch\Contracts\SubmitterResolver;
use Sgrjr\Dispatch\Contracts\TenantResolver;
use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Support\AgentMetrics;

/**
 * The single place Dispatch tasks are minted.
 *
 * Every inbound source — the in-app capture widget, the `dispatch:add` CLI, and
 * auto exception capture — routes through here so code minting, submitter
 * resolution, tenant stamping, and label attachment happen one way. Replaces
 * rupkeep's app-coupled service (hard-coded 'Reynolds Upkeep' submitter, direct
 * organization_id write) with contract-driven seams.
 */
class DispatchTaskService
{
    public function __construct(
        protected SubmitterResolver $submitters,
        protected TenantResolver $tenants,
    ) {}

    /**
     * When false, create() skips the taskCreated() notifier fan-out — and, when
     * a host has bound the reactive EventNotifier, its per-task orchestration
     * trigger. Flipped only inside quietly().
     */
    protected bool $notifyOnCreate = true;

    /**
     * Run $fn with create()-time notifications suppressed — for a bulk backfill
     * (`dispatch:import`, `dispatch:batch --no-notify`) that must not email a
     * "request received" receipt or fire reactive automation once per historical
     * row. The flag is restored even if $fn throws, and nested calls stack
     * safely. Only the create receipt is gated; explicit status/comment/assign
     * notifications elsewhere are unaffected.
     *
     * @template T
     *
     * @param  callable():T  $fn
     * @return T
     */
    public function quietly(callable $fn): mixed
    {
        $previous = $this->notifyOnCreate;
        $this->notifyOnCreate = false;

        try {
            return $fn();
        } finally {
            $this->notifyOnCreate = $previous;
        }
    }

    /**
     * Create a task and attach any labels (auto-creating labels as needed).
     *
     * @param  array<string,mixed>  $attributes  Task attributes (title required).
     * @param  array<int,string>    $labelNames  Label names to attach.
     */
    public function create(array $attributes, array $labelNames = [], ?Authenticatable $actor = null): Task
    {
        $actor ??= Auth::user();

        $attributes['title'] = Str::limit(trim((string) ($attributes['title'] ?? '')), 255, '…');
        $attributes['type'] ??= 'feature';
        $attributes['priority'] ??= 'medium';
        $attributes['status'] ??= 'triage';
        $attributes['is_public'] = (bool) ($attributes['is_public'] ?? false);

        // Default the submitter only when the caller OMITS the key. An explicit
        // value — including a deliberate null — is honored, so an agent task can
        // carry a null submitter (its identity lives in the timeline event meta)
        // instead of being silently stamped with the fallback default user.
        if (! array_key_exists('submitter_user_id', $attributes)) {
            $attributes['submitter_user_id'] = $this->submitters->currentUserId() ?? $this->submitters->defaultUserId();
        }

        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $task = $taskModel::createWithCode(
            $attributes,
            fn ($model) => $this->tenants->stamp($model, $actor),
        );

        $this->attachLabels($task, $labelNames);

        // Submission-acknowledgement receipt (N2). The DispatchNotifier
        // contract guarantees implementations never throw, but this is a
        // critical path — don't trust that promise, catch here too. Suppressed
        // inside quietly() so a bulk backfill neither emails nor orchestrates.
        if ($this->notifyOnCreate) {
            try {
                app(\Sgrjr\Dispatch\Contracts\DispatchNotifier::class)->taskCreated($task);
            } catch (\Throwable) {
                // never break task creation over a notification failure
            }
        }

        return $task;
    }

    /**
     * Capture entry point for automated sources (e.g. exception handler). Dedupes
     * on $signature: a recurring identical error appends an occurrence event to
     * the existing open task instead of creating a duplicate.
     *
     * @param  array<string,mixed>  $attributes
     * @param  array<int,string>    $labelNames
     */
    public function capture(string $signature, array $attributes, array $labelNames = []): Task
    {
        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $existing = $taskModel::query()
            ->where('exception_signature', $signature)
            // `backburner` is deliberately inside the revivable set: an error
            // recurring on a parked task is evidence the parking was premature,
            // so the occurrence lands on it (no auto-unpark — a human sees the
            // fresh event and unparks deliberately) instead of forking a dupe.
            ->whereNotIn('status', ['done', 'declined'])
            ->orderByDesc('id')
            ->first();

        if ($existing !== null) {
            $existing->recordEvent(
                TaskComment::EVENT_EXCEPTION,
                null,
                ['at' => now()->toIso8601String()],
            );

            // Occurrence tracking: bump the counters in the stored context so a
            // recurring error reads "seen N times" instead of spawning dupes.
            $ctx = $existing->context ?? [];
            $ctx['times_seen'] = (int) ($ctx['times_seen'] ?? 1) + 1;
            $ctx['last_seen'] = now()->toIso8601String();
            $existing->context = $ctx;
            $existing->save();

            return $existing;
        }

        $attributes['exception_signature'] = $signature;
        $attributes['type'] ??= 'bug';
        $attributes['status'] ??= 'triage';

        return $this->create($attributes, $labelNames);
    }

    /**
     * Attach the named labels to a task, creating any that don't exist yet.
     *
     * @param  array<int,string>  $labelNames
     */
    public function attachLabels(Task $task, array $labelNames): void
    {
        /** @var class-string $labelModel */
        $labelModel = config('dispatch.models.label');

        $labelIds = [];
        foreach ($labelNames as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $labelIds[] = $labelModel::firstOrCreate(['name' => $name])->id;
        }

        if (! empty($labelIds)) {
            $task->labels()->syncWithoutDetaching($labelIds);
        }
    }

    /**
     * Merge $loser into $winner: reparent the loser's comments and
     * attachments onto the winner, union labels, memorialize the merge on
     * both tasks, then soft-delete the loser (marked `duplicate_of` the
     * winner, status `declined`).
     *
     * Wrapped in a transaction — a merge touches four tables and must not
     * partially apply.
     */
    public function merge(Task $loser, Task $winner, ?int $actorId = null): Task
    {
        return DB::transaction(function () use ($loser, $winner, $actorId) {
            /** @var class-string $commentModel */
            $commentModel = config('dispatch.models.task_comment');
            $commentModel::where('task_id', $loser->id)->update(['task_id' => $winner->id]);

            /** @var class-string $attachmentModel */
            $attachmentModel = config('dispatch.models.task_attachment');
            $attachmentModel::where('attachable_type', $loser->getMorphClass())
                ->where('attachable_id', $loser->id)
                ->update(['attachable_id' => $winner->id, 'attachable_type' => $winner->getMorphClass()]);

            // allRelatedIds() reads the related keys straight off the pivot —
            // avoids an ambiguous unqualified `id` in the labels join (the
            // pivot also has an `id`).
            $labelIds = $loser->labels()->allRelatedIds()->all();
            $winner->labels()->syncWithoutDetaching($labelIds);

            $winner->recordEvent(
                TaskComment::EVENT_MERGED,
                $actorId,
                ['from' => $loser->code],
                "Merged {$loser->code} into this task.",
            );

            $loser->duplicate_of = $winner->id;
            $loser->status = 'declined';
            $loser->save();

            $loser->recordEvent(
                TaskComment::EVENT_MERGED,
                $actorId,
                ['into' => $winner->code],
                "Merged into {$winner->code}.",
            );

            $loser->delete();

            return $winner->refresh();
        });
    }

    /**
     * The `next`/`claim` candidate ordering: actionable-first, then configured
     * priority rank, then manual position, then id. Uses Task::prioritySql()
     * (config-aware) rather than a hardcoded priority CASE — identical ordering
     * under the default vocab (relative order is what matters, not the rank
     * values), and correct under a custom priority vocab too.
     */
    public function orderForNext(Builder $q): Builder
    {
        return $q
            ->orderByRaw(Task::actionableFirstSql())
            ->orderByRaw(Task::prioritySql())
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * The `queue` ordering: configured priority rank, then position, then id —
     * NO status term (a flat priority list, unlike {@see orderForNext()}).
     */
    public function orderForQueue(Builder $q): Builder
    {
        return $q
            ->orderByRaw(Task::prioritySql())
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * Eager-load the relations + counts the read presenters need, in one place
     * so no read surface N+1s. The `attachment_count` withCount is loaded even
     * though nothing reads it yet — a later wave adds the presenter key and must
     * not reintroduce an N+1 to get it.
     */
    public function eagerForRead(Builder $q): Builder
    {
        // The user relations are guarded: eager-loading a belongsTo BUILDS the
        // related model instance, and the host user model need not even exist in
        // a headless/agent context (TaskPresenter guards its reads on the FK for
        // the same reason). Headless installs have null submitters anyway, so
        // skipping the eager-load introduces no N+1 there.
        $userModel = config('dispatch.models.user');
        $withUser = is_string($userModel) && class_exists($userModel) ? ['submitter', 'assignee'] : [];

        return $q
            ->with(array_merge(['labels'], $withUser))
            ->withCount([
                'comments as comment_count' => fn ($q) => $q->where('event_type', TaskComment::EVENT_COMMENT),
                'attachments as attachment_count',
            ]);
    }

    /**
     * Steer a single-candidate pick through the active focuses. When $applyFocus
     * is true, each active focus (highest rank first) gets a turn: the first
     * focus that yields a task wins; a focus whose matches are all absent — or,
     * under FOR UPDATE SKIP LOCKED, all currently locked — yields null and
     * steering falls through to the NEXT focus, then finally the unsteered base.
     * Steer, never block or starve: a busy focus never stalls the loop.
     *
     * $baseQuery must return a FRESH builder per call (a Builder is single-use
     * once ->first() runs, and each focus probes its own copy).
     */
    public function steeredFirst(bool $applyFocus, \Closure $baseQuery): ?Task
    {
        if ($applyFocus) {
            /** @var class-string $focusModel */
            $focusModel = config('dispatch.models.focus', \Sgrjr\Dispatch\Models\Focus::class);

            foreach ($focusModel::query()->active()->ranked()->get() as $focus) {
                $task = $focus->applyTo($baseQuery())->first();
                if ($task !== null) {
                    return $task;
                }
            }
        }

        return $baseQuery()->first();
    }

    /**
     * The `next` pick: the single highest-priority actionable candidate, focus-
     * steered by default. Base status defaults to the actionable trio
     * (open/in_progress/triage) unless $status pins one; type/label filters
     * narrow (label is any-of). Pass $applyFocus false to bypass steering.
     *
     * @param  array{type?:string,label?:string|array<int,string>}  $filters
     */
    public function nextCandidate(array $filters = [], ?string $status = null, bool $applyFocus = true): ?Task
    {
        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $type = $filters['type'] ?? null;
        $label = $filters['label'] ?? null;

        $baseQuery = fn () => $this->orderForNext($this->eagerForRead(
            $taskModel::query()
                ->when(
                    $status,
                    fn ($q, $s) => $q->where('status', $s),
                    fn ($q) => $q->whereIn('status', ['open', 'in_progress', 'triage'])
                )
                ->when($type, fn ($q, $type) => $q->where('type', $type))
                ->when($label, fn ($q, $label) => $q->whereHas(
                    'labels',
                    fn ($lq) => $lq->whereIn('name', (array) $label)
                ))
        ));

        return $this->steeredFirst($applyFocus, $baseQuery);
    }

    /**
     * The `queue` builder: the filtered, eager-loaded, priority-ordered backlog.
     * Base status defaults to the actionable trio unless $status pins one.
     * NOT focus-steered — the queue is a full list, not a single pick. Callers
     * add their own ->limit()/->get().
     *
     * @param  array{type?:string,label?:string|array<int,string>}  $filters
     */
    public function queueQuery(array $filters = [], ?string $status = null): Builder
    {
        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $type = $filters['type'] ?? null;
        $label = $filters['label'] ?? null;

        return $this->orderForQueue($this->eagerForRead(
            $taskModel::query()
                ->when(
                    $status,
                    fn ($q, $s) => $q->where('status', $s),
                    fn ($q) => $q->whereIn('status', ['open', 'in_progress', 'triage'])
                )
                ->when($type, fn ($q, $type) => $q->where('type', $type))
                ->when($label, fn ($q, $label) => $q->whereHas(
                    'labels',
                    fn ($lq) => $lq->whereIn('name', (array) $label)
                ))
        ));
    }

    /**
     * Atomically claim an actionable task for an agent (C1). Picks only
     * UNSTARTED work (status open/triage, never `in_progress`, so two agents
     * can't grab the same in-flight task), mirroring dispatch:next's ordering so
     * an agent claims exactly what `next` would surface. Row-locked inside a
     * transaction; on MySQL/Postgres SKIP LOCKED hands each concurrent agent a
     * distinct task. Focus-steered by default ($applyFocus): the locked
     * candidate is picked through the active focuses, falling through to base
     * when a focus's matches are absent or all locked.
     *
     * Pass $code to claim ONE specific task by code instead of the next
     * candidate — used when a human (or a plan) hands an agent a particular
     * task. The same UNSTARTED guard still applies: a named task that is already
     * in_progress/done/etc. (or doesn't exist) yields null, so claim-by-code
     * still never steals in-flight work. A named code is exact — the type/label
     * filters are ignored, AND steering is forced off, since the code already
     * picks the task.
     *
     * @param  array{type?:string,label?:string|array<int,string>}  $filters
     */
    public function claim(?AgentSession $session = null, array $filters = [], ?int $assigneeUserId = null, ?string $code = null, bool $applyFocus = true): ?Task
    {
        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        // A named code picks the task outright; the narrowing filters would only
        // muddy that (and could null out an explicit request), so drop them.
        $type = $code === null ? ($filters['type'] ?? null) : null;
        $label = $code === null ? ($filters['label'] ?? null) : null;

        return DB::transaction(function () use ($taskModel, $session, $type, $label, $assigneeUserId, $code, $applyFocus) {
            // A FRESH lean, LOCKED candidate builder per call — steeredFirst may
            // probe it once per focus plus once for the base, and a locked
            // builder is single-use once ->first() runs. Deliberately lean: no
            // with()/withCount() (subqueries under FOR UPDATE are fragile) —
            // the post-claim loadMissing in the callers hydrates for output.
            $baseQuery = function () use ($taskModel, $type, $label, $code) {
                $query = $this->orderForNext(
                    $taskModel::query()
                        ->whereIn('status', ['open', 'triage'])
                        ->when($code, fn ($q, $c) => $q->where('code', $c))
                        ->when($type, fn ($q, $type) => $q->where('type', $type))
                        ->when($label, fn ($q, $label) => $q->whereHas(
                            'labels',
                            fn ($lq) => $lq->whereIn('name', (array) $label)
                        ))
                );

                // Row-lock the candidate. MySQL/Postgres get SKIP LOCKED so
                // parallel claimers each grab the NEXT free row; SQLite compiles
                // lockForUpdate to a no-op (single-connection test env —
                // atomicity is asserted by sequential distinctness, see ClaimTest).
                $driver = DB::connection()->getDriverName();
                if (in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
                    $query->lock('for update skip locked');
                } else {
                    $query->lockForUpdate();
                }

                return $query;
            };

            // An exact code overrides steering exactly as it already overrides
            // filters — the caller may also force it off (--no-focus).
            /** @var Task|null $task */
            $task = $this->steeredFirst($applyFocus && $code === null, $baseQuery);
            if ($task === null) {
                return null;
            }

            $task->status = 'in_progress';
            $task->assignee_user_id = $assigneeUserId;
            $task->save();

            $task->recordEvent(
                TaskComment::EVENT_CLAIMED,
                null,
                array_filter([
                    'agent_session_id' => $session?->public_id,
                    'agent_name' => $session?->agent_name,
                ], fn ($v) => $v !== null),
                'Claimed by '.($session?->agent_name ?? 'agent').'.',
            );

            return $task;
        });
    }

    /**
     * Idempotent create keyed on a general-purpose dedupe key (C2). Returns the
     * existing task with that key, or creates one. The DB's UNIQUE index on
     * `dedupe_key` arbitrates a two-callers-same-key race — the loser's insert is
     * rejected and we return the winner.
     *
     * @param  array<string,mixed>  $attributes
     * @param  array<int,string>    $labelNames
     */
    public function firstOrCreateByKey(string $key, array $attributes, array $labelNames = []): Task
    {
        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        if ($existing = $taskModel::query()->where('dedupe_key', $key)->first()) {
            return $existing;
        }

        try {
            return $this->create($attributes + ['dedupe_key' => $key], $labelNames);
        } catch (QueryException $e) {
            if ($taskModel::isDuplicateCodeError($e)
                && ($winner = $taskModel::query()->where('dedupe_key', $key)->first())) {
                return $winner;
            }

            throw $e;
        }
    }

    /**
     * Record a structured completion result on a task (C4): the agent's
     * `--result` JSON plus the code `--commit` it produced, stored under
     * `context.result` so human review and audit tie a task to its change.
     *
     * The result blob itself is replaced — a new close IS the new result — but
     * `metrics` survives re-work: a close without metrics keeps the prior run's,
     * and a close with metrics folds onto them via AgentMetrics::accumulate(),
     * so a task cycled through several agent runs reports their sum, not just
     * the last window.
     *
     * @param  array<string,mixed>  $result
     */
    public function recordResult(Task $task, array $result, ?string $commit = null): void
    {
        if ($commit !== null && $commit !== '') {
            $result['commit'] = $commit;
        }

        $ctx = $task->context ?? [];

        $existing = is_array($ctx['result'] ?? null) && is_array($ctx['result']['metrics'] ?? null)
            ? $ctx['result']['metrics']
            : null;
        if ($existing !== null) {
            $result['metrics'] = is_array($result['metrics'] ?? null)
                ? AgentMetrics::accumulate($existing, $result['metrics'])
                : $existing;
        }

        $ctx['result'] = $result;
        $task->context = $ctx;
        $task->save();
    }
}
