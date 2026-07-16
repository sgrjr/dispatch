<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Sgrjr\Dispatch\Models\Task;

/**
 * Trusted CLI surface: queries tasks directly (no DispatchGate::scopeVisible —
 * that scope is for user-facing web surfaces only).
 */
class DispatchNext extends Command
{
    protected $signature = 'dispatch:next {--json : Emit machine-readable JSON instead of human text}';

    protected $description = 'Show the single highest-priority actionable task to pick up next.';

    public function handle(): int
    {
        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        // Actionable = open/in_progress first; triage only as a fallback group
        // when nothing is already open/in-flight. Within a group: priority,
        // then position, then id.
        $task = $taskModel::query()
            ->with('labels')
            ->whereIn('status', ['open', 'in_progress', 'triage'])
            ->orderByRaw("CASE WHEN status IN ('open', 'in_progress') THEN 0 ELSE 1 END")
            ->orderByRaw("CASE priority WHEN 'blocker' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 99 END")
            ->orderBy('position')
            ->orderBy('id')
            ->first();

        if (! $task) {
            if ($this->option('json')) {
                $this->line('{}');
            } else {
                $this->line('No actionable tasks. Inbox zero — nice.');
            }

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'code' => $task->code,
                'title' => $task->title,
                'type' => $task->type,
                'priority' => $task->priority,
                'status' => $task->status,
                'is_public' => (bool) $task->is_public,
                'labels' => $task->labels->pluck('name')->all(),
                'description' => $task->description,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('<fg=cyan;options=bold>'.$task->code.'</> <fg=white>'.$task->title.'</>');
        $this->line('  priority: '.$task->priority.'  ·  type: '.$task->type.'  ·  status: '.$task->status);
        if ($task->labels->isNotEmpty()) {
            $this->line('  labels: '.$task->labels->pluck('name')->implode(', '));
        }
        if ($task->description) {
            $this->newLine();
            $this->line($task->description);
        }
        $this->newLine();
        $this->line('<fg=gray>Next steps:</>');
        $this->line('  <fg=gray>php artisan dispatch:show '.$task->code.'</>      # full detail + thread');
        $this->line('  <fg=gray>php artisan dispatch:note '.$task->code.' "..."</>  # leave a finding');
        $this->line('  <fg=gray>php artisan dispatch:done '.$task->code.'</>      # mark complete');

        return self::SUCCESS;
    }
}
