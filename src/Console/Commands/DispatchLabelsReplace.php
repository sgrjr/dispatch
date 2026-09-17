<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Sgrjr\Dispatch\Console\Commands\Concerns\GuardsLocalOnlyWrites;
use Sgrjr\Dispatch\Console\Commands\Concerns\ResolvesLabelArguments;
use Sgrjr\Dispatch\Services\LabelCleanupService;

/**
 * Trusted CLI surface: fold one or more labels into a canonical label across
 * every task — see LabelCleanupService::replace() for the rules (the missing
 * target is created by renaming the most-used source; old names become
 * aliases; focuses are rewritten; one internal timeline event per task).
 *
 * LOCAL-ONLY, like merge: vocabulary cleanup is a staff decision, so there is
 * deliberately no agent verb for it. Against production, use the `/labels`
 * page.
 */
class DispatchLabelsReplace extends Command
{
    use GuardsLocalOnlyWrites;
    use ResolvesLabelArguments;

    protected $signature = 'dispatch:labels:replace
        {labels* : Label name(s) to replace}
        {--with= : The canonical label they become (an existing label, or a new name)}
        {--dry-run : Show what would change without writing anything}
        {--local : Confirm the LOCAL dev DB is the intended target even while an agent session is active}
        {--json : Emit machine-readable JSON instead of human text}';

    protected $description = 'Replace label(s) with one canonical label on every task.';

    public function handle(LabelCleanupService $service): int
    {
        if (! $this->option('dry-run') && $this->blockedByActiveAgentSession('dispatch:labels:replace', 'rewrite labels across the local backlog instead of the one the session targets', [
            'no remote label verb exists' => 'use the Labels page on the board (/labels)',
        ])) {
            return self::FAILURE;
        }

        $target = trim((string) $this->option('with'));
        if ($target === '') {
            $this->error('--with=<label> is required: the canonical label to replace them with.');

            return self::FAILURE;
        }

        $labels = $this->resolveLabelArguments($service);
        if ($labels === null) {
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $preview = $service->preview($labels->modelKeys(), $target);

            return $this->report($preview + ['dry_run' => true], sprintf(
                'Would replace %s with %s (%s) on %d task(s)%s%s.',
                implode(', ', $preview['labels']),
                $preview['target'],
                $preview['target_exists'] ? 'existing label' : 'new — the most-used one is renamed',
                $preview['tasks'],
                $preview['already_on_target'] > 0 ? ", {$preview['already_on_target']} already carry it" : '',
                $preview['focuses'] > 0 ? "; {$preview['focuses']} focus(es) would be rewritten" : '',
            ));
        }

        $result = $service->replace($labels->modelKeys(), $target, Auth::id());

        if ($result['replaced'] === []) {
            return $this->report($result, "Nothing to replace — {$result['target']} was the only label named.");
        }

        return $this->report($result, sprintf(
            'Replaced %s with %s on %d task(s)%s. Old name(s) now redirect to %s.%s',
            implode(', ', $result['replaced']),
            $result['target'],
            $result['tasks'],
            $result['already_on_target'] > 0 ? " ({$result['already_on_target']} already had it)" : '',
            $result['target'],
            $result['focuses_updated'] > 0 ? " Rewrote {$result['focuses_updated']} focus(es)." : '',
        ));
    }
}
