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
     * Fold a NEW run's metrics onto whatever a task already carries, so a task
     * that cycles open → in_progress → verifying → open again shows the cost of
     * ALL its runs, not just the last one. Every stamp path (recordResult, the
     * `--stamp` flag) routes through here — a raw `metrics = new` assignment is
     * the clobber this exists to prevent.
     *
     * Same `window.from` on both sides means a RE-computation of the same run
     * (e.g. `dispatch:metrics --stamp` mid-run, then `done --with-metrics` at
     * close — both windowed to the same claim): the fresh compute wins outright,
     * because summing two measurements of one window double-counts it. Two
     * null/absent froms are likewise indistinguishable (both unbounded over a
     * transcript), so they replace too. Only genuinely distinct windows sum.
     *
     * The accumulated object keeps the collect() shape (the panel and TouchTime
     * read it unchanged) plus a `runs` count; derived fields (cache_hit_ratio,
     * cost_partial) are recomputed from the summed parts, and `transcript`
     * carries the latest run's provenance.
     *
     * @param  array<string,mixed>|null  $existing
     * @param  array<string,mixed>  $incoming
     * @return array<string,mixed>
     */
    public static function accumulate(?array $existing, array $incoming): array
    {
        if ($existing === null || $existing === []) {
            return $incoming;
        }

        $exFrom = $existing['window']['from'] ?? null;
        $inFrom = $incoming['window']['from'] ?? null;
        if ($exFrom === $inFrom) {
            return $incoming;
        }

        $exTok = is_array($existing['tokens'] ?? null) ? $existing['tokens'] : [];
        $inTok = is_array($incoming['tokens'] ?? null) ? $incoming['tokens'] : [];
        $tokens = [];
        foreach (['input', 'output', 'cache_read', 'cache_creation', 'total'] as $k) {
            $tokens[$k] = (int) ($exTok[$k] ?? 0) + (int) ($inTok[$k] ?? 0);
        }
        $inputSide = $tokens['input'] + $tokens['cache_read'] + $tokens['cache_creation'];
        $tokens['cache_hit_ratio'] = $inputSide > 0 ? round($tokens['cache_read'] / $inputSide, 4) : 0.0;

        $exCost = $existing['cost_usd'] ?? null;
        $inCost = $incoming['cost_usd'] ?? null;
        $cost = ($exCost === null && $inCost === null) ? null : round((float) $exCost + (float) $inCost, 4);

        $exDur = $existing['duration_s'] ?? null;
        $inDur = $incoming['duration_s'] ?? null;

        $tools = is_array($existing['tools'] ?? null) ? $existing['tools'] : [];
        foreach ((is_array($incoming['tools'] ?? null) ? $incoming['tools'] : []) as $name => $count) {
            $tools[$name] = (int) ($tools[$name] ?? 0) + (int) $count;
        }
        arsort($tools);

        $union = fn (string $k) => array_values(array_unique(array_merge(
            array_filter((array) ($existing[$k] ?? []), 'is_string'),
            array_filter((array) ($incoming[$k] ?? []), 'is_string'),
        )));

        return [
            'window' => [
                'from' => self::edge($exFrom, $inFrom, earliest: true),
                'to' => self::edge($existing['window']['to'] ?? null, $incoming['window']['to'] ?? null, earliest: false),
                'basis' => 'accumulated',
            ],
            'duration_s' => ($exDur === null && $inDur === null) ? null : (int) $exDur + (int) $inDur,
            'transcript' => $incoming['transcript'] ?? ($existing['transcript'] ?? null),
            'tokens' => $tokens,
            'cost_usd' => $cost,
            'cost_partial' => ! empty($existing['cost_partial']) || ! empty($incoming['cost_partial'])
                || ($cost !== null && ($exCost === null || $inCost === null)),
            'turns' => (int) ($existing['turns'] ?? 0) + (int) ($incoming['turns'] ?? 0),
            'tool_calls' => (int) ($existing['tool_calls'] ?? 0) + (int) ($incoming['tool_calls'] ?? 0),
            'tools' => $tools,
            'subagents' => (int) ($existing['subagents'] ?? 0) + (int) ($incoming['subagents'] ?? 0),
            'errors' => (int) ($existing['errors'] ?? 0) + (int) ($incoming['errors'] ?? 0),
            'models' => $union('models'),
            'unpriced_models' => $union('unpriced_models'),
            'runs' => max(1, (int) ($existing['runs'] ?? 1)) + max(1, (int) ($incoming['runs'] ?? 1)),
        ];
    }

    /**
     * Pick the earliest/latest of two ISO-8601 window bounds. A null `from` is
     * "unbounded", which absorbs any concrete start; a null `to` just yields
     * the other side. Unparseable strings (client-supplied metrics ride the
     * agent API) fall back to lexicographic order — correct for uniform ISO.
     */
    private static function edge(?string $a, ?string $b, bool $earliest): ?string
    {
        if ($a === null || $b === null) {
            return $earliest ? null : ($a ?? $b);
        }
        $ta = strtotime($a);
        $tb = strtotime($b);
        if ($ta === false || $tb === false) {
            return ($a <= $b) === $earliest ? $a : $b;
        }

        return ($ta <= $tb) === $earliest ? $a : $b;
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
