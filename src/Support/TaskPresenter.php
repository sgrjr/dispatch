<?php

namespace Sgrjr\Dispatch\Support;

use Sgrjr\Dispatch\Contracts\ConversationResolver;
use Sgrjr\Dispatch\Contracts\LaneResolver;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskAttachment;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchBatchService;

/**
 * THE canonical Task -> machine JSON shaper (§17C C5).
 *
 * Every `--json` verb (add/next/queue/show), the remote agent API, and the
 * `--remote` CLI parse THIS shape. It is a frozen contract — change a key and
 * you break the agent skill + the remote parser at once. `dispatch:schema` dumps
 * {@see schema()} so an agent parses against a documented shape, not a guess.
 *
 * Two views: the summary (list/next/queue/add) and the full detail (show AND
 * claim), the latter adding description/context/comments. Claim returns the
 * full view on purpose — it is the moment an agent commits to a task and needs
 * the human's direction, which lives in the description/comments.
 */
class TaskPresenter
{
    /**
     * @return array<string,mixed>
     */
    public static function toArray(Task $task, bool $full = false): array
    {
        $data = [
            'code' => $task->code,
            'title' => $task->title,
            'type' => $task->type,
            'priority' => $task->priority,
            'status' => $task->status,
            'is_public' => (bool) $task->is_public,
            'labels' => $task->labels->pluck('name')->values()->all(),
            // Cheap "is there human direction to read?" signal on the SUMMARY
            // shape (GAP 2c): a count of human comments (event_type=comment),
            // NOT system timeline events. > 0 means run `show` before claiming.
            'comment_count' => self::commentCount($task),
            // Task-level attachment count on the SUMMARY shape (W8-6): >0 means a
            // human attached evidence (a screenshot, a log) the JSON API cannot
            // deliver — the binaries live on a private, auth-gated disk. Treat it
            // as a signal to run `show` and, if you need the content, ask for a
            // transcription.
            'attachment_count' => self::attachmentCount($task),
            'due_at' => optional($task->due_at)->toIso8601String(),
            'dedupe_key' => $task->dedupe_key,
            // Guard on the FK before touching the relation: an agent/CLI task has
            // a null submitter, and building the belongsTo instantiates the host
            // user model — which need not even exist in a headless/agent context.
            'submitter' => $task->submitter_user_id ? self::userRef($task->submitter) : null,
            'assignee' => $task->assignee_user_id ? self::userRef($task->assignee) : null,
            // W13-4, additive: the config-defined TEAM holding the assignee
            // slot (mutually exclusive with assignee). Null on pre-groups
            // rows, so the frozen contract only ever gains a key.
            'assignee_group' => $task->assignee_group,
            // TASK-995 anchor fields — flat on BOTH shapes. `topic_account_key`
            // is a read-only rollup; agent verbs cannot set it directly.
            'topic_type' => $task->topic_type,
            'topic_id' => $task->topic_id,
            'topic_account_key' => $task->topic_account_key,
            'origin_type' => $task->origin_type,
            'origin_id' => $task->origin_id,
            'conversation_id' => $task->conversation_id,
            // TASK-997 part A — flat on BOTH shapes, like the anchor fields.
            // Null = the no-department lane (R15), not "unknown".
            'lane' => $task->lane,
            'created_at' => optional($task->created_at)->toIso8601String(),
            'updated_at' => optional($task->updated_at)->toIso8601String(),
        ];

        if ($full) {
            // Resolver calls (label/url) happen ONLY here, on the full shape —
            // never per-row in next/queue's summary list. Null-safe: $task->topic
            // / $task->origin are already null when no anchor is set.
            $data['topic_label'] = $task->topic?->label();
            $data['topic_url'] = $task->topic?->url();
            $data['origin_label'] = $task->origin?->label();
            $data['origin_url'] = $task->origin?->url();
            // TASK-997 part A — resolver call (label), full shape only, same
            // posture as topic_label/origin_label above. Null when unrouted or
            // when the bound resolver doesn't recognize the stored key.
            $data['lane_label'] = $task->lane !== null ? app(LaneResolver::class)->label($task->lane) : null;
            // TASK-997 part B — real task->task links, full shape only (like
            // topic_label/origin_label above): arrays of task CODES, never
            // ids — codes are the one identifier that travels off-instance.
            $data['blocked_by'] = $task->blockedBy->pluck('code')->values()->all();
            $data['blocks'] = $task->blocks->pluck('code')->values()->all();
            // TASK-1001 (R7/R8) — the ARC: the sibling tasks sharing this
            // task's home conversation, in birth order, plus whatever the
            // bound ConversationResolver can say about the envelope. Full
            // shape only: the transcript costs a query, and a list view must
            // never pay it per row.
            $data['arc'] = self::arc($task);
            $data['description'] = $task->description;
            $data['context'] = $task->context;
            // Task-level attachment metadata (W8-6): existence SIGNALS only — there
            // is no fetch URL because binaries do not travel the agent JSON API
            // (private disk, auth-gated streaming). A human who attached a
            // screenshot reasonably assumes the agent saw it; surface it so the
            // agent can ask for a transcription rather than silently proceeding.
            $data['attachments'] = $task->attachments->map(fn (TaskAttachment $a) => [
                'filename' => $a->original_name,
                'mime' => $a->mime_type,
                'size_bytes' => $a->size_bytes,
                'is_image' => (bool) $a->is_image,
            ])->values()->all();
            $data['comments'] = $task->comments->map(fn (TaskComment $c) => [
                'id' => $c->id,
                'event_type' => $c->event_type,
                'is_internal' => (bool) $c->is_internal,
                'author' => $c->user_id ? self::userRef($c->user) : null,
                'body' => $c->body,
                'meta' => $c->meta,
                // Per-comment attachment count (W8-6): same existence signal at the
                // comment grain — a human may hang evidence off a specific reply.
                'attachment_count' => self::commentAttachmentCount($c),
                'created_at' => optional($c->created_at)->toIso8601String(),
            ])->values()->all();
        }

        return $data;
    }

