<?php

use Illuminate\Support\Facades\Artisan;
use Sgrjr\Dispatch\Console\Commands\DispatchMetricsCapture;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\AgentMetrics;
use Sgrjr\Dispatch\Support\TranscriptMetrics;

/*
 * dispatch:metrics — windowed agent-run metrics parsed from Claude Code
 * transcript JSONL. The fixtures mirror the real transcript shape: one assistant
 * message is written as several records sharing message.id, each with a usage
 * snapshot whose output_tokens GROWS; the terminal record (non-null stop_reason)
 * holds the final usage. The parser must take that terminal snapshot — not the
 * first, and never the sum.
 */

/** Build one assistant JSONL record (a single streaming snapshot). */
function asstRecord(string $ts, string $id, string $model, array $usage, array $content, ?string $stop): array
{
    return [
        'type' => 'assistant',
        'timestamp' => $ts,
        'uuid' => $id.'-'.substr(md5($ts), 0, 8),
        'message' => [
            'role' => 'assistant',
            'id' => $id,
            'model' => $model,
            'stop_reason' => $stop,
            'content' => $content,
            'usage' => $usage,
        ],
    ];
}

function usageArr(int $in, int $out, int $cacheRead, int $cacheCreate): array
{
    return [
        'input_tokens' => $in,
        'output_tokens' => $out,
        'cache_read_input_tokens' => $cacheRead,
        'cache_creation_input_tokens' => $cacheCreate,
    ];
}

function toolUse(string $name): array
{
    return ['type' => 'tool_use', 'id' => 'toolu_'.substr(md5($name), 0, 10), 'name' => $name, 'input' => []];
}

function writeJsonl(string $path, array $records): void
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, implode("\n", array_map('json_encode', $records))."\n");
}

/** Main transcript with: streaming snapshots, a second turn, an error tool_result, and one OUT-of-window record. */
function mainRecords(): array
{
    return [
        // Out of window (before --since) — huge tokens that must NOT be counted.
        asstRecord('2025-12-31T23:00:00Z', 'msg_OUT', 'claude-opus-4-8', usageArr(999999, 999999, 0, 0), [toolUse('Grep')], 'tool_use'),

        // msg_A streamed as three snapshots; output grows 2 -> 2 -> 100 (terminal).
        asstRecord('2026-01-01T00:10:00Z', 'msg_A', 'claude-opus-4-8', usageArr(50, 2, 500, 1000), [['type' => 'thinking', 'thinking' => '']], null),
        asstRecord('2026-01-01T00:10:01Z', 'msg_A', 'claude-opus-4-8', usageArr(50, 2, 500, 1000), [['type' => 'text', 'text' => 'hi']], null),
        asstRecord('2026-01-01T00:10:02Z', 'msg_A', 'claude-opus-4-8', usageArr(50, 100, 500, 1000), [toolUse('Read')], 'tool_use'),

        // An errored tool_result (user turn).
        [
            'type' => 'user',
            'timestamp' => '2026-01-01T00:10:03Z',
            'uuid' => 'u1',
            'message' => ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'T1', 'is_error' => true, 'content' => 'boom'],
            ]],
        ],

        // Second turn.
        asstRecord('2026-01-01T00:20:00Z', 'msg_B', 'claude-opus-4-8', usageArr(10, 30, 2000, 0), [toolUse('Bash')], 'tool_use'),
    ];
}

function subagentRecords(): array
{
    return [
        asstRecord('2026-01-01T00:15:00Z', 'msg_S', 'claude-haiku-4-5-20251001', usageArr(5, 20, 0, 0), [toolUse('WebFetch')], 'tool_use'),
    ];
}

