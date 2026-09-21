<?php

namespace Sgrjr\Dispatch\Services;

use Illuminate\Support\Facades\DB;
use Sgrjr\Dispatch\Contracts\LaneResolver;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Support\Anchor;
use Sgrjr\Dispatch\Support\DueDate;

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

    /**
     * Runaway-payload backstop for a comment body, in BYTES.
     *
     * The column is longText (4GB), so this is not a content limit — agent
     * result payloads and file listings legitimately run long and must not be
     * truncated, because the comment IS the durable record. It exists so a
     * caller bug (a serialised buffer, a runaway loop) fails with an
     * operation-scoped message instead of either a raw SQLSTATE 22001 or a
     * multi-megabyte row landing in the board.
     */
    public const MAX_COMMENT_BODY_BYTES = 1048576;

    /**
     * Runaway-payload backstop for the WHOLE manifest, in BYTES.
     *
     * The per-comment cap above bounds one field; nothing bounded the sum, so a
     * manifest of many individually-legal ops could still exceed the web
     * server's request-body limit (PHP `post_max_size`, nginx
     * `client_max_body_size`). That death happens BELOW the application, where
     * no dispatch error message can reach the caller — the caller sees a bare
     * 500/413 with no cause, which is precisely the opaque failure this whole
     * guard exists to eliminate.
     *
     * Deliberately set under a typical 8M `post_max_size` so the legible
     * app-level 422 fires FIRST and names the limit. A host that has raised its
     * server limit can raise this to match (`DISPATCH_AGENT_BATCH_MAX_BYTES`);
     * 0 disables the check.
     */
    public const MAX_PAYLOAD_BYTES = 4194304;

    /**
     * The configured whole-manifest byte cap, honoring the never-republish
     * doctrine (published config predating the key falls back to env, then the
     * package default).
     */
    public static function maxPayloadBytes(): int
    {
        $raw = config('dispatch.agent.batch.max_payload_bytes');
        if ($raw === null) {
            $raw = env('DISPATCH_AGENT_BATCH_MAX_BYTES', self::MAX_PAYLOAD_BYTES);
        }

        return (int) $raw;
    }

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
     * @param  bool  $quiet  Suppress the per-add create notifier + reactive
     *                       automation (bulk memorialize / backfill).
     * @return array{summary:array<string,int>, results:array<int,array<string,mixed>>}
     *
     * @throws \InvalidArgumentException on a malformed manifest (message names
     *                                   the offending op index) — thrown BEFORE
     *                                   any write, so nothing is persisted.
     */
    public function apply(array $operations, array $actorMeta = [], ?int $actorUserId = null, bool $dryRun = false, bool $quiet = false): array
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
            foreach ($normalized as $i => $op) {
                $results[] = $op['op'] === 'add'
                    ? $this->applyAdd($op, $actorMeta, $actorUserId, $summary)
                    : $this->applyUpdate($op, $actorMeta, $actorUserId, $summary, $i);
            }
        };

        // A quiet bulk memorialize suppresses the per-add create receipt +
        // reactive automation (same scope dispatch:import --no-notify uses).
        $exec = $quiet ? fn () => $this->tasks->quietly($run) : $run;

        if ($dryRun) {
            // Same rollback-to-observe trick as dispatch:import --dry-run: run the
            // full apply for its validation/existence checks, then discard it.
            DB::beginTransaction();
            try {
                $exec();
            } finally {
                DB::rollBack();
            }
        } else {
            DB::transaction($exec);
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
        // Whole-manifest size first: it is the cheapest check and the one whose
        // absence produces the least legible failure (an opaque death below the
        // app). Both the CLI and the HTTP endpoint route through here, so the
        // cap is stated once.
        $max = self::maxPayloadBytes();
        if ($max > 0) {
            $size = strlen((string) json_encode($operations));
            if ($size > $max) {
                throw new \InvalidArgumentException(
                    "Manifest is {$size} bytes, over the {$max}-byte limit for a single batch. ".
                    'Split it into several smaller batches — re-submits are safe (keyed adds dedupe, '.
                    'comments dedupe on (event_type|body), an unchanged status records no event).'
                );
            }
        }

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
            $this->assertDueAt($i, $op);
            $this->assertAnchorInput($i, $op, 'topic');
            $this->assertAnchorInput($i, $op, 'origin');
            $this->assertConversationId($i, $op);
            $this->assertLaneInput($i, $op);

            foreach (($op['comments'] ?? []) as $c) {
                if (! is_array($c)) {
                    throw new \InvalidArgumentException("Operation {$i}: every comment must be an object with a `body`.");
                }

                $body = $c['body'] ?? null;

                // `body` must be a STRING. Guard before the (string) cast below:
                // an agent that sends a structured body (an array/object of
                // findings, say) used to hit `(string) $array` here, which is a
                // PHP warning promoted to ErrorException by Laravel's handler —
                // so the whole batch died with a bare "Array to string
                // conversion" naming no operation and no field. Say what is
                // wrong and where instead.
                if (is_array($body) || is_object($body)) {
                    throw new \InvalidArgumentException(
                        "Operation {$i}: comment `body` must be a string, got ".
                        (is_object($body) ? get_class($body) : 'array').
                        '. Render structured content to text (or JSON-encode it) before sending.'
                    );
                }

                if (! is_scalar($body) || trim((string) $body) === '') {
                    throw new \InvalidArgumentException("Operation {$i}: every comment needs a non-empty `body`.");
                }

                // The column is longText, so this is a runaway-payload backstop,
                // not a content limit. Rejecting here names the operation;
                // letting it through produced a raw SQLSTATE[22001] "Data too
                // long for column 'body'" mid-transaction.
                $length = strlen((string) $body);
                if ($length > self::MAX_COMMENT_BODY_BYTES) {
                    throw new \InvalidArgumentException(
                        "Operation {$i}: comment `body` is {$length} bytes, over the ".
                        self::MAX_COMMENT_BODY_BYTES.'-byte limit. Attach or summarise it instead.'
                    );
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
     * A settable `due_at` must parse HERE, with the rest of the manifest —
     * i.e. before the transaction opens. Discovering it mid-apply would still
     * roll back cleanly, but only after the run had written earlier ops, and
     * "validation fails before anything is attempted" is the contract the batch
     * sells. The clear sentinel (null/"") is legal and needs no parse.
     *
     * @param  array<string,mixed>  $op
     */
    protected function assertDueAt(int $i, array $op): void
    {
        if (! array_key_exists('due_at', $op) || DueDate::isClear($op['due_at'])) {
            return;
        }

        try {
            DueDate::parseOrFail($op['due_at']);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException("Operation {$i}: ".$e->getMessage());
        }
    }

    /**
     * TASK-995 — validate a topic/origin field UP FRONT, same posture as
     * assertDueAt(): a malformed shorthand string, or an invalid explicit
     * `{$field}_type`, must fail before the transaction opens. Format-only —
     * origin's WRITE-ONCE invariant can't be checked here (it needs the
     * target task, which validate() never loads); that's enforced in
     * applyUpdate() via Task::setOrigin().
     *
     * @param  array<string,mixed>  $op
     */
    protected function assertAnchorInput(int $i, array $op, string $field): void
    {
        if (array_key_exists($field, $op)) {
            $value = $op[$field];
            if ($value === null || $value === '') {
                return; // an explicit clear — legal shorthand for topic; origin's
                        // write-once rule is enforced at apply time.
            }

            try {
                Anchor::parse((string) $value);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException("Operation {$i}: `{$field}`: ".$e->getMessage());
            }

            return;
        }

        $typeKey = "{$field}_type";
        if (array_key_exists($typeKey, $op) && $op[$typeKey] !== null
            && ! preg_match('/^[a-z][a-z0-9_]{0,31}$/', (string) $op[$typeKey])) {
            throw new \InvalidArgumentException(
                "Operation {$i}: `{$typeKey}` must match ^[a-z][a-z0-9_]{0,31}\$."
            );
        }
    }

    /**
     * @param  array<string,mixed>  $op
     */
    protected function assertConversationId(int $i, array $op): void
    {
        if (! array_key_exists('conversation_id', $op) || $op['conversation_id'] === null) {
            return;
        }

        $value = $op['conversation_id'];
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new \InvalidArgumentException("Operation {$i}: `conversation_id` must be an integer or null.");
        }
    }

    /**
     * TASK-997 part A — validate `lane` UP FRONT, same posture as
     * assertDueAt()/assertAnchorInput(): a non-null value must pass
     * LaneResolver::isLane() before the transaction opens, or the WHOLE batch
     * fails naming the operation. `null`/`""` is always legal (an explicit
     * clear to the no-department lane — see applyUpdate()); an ADD's clear is
     * simply nothing to carry, matching due_at's "a new task has nothing to
     * remove."
     *
     * @param  array<string,mixed>  $op
     */
    protected function assertLaneInput(int $i, array $op): void
    {
        if (! array_key_exists('lane', $op)) {
            return;
        }

        $lane = $op['lane'];
        if ($lane === null || $lane === '') {
            return;
        }

        if (! app(LaneResolver::class)->isLane((string) $lane)) {
            throw new \InvalidArgumentException(
                "Operation {$i}: `lane` `{$lane}` is not a valid lane (see dispatch:schema)."
            );
        }
    }

    /**
     * TASK-995 — resolve a topic/origin field into `[type, id]` (a value),
     * `[null, null]` (an explicit clear), or null (the field wasn't touched
     * at all — check with anchorTouched() first). Already validated by
     * assertAnchorInput(), so parsing here cannot throw.
     *
     * @param  array<string,mixed>  $op
     * @return array{0:?string,1:?string}|null
     */
    protected function resolveAnchorInput(array $op, string $field): ?array
    {
        if (array_key_exists($field, $op)) {
            $value = $op[$field];

            return ($value === null || $value === '') ? [null, null] : Anchor::parse((string) $value);
        }

        $typeKey = "{$field}_type";
        if (array_key_exists($typeKey, $op)) {
            $type = $op[$typeKey];
            if ($type === null) {
                return [null, null];
            }

            $idKey = "{$field}_id";

            return [(string) $type, array_key_exists($idKey, $op) && $op[$idKey] !== null ? (string) $op[$idKey] : null];
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $op
     */
    protected function anchorTouched(array $op, string $field): bool
    {
        return array_key_exists($field, $op) || array_key_exists("{$field}_type", $op);
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

        // A due date is part of CREATION here, not a change — so it is set
        // silently, with no timeline event (the `update` op memorializes).
        // A clear sentinel is simply nothing to carry: a brand-new task has no
        // due date to remove. Already validated, so this cannot throw.
        if (array_key_exists('due_at', $op) && ! DueDate::isClear($op['due_at'])) {
            $attributes['due_at'] = DueDate::parseOrFail($op['due_at']);
        }

        // TASK-995 — topic/origin/conversation are likewise part of CREATION,
        // not a change: no timeline event, and a fresh task's origin is
        // always writable regardless of what the op sends. Already validated
        // (assertAnchorInput), so parsing here cannot throw.
        if ($this->anchorTouched($op, 'topic')) {
            [$topicType, $topicId] = $this->resolveAnchorInput($op, 'topic');
            if ($topicType !== null) {
                $attributes['topic_type'] = $topicType;
                $attributes['topic_id'] = $topicId;
            }
        }
        if ($this->anchorTouched($op, 'origin')) {
            [$originType, $originId] = $this->resolveAnchorInput($op, 'origin');
            if ($originType !== null) {
                $attributes['origin_type'] = $originType;
                $attributes['origin_id'] = $originId;
            }
        }
        if (array_key_exists('conversation_id', $op) && $op['conversation_id'] !== null) {
            $attributes['conversation_id'] = (int) $op['conversation_id'];
        }

        // TASK-997 part A — lane is likewise part of CREATION: set silently
        // (no timeline event) when a non-empty value is given; an empty/null
        // value on add is nothing to carry (a brand-new task has no lane to
        // clear). Already validated (assertLaneInput).
        if (array_key_exists('lane', $op) && $op['lane'] !== null && $op['lane'] !== '') {
            $attributes['lane'] = (string) $op['lane'];
        }

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
     * status transition on the timeline when the status actually changes, ditto
     * a due-date change, fold in labels/comments/result. Missing code rolls the
     * whole batch back.
     *
     * @param  array<string,mixed>  $op
     * @param  array<string,mixed>  $actorMeta
     * @param  array<string,int>  $summary
     * @param  int  $i  the operation's index in the manifest, for error messages
     * @return array<string,mixed>
     */
    protected function applyUpdate(array $op, array $actorMeta, ?int $actorUserId, array &$summary, int $i = 0): array
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

        // `due_at` is tri-state, so it CANNOT ride the loop above: that loop's
        // `!== null` test is what protects the other fields from being blanked
        // by a partial memorialize, and it is therefore structurally unable to
        // express a clear. Key presence decides here; null/"" clears.
        $dueFrom = null;
        $dueTo = null;
        $dueChanged = false;
        if (array_key_exists('due_at', $op)) {
            $dueFrom = $task->due_at?->toDateString();
            $due = DueDate::resolve($op['due_at']); // validated up front
            $task->due_at = $due;
            $dueTo = $due?->toDateString();
            // Date-grained, matching the Livewire editor: re-submitting the same
            // due date is idempotent and mints no event.
            $dueChanged = $dueFrom !== $dueTo;
        }

        // TASK-995 — topic is EDITABLE (tri-state, like due_at): untouched
        // unless the op names it, cleared by an explicit null. Origin is
        // WRITE-ONCE: Task::setOrigin() enforces it, and its LogicException is
        // translated into the same InvalidArgumentException vocabulary the
        // rest of this batch validation uses, naming the operation, so a
        // rejected change here reads like any other validation failure.
        if ($this->anchorTouched($op, 'topic')) {
            [$topicType, $topicId] = $this->resolveAnchorInput($op, 'topic');
            $task->setTopic($topicType, $topicId);
        }
        if ($this->anchorTouched($op, 'origin')) {
            [$originType, $originId] = $this->resolveAnchorInput($op, 'origin');
            try {
                $task->setOrigin($originType, $originId);
            } catch (\LogicException $e) {
                throw new \InvalidArgumentException("Operation {$i}: {$e->getMessage()}");
            }
        }
        if (array_key_exists('conversation_id', $op)) {
            $task->conversation_id = $op['conversation_id'] !== null ? (int) $op['conversation_id'] : null;
        }

        // TASK-997 part A — `lane` is tri-state like `due_at`: absent = the
        // task's routing is untouched, null/"" clears it to the no-department
        // lane, any other value is already validated (assertLaneInput).
        $laneFrom = null;
        $laneTo = null;
        $laneChanged = false;
        if (array_key_exists('lane', $op)) {
            $laneFrom = $task->lane;
            $laneTo = ($op['lane'] === null || $op['lane'] === '') ? null : (string) $op['lane'];
            $task->lane = $laneTo;
            $laneChanged = $laneFrom !== $laneTo;
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

        // Memorialize an agent-caused due-date change in the SAME words the
        // Livewire editor uses, so a human reading the timeline can't tell (and
        // needn't care) whether the board or an agent moved the date.
        if ($dueChanged) {
            $task->recordEvent(
                TaskComment::EVENT_COMMENT,
                $actorUserId,
                $actorMeta + ['due_at' => ['from' => $dueFrom, 'to' => $dueTo]],
                $dueTo ? "Due date set to {$dueTo}." : 'Due date cleared.',
            );
        }

        // TASK-997 part A — a real lane change gets its own event type
        // (EVENT_LANE_CHANGE), distinct from the generic due_at comment above,
        // so a routing change is queryable/filterable on the timeline.
        if ($laneChanged) {
            $task->recordEvent(
                TaskComment::EVENT_LANE_CHANGE,
                $actorUserId,
                $actorMeta + ['from' => $laneFrom, 'to' => $laneTo],
                $laneTo !== null ? "Routed to {$laneTo}." : 'Lane cleared.',
            );
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
