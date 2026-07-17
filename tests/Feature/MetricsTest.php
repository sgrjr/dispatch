<?php

use Illuminate\Support\Facades\Artisan;
use Sgrjr\Dispatch\Console\Commands\DispatchMetricsCapture;
use Sgrjr\Dispatch\Services\DispatchTaskService;
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
