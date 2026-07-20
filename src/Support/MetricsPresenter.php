<?php

namespace Sgrjr\Dispatch\Support;

/**
 * Shapes the agent-run metrics an agent stamps under `context.result.metrics`
 * (see dispatch:metrics --stamp / {@see TranscriptMetrics}) into a display-ready
 * array for the staff task view. DB-free — the only lookup is the
 * `dispatch.metrics.touch_time` config block (the formula itself stays
 * container-free in {@see TouchTime}), so it unit-tests against a plain array
 * and the blade stays logic-light.
 *
 * present() returns null when a task carries no stamped metrics — the caller
 * uses that to decide whether to render the panel at all, so the panel's mere
 * presence confirms a run was actually captured and stored.
 */
class MetricsPresenter
{
    /**
     * @param  array<string,mixed>|null  $context  A task's `context` attribute.
     * @param  ?string  $taskType  The task's `type`, for the touch-time base coefficient.
     * @return array<string,mixed>|null
     */
    public static function present(?array $context, ?string $taskType = null): ?array
    {
        $result = is_array($context['result'] ?? null) ? $context['result'] : [];
        $metrics = $result['metrics'] ?? null;
        if (! is_array($metrics) || $metrics === []) {
            return null;
        }

        $tokens = is_array($metrics['tokens'] ?? null) ? $metrics['tokens'] : [];
        $cacheRatio = (float) ($tokens['cache_hit_ratio'] ?? 0);

        $cost = $metrics['cost_usd'] ?? null;
        $costLabel = $cost === null
            ? 'unknown'
            : '$'.number_format((float) $cost, 4).(! empty($metrics['cost_partial']) ? ' (partial)' : '');

        $tools = is_array($metrics['tools'] ?? null) ? $metrics['tools'] : [];
        arsort($tools);
        $topTools = [];
        foreach (array_slice($tools, 0, 8, true) as $name => $count) {
            $topTools[] = ['name' => (string) $name, 'count' => (int) $count];
        }

        $models = array_values(array_filter((array) ($metrics['models'] ?? []), 'is_string'));

        $duration = isset($metrics['duration_s']) && $metrics['duration_s'] !== null
            ? (int) $metrics['duration_s']
            : null;

        // Derived at read time, never stamped — hidden entirely (null keys)
        // when the host's published config lacks the touch_time block.
        $touchCfg = (array) (config('dispatch.metrics.touch_time') ?? []);
        $touchMinutes = TouchTime::estimateMinutes($metrics, $taskType, $touchCfg);
        $touchVersion = $touchMinutes !== null ? (string) $touchCfg['version'] : null;

        return [
            'duration' => self::duration($duration),
            'touch_time' => $touchMinutes !== null ? '~'.self::duration($touchMinutes * 60) : null,
            'touch_time_minutes' => $touchMinutes,
            'touch_time_version' => $touchVersion,
            'touch_time_title' => $touchMinutes !== null
                ? "Modeled focused human touch-time for the same workflow — no queue latency, not a measurement. Formula {$touchVersion}, derived at read time from task type + tool/subagent/duration signals; tune the coefficients in config/dispatch.php (metrics.touch_time)."
                : null,
            'total_tokens' => self::compactTokens((int) ($tokens['total'] ?? 0)),
            'total_tokens_full' => number_format((int) ($tokens['total'] ?? 0)),
            'cache_pct' => number_format($cacheRatio * 100, 1).'%',
            'cost' => $costLabel,
            'turns' => (int) ($metrics['turns'] ?? 0),
            'tool_calls' => (int) ($metrics['tool_calls'] ?? 0),
            'subagents' => (int) ($metrics['subagents'] ?? 0),
            'errors' => (int) ($metrics['errors'] ?? 0),
            'tokens' => [
                'input' => number_format((int) ($tokens['input'] ?? 0)),
                'output' => number_format((int) ($tokens['output'] ?? 0)),
                'cache_read' => number_format((int) ($tokens['cache_read'] ?? 0)),
                'cache_creation' => number_format((int) ($tokens['cache_creation'] ?? 0)),
            ],
            'tools' => $topTools,
            'models' => $models,
            'window_basis' => (string) ($metrics['window']['basis'] ?? '—'),
            'transcript_source' => (string) ($metrics['transcript']['source'] ?? '—'),
            'commit' => is_string($result['commit'] ?? null) ? $result['commit'] : null,
        ];
    }

    /**
     * Humanize a second count as e.g. "45s", "12m 3s", "1h 20m". Null → "—".
     */
    public static function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $s = max(0, $seconds);
        if ($s < 60) {
            return "{$s}s";
        }

        $min = intdiv($s, 60);
        $sec = $s % 60;
        if ($min < 60) {
            return $sec ? "{$min}m {$sec}s" : "{$min}m";
        }

        $hr = intdiv($min, 60);
        $min %= 60;

        return "{$hr}h {$min}m";
    }

    /**
     * Compact a token count for a headline stat: 45600 → "45.6k", 1234567 → "1.2M".
     * Full precision stays available separately (total_tokens_full) for a tooltip.
     */
    public static function compactTokens(int $n): string
    {
        if ($n >= 1_000_000) {
            return rtrim(rtrim(number_format($n / 1_000_000, 1, '.', ''), '0'), '.').'M';
        }
        if ($n >= 1_000) {
            return rtrim(rtrim(number_format($n / 1_000, 1, '.', ''), '0'), '.').'k';
        }

        return (string) $n;
    }
}
