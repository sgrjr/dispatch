<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;

/**
 * Trusted CLI surface: queries tasks directly (no DispatchGate::scopeVisible).
 *
 * Editing the description is memorialized, not silently overwritten: the OLD
 * body is preserved as an internal EVENT_DESCRIPTION_EDITED timeline comment
 * before the new body is applied, so a description edit never loses history.
 */
class DispatchEdit extends Command
{
    protected $signature = 'dispatch:edit
        {code : The task code, e.g. TASK-042}
        {--title= : New title}
        {--description= : New description (markdown ok); the old body is memorialized on the timeline}
        {--due= : New due date (parseable date/time string, e.g. "2026-08-01" or "+3 days"); empty string clears it}
        {--json : Emit machine-readable JSON instead of human text}';

    protected $description = 'Edit a task\'s title, description, and/or due date.';

    public function handle(): int
    {
        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $task = $taskModel::query()->where('code', $this->argument('code'))->first();
        if (! $task) {
            $this->error("Task not found: {$this->argument('code')}");

            return self::FAILURE;
        }

        // Resolve --due up front so a bad date string fails loudly before any
        // other change (including the description memorial) is applied.
        $dueProvided = $this->option('due') !== null;
        $dueAt = $task->due_at;
        if ($dueProvided) {
            $raw = trim((string) $this->option('due'));
            if ($raw === '') {
                $dueAt = null;
            } else {
                try {
                    $dueAt = Carbon::parse($raw);
                } catch (\Throwable) {
                    $this->error("--due could not be parsed as a date: {$raw}");

                    return self::FAILURE;
                }
            }
        }

        $descriptionChanged = false;
        $newDescription = $this->option('description');
        if ($newDescription !== null && $newDescription !== $task->description) {
            $task->recordEvent(
                TaskComment::EVENT_DESCRIPTION_EDITED,
                null,
                ['source' => 'cli'],
                $task->description,
                true,
            );
            $task->description = $newDescription;
            $descriptionChanged = true;
        }

        if ($this->option('title') !== null) {
            $task->title = $this->option('title');
        }

        if ($dueProvided) {
            $task->due_at = $dueAt;
        }

        $task->save();

        if ($this->option('json')) {
            $this->line(json_encode([
                'code' => $task->code,
                'title' => $task->title,
                'type' => $task->type,
                'priority' => $task->priority,
                'status' => $task->status,
                'due_at' => optional($task->due_at)->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Updated {$task->code}");
        $this->line("  title: {$task->title}");
        $this->line("  type: {$task->type}  ·  priority: {$task->priority}  ·  status: {$task->status}");
        $this->line('  due: '.($task->due_at ? $task->due_at->toDateTimeString() : '(none)'));
        if ($descriptionChanged) {
            $this->line('  description updated (previous body memorialized on the timeline).');
        }

        return self::SUCCESS;
    }
}
