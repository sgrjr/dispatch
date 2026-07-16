<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Sgrjr\Dispatch\Models\Task;

/**
 * Trusted CLI surface: queries tasks directly (no DispatchGate::scopeVisible —
 * that scope is for user-facing web surfaces only). Shows the full comment
 * timeline including internal comments; there is no logged-in user to hide
 * them from in this context.
 */
class DispatchShow extends Command
{
    protected $signature = 'dispatch:show
        {code : The task code, e.g. TASK-042}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Show full detail for a task including its comment timeline.';

    public function handle(): int
    {
        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $task = $taskModel::query()
            ->with(['labels', 'submitter', 'assignee', 'comments.user'])
            ->where('code', $this->argument('code'))
            ->first();

        if (! $task) {
            $this->error("Task not found: {$this->argument('code')}");

            return self::FAILURE;
        }

        $comments = $task->comments;

        if ($this->option('json')) {
            $this->line(json_encode([
                'code' => $task->code,
                'title' => $task->title,
                'type' => $task->type,
                'priority' => $task->priority,
                'status' => $task->status,
                'is_public' => (bool) $task->is_public,
                'labels' => $task->labels->pluck('name')->all(),
                'submitter' => $task->submitter?->email,
                'assignee' => $task->assignee?->email,
                'description' => $task->description,
                'created_at' => optional($task->created_at)->toIso8601String(),
                'updated_at' => optional($task->updated_at)->toIso8601String(),
                'context' => $task->context,
                'comments' => $comments->values()->map(fn ($c) => [
                    'id' => $c->id,
                    'event_type' => $c->event_type,
                    'is_internal' => (bool) $c->is_internal,
                    'notified_submitter' => (bool) $c->notified_submitter,
                    'author' => $c->user?->email,
                    'body' => $c->body,
                    'meta' => $c->meta,
                    'created_at' => optional($c->created_at)->toIso8601String(),
                ])->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('<fg=cyan;options=bold>'.$task->code.'</> <fg=white>'.$task->title.'</>');
        $this->line('  priority: '.$task->priority.'  ·  type: '.$task->type.'  ·  status: '.$task->status.'  ·  public: '.($task->is_public ? 'yes' : 'no'));
        if ($task->labels->isNotEmpty()) {
            $this->line('  labels: '.$task->labels->pluck('name')->implode(', '));
        }
        if ($task->submitter) {
            $this->line('  submitter: '.$task->submitter->email);
        }
        if ($task->assignee) {
            $this->line('  assignee:  '.$task->assignee->email);
        }

        if ($task->description) {
            $this->newLine();
            $this->line('<fg=gray># Description</>');
            $this->line($task->description);
        }

        if (! empty($task->context)) {
            $ctx = $task->context;
            $this->newLine();
            $this->line('<fg=gray># Diagnostics</>');
            if (! empty($ctx['url'])) {
                $this->line('  url: '.$ctx['url']);
            }
            if (! empty($ctx['user_agent'])) {
                $this->line('  agent: '.$ctx['user_agent']);
            }
            $errs = $ctx['console_errors'] ?? [];
            $this->line('  console errors: '.count($errs));
            foreach (array_slice($errs, -5) as $e) {
                $this->line('    <fg=red>'.($e['type'] ?? 'error').'</>: '.($e['message'] ?? ''));
            }
        }

        $this->newLine();
        $this->line('<fg=gray># Thread ('.$comments->count().' entries)</>');
        if ($comments->isEmpty()) {
            $this->line('  <fg=gray>(no comments yet)</>');
        } else {
            foreach ($comments as $c) {
                $when = optional($c->created_at)->format('Y-m-d H:i') ?? '?';
                $who = $c->user?->email ?? ($c->isSystem() ? 'system' : 'anon');
                $tag = $c->is_internal ? '[INTERNAL]' : ($c->isSystem() ? '['.$c->event_type.']' : '');
                $this->line('  <fg=gray>'.$when.'</> <fg=yellow>'.$who.'</> '.$tag);
                foreach (preg_split('/\R/', trim((string) $c->body)) as $line) {
                    $this->line('    '.$line);
                }
            }
        }

        return self::SUCCESS;
    }
}
