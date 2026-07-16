<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;

/**
 * Trusted CLI surface: queries tasks directly (no DispatchGate::scopeVisible).
 */
class DispatchDone extends Command
{
    protected $signature = 'dispatch:done
        {code : The task code, e.g. TASK-042}
        {--status=done : Final status, e.g. done | declined | verifying}';

    protected $description = "Set a task's status and record the transition on its timeline.";

    public function handle(): int
    {
        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $task = $taskModel::query()->where('code', $this->argument('code'))->first();
        if (! $task) {
            $this->error("Task not found: {$this->argument('code')}");

            return self::FAILURE;
        }

        $status = $this->option('status');
        if (! in_array($status, Task::STATUSES, true)) {
            $this->error('--status must be one of: '.implode(', ', Task::STATUSES));

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

        $this->info("{$task->code} -> {$status}.");

        return self::SUCCESS;
    }
}
