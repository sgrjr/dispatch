<?php

namespace Sgrjr\Dispatch\Services;

use Illuminate\Support\Facades\DB;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;

/**
 * Applies a MANIFEST of task operations in a single transaction — the batch
 * "memorialize" path (§20).
 *
 * The whole point: an agent (remote or local) works the backlog offline,
 * tracking its own changes as it goes, then commits the entire run in ONE hit
 * instead of forty progressive verb calls. The same JSON shape drives the local
 * `dispatch:batch <file>` CLI and the remote `POST agent/batch` endpoint, so this
 * class is the single implementation both call.
 *
 * SECURITY / SEMANTICS — this is the batch analogue of the CURATED verbs
 * (add/note/done), NOT the destructive package↔package snapshot apply. It is
 * deliberately additive and server-bounded:
 *   - Two ops only: `add` (new task, server-minted code) and `update` (existing
 *     task, matched by `code`). There is NO delete, and `update` never creates —
 *     an agent can't inject a chosen code onto production.
 *   - Labels ATTACH additively (syncWithoutDetaching) — never replace-all, so a
 *     batch can't strip a task's existing labels.
 *   - Status is whatever the manifest sets (new tasks default to `triage`) — the
 *     batch NEVER assumes `done`, so partially-completed work memorializes as the
 *     status it actually reached.
 *   - Vocab (type/priority/status) is validated against the configured workflow.
 *   - Appended comments are plain human comments (event_type=comment) only — the
 *     system timeline (status_change, claimed, …) is minted by the server, never
 *     forged from a payload.
 *
 * The whole apply is ONE transaction: a bad op rolls the batch back cleanly so
 * a re-submit starts from a known state. Re-submits are safe — `add` dedupes on
 * its idempotency `key`, comments dedupe on (event_type|body), and re-setting a
 * status to its current value records no event.
 */
class DispatchBatchService
{
    /** The operation kinds a manifest may contain. */
    public const OPS = ['add', 'update'];

    public function __construct(protected DispatchTaskService $tasks) {}

    /**
     * Validate + apply a manifest.
     *
     * @param  array<int,array<string,mixed>>  $operations
     * @param  array<string,mixed>  $actorMeta  Agent attribution stamped onto
     *                                           created tasks + authored events
     *                                           (empty for a trusted local run).
     * @param  int|null  $actorUserId  Author id for appended comments (null for
     *                                  an agent — its identity lives in meta).
     * @return array{summary:array<string,int>, results:array<int,array<string,mixed>>}
     *
     * @throws \InvalidArgumentException on a malformed manifest (message names
     *                                   the offending op index) — thrown BEFORE
     *                                   any write, so nothing is persisted.
     */
    public function apply(array $operations, array $actorMeta = [], ?int $actorUserId = null, bool $dryRun = false): array
    {
        $normalized = $this->validate($operations);

        $summary = [
            'tasks_created' => 0,
            'tasks_updated' => 0,
            'comments_added' => 0,
            'statuses_changed' => 0,
        ];
        $results = [];

        $run = function () use ($normalized, $actorMeta, $actorUserId, &$summary, &$results) {
            foreach ($normalized as $op) {
                $results[] = $op['op'] === 'add'
                    ? $this->applyAdd($op, $actorMeta, $actorUserId, $summary)
                    : $this->applyUpdate($op, $actorMeta, $actorUserId, $summary);
            }
        };

        if ($dryRun) {
            // Same rollback-to-observe trick as dispatch:import --dry-run: run the
            // full apply for its validation/existence checks, then discard it.
            DB::beginTransaction();
            try {
                $run();
            } finally {
                DB::rollBack();
            }
        } else {
            DB::transaction($run);
        }

        return ['summary' => $summary, 'results' => $results];
    }

