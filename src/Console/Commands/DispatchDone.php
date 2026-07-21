<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Sgrjr\Dispatch\Console\Commands\Concerns\ResolvesTextInput;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\AgentMetrics;
use Sgrjr\Dispatch\Support\TaskPresenter;
use Sgrjr\Dispatch\Support\TranscriptLocator;

/**
 * Trusted CLI surface: queries tasks directly (no DispatchGate::scopeVisible).
 */
class DispatchDone extends Command
{
    use ResolvesTextInput;
    use TalksToAgentApi;

    protected $signature = 'dispatch:done
        {code : The task code, e.g. TASK-042}
        {--status=done : Target status — ANY configured workflow status, not just terminal (done | declined | verifying, backburner to park it out of the queue without declining, or e.g. open to greenlight a triaged task)}
        {--commit= : SHA of the code change}
        {--result= : JSON blob stored under context.result}
        {--result-file= : Read the JSON result from a file (or `-` for stdin) instead of inline --result — avoids the multi-line-quoting hazard}
        {--with-metrics : Compute agent-run metrics from the local transcript and fold them under context.result.metrics (so the staff "Agent run" panel renders). Status-agnostic — works with any --status (done OR verifying), since both mean "agent finished, about to release the token".}
        {--since= : ISO-8601 window start for --with-metrics — the claim time (grab it from the claimed comment created_at). Required to window a REMOTE task; a local task defaults to its own latest claim event.}
        {--transcript= : Explicit transcript path for --with-metrics (skips discovery)}
        {--session= : Claude Code session id for --with-metrics}
        {--project-dir= : Project dir whose transcripts to search for --with-metrics (default: base_path())}
        {--remote : Act on the configured remote agent API (the default while an agent session token is active)}
        {--local : Act on the local DB even while an agent session token is active (overrides sticky-remote)}
        {--json : Emit machine-readable JSON instead of human text}';

    protected $description = "Set a task's status and record the transition on its timeline.";

    public function handle(DispatchTaskService $tasks, TranscriptLocator $locator): int
    {
        // Validate against the configured workflow vocab (Task::statuses()),
        // not the const — so a host that adds a custom status via
        // dispatch.workflow.statuses can use it here, matching the agent API + UI.
        $status = $this->option('status');
        if (! in_array($status, Task::statuses(), true)) {
            $this->error('--status must be one of: '.implode(', ', Task::statuses()));

            return self::FAILURE;
        }

        $commit = $this->option('commit');

        // Result JSON comes from --result (inline) OR --result-file (a path, or
        // `-` for stdin) — the file/stdin path is the escape hatch for a large
        // result blob that would be a multi-line-quoting hazard on one command
        // line (mirrors the commit-message guidance).
        [$resultRaw, $err] = $this->resolveInlineOrFile(
            $this->option('result'),
            $this->option('result-file'),
            '--result',
            '--result-file',
        );
        if ($err !== null) {
            $this->error($err);

            return self::FAILURE;
        }

        $result = null;
        if ($resultRaw !== null) {
            $decoded = json_decode($resultRaw, true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                $this->error('Result must be a valid JSON object (--result / --result-file): '.json_last_error_msg());

                return self::FAILURE;
            }
            $result = $decoded;
        }

        // --with-metrics: compute agent-run metrics from the local transcript and
        // nest them under the result's `metrics` key. This is the remote-friendly
        // route to context.result.metrics (the panel's key-path): --stamp only
        // touches the local DB, so a remote task can't use it — folding into the
        // closing result does. Status-agnostic on purpose: `done` OR `verifying`
        // both mean "agent finished, about to release the token — stamp now".
        if ($this->option('with-metrics')) {
            [$metrics, $metricsErr] = $this->collectMetrics($locator);
            if ($metricsErr !== null) {
                $this->error($metricsErr);

                return self::FAILURE;
            }
            $result = $result ?? [];
            $result['metrics'] = $metrics;
        }

        if ($this->targetsRemote()) {
            $r = $this->agentPost('done', array_filter([
                'code' => $this->argument('code'),
                'status' => $status,
                'commit' => $commit,
                'result' => $result,
            ], fn ($v) => $v !== null));

            if ($r === null) {
                return self::FAILURE;
            }

            $this->line(json_encode($r['task'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // Point-of-need reminder replacing memorized doctrine: per-task
            // metrics ride EVERY done (the claim bridge pre-fills the flags);
            // session totals are recorded automatically at session:end.
            if (! $this->option('with-metrics')) {
                $this->sideNote('tip: no metrics on this close — metrics ride EVERY done: add --with-metrics --since=<claimed_at from claim> so this task shows its own run cost. (Session totals still land automatically at dispatch:session:end.)');
            }

            return self::SUCCESS;
        }

        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $task = $taskModel::query()->where('code', $this->argument('code'))->first();
        if (! $task) {
            $this->error("Task not found: {$this->argument('code')}");

            return self::FAILURE;
        }

        $previous = $task->status;
        if ($previous === $status) {
            $this->warn("Task {$task->code} is already in status `{$status}`.");
        }

        $task->status = $status;
        $task->save();

        $task->recordEvent(
            TaskComment::EVENT_STATUS_CHANGE,
            Auth::id(),
            ['from' => $previous, 'to' => $status],
            "Status changed from `{$previous}` to `{$status}`."
        );

        if ($commit !== null || $result !== null) {
            $tasks->recordResult($task, $result ?? [], $commit);
        }

        if ($this->option('json')) {
            $this->line(json_encode(TaskPresenter::toArray($task->fresh(), false), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("{$task->code} -> {$status}.");

        return self::SUCCESS;
    }

    /**
     * Compute the metrics object for --with-metrics. Windows to --since when
     * given; for a LOCAL task with no --since, defaults to the task's latest
     * claim event (mirroring dispatch:metrics). A remote task isn't in this DB,
     * so --since is the only way to window it — without it, metrics fall back to
     * the whole transcript (unbounded) with a warning rather than failing.
     *
     * @return array{0:array<string,mixed>|null,1:string|null}  [metrics, error]
     */
    private function collectMetrics(TranscriptLocator $locator): array
    {
        $since = null;
        if ($this->option('since')) {
            try {
                $since = Carbon::parse($this->option('since'));
            } catch (\Throwable $e) {
                return [null, 'Invalid --since: '.$e->getMessage()];
            }
        } elseif (! $this->targetsRemote()) {
            /** @var class-string<Task> $taskModel */
            $taskModel = config('dispatch.models.task');
            $task = $taskModel::query()->where('code', $this->argument('code'))->first();
            $since = $task?->comments()
                ->where('event_type', TaskComment::EVENT_CLAIMED)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first()?->created_at;
        }

        $basis = $this->option('since') ? 'since-option' : ($since ? 'claimed_at' : 'unbounded');

        $metrics = AgentMetrics::collect($locator, $since, null, $basis, [
            'transcript' => $this->option('transcript') ?: null,
            'session' => $this->option('session') ?: null,
            'projectDir' => $this->option('project-dir') ?: null,
        ]);

        if ($metrics['transcript']['main'] === null) {
            $this->warn('No transcript located for --with-metrics — token metrics are zero. Pass --since=<claim time> and/or --transcript=, or install the SessionStart hook.');
        }

        return [$metrics, null];
    }
}
