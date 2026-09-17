<?php

namespace Sgrjr\Dispatch\Console\Commands\Concerns;

use Illuminate\Support\Collection;
use Sgrjr\Dispatch\Services\LabelCleanupService;

/**
 * Shared by the `dispatch:labels:*` write commands: resolve the `labels*`
 * argument to Label models (all-or-nothing) and print a result as text or
 * `--json`.
 */
trait ResolvesLabelArguments
{
    /**
     * The named labels, or null (after printing why) when any name is unknown.
     * A name that is already an alias says where it points — "not found" would
     * send the caller hunting for a label that was deliberately folded away.
     *
     * @return Collection<int,\Sgrjr\Dispatch\Models\Label>|null
     */
    protected function resolveLabelArguments(LabelCleanupService $service): ?Collection
    {
        $found = $service->findByNames((array) $this->argument('labels'));

        foreach ($found['aliased'] as $name => $canonical) {
            $this->error("{$name} is no longer a label — it already redirects to {$canonical}.");
        }
        foreach ($found['missing'] as $name) {
            $this->error("Label not found: {$name}");
        }

        if ($found['aliased'] !== [] || $found['missing'] !== []) {
            return null;
        }

        if ($found['labels']->isEmpty()) {
            $this->error('Name at least one label.');

            return null;
        }

        return $found['labels'];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    protected function report(array $payload, string $message): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info($message);
        }

        return self::SUCCESS;
    }
}