test('TranscriptMetrics takes terminal usage per message, windows by time, and prices per model', function () {
    $dir = sys_get_temp_dir().'/dispatch-metrics-'.uniqid();
    $main = $dir.'/sess.jsonl';
    $sub = $dir.'/sess/subagents/agent-1.jsonl';
    writeJsonl($main, mainRecords());
    writeJsonl($sub, subagentRecords());

    $svc = new TranscriptMetrics(config('dispatch.metrics.pricing'));
    $m = $svc->summarize(
        [['path' => $main, 'subagent' => false], ['path' => $sub, 'subagent' => true]],
        strtotime('2026-01-01T00:00:00Z'),
        strtotime('2026-01-01T01:00:00Z'),
    );

    // Tokens: msg_A terminal (in50/out100/cr500/cc1000) + msg_B (10/30/2000/0)
    // + subagent msg_S (5/20/0/0). The OUT record and the growing snapshots are excluded.
    expect($m['tokens']['input'])->toBe(65)
        ->and($m['tokens']['output'])->toBe(150)   // 100 + 30 + 20, NOT 2+2+100+...
        ->and($m['tokens']['cache_read'])->toBe(2500)
        ->and($m['tokens']['cache_creation'])->toBe(1000)
        ->and($m['tokens']['total'])->toBe(3715);

    expect($m['turns'])->toBe(3)
        ->and($m['tool_calls'])->toBe(3)          // Read, Bash, WebFetch (Grep is out of window)
        ->and($m['tools'])->toHaveKeys(['Read', 'Bash', 'WebFetch'])
        ->and($m['tools'])->not->toHaveKey('Grep')
        ->and($m['subagents'])->toBe(1)
        ->and($m['errors'])->toBe(1)
        ->and($m['models'])->toContain('claude-opus-4-8')
        ->and($m['models'])->toContain('claude-haiku-4-5-20251001');

    // cost: opus (60*5 + 130*25 + 2500*0.5 + 1000*6.25)/1e6 = 0.01105
    //     + haiku (5*1 + 20*5)/1e6 = 0.000105  => 0.011155 -> 0.0112
    expect($m['cost_usd'])->toBe(0.0112)
        ->and($m['cost_partial'])->toBeFalse();

    // cache_hit_ratio = 2500 / (65 + 2500 + 1000) = ~0.7013
    expect((int) round($m['tokens']['cache_hit_ratio'] * 100))->toBe(70);

    array_map('unlink', glob($dir.'/sess/subagents/*'));
    unlink($main);
});

test('dispatch:metrics --json discovers subagents from the main path and emits the shape', function () {
    $svc = app(DispatchTaskService::class);
    $task = $svc->create(['title' => 'measure me', 'status' => 'open']);

    $dir = sys_get_temp_dir().'/dispatch-metrics-'.uniqid();
    $main = $dir.'/sess.jsonl';
    writeJsonl($main, mainRecords());
    writeJsonl($dir.'/sess/subagents/agent-1.jsonl', subagentRecords());

    $exit = Artisan::call('dispatch:metrics', [
        'code' => $task->code,
        '--transcript' => $main,
        '--since' => '2026-01-01T00:00:00Z',
        '--until' => '2026-01-01T01:00:00Z',
        '--json' => true,
    ]);
    expect($exit)->toBe(0);

    $out = json_decode(Artisan::output(), true);
    expect($out)->toBeArray()
        ->and($out['tokens']['total'])->toBe(3715)
        ->and($out['subagents'])->toBe(1)
        ->and($out['duration_s'])->toBe(3600)
        ->and($out['window']['basis'])->toBe('since-option')
        ->and($out['transcript']['source'])->toBe('explicit');

    array_map('unlink', glob($dir.'/sess/subagents/*'));
    unlink($main);
});

test('dispatch:metrics --stamp merges into context.result.metrics without clobbering', function () {
    $svc = app(DispatchTaskService::class);
    $task = $svc->create(['title' => 'stamp me', 'status' => 'open']);
    $svc->recordResult($task, ['tests' => 'green'], 'abc1234');

    $dir = sys_get_temp_dir().'/dispatch-metrics-'.uniqid();
    $main = $dir.'/sess.jsonl';
    writeJsonl($main, mainRecords());

    Artisan::call('dispatch:metrics', [
        'code' => $task->code,
        '--transcript' => $main,
        '--since' => '2026-01-01T00:00:00Z',
        '--until' => '2026-01-01T01:00:00Z',
        '--stamp' => true,
    ]);

    $result = $task->fresh()->context['result'];
    expect($result['tests'])->toBe('green')          // pre-existing key preserved
        ->and($result['commit'])->toBe('abc1234')
        ->and($result['metrics']['tokens']['total'])->toBe(3690) // main only, no subagents on this path
        ->and($result['metrics']['tool_calls'])->toBe(2);

    unlink($main);
});

