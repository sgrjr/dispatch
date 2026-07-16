<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Sgrjr\Dispatch\Models\Task;

/**
 * Trusted CLI surface: queries tasks directly (no DispatchGate::scopeVisible —
 * that scope is for user-facing web surfaces only).
 */
class DispatchQueue extends Command
{
    protected $signature = 'dispatch:queue
        {--status= : Restrict to a single status (default: open, in_progress, triage)}
        {--json : Emit machine-readable JSON instead of a human table}';

    protected $description = 'List the actionable backlog in priority order.';

    public function handle(): int
    {
        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $query = $taskModel::query()->with('labels');

        if ($status = $this->option('status')) {
            $query->where('status', $status);
        } else {
            $query->whereIn('status', ['open', 'in_progress', 'triage']);
        }

        $tasks = $query
            ->orderByRaw("CASE priority WHEN 'blocker' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 99 END")
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($this->option('json')) {
            $this->line(json_encode($tasks->map(fn (Task $t) => [
                'code' => $t->code,
                'title' => $t->title,
                'type' => $t->type,
                'priority' => $t->priority,
                'status' => $t->status,
                'is_public' => (bool) $t->is_public,
                'labels' => $t->labels->pluck('name')->all(),
            ])->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($tasks->isEmpty()) {
            $this->info('No matching tasks.');

            return self::SUCCESS;
        }

        $this->table(
            ['Code', 'Pri', 'Type', 'Status', 'Title'],
            $tasks->map(fn (Task $t) => [
                $t->code,
                $t->priority,
                $t->type,
                $t->status,
                Str::limit($t->title, 70),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
