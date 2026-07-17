<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Sgrjr\Dispatch\Console\Commands\Concerns\ResolvesTextInput;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\TaskPresenter;

/**
 * Trusted CLI surface: queries tasks directly (no DispatchGate::scopeVisible).
 */
class DispatchDone extends Command
{
    use ResolvesTextInput;
    use TalksToAgentApi;

    protected $signature = 'dispatch:done
        {code : The task code, e.g. TASK-042}
        {--status=done : Final status, e.g. done | declined | verifying}
        {--commit= : SHA of the code change}
        {--result= : JSON blob stored under context.result}
        {--result-file= : Read the JSON result from a file (or `-` for stdin) instead of inline --result — avoids the multi-line-quoting hazard}
        {--remote : Act on the configured remote agent API instead of the local DB}
        {--json : Emit machine-readable JSON instead of human text}';

    protected $description = "Set a task's status and record the transition on its timeline.";

    public function handle(DispatchTaskService $tasks): int
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

        if ($this->option('remote')) {
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
}