    /**
     * @param  iterable<Task>  $tasks
     * @return array<int,array<string,mixed>>
     */
    public static function collection(iterable $tasks): array
    {
        $out = [];
        foreach ($tasks as $task) {
            $out[] = self::toArray($task, false);
        }

        return $out;
    }

    /**
     * The documented shape (types + enums) dumped by `dispatch:schema`.
     *
     * @return array<string,mixed>
     */
    public static function schema(): array
    {
        return [
            'summary' => [
                'code' => 'string',
                'title' => 'string',
                'type' => Task::types(),
                'priority' => Task::priorities(),
                'status' => Task::statuses(),
                'is_public' => 'bool',
                'labels' => 'string[]',
                'comment_count' => 'int',   // human comments (event_type=comment); >0 → run `show` for direction
                'attachment_count' => 'int', // task-level; >0 = a human attached evidence the API cannot deliver — ask for a transcription
                'due_at' => 'iso8601|null',
                'dedupe_key' => 'string|null',
                'submitter' => 'string|int|null',
                'assignee' => 'string|int|null',
                'assignee_group' => 'string|null (config-defined team name holding the assignee slot; mutually exclusive with assignee)',
                // TASK-995 anchor fields.
                'topic_type' => 'string|null — short alias (account, plan, title, order, …), never a class name',
                'topic_id' => 'string|null — a permanent natural key',
                'topic_account_key' => 'string|null — STORED rollup stamped by the TopicResolver; never settable directly (an agent verb cannot set it)',
                'origin_type' => 'string|null — message | custnote | exception | contact_form | plan_request | task | an out-of-band channel (email, phone, in_person)',
                'origin_id' => 'string|null — absent for an out-of-band channel origin',
                'conversation_id' => 'int|null — the home conversation/arc',
                // TASK-997 part A.
                'lane' => 'string|null — "<department>" or "<department>:<role>"; null = the NO-DEPARTMENT lane (routing only, never visibility). Validated against the bound LaneResolver on every write; inert (always rejects a non-null value) until a host binds one.',
                'created_at' => 'iso8601',
                'updated_at' => 'iso8601',
            ],
            'full_adds' => [
                'description' => 'string|null',
                // TASK-995 — resolver calls (label/url), full shape only.
                'topic_label' => 'string|null — human label for the topic anchor, resolved via TopicResolver; null when no topic is set',
                'topic_url' => 'string|null — link to the topic\'s own page, when the resolver has one',
                'origin_label' => 'string|null — human label for the origin anchor, resolved via OriginResolver; null when no origin is set',
                'origin_url' => 'string|null — link to the origin, when the resolver has one',
                // TASK-997 part A — resolver call, full shape only.
                'lane_label' => 'string|null — human label for `lane`, resolved via LaneResolver; null when unrouted or the resolver doesn\'t recognize the key',
                // TASK-997 part B (the ball / hand-off) — real task->task
                // links, full shape only.
                'blocked_by' => 'string[] — task CODES currently blocking this one (dispatch_task_links, this task is the BLOCKED side)',
                'blocks' => 'string[] — task CODES this one blocks (this task is the BLOCKER side)',
                // TASK-1001 — the arc (R7/R8). Always present; an unhomed
                // task has conversation_id null and empty lists.
                'arc' => 'object — the home conversation and its other tasks: {conversation_id: int|null, conversation_label: string|null, conversation_url: string|null, siblings: [{code, title, status, lane, assignee, created_at}] in BIRTH ORDER, transcript: [{id, author, body, at}] oldest-first}. label/url/transcript come from the bound ConversationResolver and are null/[] when none is bound.',
                // W15-2: the one-word description sent agents past a complete
                // machine-filed diagnosis — a sweep DECLINED a live bug whose
                // context already named its fix commit. For an exception-filed
                // task this, not the description, is where the evidence lives.
                'context' => 'object|null — arbitrary per-task data. For an EXCEPTION-filed task it carries the whole incident: exception{class,message,file,line}, trace[], route/method/url, times_seen/first_seen/last_seen, plus result{commit,resolution,metrics} from any agent that worked it. An exception task with an empty description is NOT evidence-free — read context before declining it. Also carries source{file,line,imported_at} import provenance',
                'attachments' => '[{filename, mime, size_bytes, is_image:bool}] — metadata SIGNALS only: no fetch URL, binaries do not travel the agent API',
                'comments' => '[{id:int, event_type:string, is_internal:bool, author:string|int|null, body:string, meta:object|null, attachment_count:int, created_at:iso8601}]',
                // TASK-1188 — on `show` (agent API + dispatch:show --json) only.
                'kind' => 'object|null — the task KIND (a task that defines its own controls), null for a plain task: {key, actions: [{key, label, style, inputs: [{key, label, type, required?, options?}], confirm, agent_allowed}] (ONLY the ones you may `perform`), hides: string[] (default controls this kind hides: status|assignee|claim|pass|ask), locks_status: bool (true = done/batch/claim/drag are REFUSED; only its actions move it), panel: {title, state?, rows: [{label, value, emphasis?}]}|null}',
            ],
            // Close conventions (§17C / done verb): what a completed task stores
            // and the recommended way to record HOW it resolved, so the board can
            // measure pre-resolved briefs (built vs. already-implemented/obsolete).
            // TASK-1188 — POST agent/perform · dispatch:perform <code> <action> [--input=k=v]…
            'perform' => [
                'code' => 'string',
                'action' => 'string — a key from the task\'s kind.actions',
                'input' => 'object|null — the action\'s inputs by key; `required` ones must be present (422 otherwise); undeclared keys are dropped',
                'response' => '{message: string|null, task: <full shape + kind>}',
                'refusals' => '403 = not offered to an agent (a person\'s action, e.g. an approval\'s Approve / Deny); 422 = a missing input or the kind refused; 404 = no such task',
            ],
            'done' => [
                'status' => 'the target status (default done). The three CLOSED statuses each mean one thing: done = the prescribed work was completed, nothing left; resolved = dealt with, but NOT as written (partly, differently, or the need went away), note REQUIRED; declined = not done, by decision (reason recommended). All three close the task alike (dependents unblock, an ask returns the ball).',
                'note' => 'string — what actually happened, recorded as the body of the status event (meta.note). REQUIRED for status=resolved (a 422 otherwise); optional for any other status. CLI: --note / --note-file.',
                'commit' => 'sha stored at context.result.commit',
                'result' => 'object stored at context.result (a new close replaces it; metrics accumulate)',
                'resolution' => 'recommended result key on close: result.resolution = built | already-implemented | obsolete (free-form allowed) — records HOW the task resolved, so the board can measure pre-resolved briefs',
                'due_at' => 'iso8601|null (CLI --due) — set or clear the review-by date AT CLOSE, e.g. when handing back with status=verifying: a date sets it, null or "" clears it, absent leaves it untouched. A real change is memorialized on the timeline.',
            ],
            // The `dispatch:batch` / POST agent/batch manifest — apply a whole
            // run of ops in one transaction. Additive + server-bounded: `add`
            // mints a new task (defaults to triage); `update` upserts the WORK on
            // an existing task by code (never creates, never assumes done);
            // labels ATTACH (never replace); comments are plain human comments,
            // deduped on (event_type|body) so a re-submit is safe.
            'batch' => [
                'request' => ['operations' => '[op, …]', 'dry_run' => 'bool (optional)'],
                'op' => [
                    'op' => 'add|update (optional — inferred as update when `code` is present, else add)',
                    'ref' => 'string|null (client handle, echoed back in results so you can map it to the minted code)',
                    'code' => 'string (update: the task to upsert; ignored for add)',
                    'key' => 'string|null (add: idempotency key — returns the existing task instead of duplicating)',
                    'title' => 'string (add: required)',
                    'type' => Task::types(),
                    'priority' => Task::priorities(),
                    'status' => Task::statuses(),
                    'due_at' => 'iso8601|null — set the due date; null or "" clears it; absent leaves it untouched',
                    'description' => 'string|null',
                    'public' => 'bool (optional)',
                    'labels' => 'string[] (ATTACHED additively — never replaces existing labels)',
                    'commit' => 'string|null (stored under context.result.commit)',
                    'result' => 'object|null (stored under context.result)',
                    'comments' => '[{body:string, public?:bool (default false: an internal, staff-only note; true = the submitter sees it and is emailed), internal?:bool (legacy inverse of public)}]',
                    'note' => 'string|null — what actually happened; REQUIRED (this, or a comment in the same op) when status=resolved. Recorded as the body of the status event; on add it also lands as a comment.',
                    // TASK-995 — the shorthand "<type>:<id>" string OR the
                    // explicit *_type/*_id pair; tri-state like due_at (absent =
                    // untouched, null = clear). `topic_account_key` is never
                    // accepted here — it is stamped server-side.
                    'topic' => 'string|null — shorthand "<type>:<id>" (e.g. "account:0402100000001"); null clears the topic. Alternative to topic_type/topic_id.',
                    'topic_type' => 'string|null (alternative to the `topic` shorthand)',
                    'topic_id' => 'string|null (alternative to the `topic` shorthand)',
                    'origin' => 'string|null — shorthand "<type>[:<id>]" (e.g. "phone", "task:TASK-042"). WRITE-ONCE: settable only while unset; a change once set fails the whole batch. Alternative to origin_type/origin_id.',
                    'origin_type' => 'string|null (alternative to the `origin` shorthand)',
                    'origin_id' => 'string|null (alternative to the `origin` shorthand)',
                    'conversation_id' => 'int|null — set the home conversation; null clears it; absent leaves it untouched',
                    // TASK-997 part A — add: set silently at creation (no
                    // timeline event), same posture as due_at/topic on add.
                    // update: tri-state like due_at (absent = untouched,
                    // null/"" clears to the no-department lane); a real
                    // change records a `lane_change` timeline event. Either
                    // way, a non-null value must pass LaneResolver::isLane()
                    // or the WHOLE batch fails naming the operation.
                    'lane' => 'string|null — "<department>" or "<department>:<role>"; tri-state on update like due_at',
                    // TASK-997 part B — ADDITIVE (never a replace-all), same
                    // posture as `labels`: entries fold onto whatever links
                    // the task already carries. Each entry is a task CODE or
                    // `@ref`, an in-batch reference to an EARLIER op's `ref`
                    // in this same manifest (what ends the two-batch dance —
                    // file the blocker and the blocked task's link in ONE
                    // apply). An unresolvable ref, or a code not found, fails
                    // the WHOLE batch naming the operation index; so does a
                    // self-link or a would-be cycle (see
                    // DispatchTaskService::linkBlockedBy()).
                    'blocked_by' => 'string[]|null — task codes and/or "@ref" entries to ADD as blockers of this task (add or update)',
                ],
                // Sizing a manifest by op-count alone is not enough — a single
                // oversized comment body used to blow a column ceiling and take
                // the whole transaction with it. Both caps are stated here so the
                // contract an agent actually reads answers "how big may this be?"
                'limits' => [
                    'max_operations' => (int) config('dispatch.agent.batch.max_operations', 200).' — ops per request (0 = uncapped); over it: 422 "Batch too large".',
                    'max_comment_body_bytes' => DispatchBatchService::MAX_COMMENT_BODY_BYTES.' — BYTES per comment body (not characters); over it: 422 naming the operation index and the actual size. Attach or summarise instead of splitting mid-sentence.',
                    'max_payload_bytes' => DispatchBatchService::maxPayloadBytes().' — BYTES for the WHOLE manifest (json_encode of operations[]); over it: 422 naming the actual size. dispatch:batch also checks this locally and refuses to send, so you learn it without spending a request.',
                    'note' => 'These bound the app layer only. A manifest large enough to exceed the web server / PHP body limit (post_max_size) is rejected BELOW the app, where no dispatch error message can reach you — split a very large run into several batches rather than relying on a message.',
                ],
                'semantics' => [
                    'add mints a new task (server-minted code); status defaults to triage.',
                    'update upserts the WORK on an existing task by code — it never creates, and leaves status unchanged unless set.',
                    'the whole manifest applies in one transaction; a bad op rolls it all back.',
                    're-submits are safe: keyed adds dedupe, comments dedupe on (event_type|body), an unchanged status records no event.',
                ],
                'response' => [
                    'applied' => 'bool',
                    'dry_run' => 'bool',
                    'summary' => ['tasks_created' => 'int', 'tasks_updated' => 'int', 'comments_added' => 'int', 'statuses_changed' => 'int'],
                    'results' => '[{ref?:string, op:add|update, code:string, created?:bool, status?:string}]',
                ],
            ],
            // The `dispatch:import` document (also exactly what `dispatch:export`
            // writes): a full snapshot upserted by `code`, OR — for a codeless md
            // migration — by a stable import `key` persisted as `dedupe_key`, so a
            // re-import upserts instead of duplicating. Backdated timestamps +
            // per-comment author/date make this the backfill-WITH-HISTORY path
            // (the `batch` verb above is the additive, always-"now" sibling).
            'import' => [
                'request' => [
                    'tasks' => '[task, …]',
                    'labels' => '[label, …] (optional — upserted by name before tasks resolve their label refs)',
                ],
                'task' => [
                    'code' => 'string|null (upsert key; honored + never reminted — omit for a codeless md migration)',
                    'key' => 'string|null (codeless idempotency key — host convention sha1(file|first-line); persisted as dedupe_key. `dedupeKey` is an accepted alias)',
                    'title' => 'string (truncated to 255 on BOTH create and update)',
                    'description' => 'string|null',
                    'type' => Task::types(),
                    'priority' => Task::priorities(),
                    'status' => Task::statuses(),
                    'isPublic' => 'bool',
                    'position' => 'int',
                    'exceptionSignature' => 'string|null (dedupe key for auto-captured errors)',
                    'context' => 'object|null (merged onto the task — e.g. context.source = {file, line, imported_at} provenance)',
                    'submitter' => 'string|null (email — resolved to a user id; unresolved ⇒ null submitter)',
                    'assignee' => 'string|null (email — resolved to a user id)',
                    'labels' => 'string[] (label names — attached; must appear in labels[] or already exist)',
                    'comments' => '[{body:string, eventType:string, isInternal:bool, notifiedSubmitter:bool, author:string|null(email), meta:object|null, createdAt:iso8601}]',
                    'createdAt' => 'iso8601 (backdated origination — preserved on create)',
                    'updatedAt' => 'iso8601 (backdated)',
                ],
                'label' => ['name' => 'string', 'color' => 'string|null', 'description' => 'string|null'],
                'semantics' => [
                    'a row needs a code OR a key; a row with neither is skipped and counted (tasks_skipped).',
                    'an existing task (matched by code or dedupe_key) is updated in place; a local status transition newer than the snapshot is kept — unpushed work is never reverted.',
                    'comments merge additively, deduped on (event_type|body), so local-only notes survive a re-import.',
                    'run `dispatch:import --no-notify` for a bulk historical backfill: no per-row receipts, no reactive automation.',
                ],
            ],
            'event_types' => [
                TaskComment::EVENT_COMMENT,
                TaskComment::EVENT_STATUS_CHANGE,
                TaskComment::EVENT_ASSIGNEE_CHANGE,
                TaskComment::EVENT_LABEL_ADDED,
                TaskComment::EVENT_LABEL_REMOVED,
                TaskComment::EVENT_LABEL_REPLACED,
                TaskComment::EVENT_PUBLIC_TOGGLE,
                TaskComment::EVENT_PROMOTED,
                TaskComment::EVENT_EXCEPTION,
                TaskComment::EVENT_DESCRIPTION_EDITED,
                TaskComment::EVENT_MERGED,
                TaskComment::EVENT_CLAIMED,
                TaskComment::EVENT_LANE_CHANGE,
                // TASK-1188 — a task kind's action was run (internal).
                TaskComment::EVENT_ACTION,
                // TASK-997 part B (the ball / hand-off).
                TaskComment::EVENT_HANDED_OFF,
                TaskComment::EVENT_ASKED,
                TaskComment::EVENT_ANSWERED,
                TaskComment::EVENT_DEPENDENCY_RESOLVED,
            ],
        ];
    }

