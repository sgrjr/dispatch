<?php

use Sgrjr\Dispatch\Support\MetricsPresenter;

/*
 * MetricsPresenter — the pure formatter behind the staff "Agent run" panel.
 * DB-free: it shapes a task's `context` array (as stamped by
 * dispatch:metrics --stamp under context.result.metrics) into display strings.
 */

function sampleMetricsContext(array $overrides = []): array
{
    return ['result' => ['commit' => 'abc1234', 'metrics' => array_merge([
        'window' => ['from' => '2026-07-17T00:00:00+00:00', 'to' => '2026-07-17T00:12:34+00:00', 'basis' => 'claimed_at'],
        'duration_s' => 754,
        'transcript' => ['source' => 'session-file', 'main' => '/x/main.jsonl', 'subagent_files' => 1],
        'tokens' => ['input' => 1000, 'output' => 500, 'cache_read' => 8000, 'cache_creation' => 1500, 'total' => 11000, 'cache_hit_ratio' => 0.7273],
        'cost_usd' => 0.1234,
        'cost_partial' => false,
        'turns' => 8,
        'tool_calls' => 22,
        'tools' => ['Bash' => 10, 'Read' => 8, 'Edit' => 4],
        'subagents' => 2,
        'errors' => 1,
        'models' => ['claude-opus-4-8'],
        'unpriced_models' => [],
    ], $overrides)]];
}

test('present() returns null when there are no stamped metrics', function () {
    expect(MetricsPresenter::present(null))->toBeNull()
        ->and(MetricsPresenter::present([]))->toBeNull()
        ->and(MetricsPresenter::present(['result' => []]))->toBeNull()
        ->and(MetricsPresenter::present(['result' => ['metrics' => []]]))->toBeNull();
});

test('present() shapes a full metrics array into display strings', function () {
    $p = MetricsPresenter::present(sampleMetricsContext());

    expect($p['duration'])->toBe('12m 34s')
        ->and($p['total_tokens'])->toBe('11k')
        ->and($p['total_tokens_full'])->toBe('11,000')
        ->and($p['cache_pct'])->toBe('72.7%')
        ->and($p['cost'])->toBe('$0.1234')
        ->and($p['tool_calls'])->toBe(22)
        ->and($p['turns'])->toBe(8)
        ->and($p['subagents'])->toBe(2)
        ->and($p['errors'])->toBe(1)
        ->and($p['tokens']['cache_read'])->toBe('8,000')
        ->and($p['models'])->toBe(['claude-opus-4-8'])
        ->and($p['commit'])->toBe('abc1234')
        ->and($p['window_basis'])->toBe('claimed_at')
        ->and($p['transcript_source'])->toBe('session-file');
});

test('present() orders tools by call count, descending, capped at 8', function () {
    $p = MetricsPresenter::present(sampleMetricsContext());

    expect($p['tools'][0])->toBe(['name' => 'Bash', 'count' => 10])
        ->and($p['tools'][1])->toBe(['name' => 'Read', 'count' => 8])
        ->and($p['tools'][2])->toBe(['name' => 'Edit', 'count' => 4]);
});

test('present() marks a partial cost and tolerates an unknown (null) cost', function () {
    expect(MetricsPresenter::present(sampleMetricsContext(['cost_partial' => true]))['cost'])
        ->toBe('$0.1234 (partial)');

    expect(MetricsPresenter::present(sampleMetricsContext(['cost_usd' => null]))['cost'])
        ->toBe('unknown');
});

test('duration() humanizes seconds and handles null', function () {
    expect(MetricsPresenter::duration(null))->toBe('—')
        ->and(MetricsPresenter::duration(45))->toBe('45s')
        ->and(MetricsPresenter::duration(60))->toBe('1m')
        ->and(MetricsPresenter::duration(90))->toBe('1m 30s')
        ->and(MetricsPresenter::duration(3661))->toBe('1h 1m');
});

test('compactTokens() abbreviates thousands and millions', function () {
    expect(MetricsPresenter::compactTokens(900))->toBe('900')
        ->and(MetricsPresenter::compactTokens(1000))->toBe('1k')
        ->and(MetricsPresenter::compactTokens(1500))->toBe('1.5k')
        ->and(MetricsPresenter::compactTokens(45600))->toBe('45.6k')
        ->and(MetricsPresenter::compactTokens(1_234_567))->toBe('1.2M');
});