test('dispatch:metrics --note posts an internal summary comment', function () {
    $svc = app(DispatchTaskService::class);
    $task = $svc->create(['title' => 'note me', 'status' => 'open']);

    $dir = sys_get_temp_dir().'/dispatch-metrics-'.uniqid();
    $main = $dir.'/sess.jsonl';
    writeJsonl($main, mainRecords());

    Artisan::call('dispatch:metrics', [
        'code' => $task->code,
        '--transcript' => $main,
        '--since' => '2026-01-01T00:00:00Z',
        '--until' => '2026-01-01T01:00:00Z',
        '--note' => true,
    ]);

    $comment = $task->fresh()->comments()->latest('id')->first();
    expect($comment)->not->toBeNull()
        ->and($comment->is_internal)->toBeTrue()
        ->and($comment->body)->toContain('Agent metrics');

    unlink($main);
});

test('dispatch:metrics computes for a task absent from the local DB (remote flow)', function () {
    $dir = sys_get_temp_dir().'/dispatch-metrics-'.uniqid();
    $main = $dir.'/sess.jsonl';
    writeJsonl($main, mainRecords());

    $exit = Artisan::call('dispatch:metrics', [
        'code' => 'REMOTE-999',           // not in the local DB
        '--transcript' => $main,
        '--since' => '2026-01-01T00:00:00Z',
        '--until' => '2026-01-01T01:00:00Z',
        '--json' => true,
    ]);
    expect($exit)->toBe(0);

    $out = json_decode(Artisan::output(), true);
    expect($out['tokens']['total'])->toBe(3690)
        ->and($out['window']['basis'])->toBe('since-option');

    unlink($main);
});

test('dispatch:metrics --stamp refuses a task absent from the local DB', function () {
    $exit = Artisan::call('dispatch:metrics', [
        'code' => 'REMOTE-999',
        '--stamp' => true,
    ]);
    expect($exit)->toBe(1);
});

test('dispatch:metrics:capture writes the session sidecar', function () {
    $path = sys_get_temp_dir().'/dispatch-sidecar-'.uniqid().'/agent-session.json';
    config(['dispatch.metrics.session_file' => $path]);

    app(DispatchMetricsCapture::class)->write([
        'transcript_path' => '/tmp/x.jsonl',
        'session_id' => 'abc-123',
        'cwd' => '/proj',
    ]);

    expect(is_file($path))->toBeTrue();
    $data = json_decode(file_get_contents($path), true);
    expect($data['transcript_path'])->toBe('/tmp/x.jsonl')
        ->and($data['session_id'])->toBe('abc-123');

    unlink($path);
});

// --- dispatch:done --with-metrics (W4-1/W4-2: the remote-friendly fold) --------

test('dispatch:done --with-metrics folds metrics under context.result.metrics and preserves the summary (status-agnostic)', function () {
    $svc = app(DispatchTaskService::class);
    $task = $svc->create(['title' => 'close me', 'status' => 'in_progress']);

    $dir = sys_get_temp_dir().'/dispatch-metrics-'.uniqid();
    $main = $dir.'/sess.jsonl';
    writeJsonl($main, mainRecords());

    $exit = Artisan::call('dispatch:done', [
        'code' => $task->code,
        '--status' => 'verifying',                 // NOT done — metrics must still fold
        '--commit' => 'def5678',
        '--result' => json_encode(['summary' => 'did the thing', 'tests' => 'green']),
        '--with-metrics' => true,
        '--since' => '2026-01-01T00:00:00Z',
        '--transcript' => $main,
    ]);
    expect($exit)->toBe(0);

    $fresh = $task->fresh();
    expect($fresh->status)->toBe('verifying');
    $result = $fresh->context['result'];
    expect($result['summary'])->toBe('did the thing')          // hand-authored summary preserved
        ->and($result['tests'])->toBe('green')
        ->and($result['commit'])->toBe('def5678')
        ->and($result['metrics']['tokens']['total'])->toBe(3690)   // main-only transcript
        ->and($result['metrics']['window']['basis'])->toBe('since-option');

    unlink($main);
});

