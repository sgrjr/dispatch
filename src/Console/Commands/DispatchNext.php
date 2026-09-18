<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;
use Sgrjr\Dispatch\Services\DispatchTaskService;
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
        {--topic= : Filter to tasks whose topic matches "<type>[:<id>]" (id omitted matches any id of that type)}
        {--origin= : Filter to tasks whose origin matches "<type>[:<id>]"}
        {--conversation= : Filter to tasks in this conversation id}
        {--topic-account= : Filter to tasks whose topic_account_key equals this value}
        {--no-focus : Ignore any active Focus steering for this call}
        {--remote : Act on the configured remote agent API (the default while an agent session token is active)}
        {--local : Act on the local DB even while an agent session token is active (overrides sticky-remote)}
        {--json : Emit machine-readable JSON instead of human text}';

    protected $description = 'Show the single highest-priority actionable task to pick up next.';

    public function handle(DispatchTaskService $tasks): int
    {
        if ($this->targetsRemote()) {
            $r = $this->agentGet('next', array_filter([
                'status' => $this->option('status'),
                'type' => $this->option('type'),
                'label' => $this->option('label'),
                'no_focus' => $this->option('no-focus') ? 1 : null,
                // Anchor wire strings travel RAW — the server re-parses them
                // with the same Anchor::parse().
                'topic' => $this->option('topic'),
                'origin' => $this->option('origin'),
                'conversation' => $this->option('conversation'),
                'topic_account' => $this->option('topic-account'),
            ]));

            if ($r === null) {
                return self::FAILURE;
            }

            $this->line(json_encode($r['task'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        // Actionable = open/in_progress first; triage only as a fallback group
        // when nothing is already open/in-flight. Within a group: priority,
        // then position, then id. Focus-steered unless --no-focus. Ordering,
        // eager-loading and steering all live in the service.
        $filters = array_filter([
            'type' => $this->option('type'),
            'label' => $this->option('label'),
            'topic' => $this->option('topic'),
            'origin' => $this->option('origin'),
            'conversation' => $this->option('conversation'),
            'topic_account' => $this->option('topic-account'),
        ]);

        try {
            $task = $tasks->nextCandidate($filters, $this->option('status'), ! $this->option('no-focus'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

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
