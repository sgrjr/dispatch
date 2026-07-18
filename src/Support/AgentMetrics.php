<?php

namespace Sgrjr\Dispatch\Support;

use Illuminate\Support\Carbon;

/**
 * Assembles the agent-run metrics object (window + duration + transcript +
 * token/cost/tool summary) from the local Claude Code transcript.
 *
 * Extracted so BOTH entry points produce the byte-identical shape the staff
 * "Agent run" panel reads at `context.result.metrics`:
 *   - `dispatch:metrics` (compute / --stamp / --note / --json), and
 *   - `dispatch:done --with-metrics` (fold under the CLOSING result — the
 *     remote-friendly path, since a remote task never reaches a local --stamp).
 *
 * Pure of the task model: the caller decides the window (`$since`) and the
 * `$basis` label; this only locates transcripts and summarizes them.
 */
class AgentMetrics
{
    /**
     * @param  array{transcript?:?string,session?:?string,projectDir?:?string}  $opts
     * @return array<string,mixed>
     */
    public static function collect(
        TranscriptLocator $locator,
        ?Carbon $since,
        ?Carbon $until,
        string $basis,
        array $opts = [],
    ): array {
        $until = $until ?? Carbon::now();

        $loc = $locator->locate(
            $opts['transcript'] ?? null,
            $opts['session'] ?? null,
            $opts['projectDir'] ?? null,
            config('dispatch.metrics.session_file'),
            config('dispatch.metrics.transcript_root'),
        );

        $files = [];
        if ($loc['main'] !== null) {
            $files[] = ['path' => $loc['main'], 'subagent' => false];
        }
        foreach ($loc['subagents'] as $sub) {
            $files[] = ['path' => $sub, 'subagent' => true];
        }

        $svc = new TranscriptMetrics((array) config('dispatch.metrics.pricing', []));
        $computed = $svc->summarize($files, $since?->getTimestamp(), $until->getTimestamp());

        return array_merge([
            'window' => [
                'from' => optional($since)->toIso8601String(),
                'to' => $until->toIso8601String(),
                'basis' => $basis,
            ],
            'duration_s' => $since !== null ? max(0, $until->getTimestamp() - $since->getTimestamp()) : null,
            'transcript' => [
                'source' => $loc['source'],
                'main' => $loc['main'],
                'subagent_files' => count($loc['subagents']),
            ],
        ], $computed);
    }
}
