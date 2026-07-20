<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
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
        {--limit= : Cap the number of tasks returned, top of the priority order (default: all). For the single-task case use dispatch:next.}
        {--count : Emit counts by status (total + by_status) instead of the task list. With no --status it censuses the whole non-terminal board (open/in_progress/triage/verifying), zero-filled — an empty bucket (e.g. verifying) still prints as 0.}
        {--remote : Act on the configured remote agent API (the default while an agent session token is active)}
        {--local : Act on the local DB even while an agent session token is active (overrides sticky-remote)}
        {--json : Emit machine-readable JSON instead of a human table}';

    protected $description = 'List the actionable backlog in priority order.';

    public function handle(): int
    {
        $limit = $this->option('limit');
        if ($limit !== null) {
            if (! ctype_digit((string) $limit) || (int) $limit < 1) {
                $this->error('--limit must be a positive integer.');

                return self::FAILURE;
            }
            $limit = (int) $limit;
        }

        if ($this->targetsRemote()) {
            $r = $this->agentGet('queue', array_filter([
                'status' => $this->option('status'),
                'type' => $this->option('type'),
                'label' => $this->option('label'),
                'limit' => $limit,
                'count' => $this->option('count') ? 1 : null,
            ]));

            if ($r === null) {
                return self::FAILURE;
            }

            if ($this->option('count')) {
                $this->renderCount((int) ($r['total'] ?? 0), (array) ($r['by_status'] ?? []));

                return self::SUCCESS;
            }

            $this->line(json_encode($r['tasks'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        if ($this->option('count')) {
            // The no-`--status` census spans the whole NON-TERMINAL board —
            // including `verifying`, which the actionable LIST default
            // deliberately excludes (claim only takes open/triage) — and
            // zero-fills every bucket so an empty state prints as 0 instead of
            // silently vanishing (W5-2). done/declined stay out so a backfilled
            // archive doesn't pollute "backlog size".
            $census = ($status = $this->option('status')) ? [$status] : ['open', 'in_progress', 'triage', 'verifying'];

            $grouped = $taskModel::query()
                ->whereIn('status', $census)
                ->when($this->option('type'), fn ($q, $type) => $q->where('type', $type))
                ->when($this->option('label'), fn ($q, $label) => $q->whereHas('labels', fn ($lq) => $lq->whereIn('name', (array) $label)))
                ->selectRaw('status, COUNT(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status')
                ->map(fn ($v) => (int) $v)
                ->all();

            $byStatus = array_replace(array_fill_keys($census, 0), $grouped);

            $this->renderCount(array_sum($byStatus), $byStatus);

            return self::SUCCESS;
        }

        $query = $taskModel::query()
            ->with('labels')
            ->withCount(['comments as comment_count' => fn ($q) => $q->where('event_type', TaskComment::EVENT_COMMENT)]);

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
            ->when($limit, fn ($q) => $q->limit($limit))
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

    /**
     * Render the --count envelope: JSON `{total, by_status}` for machines, a
     * small table for humans. Shared by the local and --remote count paths.
     *
     * @param  array<string,int>  $byStatus
     */
    private function renderCount(int $total, array $byStatus): void
    {
        if ($this->option('json')) {
            $this->line(json_encode(['total' => $total, 'by_status' => $byStatus], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $rows = [];
        foreach ($byStatus as $status => $count) {
            $rows[] = [$status, $count];
        }
        $this->table(['Status', 'Count'], $rows);
        $this->info("total: {$total}");
    }
}