    /**
     * Number of HUMAN comments (event_type=comment) on the task — the "does this
     * task carry direction I should read before claiming?" signal (GAP 2c).
     * System timeline events (status_change, claimed, …) are excluded.
     *
     * Order of preference keeps collections off the N+1 path:
     *   1. an eager `->withCount(['comments as comment_count' => human filter])`
     *      (added by the summary/collection query sites);
     *   2. the already-loaded `comments` relation (full shape) — counted in memory;
     *   3. a single COUNT query as a fallback (single-task summaries only).
     */
    /**
     * TASK-1001 (R7/R8) — the arc block on the full shape. A conversation is
     * an arc of undefined size: the package knows which tasks share the
     * envelope (birth order by id), the bound ConversationResolver supplies
     * the label, URL and transcript.
     *
     * Always present, never null, so a consumer can read `arc.siblings`
     * without a guard. An unhomed task yields the same shape with a null
     * `conversation_id` and empty lists — "no arc" and "an arc of one" stay
     * distinguishable by `conversation_id`, not by a missing key.
     *
     * The resolver is rescue-wrapped: a chat backend that is down must not
     * be the reason `dispatch:show` fails. Context is context.
     *
     * Public so `dispatch:show` can render the arc without shaping the whole
     * task twice (which would also run the resolver twice).
     *
     * @return array<string,mixed>
     */
    public static function arc(Task $task): array
    {
        $id = $task->conversation_id;

        $arc = [
            'conversation_id' => $id,
            'conversation_label' => null,
            'conversation_url' => null,
            'siblings' => [],
            'transcript' => [],
        ];

        if ($id === null) {
            return $arc;
        }

        $arc['siblings'] = $task->arc()->map(fn (Task $t) => [
            'code' => $t->code,
            'title' => $t->title,
            'status' => $t->status,
            'lane' => $t->lane,
            'assignee' => $t->assignee_user_id ? self::userRef($t->assignee) : null,
            'created_at' => optional($t->created_at)->toIso8601String(),
        ])->values()->all();

        try {
            $resolver = app(ConversationResolver::class);
            $arc['conversation_label'] = $resolver->label($id);
            $arc['conversation_url'] = $resolver->url($id);
            $arc['transcript'] = $resolver->transcript(
                $id,
                (int) config('dispatch.arc.transcript_limit', 20),
            );
        } catch (\Throwable) {
            // Leave the resolver-sourced keys at their defaults.
        }

        return $arc;
    }

