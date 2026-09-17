<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Sgrjr\Dispatch\Console\Commands\Concerns\GuardsLocalOnlyWrites;
use Sgrjr\Dispatch\Console\Commands\Concerns\ResolvesLabelArguments;
use Sgrjr\Dispatch\Services\LabelCleanupService;

/**
 * Trusted CLI surface: remove one or more labels from every task and delete
 * them — see LabelCleanupService::retire() (focus axes are stripped, a focus
 * left with no labels is deactivated, one internal timeline event per task).
 *
 * LOCAL-ONLY, like merge; against production, use the `/labels` page.
 */
class DispatchLabelsRetire extends Command
{
    use GuardsLocalOnlyWrites;
    use ResolvesLabelArguments;

    protected $signature = 'dispatch:labels:retire
        {labels* : Label name(s) to remove from every task and delete}
        {--dry-run : Show what would change without writing anything}
        {--local : Confirm the LOCAL dev DB is the intended target even while an agent session is active}
        {--json : Emit machine-readable JSON instead of human text}';

    protected $description = 'Retire label(s): remove them from every task and delete them.';

    public function handle(LabelCleanupService $service): int
    {
        if (! $this->option('dry-run') && $this->blockedByActiveAgentSession('dispatch:labels:retire', 'strip labels from the local backlog instead of the one the session targets', [
            'no remote label verb exists' => 'use the Labels page on the board (/labels)',
        ])) {
            return self::FAILURE;
        }

        $labels = $this->resolveLabelArguments($service);
        if ($labels === null) {
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $preview = $service->preview($labels->modelKeys());

            return $this->report($preview + ['dry_run' => true], sprintf(
                'Would retire %s from %d task(s)%s.',
                implode(', ', $preview['labels']),
                $preview['tasks'],
                $preview['focuses_emptied'] !== []
                    ? '; would DEACTIVATE focus(es) left with no labels: '.implode(', ', $preview['focuses_emptied'])
                    : ($preview['focuses'] > 0 ? "; {$preview['focuses']} focus(es) would be rewritten" : ''),
            ));
        }

        $result = $service->retire($labels->modelKeys(), Auth::id());

        return $this->report($result, sprintf(
            'Retired %s from %d task(s).%s',
            implode(', ', $result['retired']),
            $result['tasks'],
            $result['focuses_deactivated'] !== []
                ? ' Deactivated focus(es) left with no labels: '.implode(', ', $result['focuses_deactivated']).'.'
                : ($result['focuses_updated'] > 0 ? " Rewrote {$result['focuses_updated']} focus(es)." : ''),
        ));
    }
}
