<?php

namespace Sgrjr\Dispatch\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Sgrjr\Dispatch\Contracts\DispatchNotifier;
use Sgrjr\Dispatch\Contracts\LaneResolver;
use Sgrjr\Dispatch\Contracts\SubmitterResolver;
use Sgrjr\Dispatch\Contracts\TenantResolver;
use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Models\LabelAlias;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Models\TaskLink;
use Sgrjr\Dispatch\Support\AgentMetrics;
use Sgrjr\Dispatch\Support\Anchor;
use Sgrjr\Dispatch\Support\DueDate;
use Sgrjr\Dispatch\Support\Lane;

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

        // W13-5 visibility default, keyed on the ACTOR, not the submitter: a
        // staff creator IS the task's circle, so their task starts
        // participants-only (the operator's opt-in-to-staff ruling); a task
        // with no staff actor behind it — customer widget submit, exception
        // reporter, CLI/agent/import with no auth — has no circle yet and
        // MUST land staff-visible or nobody would ever triage it. An explicit
        // valid value always wins; garbage falls through to the default.
        if (! in_array($attributes['visibility'] ?? null, Task::VISIBILITIES, true)) {
            $attributes['visibility'] = app(\Sgrjr\Dispatch\Contracts\DispatchGate::class)->isStaff($actor)
                ? Task::VISIBILITY_PARTICIPANTS
                : Task::VISIBILITY_STAFF;
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
     * A name that label cleanup folded into a canonical label resolves to
     * that label first (LabelAlias::canonicalize), so an old name never
     * re-mints the label it replaced.
     *
     * @param  array<int,string>  $labelNames
     */
    public function attachLabels(Task $task, array $labelNames): void
    {
        /** @var class-string $labelModel */
        $labelModel = config('dispatch.models.label');

        $labelIds = [];
        foreach (LabelAlias::canonicalize($labelNames) as $name) {
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
     * TASK-995 — apply the optional anchor filters (`topic`, `origin`,
     * `conversation`, `topic_account`) shared by next/queue/find/claim. Each
     * anchor value is a wire string (`"<type>[:<id>]"`) run through the ONE
     * parser (Anchor::parse); a malformed one throws InvalidArgumentException
     * — callers validate up front (same posture as due_at) so this never
     * surfaces mid-query.
     *
     * @param  array{topic?:string,origin?:string,conversation?:int|string,topic_account?:string}  $filters
     */
    protected function applyAnchorFilters(Builder $q, array $filters): Builder
    {
        if (($topic = $filters['topic'] ?? null) !== null && $topic !== '') {
            [$type, $id] = Anchor::parse((string) $topic);
            $q->aboutTopic($type, $id);
        }

        if (($origin = $filters['origin'] ?? null) !== null && $origin !== '') {
            [$type, $id] = Anchor::parse((string) $origin);
            $q->fromOrigin($type, $id);
        }

        if (($conversation = $filters['conversation'] ?? null) !== null && $conversation !== '') {
            $q->inConversation((int) $conversation);
        }

        if (($account = $filters['topic_account'] ?? null) !== null && $account !== '') {
            $q->aboutAccount((string) $account);
        }

        return $q;
    }

    /**
     * TASK-997 part A — apply the optional `lane` filter shared by
     * next/queue/find/claim. `$filters['lane']` is a raw wire value: a lane
     * key (department-vs-exact semantics, see Task::scopeInLane) or
     * {@see Lane::NONE} ('none') for the no-department lane. Not validated
     * against LaneResolver::isLane() here — a filter for a lane the resolver
     * no longer recognizes should still find whatever rows carry it, unlike a
     * WRITE, which must never persist a value the resolver rejects.
     *
     * @param  array{lane?:string}  $filters
     */
    protected function applyLaneFilter(Builder $q, array $filters): Builder
    {
        if (($lane = $filters['lane'] ?? null) !== null && $lane !== '') {
            $q->inLane((string) $lane);
        }

        return $q;
    }

    /**
     * TASK-999 (R24) — narrow `next`/`claim` candidates to the lanes the
     * CALLER is served. Unlike `lane`, this is NOT a wire filter: the agent
     * API sets `$filters['served_lanes']` from the approved session's granted
     * lane, so an agent cannot widen its own reach by omitting a flag. A
     * caller with no served lane (`null`/absent) is unrestricted — the
     * pre-TASK-999 whole-board behavior every non-agent path keeps.
     *
     * It composes with a user-supplied `lane` filter rather than replacing
     * it: `--lane=` narrows WITHIN what you are served, and asking for a
     * lane you aren't served correctly yields nothing.
     *
     * The no-department lane rides along by default (R15: that lane is open
     * to any user or department); `dispatch.agent.lane_includes_unrouted`
     * turns it off for a host that wants unrouted work triaged by a human
     * before any agent can claim it.
     *
     * @param  array{served_lanes?:array<int,string>|null}  $filters
     */
    protected function applyServedLanes(Builder $q, array $filters): Builder
    {
        $served = $filters['served_lanes'] ?? null;

        if (! is_array($served) || $served === []) {
            return $q;
        }

        return $q->servedByLanes(
            $served,
            (bool) config('dispatch.agent.lane_includes_unrouted', true),
        );
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
     * TASK-999 — `served_lanes` (set by the agent API from the approved
     * session's lane, never by the wire) narrows candidates to the lanes the
     * caller is served; see {@see applyServedLanes()}.
     *
     * @param  array{type?:string,label?:string|array<int,string>,topic?:string,origin?:string,conversation?:int|string,topic_account?:string,lane?:string,served_lanes?:array<int,string>}  $filters
     */
    public function nextCandidate(array $filters = [], ?string $status = null, bool $applyFocus = true): ?Task
    {
        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $type = $filters['type'] ?? null;
        $label = $filters['label'] ?? null;

        $baseQuery = fn () => $this->orderForNext($this->eagerForRead(
            $this->applyServedLanes($this->applyLaneFilter($this->applyAnchorFilters(
                $taskModel::query()
                    ->when(
                        $status,
                        fn ($q, $s) => $q->where('status', $s),
                        fn ($q) => $q->whereIn('status', ['open', 'in_progress', 'triage'])
                    )
                    ->when($type, fn ($q, $type) => $q->where('type', $type))
                    ->when($label, fn ($q, $label) => $q->whereHas(
                        'labels',
                        fn ($lq) => $lq->whereIn('name', LabelAlias::canonicalize((array) $label))
                    )),
                $filters,
            ), $filters), $filters)
        ));

        return $this->steeredFirst($applyFocus, $baseQuery);
    }

    /**
     * The `queue` builder: the filtered, eager-loaded, priority-ordered backlog.
     * Base status defaults to the actionable trio unless $status pins one.
     * NOT focus-steered — the queue is a full list, not a single pick. Callers
     * add their own ->limit()/->get().
     *
     * @param  array{type?:string,label?:string|array<int,string>,topic?:string,origin?:string,conversation?:int|string,topic_account?:string,lane?:string}  $filters
     */
    public function queueQuery(array $filters = [], ?string $status = null): Builder
    {
        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $type = $filters['type'] ?? null;
        $label = $filters['label'] ?? null;

        return $this->orderForQueue($this->eagerForRead(
            $this->applyLaneFilter($this->applyAnchorFilters(
                $taskModel::query()
                    ->when(
                        $status,
                        fn ($q, $s) => $q->where('status', $s),
                        fn ($q) => $q->whereIn('status', ['open', 'in_progress', 'triage'])
                    )
                    ->when($type, fn ($q, $type) => $q->where('type', $type))
                    ->when($label, fn ($q, $label) => $q->whereHas(
                        'labels',
                        fn ($lq) => $lq->whereIn('name', LabelAlias::canonicalize((array) $label))
                    )),
                $filters,
            ), $filters)
        ));
    }

    /**
     * Text search across the board (W9-7) — the query behind `dispatch:find` and
     * the queue endpoint's `?q=`.
     *
     * Deliberately the INVERSE of queueQuery's default: no status filter unless
     * one is asked for. The dominant reason an agent searches is "has this
     * already been filed, or already built?", and the answers to that live in
     * exactly the statuses the actionable queue excludes — `done`, `declined`,
     * `backburner`. Defaulting to the actionable board here would make the verb
     * confidently wrong in its most important use, which is worse than not
     * having it. Ordering is newest-first: for a duplicate check, recency beats
     * priority.
     *
     * Matches title, code, and description. `description` is included because a
     * duplicate is often recognisable only from the body (a wiring identifier, a
     * PROD_NO) that never made it into the title.
     *
     * @param  array<string,mixed>  $filters  type/label/topic/origin/conversation/topic_account/lane, same shape as queueQuery
     */
    public function searchQuery(string $term, array $filters = [], ?string $status = null): Builder
    {
        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $type = $filters['type'] ?? null;
        $label = $filters['label'] ?? null;

        // Escape the LIKE wildcards so a term containing % or _ searches for
        // those characters instead of silently matching everything — a false
        // "already filed" is worse than no search at all.
        //
        // The escape character is declared explicitly, and is `!` rather than a
        // backslash, because backslash is NOT portable here: MySQL treats it as
        // the default LIKE escape, SQLite has no default at all, and Postgres
        // reads `'\\'` differently depending on standard_conforming_strings. An
        // explicit ESCAPE with a character that is inert in every dialect's
        // string literals behaves identically on all three.
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($term));
        $like = '%'.$escaped.'%';

        $table = (new $taskModel)->getTable();

        return $this->eagerForRead(
            $this->applyLaneFilter($this->applyAnchorFilters(
                $taskModel::query()
                    ->where(function (Builder $q) use ($like, $table) {
                        $q->whereRaw("{$table}.title LIKE ? ESCAPE '!'", [$like])
                            ->orWhereRaw("{$table}.code LIKE ? ESCAPE '!'", [$like])
                            ->orWhereRaw("{$table}.description LIKE ? ESCAPE '!'", [$like]);
                    })
                    ->when($status, fn ($q, $s) => $q->where('status', $s))
                    ->when($type, fn ($q, $type) => $q->where('type', $type))
                    ->when($label, fn ($q, $label) => $q->whereHas(
                        'labels',
                        fn ($lq) => $lq->whereIn('name', LabelAlias::canonicalize((array) $label))
                    )),
                $filters,
            ), $filters)
        )->orderByDesc('updated_at')->orderByDesc('id');
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
     * TASK-999 (R24): when $session carries a granted lane, candidates are
     * narrowed to the lanes it serves — its lane, the departments above it,
     * and (by config) the no-department lane. Claim-by-$code bypasses that
     * narrowing, so a human can still hand an agent any task.
     *
     * @param  array{type?:string,label?:string|array<int,string>,topic?:string,origin?:string,conversation?:int|string,topic_account?:string,lane?:string}  $filters
     */
    public function claim(?AgentSession $session = null, array $filters = [], ?int $assigneeUserId = null, ?string $code = null, bool $applyFocus = true): ?Task
    {
        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        // A named code picks the task outright; the narrowing filters would only
        // muddy that (and could null out an explicit request), so drop them.
        $type = $code === null ? ($filters['type'] ?? null) : null;
        $label = $code === null ? ($filters['label'] ?? null) : null;
        $anchorFilters = $code === null ? $filters : [];

        // TASK-999 (R24) — the SESSION is the authority on which lanes it is
        // served, so derive that here rather than trusting the caller to pass
        // it: no transport can widen an agent's reach by omitting a key. A
        // named $code is exempt (it already fell out with $anchorFilters
        // above) — handing an agent a specific task stays possible from any
        // lane, which is what "or tasks held by the agent explicitly" means.
        if ($code === null && ($sessionLanes = $session?->servedLanes()) !== null) {
            $anchorFilters['served_lanes'] = $sessionLanes;
        }

        return DB::transaction(function () use ($taskModel, $session, $type, $label, $assigneeUserId, $code, $applyFocus, $anchorFilters) {
            // A FRESH lean, LOCKED candidate builder per call — steeredFirst may
            // probe it once per focus plus once for the base, and a locked
            // builder is single-use once ->first() runs. Deliberately lean: no
            // with()/withCount() (subqueries under FOR UPDATE are fragile) —
            // the post-claim loadMissing in the callers hydrates for output.
            //
            // This is the AGENT claim path — it never sets a lane (R15's
            // auto-join-a-lane logic lives in claimForUser() below, used by
            // the UI claim action instead).
            $baseQuery = function () use ($taskModel, $type, $label, $code, $anchorFilters) {
                $query = $this->orderForNext(
                    $this->applyServedLanes($this->applyLaneFilter($this->applyAnchorFilters(
                        $taskModel::query()
                            ->whereIn('status', ['open', 'triage'])
                            ->when($code, fn ($q, $c) => $q->where('code', $c))
                            ->when($type, fn ($q, $type) => $q->where('type', $type))
                            ->when($label, fn ($q, $label) => $q->whereHas(
                                'labels',
                                fn ($lq) => $lq->whereIn('name', LabelAlias::canonicalize((array) $label))
                            )),
                        $anchorFilters,
                    ), $anchorFilters), $anchorFilters)
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
     * TASK-997 part A, R15 — a HUMAN claims $task for themselves (the UI claim
     * action; see TaskShow::claimForSelf()). Distinct from {@see claim()},
     * which finds+locks+claims a candidate for an AGENT session and never
     * touches `lane`: this method takes an already-resolved task and an
     * Authenticatable actor, and — only when the task is currently UNROUTED —
     * also joins it into one of the actor's lanes:
     *
     *   - `$lane` given: it must be one of `lanesFor($user)`, or this throws.
     *   - `$lane` omitted: reduce the user's lanes to their MOST-SPECIFIC form
     *     (a `dept:role` sub-lane wins over its bare `dept` when the user
     *     holds exactly one sub-lane in that department — see
     *     {@see reduceLanesToMostSpecific()}). Exactly one survivor joins it
     *     silently; more than one throws a "pick a lane" error listing them.
     *     Zero survivors (including the inert NullLaneResolver — no lanes
     *     configured at all) is NOT an error: the claim proceeds and the task
     *     stays unrouted, so a host that hasn't adopted lanes sees the exact
     *     pre-lanes claim behavior.
     *
     * A task that is ALREADY routed keeps its lane untouched regardless of
     * `$lane` — claiming is not how a routed task's lane changes (that's
     * {@see routeToLane()}).
     *
     * @throws \InvalidArgumentException when an explicit $lane isn't one of
     *                                   the user's lanes, or when omitting it
     *                                   leaves more than one candidate.
     */
    public function claimForUser(Task $task, Authenticatable $user, ?string $lane = null): Task
    {
        return DB::transaction(function () use ($task, $user, $lane) {
            $userId = $user->getAuthIdentifier();
            $fromLane = $task->lane;

            if ($task->lane === null) {
                $userLanes = app(LaneResolver::class)->lanesFor($user);

                if ($lane !== null) {
                    if (! in_array($lane, $userLanes, true)) {
                        throw new \InvalidArgumentException("`{$lane}` is not one of your lanes.");
                    }
                    $task->lane = $lane;
                } else {
                    $candidates = $this->reduceLanesToMostSpecific($userLanes);

                    if (count($candidates) === 1) {
                        $task->lane = $candidates[0];
                    } elseif (count($candidates) > 1) {
                        throw new \InvalidArgumentException(
                            'Pick a lane: '.implode(', ', $candidates)
                        );
                    }
                    // count($candidates) === 0: nothing to join — proceed unrouted.
                }
            }

            $task->assignee_user_id = $userId;
            $task->save();

            $task->recordEvent(
                TaskComment::EVENT_CLAIMED,
                $userId,
                [],
                'Claimed by '.($user->name ?? $userId).'.',
            );

            if ($task->lane !== $fromLane) {
                $task->recordEvent(
                    TaskComment::EVENT_LANE_CHANGE,
                    $userId,
                    ['from' => $fromLane, 'to' => $task->lane],
                    $task->lane !== null ? "Joined lane {$task->lane} on claim." : 'Lane cleared.',
                );
            }

            return $task->refresh();
        });
    }

    /**
     * TASK-997 part A, R15 — route $task into $lane, UNCLAIMED (assignee
     * cleared — `assignee_group` is left alone; groups are a separate,
     * config-only concept, see Support\Groups). Two people may do this:
     *
     *   - an admin (`LaneResolver::canRoute($actor)` true) — any task, any
     *     valid lane;
     *   - a lane MEMBER, but only to pull a task OUT of the open
     *     (no-department) lane and only into one of their OWN lanes
     *     (`$lane ∈ lanesFor($actor)`) — a department claiming unrouted work
     *     for itself, without assigning it to a specific person yet.
     *
     * Anyone else — including a non-admin trying to re-route an
     * ALREADY-routed task, or route into a lane they don't work — is refused.
     *
     * @throws \InvalidArgumentException  when $lane isn't a valid lane.
     * @throws AuthorizationException     when $actor may not perform this route.
     */
    public function routeToLane(Task $task, string $lane, Authenticatable $actor): Task
    {
        $resolver = app(LaneResolver::class);

        if (! $resolver->isLane($lane)) {
            throw new \InvalidArgumentException("`{$lane}` is not a valid lane.");
        }

        $claimingOpenLane = $task->lane === null && in_array($lane, $resolver->lanesFor($actor), true);

        if (! $resolver->canRoute($actor) && ! $claimingOpenLane) {
            throw new AuthorizationException('You are not allowed to route this task.');
        }

        return DB::transaction(function () use ($task, $lane, $actor) {
            $from = $task->lane;

            $task->lane = $lane;
            $task->assignee_user_id = null;
            $task->save();

            if ($from !== $lane) {
                $task->recordEvent(
                    TaskComment::EVENT_LANE_CHANGE,
                    $actor->getAuthIdentifier(),
                    ['from' => $from, 'to' => $lane],
                    "Routed to {$lane}.",
                );
            }

            return $task->refresh();
        });
    }

    /**
     * Collapse a user's lanes to their MOST-SPECIFIC form (R15's claim-time
     * reduction): when the user holds both a bare department (`marketing`)
     * and EXACTLY ONE of its sub-lanes (`marketing:developer`), the bare
     * department is dropped in favor of the single sub-lane — so a user who
     * works only that one role reads as having ONE lane, not two ambiguous
     * ones. Two or more sub-lanes in the same department are left as-is
     * (still ambiguous — nothing to prefer between them).
     *
     * @param  array<int,string>  $lanes
     * @return array<int,string>
     */
    protected function reduceLanesToMostSpecific(array $lanes): array
    {
        $lanes = array_values(array_unique($lanes));

        $byDepartment = [];
        foreach ($lanes as $l) {
            $byDepartment[Lane::department($l)][] = $l;
        }

        $result = [];
        foreach ($byDepartment as $department => $group) {
            $subLanes = array_values(array_filter($group, fn ($l) => $l !== $department));

            if (in_array($department, $group, true) && count($subLanes) === 1) {
                $result[] = $subLanes[0];
            } else {
                array_push($result, ...$group);
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * TASK-997 part B, R15/R22 — the hand-off ("the ball"). $to is always a
     * PERSON; where they sit relative to $task's lane decides what happens:
     *
     *   - $to already works $task's lane (or both are unrouted — see
     *     `sameLane()`, the inert-compatible reading of R21/R22): the ball
     *     moves on THIS SAME task (assignee := $to).
     *   - Otherwise: a NEW task is minted in one of $to's lanes (their single
     *     most-specific one, or `$opts['lane']` to disambiguate, or the
     *     no-department lane when $to works none) — same conversation_id,
     *     origin = `task:<this code>`, assigned to $to. The ball never
     *     simply leaves its lane: a person holding another lane's task is
     *     invisible to their own manager.
     *
     * PASS (`$opts['ask']` false, the default): when a new task is minted,
     * THIS task closes (status -> done) unless `$opts['keep_open']` — its
     * part is done. ASK (`$opts['ask']` true): ALWAYS mints a new task, even
     * when $to works this task's own lane, and that new task BLOCKS this
     * one (a real {@see TaskLink} row) instead of closing it — $task keeps
     * its holder, untouched, and gains the blocked-by link. See {@see
     * notifyDependentsOfClosure()} for the return-the-ball half: it runs
     * whenever ANY task reaches a terminal status and, for an ask
     * specifically, reassigns the asker's task back to them with the
     * answer.
     *
     * $actor is nullable (not the non-nullable Authenticatable a first read
     * of the contract might suggest): an AgentSession is not an
     * Authenticatable, and the CLI is a trusted, often-unauthenticated
     * context (mirroring `create()`'s "an agent task can carry a null
     * submitter" posture) — a null actor simply means the timeline events
     * below carry no user_id, same as every other agent/CLI mutation.
     *
     * @param  array{ask?:bool,lane?:?string,note?:?string,due?:?string,keep_open?:bool}  $opts
     * @return Task the task now carrying the ball: THIS task (pass, same
     *              lane; ask, always — it never moves), or the newly minted
     *              continuation (a pass that left the lane).
     *
     * @throws \InvalidArgumentException when `$opts['lane']` isn't one of
     *                                   $to's lanes, or omitting it leaves
     *                                   more than one candidate (mirrors
     *                                   claimForUser()'s "pick a lane").
     */
    public function handoff(Task $task, Authenticatable $to, ?Authenticatable $actor, array $opts = []): Task
    {
        $ask = (bool) ($opts['ask'] ?? false);
        $recipientLanes = app(LaneResolver::class)->lanesFor($to);
        $sameLane = $this->sameLane($task->lane, $recipientLanes);

        if (! $ask && $sameLane) {
            return $this->passWithinLane($task, $to, $actor, $opts);
        }

        $lane = $sameLane
            ? $task->lane
            : $this->pickHandoffLane($recipientLanes, $opts['lane'] ?? null);

        return $ask
            ? $this->createAskTask($task, $to, $actor, $opts, $lane)
            : $this->createContinuationTask($task, $to, $actor, $opts, $lane);
    }

    /**
     * The "does the ball stay in the same place, lane-wise" test behind
     * handoff()'s row-1-vs-not decision. A real lane match is the obvious
     * case; `$taskLane === null && $recipientLanes === []` is the
     * inert-compatible reading — when NEITHER side has any lane at all
     * (the shipped NullLaneResolver, or a real resolver for a person who
     * happens to work none), there is nothing for the ball to "leave", so a
     * plain reassignment is correct rather than spinning a same-shape new
     * task. This is what keeps "handoff still works for same-lane
     * assignment" true with the Null resolver bound.
     *
     * @param  array<int,string>  $recipientLanes
     */
    protected function sameLane(?string $taskLane, array $recipientLanes): bool
    {
        return $taskLane !== null
            ? in_array($taskLane, $recipientLanes, true)
            : $recipientLanes === [];
    }

    /**
     * Resolve which lane a MINTED continuation/ask task lands in, when the
     * recipient does NOT already work the passing task's lane. Mirrors
     * claimForUser()'s reduction exactly, but for a THIRD PARTY (the
     * recipient), not the caller — so the messages name "the recipient"
     * rather than "you". Zero candidates (R15) is not an error: the new
     * task lands in the no-department lane for an admin to route.
     *
     * @param  array<int,string>  $recipientLanes
     */
    protected function pickHandoffLane(array $recipientLanes, ?string $requested): ?string
    {
        if ($requested !== null) {
            if (! in_array($requested, $recipientLanes, true)) {
                throw new \InvalidArgumentException("`{$requested}` is not one of the recipient's lanes.");
            }

            return $requested;
        }

        $candidates = $this->reduceLanesToMostSpecific($recipientLanes);

        if (count($candidates) === 1) {
            return $candidates[0];
        }
        if (count($candidates) > 1) {
            throw new \InvalidArgumentException('Pick a lane for the recipient: '.implode(', ', $candidates));
        }

        return null; // no lanes at all — lands in the no-department lane.
    }

    /**
     * PASS, same lane (or both unrouted): the ball moves on THIS task. ONE
     * assignee_change event carries the note; a given `due` is applied
     * tri-state, same posture as every other due-date write surface.
     *
     * @param  array{note?:?string,due?:?string}  $opts
     */
    protected function passWithinLane(Task $task, Authenticatable $to, ?Authenticatable $actor, array $opts): Task
    {
        $note = $opts['note'] ?? null;
        $actorId = $actor?->getAuthIdentifier();

        return DB::transaction(function () use ($task, $to, $actor, $actorId, $note, $opts) {
            $fromId = $task->assignee_user_id;
            $toId = $to->getAuthIdentifier();

            $dueChanged = false;
            $dueFrom = null;
            $dueTo = null;
            if (array_key_exists('due', $opts) && $opts['due'] !== null) {
                $dueFrom = $task->due_at?->toDateString();
                $due = DueDate::resolve($opts['due']);
                $task->due_at = $due;
                $dueTo = $due?->toDateString();
                $dueChanged = $dueFrom !== $dueTo;
            }

            $task->assignee_user_id = $toId;
            $task->save();

            $task->recordEvent(
                TaskComment::EVENT_ASSIGNEE_CHANGE,
                $actorId,
                array_filter(['from' => $fromId, 'to' => $toId, 'note' => $note], fn ($v) => $v !== null),
                $note ? "Passed to {$this->userLabel($to)}: {$note}" : "Passed to {$this->userLabel($to)}.",
            );

            if ($dueChanged) {
                $task->recordEvent(
                    TaskComment::EVENT_COMMENT,
                    $actorId,
                    ['due_at' => ['from' => $dueFrom, 'to' => $dueTo]],
                    $dueTo ? "Due date set to {$dueTo}." : 'Due date cleared.',
                );
            }

            app(DispatchNotifier::class)->taskAssigned($task, $fromId, (int) $toId, $actor);

            return $task->refresh();
        });
    }

    /**
     * PASS, cross-lane (or no lane at all): mint the continuation task and,
     * unless `$opts['keep_open']`, close THIS one — its part is done.
     * Returns the NEW task (where the ball now is).
     *
     * @param  array{note?:?string,due?:?string,keep_open?:bool}  $opts
     */
    protected function createContinuationTask(Task $task, Authenticatable $to, ?Authenticatable $actor, array $opts, ?string $lane): Task
    {
        $note = $opts['note'] ?? null;
        $actorId = $actor?->getAuthIdentifier();

        return DB::transaction(function () use ($task, $to, $actor, $actorId, $note, $opts, $lane) {
            $new = $this->mintHandoffTask($task, $to, $actor, $lane, $note, 'Passed from', continuation: true);

            $task->recordEvent(
                TaskComment::EVENT_HANDED_OFF,
                $actorId,
                array_filter(['to' => $to->getAuthIdentifier(), 'continued_as' => $new->code, 'note' => $note], fn ($v) => $v !== null),
                "Passed to {$this->userLabel($to)} — continued as {$new->code}.",
            );

            if (empty($opts['keep_open'])) {
                // BEFORE the close: once $task is terminal it stops gating its
                // dependents, and a dependent left pointing only at it would read
                // as unblocked — which is how a passed ask used to lose its answer.
                $this->moveDependentsOnto($task, $new);

                $from = $task->status;
                $task->status = 'done';
                $task->save();

                $task->recordEvent(
                    TaskComment::EVENT_STATUS_CHANGE,
                    $actorId,
                    ['from' => $from, 'to' => 'done'],
                    "Status changed from `{$from}` to `done` (handed off — continued as {$new->code}).",
                );

                app(DispatchNotifier::class)->taskStatusChanged($task, $from, 'done', $actor);
            }

            return $new;
        });
    }

    /**
     * TASK-1069 — a closing pass hands the work on, it does not finish it, so
     * whatever was waiting on $from is now waiting on $to: every task $from
     * BLOCKS gains the same link to $to (same kind, same `created_by_user_id`
     * — for an ask that is the ASKER, who is who the ball returns to).
     *
     * The old link rows stay, for history: $from is about to go terminal, and a
     * terminal blocker no longer gates ({@see Task::scopeBlocked()}), so only the
     * new link holds the dependent. Closing $from this way must NOT be read as
     * an answer — createContinuationTask() never calls
     * notifyDependentsOfClosure() — and the continuation's own close is
     * recognised as the answer by {@see answersAskOf()}.
     *
     * No cycle check: $to was minted a moment ago and blocks nothing and is
     * blocked by nothing, so no path can lead back from it.
     */
    protected function moveDependentsOnto(Task $from, Task $to): void
    {
        TaskLink::query()
            ->where('blocked_by_task_id', $from->id)
            ->get()
            ->each(fn (TaskLink $link) => TaskLink::query()->firstOrCreate(
                ['task_id' => $link->task_id, 'blocked_by_task_id' => $to->id, 'kind' => $link->kind],
                ['created_by_user_id' => $link->created_by_user_id],
            ));
    }

    /**
     * Is $closed the answer to a question $dependent asked? True when $closed
     * IS the ask (its origin is $dependent — {@see createAskTask()} always sets
     * it), or a continuation of that ask, however many passes on: a passed ask
     * is minted with `origin = task:<the ask>`, so the walk follows origins up
     * until it reaches $dependent. Capped and cycle-guarded; walks trashed rows
     * so a soft-deleted middle link still joins the chain.
     */
    protected function answersAskOf(Task $closed, Task $dependent): bool
    {
        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $seen = [];
        $node = $closed;

        for ($hop = 0; $hop < 25 && $node !== null; $hop++) {
            if ($node->origin_type !== 'task' || isset($seen[$node->id])) {
                return false;
            }
            if ($node->origin_id === $dependent->code) {
                return true;
            }
            $seen[$node->id] = true;
            $node = $taskModel::withTrashed()->where('code', $node->origin_id)->first();
        }

        return false;
    }

    /**
     * ASK: mint a task for $to (in $lane, which by the time this is called
     * is either $task's own lane — $to works it too — or the recipient's
     * resolved lane) that BLOCKS $task, then link them. $task keeps its
     * holder untouched. Returns $task (refreshed) — the ball never moves for
     * an ask; only {@see notifyDependentsOfClosure()} moves it, when the ask
     * closes.
     *
     * @param  array{note?:?string,due?:?string}  $opts
     */
    protected function createAskTask(Task $task, Authenticatable $to, ?Authenticatable $actor, array $opts, ?string $lane): Task
    {
        $note = $opts['note'] ?? null;
        $actorId = $actor?->getAuthIdentifier();

        return DB::transaction(function () use ($task, $to, $actor, $actorId, $note, $opts, $lane) {
            $askTask = $this->mintHandoffTask($task, $to, $actor, $lane, $note, 'Requested via', continuation: false);

            if (array_key_exists('due', $opts) && $opts['due'] !== null) {
                $askTask->due_at = DueDate::resolve($opts['due']);
                $askTask->save();
            }

            // The asker (whoever performs THIS ask) is who the ball returns
            // to when $askTask closes — see returnTheBall(). A null $actor
            // (agent-driven ask) simply falls back to whoever already holds
            // $task at close time.
            $this->linkBlockedBy($task, $askTask, $actorId);

            $task->recordEvent(
                TaskComment::EVENT_ASKED,
                $actorId,
                array_filter(['to' => $to->getAuthIdentifier(), 'blocked_by' => $askTask->code, 'note' => $note], fn ($v) => $v !== null),
                "Asked {$this->userLabel($to)} — blocked by {$askTask->code}.".($note ? " {$note}" : ''),
            );

            return $task->refresh();
        });
    }

    /**
     * Shared mint step for both a pass-continuation and an ask task: same
     * title/type/priority/visibility/is_public/submitter/conversation/topic as
     * $task (this IS that work, continuing or being asked-about), status
     * `open` (already vetted, actionable — not a fresh `triage` report),
     * `origin` = `task:<$task->code>`, assigned to $to. The create-time
     * "request received" receipt is suppressed (quietly()) — $to gets the
     * notification that actually matters here (taskAssigned, the EXISTING
     * seam, no new channel) instead of $task's submitter being told
     * "received" a second time for a task they didn't submit.
     *
     * The TOPIC always carries: both kinds are about the same account/plan/
     * title, and dropping it takes the work out of every "tasks about this"
     * view and the topic_account_key rollup the moment it crosses a lane.
     * LABELS carry only on a continuation (`$continuation`), which IS the
     * same work under a new holder. An ask is a new task — a question about
     * that work — and labels such as `source:widget` or `kind:investigate`
     * describe the original, not the question.
     */
    protected function mintHandoffTask(Task $task, Authenticatable $to, ?Authenticatable $actor, ?string $lane, ?string $note, string $verb, bool $continuation): Task
    {

        $attributes = [
            'title' => $task->title,
            'type' => $task->type,
            'priority' => $task->priority,
            'status' => 'open',
            'description' => $note ?? "{$verb} {$task->code}.",
            'submitter_user_id' => $task->submitter_user_id,
            'assignee_user_id' => (int) $to->getAuthIdentifier(),
            'is_public' => (bool) $task->is_public,
            'visibility' => $task->visibility,
            'origin_type' => 'task',
            'origin_id' => $task->code,
        ];
        if ($task->conversation_id !== null) {
            $attributes['conversation_id'] = $task->conversation_id;
        }
        if ($lane !== null) {
            $attributes['lane'] = $lane;
        }
        if ($task->topic_type !== null) {
            $attributes['topic_type'] = $task->topic_type;
            $attributes['topic_id'] = $task->topic_id;
        }

        $labels = $continuation ? $task->labels()->pluck('name')->all() : [];

        $new = $this->quietly(fn () => $this->create($attributes, $labels, $actor));

        app(DispatchNotifier::class)->taskAssigned($new, null, (int) $to->getAuthIdentifier(), $actor);

        return $new;
    }

    /** A human-readable name for a message — falls back to the auth id. */
    protected function userLabel(Authenticatable $user): string
    {
        return (string) ($user->name ?? $user->getAuthIdentifier());
    }

    /**
     * TASK-997 part B — link $blocker as one of $task's blockers (a real
     * `dispatch_task_links` row), refusing a self-link or a cycle. Idempotent:
     * re-linking the same pair/kind returns the existing row.
     *
     * Cycle check: walking $blocker's OWN blocked-by chain (its blockers,
     * their blockers, ...) must never reach $task — if it did, $task would
     * end up (transitively) blocked by something $task itself blocks.
     *
     * @throws \InvalidArgumentException on a self-link or a would-be cycle.
     */
    public function linkBlockedBy(Task $task, Task $blocker, ?int $actorUserId = null, string $kind = TaskLink::KIND_BLOCKS): TaskLink
    {
        if ($task->is($blocker)) {
            throw new \InvalidArgumentException("{$task->code} cannot be blocked by itself.");
        }

        if ($this->wouldCreateCycle($task, $blocker)) {
            throw new \InvalidArgumentException(
                "Linking {$task->code} blocked-by {$blocker->code} would create a cycle — ".
                "{$blocker->code} is already (transitively) blocked by {$task->code}."
            );
        }

        return TaskLink::query()->firstOrCreate(
            ['task_id' => $task->id, 'blocked_by_task_id' => $blocker->id, 'kind' => $kind],
            ['created_by_user_id' => $actorUserId],
        );
    }

    /**
     * BFS over `dispatch_task_links` starting at $blocker, following ITS
     * blocked-by edges (its blockers, their blockers, ...). True if $task is
     * ever reached — meaning $blocker is already (transitively) blocked by
     * $task, so linking $task blocked-by $blocker would close a cycle. A
     * self-link ($task === $blocker) is caught here too (the walk starts AT
     * $blocker and the very first node checked is $blocker itself).
     */
    protected function wouldCreateCycle(Task $task, Task $blocker): bool
    {
        $visited = [];
        $queue = [$blocker->id];

        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;

            if ($current === $task->id) {
                return true;
            }

            $ids = TaskLink::query()->where('task_id', $current)->pluck('blocked_by_task_id')->all();
            foreach ($ids as $id) {
                if (! isset($visited[$id])) {
                    $queue[] = $id;
                }
            }
        }

        return false;
    }

    /**
     * TASK-997 part B, §3 — "closing a blocker notifies the next holder."
     * Called by every surface that writes a task's status (dispatch:done +
     * `POST agent/done`, `dispatch:batch`, TaskShow/TaskList/TaskBoard) right
     * after a REAL transition into a terminal status ({@see
     * Task::isInactive()}); a no-op otherwise, so it's safe to call
     * unconditionally.
     *
     * For each task $task blocks: if $task answers a question that dependent
     * asked — it IS the ask (its origin points back at the dependent, which
     * {@see createAskTask()} always sets) or a continuation of one that was
     * passed on ({@see answersAskOf()}) — the ball RETURNS (reassign + the
     * answer). Otherwise it's a plain `blocked_by` link: just notify + record
     * that the blocker resolved. Both reuse the EXISTING DispatchNotifier
     * seam (taskAssigned / taskCommented) — no new channel.
     */
    public function notifyDependentsOfClosure(Task $task, ?int $actorUserId = null): void
    {
        if (! $task->isInactive()) {
            return;
        }

        foreach ($task->blocks as $dependent) {
            if ($this->answersAskOf($task, $dependent)) {
                $this->returnTheBall($dependent, $task, $actorUserId);
            } else {
                $this->notifyGenericDependency($dependent, $task, $actorUserId);
            }
        }
    }

    /**
     * The Ask return: $dependent (the asker's task) is re-assigned back to
     * whoever asked (the link's `created_by_user_id` — falling back to
     * $dependent's CURRENT assignee when that's null, e.g. a null-actor
     * agent-driven ask, so an unassigned dependent doesn't get force-
     * reassigned to nobody), and gets an event carrying the closed ask's
     * answer (its last human comment, or its recorded result).
     */
    protected function returnTheBall(Task $dependent, Task $closedAsk, ?int $actorUserId): void
    {
        $pivot = TaskLink::query()
            ->where('task_id', $dependent->id)
            ->where('blocked_by_task_id', $closedAsk->id)
            ->first();
        $askerId = $pivot?->created_by_user_id ?? $dependent->assignee_user_id;

        $fromAssignee = $dependent->assignee_user_id;
        $dependent->assignee_user_id = $askerId;
        $dependent->save();

        $answer = $this->summarizeAnswer($closedAsk);

        $comment = $dependent->recordEvent(
            TaskComment::EVENT_ANSWERED,
            $actorUserId,
            array_filter(['from' => $closedAsk->code, 'answer' => $answer], fn ($v) => $v !== null),
            $answer ? "Answered by {$closedAsk->code}: {$answer}" : "Answered by {$closedAsk->code}.",
        );

        $notifier = app(DispatchNotifier::class);
        if ($askerId !== $fromAssignee) {
            $notifier->taskAssigned($dependent, $fromAssignee, $askerId, null);
        }
        $notifier->taskCommented($dependent, $comment);
    }

    /**
     * The plain (non-ask) case: a task blocked by $closedBlocker just had its
     * blocker resolve. Record it and notify via taskCommented (the existing
     * seam) — no reassignment, since a generic `blocked_by` link never held
     * $dependent's assignment in the first place.
     */
    protected function notifyGenericDependency(Task $dependent, Task $closedBlocker, ?int $actorUserId): void
    {
        $comment = $dependent->recordEvent(
            TaskComment::EVENT_DEPENDENCY_RESOLVED,
            $actorUserId,
            ['blocker' => $closedBlocker->code, 'status' => $closedBlocker->status],
            "Blocker {$closedBlocker->code} is now `{$closedBlocker->status}`.",
        );

        app(DispatchNotifier::class)->taskCommented($dependent, $comment);
    }

    /**
     * The "answer" carried by an Ask's return event: prefer the closed ask's
     * latest human comment (the natural place a closer writes "here's what I
     * found"), falling back to its recorded result's `resolution` or
     * `commit` (§17C close conventions), else null — an ask can close with
     * neither and the return event still fires, just without an inline
     * answer body.
     */
    protected function summarizeAnswer(Task $closedAsk): ?string
    {
        $lastComment = $closedAsk->comments()
            ->where('event_type', TaskComment::EVENT_COMMENT)
            ->orderByDesc('id')
            ->first();
        if ($lastComment !== null && trim((string) $lastComment->body) !== '') {
            return $lastComment->body;
        }

        $result = $closedAsk->context['result'] ?? null;
        if (is_array($result)) {
            if (! empty($result['resolution'])) {
                return (string) $result['resolution'];
            }
            if (! empty($result['commit'])) {
                return "commit {$result['commit']}";
            }
        }

        return null;
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