test('dispatch:done --with-metrics defaults the window to the local claim time when --since is omitted', function () {
    $svc = app(DispatchTaskService::class);
    $task = $svc->create(['title' => 'claimed then closed', 'status' => 'in_progress']);
    $task->recordEvent(\Sgrjr\Dispatch\Models\TaskComment::EVENT_CLAIMED, null, []);

    $dir = sys_get_temp_dir().'/dispatch-metrics-'.uniqid();
    $main = $dir.'/sess.jsonl';
    writeJsonl($main, mainRecords());

    Artisan::call('dispatch:done', [
        'code' => $task->code,
        '--commit' => 'aaa111',
        '--with-metrics' => true,
        '--transcript' => $main,
    ]);

    $result = $task->fresh()->context['result'];
    expect($result['metrics']['window']['basis'])->toBe('claimed_at');   // defaulted from the claim event

    unlink($main);
});

// --- Re-work accumulation: metrics survive and sum across runs ------------------
//
// A task cycled open → in_progress → verifying → open again gets stamped once
// per run. Before AgentMetrics::accumulate() every stamp CLOBBERED the prior
// object, so the panel showed only the LAST run's window (a 6-minute follow-up
// hiding an hour-long first run) — and a later close without metrics erased
// them entirely.

/** A stamped-shape metrics array for accumulation tests. */
function accMetricsRun(array $overrides = []): array
{
    return array_merge([
        'window' => ['from' => '2026-01-01T00:00:00+00:00', 'to' => '2026-01-01T01:00:00+00:00', 'basis' => 'claimed_at'],
        'duration_s' => 3600,
        'transcript' => ['source' => 'explicit', 'main' => '/a.jsonl', 'subagent_files' => 0],
        'tokens' => ['input' => 100, 'output' => 50, 'cache_read' => 800, 'cache_creation' => 100, 'total' => 1050, 'cache_hit_ratio' => 0.8],
        'cost_usd' => 0.5,
        'cost_partial' => false,
        'turns' => 4,
        'tool_calls' => 6,
        'tools' => ['Read' => 4, 'Bash' => 2],
        'subagents' => 1,
        'errors' => 0,
        'models' => ['claude-opus-4-8'],
        'unpriced_models' => [],
    ], $overrides);
}

test('AgentMetrics::accumulate sums distinct-window runs and recomputes derived fields', function () {
    $second = accMetricsRun([
        'window' => ['from' => '2026-01-02T00:00:00+00:00', 'to' => '2026-01-02T00:07:00+00:00', 'basis' => 'claimed_at'],
        'duration_s' => 420,
        'tokens' => ['input' => 10, 'output' => 5, 'cache_read' => 200, 'cache_creation' => 40, 'total' => 255, 'cache_hit_ratio' => 0.8],
        'cost_usd' => 0.25,
        'turns' => 2,
        'tool_calls' => 3,
        'tools' => ['Bash' => 2, 'Edit' => 1],
        'subagents' => 0,
        'errors' => 1,
        'models' => ['claude-haiku-4-5-20251001'],
    ]);

    $m = AgentMetrics::accumulate(accMetricsRun(), $second);

    expect($m['runs'])->toBe(2)
        ->and($m['duration_s'])->toBe(4020)
        ->and($m['tokens']['total'])->toBe(1305)
        ->and($m['tokens']['cache_read'])->toBe(1000)
        ->and($m['tokens']['cache_hit_ratio'])->toBe(0.8)   // 1000 / (110 + 1000 + 140)
        ->and($m['cost_usd'])->toBe(0.75)
        ->and($m['turns'])->toBe(6)
        ->and($m['tool_calls'])->toBe(9)
        ->and($m['tools'])->toBe(['Read' => 4, 'Bash' => 4, 'Edit' => 1])   // stable arsort: ties keep insertion order
        ->and($m['subagents'])->toBe(1)
        ->and($m['errors'])->toBe(1)
        ->and($m['models'])->toBe(['claude-opus-4-8', 'claude-haiku-4-5-20251001'])
        ->and($m['window']['from'])->toBe('2026-01-01T00:00:00+00:00')
        ->and($m['window']['to'])->toBe('2026-01-02T00:07:00+00:00')
        ->and($m['window']['basis'])->toBe('accumulated');

    // A third run keeps counting.
    $third = accMetricsRun(['window' => ['from' => '2026-01-03T00:00:00+00:00', 'to' => '2026-01-03T00:10:00+00:00', 'basis' => 'claimed_at']]);
    expect(AgentMetrics::accumulate($m, $third)['runs'])->toBe(3);
});