    /**
     * Validate the whole manifest up front and return it with each op's kind
     * resolved (`op` defaults to `update` when a `code` is present, else `add`).
     *
     * @param  array<int,array<string,mixed>>  $operations
     * @return array<int,array<string,mixed>>
     */
    protected function validate(array $operations): array
    {
        $out = [];

        foreach ($operations as $i => $op) {
            if (! is_array($op)) {
                throw new \InvalidArgumentException("Operation {$i} is not an object.");
            }

            $kind = $op['op'] ?? (! empty($op['code']) ? 'update' : 'add');
            if (! in_array($kind, self::OPS, true)) {
                throw new \InvalidArgumentException("Operation {$i}: unknown op `{$kind}` (expected add|update).");
            }

            if ($kind === 'add' && trim((string) ($op['title'] ?? '')) === '') {
                throw new \InvalidArgumentException("Operation {$i} (add): `title` is required.");
            }

            if ($kind === 'update' && trim((string) ($op['code'] ?? '')) === '') {
                throw new \InvalidArgumentException("Operation {$i} (update): `code` is required.");
            }

            $this->assertVocab($i, 'type', $op['type'] ?? null, Task::types());
            $this->assertVocab($i, 'priority', $op['priority'] ?? null, Task::priorities());
            $this->assertVocab($i, 'status', $op['status'] ?? null, Task::statuses());

            foreach (($op['comments'] ?? []) as $c) {
                if (! is_array($c) || trim((string) ($c['body'] ?? '')) === '') {
                    throw new \InvalidArgumentException("Operation {$i}: every comment needs a non-empty `body`.");
                }
            }

            $op['op'] = $kind;
            $out[] = $op;
        }

        return $out;
    }

