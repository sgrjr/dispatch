<?php

namespace Sgrjr\Dispatch\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Sgrjr\Dispatch\Contracts\SubmitterResolver;
use Sgrjr\Dispatch\Contracts\TenantResolver;
use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;

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
     * Atomically claim an actionable task for an agent (C1). Picks only
     * UNSTARTED work (status open/triage, never `in_progress`, so two agents
     * can't grab the same in-flight task), mirroring dispatch:next's ordering so
     * an agent claims exactly what `next` would surface. Row-locked inside a
     * transaction; on MySQL/Postgres SKIP LOCKED hands each concurrent agent a
     * distinct task.
     *
     * Pass $code to claim ONE specific task by code instead of the next
     * candidate — used when a human (or a plan) hands an agent a particular
     * task. The same UNSTARTED guard still applies: a named task that is already
     * in_progress/done/etc. (or doesn't exist) yields null, so claim-by-code
     * still never steals in-flight work. A named code is exact — the type/label
     * filters are ignored, since the code already picks the task.
     *
     * @param  array{type?:string,label?:string|array<int,string>}  $filters
     */
    public function claim(?AgentSession $session = null, array $filters = [], ?int $assigneeUserId = null, ?string $code = null): ?Task
    {
        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        // A named code picks the task outright; the narrowing filters would only
        // muddy that (and could null out an explicit request), so drop them.
        $type = $code === null ? ($filters['type'] ?? null) : null;
        $label = $code === null ? ($filters['label'] ?? null) : null;

        return DB::transaction(function () use ($taskModel, $session, $type, $label, $assigneeUserId, $code) {
            $query = $taskModel::query()
                ->whereIn('status', ['open', 'triage'])
                ->when($code, fn ($q, $c) => $q->where('code', $c))
                ->when($type, fn ($q, $type) => $q->where('type', $type))
                ->when($label, fn ($q, $label) => $q->whereHas(
                    'labels',
                    fn ($lq) => $lq->whereIn('name', (array) $label)
                ))
                ->orderByRaw("CASE WHEN status IN ('open', 'in_progress') THEN 0 ELSE 1 END")
                ->orderByRaw("CASE priority WHEN 'blocker' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 99 END")
                ->orderBy('position')
                ->orderBy('id');

            // Row-lock the candidate. MySQL/Postgres get SKIP LOCKED so parallel
            // claimers each grab the NEXT free row; SQLite compiles lockForUpdate
            // to a no-op (single-connection test env — atomicity is asserted by
            // sequential distinctness, see ClaimTest).
            $driver = DB::connection()->getDriverName();
            if (in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
                $query->lock('for update skip locked');
            } else {
                $query->lockForUpdate();
            }

            /** @var Task|null $task */
            $task = $query->first();
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
     * @param  array<string,mixed>  $result
     */
    public function recordResult(Task $task, array $result, ?string $commit = null): void
    {
        if ($commit !== null && $commit !== '') {
            $result['commit'] = $commit;
        }

        $ctx = $task->context ?? [];
        $ctx['result'] = $result;
        $task->context = $ctx;
        $task->save();
    }
}
