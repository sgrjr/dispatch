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
 * Two views: the summary (list/next/queue/add) and the full detail (show), the
 * latter adding description/context/comments.
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
