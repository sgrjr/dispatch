<?php

use Sgrjr\Dispatch\Support\TouchTime;

/*
 * TouchTime — the pure v1 formula behind the "est. human time" figure.
 * Container-free: coefficients are pinned in-file (a copy of the shipped
 * defaults) so these exact-math assertions stay stable when the live
 * config/dispatch.php values are retuned.
 */

function touchTimeCfg(array $overrides = []): array
{
    return array_merge([
        'version' => 'v1',
        'base_minutes' => [
            'default' => 10, 'bug' => 15, 'feature' => 20,
            'chore' => 5, 'debt' => 15, 'verify' => 10,
        ],
        'per_tool_minutes' => ['mutate' => 4.0, 'bash' => 1.5, 'other' => 0.5],
        'mutate_tools' => ['Edit', 'Write', 'MultiEdit', 'NotebookEdit'],
        'bash_tools' => ['Bash', 'PowerShell'],
        'category_cap_minutes' => ['mutate' => 240, 'bash' => 90, 'other' => 60],
        'per_subagent_minutes' => 5,
        'subagent_cap_minutes' => 60,
        'duration_weight' => 0.15,
        'duration_cap_minutes' => 20,
    ], $overrides);
}

function touchTimeMetrics(array $overrides = []): array
{
    return array_merge([
        'duration_s' => 754,
        'tools' => ['Bash' => 10, 'Read' => 8, 'Edit' => 4],
        'subagents' => 2,
    ], $overrides);
}

test('estimateMinutes() sums base + categorized tools + subagents + weighted duration', function () {
    // 10 + (4×4) + (10×1.5) + (8×0.5) + (2×5) + (754/60 × 0.15) = 56.885 → 57
    expect(TouchTime::estimateMinutes(touchTimeMetrics(), null, touchTimeCfg()))->toBe(57);
});

test('estimateMinutes() applies the type-aware base, falling back for unknown types', function () {
    expect(TouchTime::estimateMinutes(touchTimeMetrics(), 'feature', touchTimeCfg()))->toBe(67)
        ->and(TouchTime::estimateMinutes(touchTimeMetrics(), 'chore', touchTimeCfg()))->toBe(52)
        ->and(TouchTime::estimateMinutes(touchTimeMetrics(), 'weird', touchTimeCfg()))->toBe(57);
});

test('estimateMinutes() floors at the base for a run with no tool signals', function () {
    $zeroTools = touchTimeMetrics(['tools' => [], 'subagents' => 0]);

    // 10 + (600/60 × 0.15) = 11.5 → 12
    expect(TouchTime::estimateMinutes(array_merge($zeroTools, ['duration_s' => 600]), null, touchTimeCfg()))->toBe(12)
        ->and(TouchTime::estimateMinutes(array_merge($zeroTools, ['duration_s' => null]), null, touchTimeCfg()))->toBe(10);
});

test('estimateMinutes() caps each category so huge sweeps stay sane', function () {
    $quiet = ['duration_s' => null, 'subagents' => 0];

    // mutate: 10 + min(500×4, 240) = 250
    expect(TouchTime::estimateMinutes(array_merge($quiet, ['tools' => ['Edit' => 500]]), null, touchTimeCfg()))->toBe(250)
        // subagents: 10 + min(50×5, 60) = 70
        ->and(TouchTime::estimateMinutes(['duration_s' => null, 'tools' => [], 'subagents' => 50], null, touchTimeCfg()))->toBe(70)
        // duration: 10 + min(36000/60 × 0.15, 20) = 30
        ->and(TouchTime::estimateMinutes(array_merge($quiet, ['tools' => [], 'duration_s' => 36000]), null, touchTimeCfg()))->toBe(30);
});

test('estimateMinutes() counts unlisted and unknown tool names as other', function () {
    $m = ['duration_s' => null, 'subagents' => 0, 'tools' => ['unknown' => 2, 'mcp__x__y' => 2]];

    // 10 + (4 × 0.5) = 12
    expect(TouchTime::estimateMinutes($m, null, touchTimeCfg()))->toBe(12);
});

test('estimateMinutes() returns null when the config block is absent or unusable', function () {
    $noVersion = touchTimeCfg();
    unset($noVersion['version']);
    $noBase = touchTimeCfg();
    unset($noBase['base_minutes']);

    expect(TouchTime::estimateMinutes(touchTimeMetrics(), null, []))->toBeNull()
        ->and(TouchTime::estimateMinutes(touchTimeMetrics(), null, $noVersion))->toBeNull()
        ->and(TouchTime::estimateMinutes(touchTimeMetrics(), null, $noBase))->toBeNull();
});

test('estimateMinutes() defaults every non-required coefficient for a partial published block', function () {
    $partial = ['version' => 'v1', 'base_minutes' => ['default' => 10]];

    // Code fallbacks mirror the shipped defaults, so the math matches the full pin.
    expect(TouchTime::estimateMinutes(touchTimeMetrics(), null, $partial))->toBe(57);
});
