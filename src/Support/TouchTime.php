<?php

namespace Sgrjr\Dispatch\Support;

/**
 * Estimates the focused human touch-time (in whole minutes) a task's agent run
 * would have taken a human — modeled for the SAME workflow, no queue latency,
 * not a measurement. Pure and container-free: the coefficients arrive as an
 * array (the `dispatch.metrics.touch_time` config block), so the formula
 * unit-tests against pinned values and historical tasks re-derive at read time
 * whenever the coefficients are tuned. Nothing here is ever stamped/stored.
 *
 * v1 formula — linear per-category with per-category caps (explainable in a
 * tooltip; the caps stop a 500-Edit sweep from claiming absurd totals):
 *
 *   base_minutes[type ?? 'default']
 *   + min(mutate_calls × rate, cap)   // Edit/Write/… — locate + type + check
 *   + min(bash_calls   × rate, cap)
 *   + min(other_calls  × rate, cap)   // reads/searches/anything unlisted
 *   + min(subagents    × rate, cap)   // parallel work a human does serially
 *   + min(duration_min × weight, cap) // wall-clock: capped, low weight only
 */
class TouchTime
{
    /**
     * @param  array<string,mixed>  $metrics  A stamped `context.result.metrics` array.
     * @param  ?string  $type  The task's type (null/unknown falls back to 'default').
     * @param  array<string,mixed>  $config  The `dispatch.metrics.touch_time` block.
     * @return ?int Whole minutes (>= 1), or null when the config block is absent
     *              or unusable (`version` and `base_minutes` are required — an
     *              unversioned figure must never render; every other coefficient
     *              falls back to the shipped default).
     */
    public static function estimateMinutes(array $metrics, ?string $type, array $config): ?int
    {
        $base = $config['base_minutes'] ?? null;
        if (! is_string($config['version'] ?? null) || ! is_array($base)) {
            return null;
        }

        $minutes = (float) ($base[$type] ?? $base['default'] ?? 10);

        $rates = (array) ($config['per_tool_minutes'] ?? []);
        $caps = (array) ($config['category_cap_minutes'] ?? []);
        $counts = self::categorize((array) ($metrics['tools'] ?? []), $config);

        $minutes += min($counts['mutate'] * (float) ($rates['mutate'] ?? 4.0), (float) ($caps['mutate'] ?? 240));
        $minutes += min($counts['bash'] * (float) ($rates['bash'] ?? 1.5), (float) ($caps['bash'] ?? 90));
        $minutes += min($counts['other'] * (float) ($rates['other'] ?? 0.5), (float) ($caps['other'] ?? 60));

        $subagents = max(0, (int) ($metrics['subagents'] ?? 0));
        $minutes += min(
            $subagents * (float) ($config['per_subagent_minutes'] ?? 5),
            (float) ($config['subagent_cap_minutes'] ?? 60),
        );

        $durationMin = max(0, (int) ($metrics['duration_s'] ?? 0)) / 60;
        $minutes += min(
            $durationMin * (float) ($config['duration_weight'] ?? 0.15),
            (float) ($config['duration_cap_minutes'] ?? 20),
        );

        return max(1, (int) round($minutes));
    }

    /**
     * Split the raw tools histogram (tool name => count) into category counts.
     * Names are matched verbatim against the config lists; anything unlisted —
     * including 'unknown' and MCP tool names — counts as 'other'.
     *
     * @param  array<string,mixed>  $tools
     * @param  array<string,mixed>  $config
     * @return array{mutate:int,bash:int,other:int}
     */
    private static function categorize(array $tools, array $config): array
    {
        $mutate = (array) ($config['mutate_tools'] ?? ['Edit', 'Write', 'MultiEdit', 'NotebookEdit']);
        $bash = (array) ($config['bash_tools'] ?? ['Bash', 'PowerShell']);

        $counts = ['mutate' => 0, 'bash' => 0, 'other' => 0];
        foreach ($tools as $name => $count) {
            $bucket = in_array($name, $mutate, true) ? 'mutate' : (in_array($name, $bash, true) ? 'bash' : 'other');
            $counts[$bucket] += max(0, (int) $count);
        }

        return $counts;
    }
}
