<?php

namespace Sgrjr\Dispatch\Support;

use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskAttachment;
use Sgrjr\Dispatch\Models\TaskComment;

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
            'created_at' => optional($task->created_at)->toIso8601String(),
            'updated_at' => optional($task->updated_at)->toIso8601String(),
        ];

        if ($full) {
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
                'created_at' => 'iso8601',
                'updated_at' => 'iso8601',
            ],
            'full_adds' => [
                'description' => 'string|null',
                'context' => 'object|null',
                'attachments' => '[{filename, mime, size_bytes, is_image:bool}] — metadata SIGNALS only: no fetch URL, binaries do not travel the agent API',
                'comments' => '[{id:int, event_type:string, is_internal:bool, author:string|int|null, body:string, meta:object|null, attachment_count:int, created_at:iso8601}]',
            ],
            // Close conventions (§17C / done verb): what a completed task stores
            // and the recommended way to record HOW it resolved, so the board can
            // measure pre-resolved briefs (built vs. already-implemented/obsolete).
            'done' => [
                'commit' => 'sha stored at context.result.commit',
                'result' => 'object stored at context.result (a new close replaces it; metrics accumulate)',
                'resolution' => 'recommended result key on close: result.resolution = built | already-implemented | obsolete (free-form allowed) — records HOW the task resolved, so the board can measure pre-resolved briefs',
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
                    'description' => 'string|null',
                    'public' => 'bool (optional)',
                    'labels' => 'string[] (ATTACHED additively — never replaces existing labels)',
                    'commit' => 'string|null (stored under context.result.commit)',
                    'result' => 'object|null (stored under context.result)',
                    'comments' => '[{body:string, internal:bool}]',
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
                TaskComment::EVENT_PUBLIC_TOGGLE,
                TaskComment::EVENT_PROMOTED,
                TaskComment::EVENT_EXCEPTION,
                TaskComment::EVENT_DESCRIPTION_EDITED,
                TaskComment::EVENT_MERGED,
                TaskComment::EVENT_CLAIMED,
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
