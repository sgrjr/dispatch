<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Sgrjr\Dispatch\Models\Label;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Throwable;

/**
 * Reads a JSON-LD snapshot (as written by dispatch:export) and upserts tasks
 * by `code`, plus their labels/comments. New tasks are minted through
 * DispatchTaskService (which honors an explicit `code` in the attributes
 * rather than reminting one — see Task::createWithCode()); existing tasks are
 * updated in place, which is not "creation" so it bypasses the service.
 */
class DispatchImport extends Command
{
    protected $signature = 'dispatch:import
        {path : JSON-LD file to import, resolved relative to the app base path}
        {--dry-run : Show what would change without writing}';

    protected $description = 'Import a JSON-LD snapshot, upserting tasks by code (and their comments/labels).';

    public function handle(DispatchTaskService $tasks): int
    {
        $path = base_path($this->argument('path'));
        $dryRun = (bool) $this->option('dry-run');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $doc = json_decode((string) file_get_contents($path), true);
        if (! is_array($doc) || ! isset($doc['tasks']) || ! is_array($doc['tasks'])) {
            $this->error('Invalid JSON-LD: missing tasks array.');

            return self::FAILURE;
        }

        $tasksData = $doc['tasks'];
        $labelsData = $doc['labels'] ?? [];

        $this->line('Loaded '.count($tasksData).' tasks, '.count($labelsData).' labels from '.$this->argument('path'));

        $summary = [
            'labels_created' => 0, 'labels_updated' => 0,
            'tasks_created' => 0, 'tasks_updated' => 0, 'tasks_skipped' => 0,
            'statuses_preserved' => 0, 'comments_added' => 0,
        ];

        $run = function () use ($tasks, $tasksData, $labelsData, &$summary) {
            /** @var class-string<Label> $labelModel */
            $labelModel = config('dispatch.models.label');
            /** @var class-string<Task> $taskModel */
            $taskModel = config('dispatch.models.task');
            /** @var class-string $userModel */
            $userModel = config('dispatch.models.user');

            foreach ($labelsData as $l) {
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

            $userLookup = function (?string $email) use ($userModel) {
                if (! $email || ! is_string($userModel) || ! class_exists($userModel)) {
                    return null;
                }

                return $userModel::query()->where('email', $email)->value('id');
            };

            foreach ($tasksData as $t) {
                $code = $t['code'] ?? null;
                if (! $code) {
                    $summary['tasks_skipped']++;
                    continue;
                }

                $payload = [
                    'title' => $t['title'] ?? '(untitled)',
                    'description' => $t['description'] ?? null,
                    'type' => in_array($t['type'] ?? null, Task::TYPES, true) ? $t['type'] : 'feature',
                    'priority' => in_array($t['priority'] ?? null, Task::PRIORITIES, true) ? $t['priority'] : 'medium',
                    'status' => in_array($t['status'] ?? null, Task::STATUSES, true) ? $t['status'] : 'triage',
                    'is_public' => (bool) ($t['isPublic'] ?? false),
                    'position' => (int) ($t['position'] ?? 0),
                    'exception_signature' => $t['exceptionSignature'] ?? null,
                ];

                /** @var Task|null $task */
                $task = $taskModel::query()->where('code', $code)->first();

                if ($task) {
                    // A local status transition newer than the snapshot means
                    // unpushed work (dispatch:done etc.) — keep the local status
                    // instead of silently reverting it to what the snapshot last
                    // saw.
                    if ($payload['status'] !== $task->status && $this->localStatusIsNewer($task, $t)) {
                        $this->warn("  {$code}: keeping local status `{$task->status}` (unpushed transition is newer than snapshot; snapshot says `{$payload['status']}`)");
                        $payload['status'] = $task->status;
                        $summary['statuses_preserved']++;
                    }

                    $task->fill($payload);
                    if (isset($t['updatedAt'])) {
                        try {
                            $task->updated_at = Carbon::parse($t['updatedAt']);
                        } catch (Throwable) {
                        }
                    }
                    $task->save();
                    $summary['tasks_updated']++;
                } else {
                    $submitterId = $userLookup($t['submitter'] ?? null);
                    $createAttrs = ['code' => $code] + $payload;
                    if ($submitterId) {
                        $createAttrs['submitter_user_id'] = $submitterId;
                    }

                    $task = $tasks->create($createAttrs, []);

                    if (isset($t['createdAt'])) {
                        try {
                            $task->created_at = Carbon::parse($t['createdAt']);
                            $task->save();
                        } catch (Throwable) {
                        }
                    }
                    if (isset($t['updatedAt'])) {
                        try {
                            $task->updated_at = Carbon::parse($t['updatedAt']);
                            $task->save();
                        } catch (Throwable) {
                        }
                    }
                    $summary['tasks_created']++;
                }

                $assigneeId = $userLookup($t['assignee'] ?? null);
                if ($assigneeId && (int) $task->assignee_user_id !== (int) $assigneeId) {
                    $task->assignee_user_id = $assigneeId;
                    $task->save();
                }

                $labelIds = [];
                foreach (($t['labels'] ?? []) as $name) {
                    if (isset($labelIdsByName[$name])) {
                        $labelIds[] = $labelIdsByName[$name];
                    }
                }
                $task->labels()->sync($labelIds);

                // Merge comments: add snapshot comments we don't have yet, keep
                // local-only ones (a destructive replace-all would erase
                // unpushed notes and reset every author/timestamp).
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
                            $comment->user_id = $userLookup($c['author']);
                        }

                        if (! empty($c['createdAt'])) {
                            try {
                                $comment->created_at = Carbon::parse($c['createdAt']);
                            } catch (Throwable) {
                            }
                        }

                        $comment->save();
                        $summary['comments_added']++;
                    }
                }
            }
        };

        if ($dryRun) {
            DB::beginTransaction();
            try {
                $run();
            } finally {
                DB::rollBack();
            }
            $this->warn('Dry run — no changes persisted.');
        } else {
            DB::transaction($run);
        }

        $this->info('Import complete.');
        foreach ($summary as $k => $v) {
            $this->line("  {$k}: {$v}");
        }

        return self::SUCCESS;
    }

    /**
     * True when the local task carries a status transition the snapshot can't
     * know about — i.e. its latest status_change comment postdates the
     * snapshot's updatedAt. No updatedAt in the snapshot means we can't tell,
     * so the snapshot wins.
     */
    private function localStatusIsNewer(Task $task, array $snapshotTask): bool
    {
        if (empty($snapshotTask['updatedAt'])) {
            return false;
        }

        try {
            $snapshotUpdatedAt = Carbon::parse($snapshotTask['updatedAt']);
        } catch (Throwable) {
            return false;
        }

        $lastTransition = $task->comments()
            ->where('event_type', TaskComment::EVENT_STATUS_CHANGE)
            ->latest('created_at')
            ->first();

        return $lastTransition && $lastTransition->created_at->gt($snapshotUpdatedAt);
    }
}