test('AgentMetrics::accumulate replaces on a same-window re-stamp (no double count)', function () {
    // Same window.from = a RE-computation of the same run (--stamp mid-run,
    // then done --with-metrics at close): latest wins, nothing is summed.
    $recompute = accMetricsRun([
        'tokens' => ['input' => 120, 'output' => 60, 'cache_read' => 900, 'cache_creation' => 120, 'total' => 1200, 'cache_hit_ratio' => 0.79],
        'cost_usd' => 0.6,
    ]);

    $m = AgentMetrics::accumulate(accMetricsRun(), $recompute);

    expect($m['tokens']['total'])->toBe(1200)
        ->and($m['cost_usd'])->toBe(0.6)
        ->and($m['window']['basis'])->toBe('claimed_at')
        ->and($m)->not->toHaveKey('runs');
});

test('recordResult keeps prior metrics when a later close carries none, and sums when it does', function () {
    $svc = app(DispatchTaskService::class);
    $task = $svc->create(['title' => 're-worked task', 'status' => 'open']);

    // Run 1 closes with metrics.
    $svc->recordResult($task, ['summary' => 'run 1', 'metrics' => accMetricsRun()], 'sha1');

    // A follow-up close WITHOUT metrics must not erase them.
    $svc->recordResult($task, ['summary' => 'human follow-up'], 'sha2');
    $result = $task->fresh()->context['result'];
    expect($result['summary'])->toBe('human follow-up')
        ->and($result['commit'])->toBe('sha2')
        ->and($result['metrics']['tokens']['total'])->toBe(1050);

    // Run 2 (new claim window) closes with metrics → summed, not clobbered.
    $svc->recordResult($task, ['summary' => 'run 2', 'metrics' => accMetricsRun([
        'window' => ['from' => '2026-01-05T00:00:00+00:00', 'to' => '2026-01-05T00:06:07+00:00', 'basis' => 'claimed_at'],
        'duration_s' => 367,
    ])], 'sha3');
    $result = $task->fresh()->context['result'];
    expect($result['metrics']['runs'])->toBe(2)
        ->and($result['metrics']['duration_s'])->toBe(3967)     // NOT just the 6m7s follow-up
        ->and($result['metrics']['tokens']['total'])->toBe(2100)
        ->and($result['metrics']['window']['basis'])->toBe('accumulated');
});

test('dispatch:metrics --stamp accumulates across re-claim windows', function () {
    $svc = app(DispatchTaskService::class);
    $task = $svc->create(['title' => 'stamped twice', 'status' => 'open']);

    $dir = sys_get_temp_dir().'/dispatch-metrics-'.uniqid();
    $main = $dir.'/sess.jsonl';
    writeJsonl($main, mainRecords());

    // Run 1: the first claim's window (captures msg_A only).
    Artisan::call('dispatch:metrics', [
        'code' => $task->code,
        '--transcript' => $main,
        '--since' => '2026-01-01T00:00:00Z',
        '--until' => '2026-01-01T00:15:00Z',
        '--stamp' => true,
    ]);

    // Run 2 after a re-claim: a disjoint later window (captures msg_B only).
    Artisan::call('dispatch:metrics', [
        'code' => $task->code,
        '--transcript' => $main,
        '--since' => '2026-01-01T00:15:00Z',
        '--until' => '2026-01-01T01:00:00Z',
        '--stamp' => true,
    ]);

    $m = $task->fresh()->context['result']['metrics'];
    expect($m['runs'])->toBe(2)
        ->and($m['tokens']['total'])->toBe(3690)     // msg_A (1650) + msg_B (2040)
        ->and($m['duration_s'])->toBe(3600)          // 900 + 2700
        ->and($m['window']['basis'])->toBe('accumulated')
        ->and($m['window']['from'])->toBe('2026-01-01T00:00:00+00:00')
        ->and($m['window']['to'])->toBe('2026-01-01T01:00:00+00:00');

    unlink($main);
});
