<?php

namespace Sgrjr\Dispatch\Support;

use Sgrjr\Dispatch\Models\Task;
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
            $data['comments'] = $task->comments->map(fn (TaskComment $c) => [
                'id' => $c->id,
                'event_type' => $c->event_type,
                'is_internal' => (bool) $c->is_internal,
                'author' => $c->user_id ? self::userRef($c->user) : null,
                'body' => $c->body,
                'meta' => $c->meta,
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
                'comments' => '[{id:int, event_type:string, is_internal:bool, author:string|int|null, body:string, meta:object|null, created_at:iso8601}]',
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
