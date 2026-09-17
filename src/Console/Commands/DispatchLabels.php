<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Sgrjr\Dispatch\Services\LabelCleanupService;

/**
 * Trusted CLI surface: the label vocabulary with usage, fewest tasks first —
 * the read half of label cleanup. `--unused` / `--max-uses=1` narrow it to the
 * noise; `dispatch:labels:replace` / `dispatch:labels:retire` (or the staff
 * `/labels` page) act on what it finds. Read-only, local DB.
 */
class DispatchLabels extends Command
{
    protected $signature = 'dispatch:labels
        {--unused : Only labels no live task carries}
        {--max-uses= : Only labels on at most this many tasks (e.g. 1 for one-off labels)}
        {--json : Emit machine-readable JSON instead of human text}';

    protected $description = 'List labels with how many tasks use them, fewest first.';

    public function handle(LabelCleanupService $labels): int
    {
        $maxUses = $this->option('unused') ? 0 : $this->option('max-uses');
        if ($maxUses !== null && $maxUses !== '' && ! ctype_digit((string) $maxUses)) {
            $this->error('--max-uses must be a whole number.');

            return self::FAILURE;
        }

        $all = $labels->usage();
        $rows = $all
            ->filter(fn ($l) => $maxUses === null || $maxUses === '' || (int) $l->tasks_count <= (int) $maxUses)
            ->sortBy([['tasks_count', 'asc'], fn ($a, $b) => strcasecmp($a->name, $b->name)])
            ->values();

        $totals = [
            'total' => $all->count(),
            'unused' => $all->where('tasks_count', 0)->count(),
            'single_use' => $all->where('tasks_count', 1)->count(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($totals + [
                'labels' => $rows->map(fn ($l) => [
                    'name' => $l->name,
                    'kind' => $l->effectiveKind() ?? 'plain',
                    'tasks' => (int) $l->tasks_count,
                    'last_used_at' => $l->last_used_at ? Carbon::parse($l->last_used_at)->toIso8601String() : null,
                    'aliases' => $l->aliases->pluck('name')->all(),
                ])->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($rows->isEmpty()) {
            $this->info($all->isEmpty() ? 'No labels yet.' : 'No labels match.');

            return self::SUCCESS;
        }

        $this->table(
            ['Label', 'Kind', 'Tasks', 'Last attached', 'Redirects from'],
            $rows->map(fn ($l) => [
                $l->name,
                $l->effectiveKind() ?? 'plain',
                (int) $l->tasks_count,
                $l->last_used_at ? Carbon::parse($l->last_used_at)->diffForHumans() : '—',
                $l->aliases->pluck('name')->implode(', '),
            ])->all(),
        );

        $this->line(sprintf('%d labels · %d unused · %d used once.', $totals['total'], $totals['unused'], $totals['single_use']));
        $this->line('Fold near-duplicates: dispatch:labels:replace <label>... --with=<canonical>   Drop noise: dispatch:labels:retire <label>...');

        return self::SUCCESS;
    }
}