    /**
     * @param  array<int,string>  $allowed
     */
    protected function assertVocab(int $i, string $field, mixed $value, array $allowed): void
    {
        if ($value !== null && ! in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Operation {$i}: `{$field}` must be one of: ".implode(', ', $allowed).'.'
            );
        }
    }

    /**
     * Insert a new task (defaulting to triage), or — when an idempotency `key`
     * resolves to an existing task — leave its fields untouched and only fold in
     * new labels/comments/result. New tasks carry a null submitter; their agent
     * origin lives in context (mirroring AgentController::add).
     *
     * @param  array<string,mixed>  $op
     * @param  array<string,mixed>  $actorMeta
     * @param  array<string,int>  $summary
     * @return array<string,mixed>
     */
    protected function applyAdd(array $op, array $actorMeta, ?int $actorUserId, array &$summary): array
    {
        $labels = $this->labelNames($op['labels'] ?? []);
        $key = ! empty($op['key']) ? (string) $op['key'] : null;

        $existing = $key !== null
            ? config('dispatch.models.task')::query()->where('dedupe_key', $key)->first()
            : null;

        if ($existing !== null) {
            // Idempotent re-add: the keyed task already exists. Additively fold in
            // labels/comments/result; do NOT clobber its current fields or status.
            $this->tasks->attachLabels($existing, $labels);
            $summary['comments_added'] += $this->appendComments($existing, $op['comments'] ?? [], $actorUserId, $actorMeta);
            $this->recordResultIfAny($existing, $op);

            return $this->addResult($op, $existing, false);
        }

        $attributes = array_filter([
            'title' => $op['title'] ?? null,
            'type' => $op['type'] ?? null,
            'priority' => $op['priority'] ?? null,
            'description' => $op['description'] ?? null,
            'status' => $op['status'] ?? null, // create() defaults this to triage
        ], fn ($v) => $v !== null) + [
            'submitter_user_id' => null,
            'is_public' => (bool) ($op['public'] ?? false),
        ];

        $task = $key !== null
            ? $this->tasks->firstOrCreateByKey($key, $attributes, $labels)
            : $this->tasks->create($attributes, $labels);

        if ($actorMeta !== []) {
            $task->context = array_merge($task->context ?? [], ['agent' => $actorMeta]);
            $task->save();
        }

        $summary['tasks_created']++;
        $summary['comments_added'] += $this->appendComments($task, $op['comments'] ?? [], $actorUserId, $actorMeta);
        $this->recordResultIfAny($task, $op);

        return $this->addResult($op, $task, true);
    }

    /**
     * Upsert the WORK on an existing task: apply the provided fields, record a
     * status transition on the timeline when the status actually changes, fold in
     * labels/comments/result. Missing code rolls the whole batch back.
     *
     * @param  array<string,mixed>  $op
     * @param  array<string,mixed>  $actorMeta
     * @param  array<string,int>  $summary
     * @return array<string,mixed>
     */
    protected function applyUpdate(array $op, array $actorMeta, ?int $actorUserId, array &$summary): array
    {
        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $code = (string) $op['code'];
        /** @var Task|null $task */
        $task = $taskModel::query()->where('code', $code)->first();

        if ($task === null) {
            throw new \InvalidArgumentException("Update target `{$code}` not found. Use an `add` op to create a new task.");
        }

        // Only touch fields the op actually carries — a partial memorialize must
        // not blank out columns the manifest left out.
        foreach (['title', 'type', 'priority', 'description'] as $field) {
            if (array_key_exists($field, $op) && $op[$field] !== null) {
                $task->{$field} = $op[$field];
            }
        }
        if (array_key_exists('public', $op)) {
            $task->is_public = (bool) $op['public'];
        }

        $from = $task->status;
        $to = $op['status'] ?? null;
        $statusChanged = $to !== null && $to !== $from;
        if ($statusChanged) {
            $task->status = $to;
        }

        $task->save();

        if ($statusChanged) {
            $task->recordEvent(
                TaskComment::EVENT_STATUS_CHANGE,
                $actorUserId,
                $actorMeta + ['from' => $from, 'to' => $to],
                "Status changed from {$from} to {$to}.",
            );
            $summary['statuses_changed']++;
        }

        $this->tasks->attachLabels($task, $this->labelNames($op['labels'] ?? []));
        $summary['comments_added'] += $this->appendComments($task, $op['comments'] ?? [], $actorUserId, $actorMeta);
        $this->recordResultIfAny($task, $op);

        $summary['tasks_updated']++;

        return array_filter([
            'ref' => $op['ref'] ?? null,
            'op' => 'update',
            'code' => $task->code,
            'status' => $task->status,
        ], fn ($v) => $v !== null);
    }

    /**
     * Append the op's comments, skipping any whose (event_type|body) already
     * exists on the task — so a re-submitted manifest never double-posts. Batch
     * comments are always plain human comments; the timeline vocabulary is the
     * server's to mint.
     *
     * @param  array<int,array<string,mixed>>  $comments
     * @param  array<string,mixed>  $actorMeta
     */
    protected function appendComments(Task $task, array $comments, ?int $actorUserId, array $actorMeta): int
    {
        if ($comments === []) {
            return 0;
        }

        $existingKeys = $task->comments()
            ->get(['body', 'event_type'])
            ->map(fn ($c) => $c->event_type.'|'.$c->body)
            ->flip();

        $added = 0;
        foreach ($comments as $c) {
            $body = (string) ($c['body'] ?? '');
            $dedupeKey = TaskComment::EVENT_COMMENT.'|'.$body;
            if (isset($existingKeys[$dedupeKey])) {
                continue;
            }

            $task->comments()->create([
                'user_id' => $actorUserId,
                'body' => $body,
                'is_internal' => (bool) ($c['internal'] ?? false),
                'event_type' => TaskComment::EVENT_COMMENT,
                'meta' => $actorMeta ?: null,
            ]);

            $existingKeys[$dedupeKey] = true; // guard against dupes within one op too
            $added++;
        }

        return $added;
    }

    /**
     * Fold a `commit`/`result` pair into context.result, matching the `done`
     * verb, when the op carries either.
     *
     * @param  array<string,mixed>  $op
     */
    protected function recordResultIfAny(Task $task, array $op): void
    {
        $hasResult = array_key_exists('result', $op) && is_array($op['result']);
        $hasCommit = array_key_exists('commit', $op) && $op['commit'] !== null && $op['commit'] !== '';

        if ($hasResult || $hasCommit) {
            $this->tasks->recordResult($task, $hasResult ? $op['result'] : [], $hasCommit ? (string) $op['commit'] : null);
        }
    }

    /**
     * @param  mixed  $labels
     * @return array<int,string>
     */
    protected function labelNames(mixed $labels): array
    {
        return array_values(array_filter(
            array_map(fn ($n) => trim((string) $n), (array) $labels),
            fn ($n) => $n !== ''
        ));
    }

    /**
     * @param  array<string,mixed>  $op
     * @return array<string,mixed>
     */
    protected function addResult(array $op, Task $task, bool $created): array
    {
        return array_filter([
            'ref' => $op['ref'] ?? null,
            'op' => 'add',
            'code' => $task->code,
            'created' => $created,
        ], fn ($v) => $v !== null);
    }
}
