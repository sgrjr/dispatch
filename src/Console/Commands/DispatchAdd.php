<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\TaskPresenter;

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
    use TalksToAgentApi;

    protected $signature = 'dispatch:add
        {title : The task title (short)}
        {--type= : bug | feature | chore | debt | verify (default: feature)}
        {--priority= : blocker | high | medium | low (default: medium)}
        {--description= : Full task body (markdown). Use heredoc or quoted multi-line.}
        {--label=* : Label name(s) to attach; auto-created if missing. Repeatable.}
        {--public : Mark visible outside staff (default: private)}
        {--key= : Idempotency key; returns the existing task with this key instead of creating a duplicate}
        {--remote : Act on the configured remote agent API instead of the local DB}
        {--json : Emit machine-readable JSON instead of human text}';

    protected $description = 'Create a new task via DispatchTaskService.';

    public function handle(DispatchTaskService $tasks): int
    {
        // Validate against the configured workflow vocab (not the const) so a
        // host's custom types/priorities are accepted, matching the agent API + UI.
        $type = $this->option('type');
        if ($type !== null && ! in_array($type, Task::types(), true)) {
            $this->error('--type must be one of: '.implode(', ', Task::types()));

            return self::FAILURE;
        }

        $priority = $this->option('priority');
        if ($priority !== null && ! in_array($priority, Task::priorities(), true)) {
            $this->error('--priority must be one of: '.implode(', ', Task::priorities()));

            return self::FAILURE;
        }

        $labelNames = array_values(array_filter(
            array_map('trim', (array) $this->option('label')),
            fn ($n) => $n !== ''
        ));

        $key = $this->option('key');

        if ($this->option('remote')) {
            $r = $this->agentPost('add', array_filter([
                'title' => $this->argument('title'),
                'type' => $type,
                'priority' => $priority,
                'description' => $this->option('description'),
                'labels' => $labelNames ?: null,
                'public' => $this->option('public') ? true : null,
                'key' => $key,
            ], fn ($v) => $v !== null));

            if ($r === null) {
                return self::FAILURE;
            }

            $this->line(json_encode($r['task'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

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

        $task = $key !== null
            ? $tasks->firstOrCreateByKey($key, $attributes, $labelNames)
            : $tasks->create($attributes, $labelNames);

        if ($this->option('json')) {
            $this->line(json_encode(TaskPresenter::toArray($task, false), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

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
