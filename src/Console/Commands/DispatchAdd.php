<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/**
 * Create a new task. Creation ALWAYS routes through DispatchTaskService (never
 * Task::create directly) so code minting, submitter resolution, tenant
 * stamping, and label attachment happen the one true way.
 *
 * The CLI is a trusted developer/agent context (no logged-in user) — the
 * service falls back to its configured default submitter when none is given.
 */
class DispatchAdd extends Command
{
    protected $signature = 'dispatch:add
        {title : The task title (short)}
        {--type= : bug | feature | chore | debt | verify (default: feature)}
        {--priority= : blocker | high | medium | low (default: medium)}
        {--description= : Full task body (markdown). Use heredoc or quoted multi-line.}
        {--label=* : Label name(s) to attach; auto-created if missing. Repeatable.}
        {--public : Mark visible outside staff (default: private)}
        {--json : Emit machine-readable JSON instead of human text}';

    protected $description = 'Create a new task via DispatchTaskService.';

    public function handle(DispatchTaskService $tasks): int
    {
        $type = $this->option('type');
        if ($type !== null && ! in_array($type, Task::TYPES, true)) {
            $this->error('--type must be one of: '.implode(', ', Task::TYPES));

            return self::FAILURE;
        }

        $priority = $this->option('priority');
        if ($priority !== null && ! in_array($priority, Task::PRIORITIES, true)) {
            $this->error('--priority must be one of: '.implode(', ', Task::PRIORITIES));

            return self::FAILURE;
        }

        $labelNames = array_values(array_filter(
            array_map('trim', (array) $this->option('label')),
            fn ($n) => $n !== ''
        ));

        $attributes = ['title' => $this->argument('title')];
        if ($type !== null) {
            $attributes['type'] = $type;
        }
        if ($priority !== null) {
            $attributes['priority'] = $priority;
        }
        if ($description = $this->option('description')) {
            $attributes['description'] = $description;
        }
        $attributes['is_public'] = (bool) $this->option('public');

        $task = $tasks->create($attributes, $labelNames);

        if ($this->option('json')) {
            $this->line(json_encode([
                'code' => $task->code,
                'title' => $task->title,
                'type' => $task->type,
                'priority' => $task->priority,
                'status' => $task->status,
                'is_public' => (bool) $task->is_public,
                'labels' => $labelNames,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Created {$task->code}");
        $this->line("  title: {$task->title}");
        $this->line("  type: {$task->type}  ·  priority: {$task->priority}  ·  status: {$task->status}  ·  public: ".($task->is_public ? 'yes' : 'no'));
        if ($labelNames) {
            $this->line('  labels: '.implode(', ', $labelNames));
        }

        return self::SUCCESS;
    }
}
