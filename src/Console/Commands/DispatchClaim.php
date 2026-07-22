<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\TaskPresenter;

/**
 * Atomically claim an actionable task: marks it in_progress + assigns it (see
 * DispatchTaskService::claim — C1). With no argument it claims the next
 * candidate (dispatch:next order); pass a {code} to claim ONE specific task by
 * code, provided it's still unclaimed (open/triage). Local by default;
 * `--remote` posts to the agent API's `claim` verb instead, which runs the same
 * service on the authoritative (production) instance.
 */
class DispatchClaim extends Command
{
    use TalksToAgentApi;

    protected $signature = 'dispatch:claim
        {code? : Claim THIS task by code (e.g. TASK-042), if still unclaimed; omit to claim the next candidate}
        {--type= : Restrict to this task type (ignored when a code is given)}
        {--label=* : Restrict to tasks carrying ALL of these labels. Repeatable. (ignored when a code is given)}
        {--assignee= : User id to assign the claimed task to}
        {--no-focus : Ignore any active Focus steering for this claim}
        {--json : Emit machine-readable JSON instead of human text}
        {--remote : Claim via the remote agent API (the default while an agent session token is active)}
        {--local : Claim on the local DB even while an agent session token is active (overrides sticky-remote)}';

    protected $description = 'Atomically claim an actionable task — the next candidate, or a specific one by code (marks it in_progress + assigns it).';

    public function handle(DispatchTaskService $tasks): int
    {
        $filters = array_filter([
            'type' => $this->option('type'),
            'label' => $this->option('label'),
        ]);

        $code = $this->argument('code');

        if ($this->targetsRemote()) {
            return $this->claimRemote($filters, $code);
        }

        $assignee = $this->option('assignee');

        $task = $tasks->claim(null, $filters, $assignee !== null ? (int) $assignee : null, $code, ! $this->option('no-focus'));

        if ($task === null) {
            return $this->reportNothingClaimed($code);
        }

        $task->loadMissing('labels', 'submitter', 'assignee', 'comments.user');
        $claimedAt = optional(
            $task->comments->where('event_type', TaskComment::EVENT_CLAIMED)->max('created_at')
        )->toIso8601String();

        if ($this->option('json')) {
            // Full shape on claim (description + context + comments) — same as the
            // remote `claim` verb — so a claiming agent sees the human's direction,
            // not just the summary fields. Mirrors AgentController::claim.
            $this->line(json_encode(
                TaskPresenter::toArray($task, true),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            $this->emitClaimFollowUp($task->code, $claimedAt);

            return self::SUCCESS;
        }

        $this->info("Claimed {$task->code}");
        $this->line("  title: {$task->title}");
        $this->line("  type: {$task->type}  ·  priority: {$task->priority}  ·  status: {$task->status}");

        $this->emitClaimFollowUp($task->code, $claimedAt);

        return self::SUCCESS;
    }

    /**
     * The claim → close bridge, printed at the exact moment the values exist so
     * closing needs zero recall (and zero doc lookup): the code and the claim
     * timestamp (--since for --with-metrics) are pre-filled. Emitted via the
     * STDERR side channel so a piped --json stdout stays contract-pure.
     */
    protected function emitClaimFollowUp(?string $code, ?string $claimedAt): void
    {
        if ($code === null) {
            return;
        }

        $since = $claimedAt !== null ? ' --with-metrics --since="'.$claimedAt.'"' : '';

        $this->sideNote("claimed_at: ".($claimedAt ?? 'unknown'));
        $this->sideNote("→ when finished: php artisan dispatch:done {$code} --status=done|verifying --result-file=result.json{$since}");
        $this->sideNote('   (done = you verified it yourself · verifying = a human still has a named check. Then: dispatch:session:end)');
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    protected function claimRemote(array $filters, ?string $code): int
    {
        $payload = array_filter([
            'type' => $filters['type'] ?? null,
            'label' => $filters['label'] ?? null,
            'code' => $code,
            'no_focus' => $this->option('no-focus') ? 1 : null,
        ]);

        $response = $this->agentPost('claim', $payload);
        if ($response === null) {
            return self::FAILURE;
        }

        $task = $response['task'] ?? null;

        $this->line(json_encode($task, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($task !== null) {
            // claimed_at rides the claim envelope top-level (beside `task`) for
            // zero-parse reuse; fall back to nothing rather than digging.
            $this->emitClaimFollowUp($task['code'] ?? null, $response['claimed_at'] ?? null);
        }

        // A NAMED code that came back unclaimed is a failure — mirror the local
        // path so `dispatch:claim TASK-003 --remote` is scriptable the same way.
        if ($code !== null && $task === null) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Nothing got claimed. In next-candidate mode an empty queue is a normal,
     * successful no-op. But when the caller NAMED a task by code and it wasn't
     * claimable, that's a failure — exit non-zero and say why (not found vs.
     * already past the open/triage window a claim needs), so a script that asked
     * for a specific task can tell "queue empty" from "I didn't get TASK-003".
     */
    protected function reportNothingClaimed(?string $code): int
    {
        if ($code === null) {
            $this->line($this->option('json') ? 'null' : 'Nothing to claim.');

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line('null');
        } else {
            /** @var class-string<\Sgrjr\Dispatch\Models\Task> $taskModel */
            $taskModel = config('dispatch.models.task');
            $existing = $taskModel::query()->where('code', $code)->first();

            if ($existing === null) {
                $this->error("No task {$code}.");
            } else {
                $this->error("{$code} is not claimable — its status is {$existing->status} (only open/triage tasks can be claimed).");
            }
        }

        return self::FAILURE;
    }
}
