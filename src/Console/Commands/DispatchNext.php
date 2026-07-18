<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Support\TaskPresenter;

/**
 * Trusted CLI surface: queries tasks directly (no DispatchGate::scopeVisible —
 * that scope is for user-facing web surfaces only).
 */
class DispatchNext extends Command
{
    use TalksToAgentApi;

    protected $signature = 'dispatch:next
        {--status= : Restrict to a single status (default: open, in_progress, triage)}
        {--type= : Filter to a single type}
        {--label=* : Filter to tasks carrying any of these labels}
        {--remote : Act on the configured remote agent API instead of the local DB}
        {--json : Emit machine-readable JSON instead of human text}';

    protected $description = 'Show the single highest-priority actionable task to pick up next.';

    public function handle(): int
    {
        if ($this->option('remote')) {
            $r = $this->agentGet('next', array_filter([
                'status' => $this->option('status'),
                'type' => $this->option('type'),
                'label' => $this->option('label'),
            ]));

            if ($r === null) {
                return self::FAILURE;
            }

            $this->line(json_encode($r['task'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $type = $this->option('type');
        $labels = $this->option('label');

        // Actionable = open/in_progress first; triage only as a fallback group
        // when nothing is already open/in-flight. Within a group: priority,
        // then position, then id.
        $task = $taskModel::query()
            ->with('labels')
            ->withCount(['comments as comment_count' => fn ($q) => $q->where('event_type', TaskComment::EVENT_COMMENT)])
            ->when(
                $this->option('status'),
                fn ($q, $status) => $q->where('status', $status),
                fn ($q) => $q->whereIn('status', ['open', 'in_progress', 'triage'])
            )
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($labels, fn ($q) => $q->whereHas('labels', fn ($lq) => $lq->whereIn('name', (array) $labels)))
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
            $this->line(json_encode(TaskPresenter::toArray($task, false), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

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
