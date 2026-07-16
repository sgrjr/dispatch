<?php

namespace Sgrjr\Dispatch\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Models\Label;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/**
 * Package<->package JSON-LD sync (e.g. `dispatch:pull` / `dispatch:push`
 * talking to another Dispatch install on the same schema). Both endpoints are
 * gated by {@see DispatchGate::canSeeAll()} — this is a super-user-only,
 * whole-dataset surface, not a per-user API.
 *
 * Ported from rupkeep's Api\DispatchController, stripped of the
 * organization/is_super coupling (now DispatchGate::canSeeAll) and the
 * sent_to_customer column (renamed notified_submitter in this package).
 */
class SyncController extends Controller
{
    /**
     * Export the full task set (+ labels) as a JSON-LD document.
     */
    public function snapshot(Request $request): JsonResponse
    {
        abort_unless(app(DispatchGate::class)->canSeeAll(Auth::user()), 403);

        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');
        /** @var class-string<Label> $labelModel */
        $labelModel = config('dispatch.models.label');

        $tasks = $taskModel::query()
            ->with(['labels', 'comments.user', 'submitter', 'assignee'])
            ->orderBy('code')
            ->get()
            ->map(fn (Task $t) => $this->taskToJsonLd($t))
            ->all();

        $labels = $labelModel::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Label $l) => [
                '@type' => 'Label',
                'name' => $l->name,
                'color' => $l->color,
                'description' => $l->description,
            ])
            ->all();

        return response()->json([
            '@context' => [
                '@vocab' => config('dispatch.jsonld.vocab'),
                'code' => '@id',
                'labels' => ['@container' => '@set'],
                'comments' => ['@container' => '@list'],
                'createdAt' => ['@type' => 'http://www.w3.org/2001/XMLSchema#dateTime'],
                'updatedAt' => ['@type' => 'http://www.w3.org/2001/XMLSchema#dateTime'],
            ],
            '@type' => 'TaskCollection',
            'schemaVersion' => '1.0',
            'exportedAt' => now()->toIso8601String(),
            'exportedBy' => 'api:dispatch.sync.snapshot',
            'exportedFor' => Auth::user()?->email,
            'tasks' => $tasks,
            'labels' => $labels,
        ]);
    }

    /**
     * Upsert tasks (by `code`), their labels, and their comments from a posted
     * JSON-LD document of the same shape {@see snapshot()} produces.
     *
     * Upsert semantics:
     *  - Tasks are matched by `code` (the human-facing, cross-install stable
     *    identifier). New codes go through DispatchTaskService::create() (the
     *    one place code-minting/tenant-stamping happens); an explicit code is
     *    honored as-is. Existing tasks are updated in place via fill()/save()
     *    — this is a plain attribute update, not a creation, so it does not go
     *    through the service.
     *  - Labels replace-all via sync() to match the incoming document (the
     *    document is authoritative for label membership on both create and
     *    update).
     *  - Comments MERGE rather than replace: any (event_type, body) pair not
     *    already present is appended; existing comments are left untouched.
     *    This preserves comments made locally since the last sync (e.g. a
     *    submitter portal reply) that a destructive replace would erase.
     */
    public function apply(Request $request): JsonResponse
    {
        abort_unless(app(DispatchGate::class)->canSeeAll(Auth::user()), 403);

        $doc = $request->json()->all();
        if (! is_array($doc) || ! isset($doc['tasks']) || ! is_array($doc['tasks'])) {
            return response()->json(['error' => 'Invalid JSON-LD: missing tasks array.'], 422);
        }

        $summary = [
            'labels_created' => 0,
            'labels_updated' => 0,
            'tasks_created' => 0,
            'tasks_updated' => 0,
            'comments_added' => 0,
        ];

        DB::transaction(function () use ($doc, &$summary) {
            /** @var class-string<Label> $labelModel */
            $labelModel = config('dispatch.models.label');
            /** @var class-string<Task> $taskModel */
            $taskModel = config('dispatch.models.task');
            /** @var class-string $userModel */
            $userModel = config('dispatch.models.user');

            foreach (($doc['labels'] ?? []) as $l) {
                $name = $l['name'] ?? null;
                if (! $name) {
                    continue;
                }

                $existing = $labelModel::query()->where('name', $name)->first();
                if ($existing) {
                    $existing->update([
                        'color' => $l['color'] ?? $existing->color,
                        'description' => $l['description'] ?? $existing->description,
                    ]);
                    $summary['labels_updated']++;
                } else {
                    $labelModel::query()->create([
                        'name' => $name,
                        'color' => $l['color'] ?? null,
                        'description' => $l['description'] ?? null,
                    ]);
                    $summary['labels_created']++;
                }
            }

            $labelIdsByName = $labelModel::query()->pluck('id', 'name')->all();

            foreach ($doc['tasks'] as $t) {
                $code = $t['code'] ?? null;
                if (! $code) {
                    continue;
                }

                $payload = [
                    'title' => $t['title'] ?? '(untitled)',
                    'description' => $t['description'] ?? null,
                    'type' => in_array($t['type'] ?? null, Task::TYPES, true) ? $t['type'] : 'feature',
                    'priority' => in_array($t['priority'] ?? null, Task::PRIORITIES, true) ? $t['priority'] : 'medium',
                    'status' => in_array($t['status'] ?? null, Task::STATUSES, true) ? $t['status'] : 'triage',
                    'is_public' => (bool) ($t['isPublic'] ?? false),
                ];

                $submitterId = ! empty($t['submitter'])
                    ? $userModel::query()->where('email', $t['submitter'])->value('id')
                    : null;
                $assigneeId = ! empty($t['assignee'])
                    ? $userModel::query()->where('email', $t['assignee'])->value('id')
                    : null;

                $existing = $taskModel::query()->where('code', $code)->first();

                if ($existing) {
                    $existing->fill($payload);
                    if ($submitterId !== null) {
                        $existing->submitter_user_id = $submitterId;
                    }
                    if ($assigneeId !== null) {
                        $existing->assignee_user_id = $assigneeId;
                    }
                    $existing->save();
                    $task = $existing;
                    $summary['tasks_updated']++;
                } else {
                    $payload['code'] = $code;
                    if ($submitterId !== null) {
                        $payload['submitter_user_id'] = $submitterId;
                    }
                    if ($assigneeId !== null) {
                        $payload['assignee_user_id'] = $assigneeId;
                    }
                    $task = app(DispatchTaskService::class)->create($payload);
                    $summary['tasks_created']++;
                }

                $labelIds = [];
                foreach (($t['labels'] ?? []) as $name) {
                    if (isset($labelIdsByName[$name])) {
                        $labelIds[] = $labelIdsByName[$name];
                    }
                }
                $task->labels()->sync($labelIds);

                if (! empty($t['comments']) && is_array($t['comments'])) {
                    $existingKeys = $task->comments()
                        ->get(['body', 'event_type'])
                        ->map(fn ($c) => $c->event_type.'|'.$c->body)
                        ->flip();

                    foreach ($t['comments'] as $c) {
                        $body = $c['body'] ?? '';
                        $eventType = $c['eventType'] ?? TaskComment::EVENT_COMMENT;

                        if (isset($existingKeys[$eventType.'|'.$body])) {
                            continue;
                        }

                        $comment = $task->comments()->make([
                            'body' => $body,
                            'is_internal' => (bool) ($c['isInternal'] ?? false),
                            'notified_submitter' => (bool) ($c['notifiedSubmitter'] ?? false),
                            'event_type' => $eventType,
                            'meta' => $c['meta'] ?? null,
                        ]);

                        if (! empty($c['author'])) {
                            $comment->user_id = $userModel::query()->where('email', $c['author'])->value('id');
                        }

                        if (! empty($c['createdAt'])) {
                            try {
                                $comment->created_at = \Carbon\Carbon::parse($c['createdAt']);
                            } catch (\Throwable) {
                                // Leave created_at at its default (now()) if unparsable.
                            }
                        }

                        $comment->save();
                        $summary['comments_added']++;
                    }
                }
            }
        });

        return response()->json([
            'status' => 'applied',
            'summary' => $summary,
            'appliedAt' => now()->toIso8601String(),
        ]);
    }

    protected function taskToJsonLd(Task $t): array
    {
        return [
            '@type' => 'Task',
            'code' => $t->code,
            'title' => $t->title,
            'description' => $t->description,
            'type' => $t->type,
            'priority' => $t->priority,
            'status' => $t->status,
            'isPublic' => (bool) $t->is_public,
            'labels' => $t->labels->pluck('name')->all(),
            'submitter' => $t->submitter?->email,
            'assignee' => $t->assignee?->email,
            'createdAt' => optional($t->created_at)->toIso8601String(),
            'updatedAt' => optional($t->updated_at)->toIso8601String(),
            'comments' => $t->comments->map(fn (TaskComment $c) => [
                '@type' => 'Comment',
                'body' => $c->body,
                'author' => $c->user?->email,
                'isInternal' => (bool) $c->is_internal,
                'notifiedSubmitter' => (bool) $c->notified_submitter,
                'eventType' => $c->event_type,
                'meta' => $c->meta,
                'createdAt' => optional($c->created_at)->toIso8601String(),
            ])->all(),
        ];
    }
}
