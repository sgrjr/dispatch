<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Support\TaskPresenter;

/**
 * Trusted CLI surface: queries tasks directly (no DispatchGate::scopeVisible —
 * that scope is for user-facing web surfaces only).
 */
class DispatchQueue extends Command
{
    use TalksToAgentApi;

    protected $signature = 'dispatch:queue
        {--status= : Restrict to a single status (default: open, in_progress, triage)}
        {--type= : Filter to a single type}
        {--label=* : Filter to tasks carrying any of these labels}
        {--remote : Act on the configured remote agent API instead of the local DB}
        {--json : Emit machine-readable JSON instead of a human table}';

    protected $description = 'List the actionable backlog in priority order.';

    public function handle(): int
    {
        if ($this->option('remote')) {
            $r = $this->agentGet('queue', array_filter([
                'status' => $this->option('status'),
                'type' => $this->option('type'),
                'label' => $this->option('label'),
            ]));

            if ($r === null) {
                return self::FAILURE;
            }

            $this->line(json_encode($r['tasks'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $query = $taskModel::query()->with('labels');

        if ($status = $this->option('status')) {
            $query->where('status', $status);
        } else {
            $query->whereIn('status', ['open', 'in_progress', 'triage']);
        }

        $type = $this->option('type');
        $labels = $this->option('label');

        $tasks = $query
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($labels, fn ($q) => $q->whereHas('labels', fn ($lq) => $lq->whereIn('name', (array) $labels)))
            ->orderByRaw("CASE priority WHEN 'blocker' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 99 END")
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($this->option('json')) {
            $this->line(json_encode(TaskPresenter::collection($tasks), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

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
