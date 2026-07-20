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

    /**
     * One-line human summary of a collected metrics object — shared by the
     * `dispatch:metrics --note` timeline comment, the `session:end` receipt,
     * and the Agent Sessions "recently ended" row, so the run reads the same
     * everywhere it's memorialized.
     *
     * @param  array<string,mixed>  $m
     */
    public static function summaryLine(array $m): string
    {
        $t = is_array($m['tokens'] ?? null) ? $m['tokens'] : [];
        $dur = isset($m['duration_s']) && $m['duration_s'] !== null
            ? MetricsPresenter::duration((int) $m['duration_s'])
            : '—';
        $cachePct = (int) round(((float) ($t['cache_hit_ratio'] ?? 0)) * 100);
        $cost = ($m['cost_usd'] ?? null) !== null ? '~$'.number_format((float) $m['cost_usd'], 2) : '~$?';

        return sprintf(
            '📊 Agent metrics — ⏱ %s · %s tok (%d%% cached) · %s · %d tools · %d subagents · %d turns',
            $dur,
            number_format((int) ($t['total'] ?? 0)),
            $cachePct,
            $cost,
            (int) ($m['tool_calls'] ?? 0),
            (int) ($m['subagents'] ?? 0),
            (int) ($m['turns'] ?? 0),
        );
    }
}
