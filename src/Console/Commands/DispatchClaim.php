<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\TaskPresenter;

/**
 * Atomically claim the next actionable task: marks it in_progress + assigns it
 * (see DispatchTaskService::claim — C1). Local by default; `--remote` posts to
 * the agent API's `claim` verb instead, which runs the same service on the
 * authoritative (production) instance.
 */
class DispatchClaim extends Command
{
    use TalksToAgentApi;

    protected $signature = 'dispatch:claim
        {--type= : Restrict to this task type}
        {--label=* : Restrict to tasks carrying ALL of these labels. Repeatable.}
        {--assignee= : User id to assign the claimed task to}
        {--json : Emit machine-readable JSON instead of human text}
        {--remote : Claim via the remote agent API instead of the local DB}';

    protected $description = 'Atomically claim the next actionable task (marks it in_progress + assigns it).';

    public function handle(DispatchTaskService $tasks): int
    {
        $filters = array_filter([
            'type' => $this->option('type'),
            'label' => $this->option('label'),
        ]);

        if ($this->option('remote')) {
            return $this->claimRemote($filters);
        }

        $assignee = $this->option('assignee');

        $task = $tasks->claim(null, $filters, $assignee !== null ? (int) $assignee : null);

        if ($task === null) {
            if ($this->option('json')) {
                $this->line('null');
            } else {
                $this->line('Nothing to claim.');
            }

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            // Full shape on claim (description + context + comments) — same as the
            // remote `claim` verb — so a claiming agent sees the human's direction,
            // not just the summary fields. Mirrors AgentController::claim.
            $this->line(json_encode(
                TaskPresenter::toArray($task->load('labels', 'submitter', 'assignee', 'comments.user'), true),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        $this->info("Claimed {$task->code}");
        $this->line("  title: {$task->title}");
        $this->line("  type: {$task->type}  ·  priority: {$task->priority}  ·  status: {$task->status}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    protected function claimRemote(array $filters): int
    {
        $payload = array_filter([
            'type' => $filters['type'] ?? null,
            'label' => $filters['label'] ?? null,
        ]);

        $response = $this->agentPost('claim', $payload);
        if ($response === null) {
            return self::FAILURE;
        }

        $this->line(json_encode($response['task'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