    protected static function commentCount(Task $task): int
    {
        $attrs = $task->getAttributes();
        if (array_key_exists('comment_count', $attrs)) {
            return (int) $attrs['comment_count'];
        }

        if ($task->relationLoaded('comments')) {
            return $task->comments
                ->where('event_type', TaskComment::EVENT_COMMENT)
                ->count();
        }

        return (int) $task->comments()
            ->where('event_type', TaskComment::EVENT_COMMENT)
            ->count();
    }

    /**
     * Number of attachments on the task (W8-6) — the "did a human attach evidence
     * the JSON API can't hand me?" signal. Mirrors {@see commentCount()}'s 3-tier
     * preference to keep collections off the N+1 path (there is no event_type
     * filter — every attachment counts):
     *   1. an eager `->withCount('attachments as attachment_count')` (loaded by
     *      DispatchTaskService::eagerForRead on the next/queue query paths);
     *   2. the already-loaded `attachments` relation — counted in memory;
     *   3. a single COUNT query as a fallback (single-task summaries only).
     */
    protected static function attachmentCount(Task $task): int
    {
        $attrs = $task->getAttributes();
        if (array_key_exists('attachment_count', $attrs)) {
            return (int) $attrs['attachment_count'];
        }

        if ($task->relationLoaded('attachments')) {
            return $task->attachments->count();
        }

        return (int) $task->attachments()->count();
    }

    /**
     * Number of attachments on a single comment (W8-6). The claim/show paths eager
     * `comments.attachments`, so the loaded-relation branch wins and there is no
     * N+1; the count-query branch is the single-comment fallback only.
     */
    protected static function commentAttachmentCount(TaskComment $comment): int
    {
        if ($comment->relationLoaded('attachments')) {
            return $comment->attachments->count();
        }

        return (int) $comment->attachments()->count();
    }

    /**
     * A stable, host-agnostic reference for a related user: email if the app's
     * User exposes one, else its primary key, else null.
     */
    protected static function userRef(mixed $user): int|string|null
    {
        if ($user === null) {
            return null;
        }

        return $user->email ?? $user->getKey();
    }
}
