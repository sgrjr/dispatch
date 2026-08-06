<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/*
 * Exercises the agent-loop CLI surface layered onto the WS-Console verbs:
 * C2 (dispatch:add --key idempotency), C3 (--type/--label filters on
 * next/queue), C4 (dispatch:done --commit/--result/--json), C5 (TaskPresenter
 * everywhere), and the --remote branch on next/queue/show/add/note/done.
 *
 * dispatchFakeUsers() runs first in beforeEach — TaskPresenter (behind every
 * --json path here) resolves the submitter/assignee relations, and Testbench
 * has no App\Models\User by default.
 *
 * The --remote tests point dispatch.agent.remote.url at a fake host, and each
 * one seeds its own token file (seedAgentToken) so TalksToAgentApi's
 * requireToken guard doesn't short-circuit before Http::fake sees a request.
 * The token is deliberately NOT ambient fixture state: under sticky remote a
 * present token flips bare verbs to the remote target, so the local tests
 * here run token-less — exactly the real "no active session" state.
 */

beforeEach(function () {
    dispatchFakeUsers();

    config([
        'dispatch.agent.remote.url' => 'https://agent.example.test/api/dispatch/agent',
        'dispatch.agent.remote.token_path' => sys_get_temp_dir().'/dispatch-agent-cli-test-'.uniqid().'.json',
    ]);
});

afterEach(function () {
    $path = config('dispatch.agent.remote.token_path');
    if (is_string($path)) {
        foreach ([$path, $path.'.dropped'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
});

// --- C2: dispatch:add --key idempotency -----------------------------------

test('dispatch:add --key is idempotent: running it twice yields one task', function () {
    Artisan::call('dispatch:add', [
        'title' => 'Disk full',
        '--type' => 'bug',
        '--key' => 'monitor:disk-full',
    ]);
    $firstOutput = Artisan::output();

    Artisan::call('dispatch:add', [
        'title' => 'Disk full again',
        '--type' => 'bug',
        '--key' => 'monitor:disk-full',
    ]);
    $secondOutput = Artisan::output();

    expect(Task::where('dedupe_key', 'monitor:disk-full')->count())->toBe(1);

    $task = Task::where('dedupe_key', 'monitor:disk-full')->firstOrFail();
    expect($firstOutput)->toContain($task->code)
        ->and($secondOutput)->toContain($task->code)
        // the second call never adopted the second title
        ->and($task->title)->toBe('Disk full');
});

// --- C3: --type / --label filters on next + queue --------------------------

test('dispatch:next --type filters to the given type', function () {
    app(DispatchTaskService::class)->create(['title' => 'a feature', 'status' => 'open', 'type' => 'feature', 'priority' => 'high']);
    $bug = app(DispatchTaskService::class)->create(['title' => 'a bug', 'status' => 'open', 'type' => 'bug', 'priority' => 'low']);

    Artisan::call('dispatch:next', ['--type' => 'bug', '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['code'] ?? null)->toBe($bug->code);
});

test('dispatch:next --label filters to tasks carrying that label', function () {
    app(DispatchTaskService::class)->create(['title' => 'unlabeled', 'status' => 'open']);
    $labeled = app(DispatchTaskService::class)->create(['title' => 'labeled', 'status' => 'open'], ['area:api']);

    Artisan::call('dispatch:next', ['--label' => ['area:api'], '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['code'] ?? null)->toBe($labeled->code);
});

test('dispatch:queue --status, --type and --label all compose (AND)', function () {
    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'bug no label', 'status' => 'open', 'type' => 'bug']);
    $match = $svc->create(['title' => 'bug with label', 'status' => 'open', 'type' => 'bug'], ['area:api']);
    $svc->create(['title' => 'feature with label', 'status' => 'open', 'type' => 'feature'], ['area:api']);
    $svc->create(['title' => 'done bug with label', 'status' => 'done', 'type' => 'bug'], ['area:api']);

    Artisan::call('dispatch:queue', [
        '--status' => 'open',
        '--type' => 'bug',
        '--label' => ['area:api'],
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded)->toHaveCount(1)
        ->and($decoded[0]['code'])->toBe($match->code);
});

test('dispatch:queue --limit caps the rows to the top of the priority order', function () {
    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'low one', 'status' => 'open', 'priority' => 'low']);
    $high = $svc->create(['title' => 'high one', 'status' => 'open', 'priority' => 'high']);
    $mid = $svc->create(['title' => 'mid one', 'status' => 'open', 'priority' => 'medium']);

    Artisan::call('dispatch:queue', ['--limit' => 2, '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded)->toHaveCount(2)
        ->and(array_column($decoded, 'code'))->toBe([$high->code, $mid->code]);
});

test('dispatch:queue --limit rejects a non-positive value', function () {
    app(DispatchTaskService::class)->create(['title' => 'a task', 'status' => 'open']);

    $exit = Artisan::call('dispatch:queue', ['--limit' => '0']);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('--limit must be a positive integer');
});

// --- C4: dispatch:done --commit/--result/--json -----------------------------

test('dispatch:done --commit --result records the result under context.result', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'ship it', 'status' => 'open']);

    $exit = Artisan::call('dispatch:done', [
        'code' => $task->code,
        '--commit' => 'abc1234',
        '--result' => json_encode(['tests' => 'green', 'notes' => 'all good']),
    ]);

    expect($exit)->toBe(0);

    $fresh = $task->fresh();
    expect($fresh->status)->toBe('done')
        ->and($fresh->context['result']['commit'])->toBe('abc1234')
        ->and($fresh->context['result']['tests'])->toBe('green')
        ->and($fresh->context['result']['notes'])->toBe('all good');
});

test('dispatch:done --result rejects invalid JSON with a clear error', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'ship it', 'status' => 'open']);

    $exit = Artisan::call('dispatch:done', [
        'code' => $task->code,
        '--result' => '{not valid json',
    ]);

    expect($exit)->toBe(1)
        ->and($task->fresh()->status)->toBe('open'); // never touched
});

test('dispatch:done --result-file reads the JSON result from a file', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'ship it', 'status' => 'open']);

    $path = sys_get_temp_dir().'/dispatch-result-'.uniqid().'.json';
    file_put_contents($path, json_encode(['tests' => 'green', 'notes' => 'from a file']));

    $exit = Artisan::call('dispatch:done', [
        'code' => $task->code,
        '--commit' => 'abc1234',
        '--result-file' => $path,
    ]);
    @unlink($path);

    expect($exit)->toBe(0);

    $fresh = $task->fresh();
    expect($fresh->status)->toBe('done')
        ->and($fresh->context['result']['commit'])->toBe('abc1234')
        ->and($fresh->context['result']['tests'])->toBe('green')
        ->and($fresh->context['result']['notes'])->toBe('from a file');
});

test('dispatch:done rejects both --result and --result-file', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'ship it', 'status' => 'open']);

    $exit = Artisan::call('dispatch:done', [
        'code' => $task->code,
        '--result' => json_encode(['a' => 1]),
        '--result-file' => 'anything.json',
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('not both')
        ->and($task->fresh()->status)->toBe('open'); // never touched
});

test('dispatch:done --result-file errors when the file is missing', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'ship it', 'status' => 'open']);

    $exit = Artisan::call('dispatch:done', [
        'code' => $task->code,
        '--result-file' => sys_get_temp_dir().'/dispatch-missing-'.uniqid().'.json',
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('--result-file not found')
        ->and($task->fresh()->status)->toBe('open'); // never touched
});

test('dispatch:done --json emits a TaskPresenter summary', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'ship it', 'status' => 'open'], ['area:api']);

    Artisan::call('dispatch:done', [
        'code' => $task->code,
        '--json' => true,
    ]);

    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['code'])->toBe($task->code)
        ->and($decoded['status'])->toBe('done')
        ->and($decoded['labels'])->toBe(['area:api'])
        ->and(array_keys($decoded))->toEqual([
            'code', 'title', 'type', 'priority', 'status', 'is_public',
            'labels', 'comment_count', 'attachment_count', 'due_at', 'dedupe_key', 'submitter', 'assignee',
            'assignee_group', 'created_at', 'updated_at',
        ]);
});

// --- file / stdin input for the long-text options (note body, add description)

test('dispatch:note --body-file reads the comment body from a file', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'discuss', 'status' => 'open']);

    $path = sys_get_temp_dir().'/dispatch-note-'.uniqid().'.md';
    file_put_contents($path, "Root cause across\nmultiple lines with \"quotes\".");

    $exit = Artisan::call('dispatch:note', [
        'code' => $task->code,
        '--body-file' => $path,
    ]);
    @unlink($path);

    expect($exit)->toBe(0);

    $comment = $task->comments()->latest('id')->first();
    expect($comment->body)->toBe("Root cause across\nmultiple lines with \"quotes\".");
});

test('dispatch:note errors when neither a body argument nor --body-file is given', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'discuss', 'status' => 'open']);

    $exit = Artisan::call('dispatch:note', ['code' => $task->code]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('--body-file')
        ->and($task->comments()->where('event_type', 'comment')->count())->toBe(0);
});

test('dispatch:note rejects both an inline body and --body-file', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'discuss', 'status' => 'open']);

    $exit = Artisan::call('dispatch:note', [
        'code' => $task->code,
        'body' => 'inline',
        '--body-file' => 'somewhere.md',
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('not both');
});

test('dispatch:add --description-file reads the task body from a file', function () {
    $path = sys_get_temp_dir().'/dispatch-desc-'.uniqid().'.md';
    file_put_contents($path, "## Steps\n1. do a\n2. do b");

    $exit = Artisan::call('dispatch:add', [
        'title' => 'long-bodied task',
        '--type' => 'bug',
        '--description-file' => $path,
    ]);
    @unlink($path);

    expect($exit)->toBe(0);

    $task = Task::where('title', 'long-bodied task')->firstOrFail();
    expect($task->description)->toBe("## Steps\n1. do a\n2. do b");
});

test('dispatch:add rejects both --description and --description-file', function () {
    $exit = Artisan::call('dispatch:add', [
        'title' => 'conflicting body',
        '--description' => 'inline',
        '--description-file' => 'somewhere.md',
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('not both')
        ->and(Task::where('title', 'conflicting body')->exists())->toBeFalse();
});

// --- --remote: one case per AGENT API JSON CONTRACT pattern -----------------

test('dispatch:next --remote calls GET next and prints the returned task', function () {
    seedAgentToken();
    Http::fake([
        'agent.example.test/*' => Http::response(['task' => ['code' => 'TASK-900', 'title' => 'remote next']], 200),
    ]);

    $exit = Artisan::call('dispatch:next', ['--remote' => true, '--type' => 'bug']);
    expect($exit)->toBe(0);

    $decoded = json_decode(Artisan::output(), true);
    expect($decoded['code'])->toBe('TASK-900');

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_contains($request->url(), '/api/dispatch/agent/next')
        && str_contains($request->url(), 'type=bug'));

    expect(Task::count())->toBe(0); // never touches the local DB
});

test('dispatch:queue --remote calls GET queue and prints the returned tasks', function () {
    seedAgentToken();
    Http::fake([
        'agent.example.test/*' => Http::response(['tasks' => [
            ['code' => 'TASK-901', 'title' => 'remote task one'],
        ]], 200),
    ]);

    $exit = Artisan::call('dispatch:queue', ['--remote' => true, '--status' => 'open']);
    expect($exit)->toBe(0);

    $decoded = json_decode(Artisan::output(), true);
    expect($decoded)->toHaveCount(1)
        ->and($decoded[0]['code'])->toBe('TASK-901');

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_contains($request->url(), '/api/dispatch/agent/queue')
        && str_contains($request->url(), 'status=open'));
});

test('dispatch:queue --remote forwards --limit as a query param', function () {
    seedAgentToken();
    Http::fake([
        'agent.example.test/*' => Http::response(['tasks' => []], 200),
    ]);

    $exit = Artisan::call('dispatch:queue', ['--remote' => true, '--limit' => 5]);
    expect($exit)->toBe(0);

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_contains($request->url(), '/api/dispatch/agent/queue')
        && str_contains($request->url(), 'limit=5'));
});

// --- W4-4: dispatch:queue --count / W4-8: dispatch:next --status -------------

test('dispatch:queue --count emits totals by status (W4-4)', function () {
    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'o1', 'status' => 'open']);
    $svc->create(['title' => 'o2', 'status' => 'open']);
    $svc->create(['title' => 't1', 'status' => 'triage']);

    Artisan::call('dispatch:queue', ['--count' => true, '--json' => true]);
    $out = json_decode(Artisan::output(), true);

    expect($out['total'])->toBe(3)
        ->and($out['by_status']['open'])->toBe(2)
        ->and($out['by_status']['triage'])->toBe(1);
});

test('dispatch:next --status restricts to a single status (W4-8)', function () {
    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'triage hi', 'status' => 'triage', 'priority' => 'high']);
    $open = $svc->create(['title' => 'open lo', 'status' => 'open', 'priority' => 'low']);

    Artisan::call('dispatch:next', ['--status' => 'open', '--json' => true]);
    $out = json_decode(Artisan::output(), true);

    expect($out['code'])->toBe($open->code);
});

test('dispatch:queue --remote --count forwards count=1 and prints the returned envelope', function () {
    seedAgentToken();
    Http::fake([
        'agent.example.test/*' => Http::response(['total' => 5, 'by_status' => ['open' => 3, 'triage' => 2]], 200),
    ]);

    $exit = Artisan::call('dispatch:queue', ['--remote' => true, '--count' => true, '--json' => true]);
    expect($exit)->toBe(0);

    $out = json_decode(Artisan::output(), true);
    expect($out['total'])->toBe(5)
        ->and($out['by_status']['open'])->toBe(3);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/dispatch/agent/queue')
        && str_contains($request->url(), 'count=1'));
});

test('dispatch:show renders an Agent run section from stamped context.result.metrics', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'agent-worked task']);
    $task->context = ['result' => ['commit' => 'abc1234', 'metrics' => [
        'window' => ['basis' => 'claimed_at'],
        'duration_s' => 754,
        'transcript' => ['source' => 'session-file'],
        'tokens' => ['input' => 1000, 'output' => 500, 'cache_read' => 8000, 'cache_creation' => 1500, 'total' => 11000, 'cache_hit_ratio' => 0.7273],
        'cost_usd' => 0.1234,
        'cost_partial' => false,
        'turns' => 8,
        'tool_calls' => 22,
        'tools' => ['Bash' => 10, 'Read' => 8],
        'subagents' => 2,
        'errors' => 1,
        'models' => ['claude-opus-4-8'],
    ]]];
    $task->save();

    // Pin the touch-time coefficients (code fallbacks cover the rest) so the
    // exact figure survives retunes. Task type is 'feature' by service default:
    // 20 + (10×1.5) + (8×0.5) + (2×5) + (754/60 × 0.15) = 50.885 → ~51m.
    config(['dispatch.metrics.touch_time' => [
        'version' => 'v1',
        'base_minutes' => ['default' => 10, 'feature' => 20],
    ]]);

    Artisan::call('dispatch:show', ['code' => $task->code]);
    $out = Artisan::output();

    expect($out)->toContain('# Agent run')
        ->and($out)->toContain('11k (72.7% cached)')
        ->and($out)->toContain('duration: 12m 34s')
        ->and($out)->toContain('cost: $0.1234')
        ->and($out)->toContain('est. human time (v1): ~51m')
        ->and($out)->toContain('Bash · 10')
        ->and($out)->toContain('claude-opus-4-8')
        ->and($out)->toContain('commit: abc1234');
});

test('dispatch:show omits the Agent run section when no metrics are stamped', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'never worked by an agent']);

    Artisan::call('dispatch:show', ['code' => $task->code]);

    expect(Artisan::output())->not->toContain('# Agent run');
});

test('dispatch:show --remote calls GET show/{code} and prints the returned task', function () {
    seedAgentToken();
    Http::fake([
        'agent.example.test/*' => Http::response(['task' => ['code' => 'TASK-902', 'title' => 'remote show', 'comments' => []]], 200),
    ]);

    $exit = Artisan::call('dispatch:show', ['code' => 'TASK-902', '--remote' => true]);
    expect($exit)->toBe(0);

    $decoded = json_decode(Artisan::output(), true);
    expect($decoded['code'])->toBe('TASK-902');

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_contains($request->url(), '/api/dispatch/agent/show/TASK-902'));
});

test('dispatch:add --remote posts add and prints the returned task without touching the local DB', function () {
    seedAgentToken();
    Http::fake([
        'agent.example.test/*' => Http::response(['task' => ['code' => 'TASK-903', 'title' => 'remote add']], 200),
    ]);

    $exit = Artisan::call('dispatch:add', [
        'title' => 'remote add',
        '--type' => 'bug',
        '--label' => ['area:api'],
        '--key' => 'remote:add:1',
        '--remote' => true,
    ]);
    expect($exit)->toBe(0);

    $decoded = json_decode(Artisan::output(), true);
    expect($decoded['code'])->toBe('TASK-903');

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/api/dispatch/agent/add')
            && $request->data()['title'] === 'remote add'
            && $request->data()['type'] === 'bug'
            && $request->data()['labels'] === ['area:api']
            && $request->data()['key'] === 'remote:add:1';
    });

    expect(Task::count())->toBe(0);
});

test('dispatch:note --remote posts note and prints the task plus comment_id', function () {
    seedAgentToken();
    Http::fake([
        'agent.example.test/*' => Http::response([
            'task' => ['code' => 'TASK-904', 'title' => 'noted'],
            'comment_id' => 55,
        ], 200),
    ]);

    $exit = Artisan::call('dispatch:note', [
        'code' => 'TASK-904',
        'body' => 'found the root cause',
        '--internal' => true,
        '--remote' => true,
    ]);
    expect($exit)->toBe(0);

    $decoded = json_decode(Artisan::output(), true);
    expect($decoded['task']['code'])->toBe('TASK-904')
        ->and($decoded['comment_id'])->toBe(55);

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/api/dispatch/agent/note')
            && $request->data()['code'] === 'TASK-904'
            && $request->data()['body'] === 'found the root cause'
            && $request->data()['internal'] === true;
    });
});

test('dispatch:done --remote posts done and prints the returned task without touching the local DB', function () {
    seedAgentToken();
    Http::fake([
        'agent.example.test/*' => Http::response(['task' => ['code' => 'TASK-905', 'title' => 'done remote', 'status' => 'done']], 200),
    ]);

    $exit = Artisan::call('dispatch:done', [
        'code' => 'TASK-905',
        '--commit' => 'deadbee',
        '--result' => json_encode(['tests' => 'green']),
        '--remote' => true,
    ]);
    expect($exit)->toBe(0);

    // dispatchJson: the metrics tip rides the side-channel after the JSON.
    $decoded = dispatchJson(Artisan::output());
    expect($decoded['code'])->toBe('TASK-905');

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/api/dispatch/agent/done')
            && $request->data()['code'] === 'TASK-905'
            && $request->data()['commit'] === 'deadbee'
            && $request->data()['result']['tests'] === 'green';
    });

    expect(Task::count())->toBe(0);
});

test('dispatch:next --remote fails cleanly when no remote is configured', function () {
    config(['dispatch.agent.remote.url' => null]);

    $exit = Artisan::call('dispatch:next', ['--remote' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('No agent remote configured');
});

// --- sticky remote: an active session token defaults bare verbs to remote ---

test('sticky remote: an active token targets bare verbs at the remote, with a banner', function () {
    seedAgentToken();
    Http::fake([
        'agent.example.test/*' => Http::response(['task' => null], 200),
    ]);

    $exit = Artisan::call('dispatch:next'); // no --remote flag at all
    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('→ remote: https://agent.example.test');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/dispatch/agent/next'));
});

test('sticky remote: --local overrides back to the local DB', function () {
    seedAgentToken();
    Http::fake();
    app(DispatchTaskService::class)->create(['title' => 'local task', 'status' => 'open']);

    Artisan::call('dispatch:queue', ['--local' => true, '--json' => true]);
    $out = dispatchJson(Artisan::output());

    expect($out)->toHaveCount(1)
        ->and($out[0]['title'])->toBe('local task');
    Http::assertNothingSent();
});

test('sticky remote: disabled via config keeps bare verbs local even with a token', function () {
    seedAgentToken();
    config(['dispatch.agent.remote.sticky' => false]);
    Http::fake();

    Artisan::call('dispatch:queue', ['--json' => true]);

    Http::assertNothingSent();
});

test('sticky remote: without a token, bare verbs stay local (no banner)', function () {
    Http::fake();

    Artisan::call('dispatch:queue', ['--json' => true]);

    expect(Artisan::output())->not->toContain('→ remote:');
    Http::assertNothingSent();
});

// --- dropped-session guard: a lost token fails loud, never masquerades --------

test('a mid-run 401 writes the drop marker as it clears the token', function () {
    seedAgentToken();
    Http::fake(['agent.example.test/*' => Http::response('', 401)]);

    $exit = Artisan::call('dispatch:next');
    $out = Artisan::output();
    $tokenPath = config('dispatch.agent.remote.token_path');

    expect($exit)->toBe(1)
        ->and($out)->toContain('revoked or expired')
        ->and($out)->toContain('dispatch:session:refresh')
        ->and(is_file($tokenPath))->toBeFalse()
        ->and(is_file($tokenPath.'.dropped'))->toBeTrue();
});

test('dropped session: bare verbs fail loud instead of silently serving local data', function () {
    file_put_contents(config('dispatch.agent.remote.token_path').'.dropped', json_encode([
        'reason' => 'revoked or expired (agent API returned 401)',
        'at' => '2026-07-21T00:00:00Z',
    ]));
    app(DispatchTaskService::class)->create(['title' => 'local throwaway', 'status' => 'open']);
    Http::fake();

    $exit = Artisan::call('dispatch:queue', ['--json' => true]);
    $out = Artisan::output();

    // The masquerade the guard exists to prevent: local tasks presented as the
    // board would read as "production tasks vanished".
    expect($exit)->toBe(1)
        ->and($out)->toContain('Refusing')
        ->and($out)->toContain('dispatch:session:refresh')
        ->and($out)->not->toContain('local throwaway');
    Http::assertNothingSent();
});

test('dropped session: --local is the explicit override and still works', function () {
    file_put_contents(config('dispatch.agent.remote.token_path').'.dropped', json_encode([
        'reason' => 'session expired', 'at' => '2026-07-21T00:00:00Z',
    ]));
    app(DispatchTaskService::class)->create(['title' => 'local throwaway', 'status' => 'open']);
    Http::fake();

    $exit = Artisan::call('dispatch:queue', ['--local' => true, '--json' => true]);

    expect($exit)->toBe(0)
        ->and(dispatchJson(Artisan::output())[0]['title'])->toBe('local throwaway');
    Http::assertNothingSent();
});

test('dropped session: an explicit --remote surfaces the dropped context on the no-token error', function () {
    file_put_contents(config('dispatch.agent.remote.token_path').'.dropped', json_encode([
        'reason' => 'session revoked', 'at' => '2026-07-21T00:00:00Z',
    ]));
    Http::fake();

    $exit = Artisan::call('dispatch:queue', ['--remote' => true]);
    $out = Artisan::output();

    expect($exit)->toBe(1)
        ->and($out)->toContain('No agent session token')
        ->and($out)->toContain('previous session was dropped')
        ->and($out)->toContain('session revoked');
});

test('dispatch:session:end acknowledges a dropped session and restores local-by-default', function () {
    $markerPath = config('dispatch.agent.remote.token_path').'.dropped';
    file_put_contents($markerPath, json_encode([
        'reason' => 'session expired', 'at' => '2026-07-21T00:00:00Z',
    ]));

    Artisan::call('dispatch:session:end');

    expect(Artisan::output())->toContain('guard cleared')
        ->and(is_file($markerPath))->toBeFalse();

    // Bare verbs are quietly local again — the acknowledged state is the
    // ordinary "no active session" state.
    Http::fake();
    expect(Artisan::call('dispatch:queue', ['--json' => true]))->toBe(0);
    Http::assertNothingSent();
});

test('a 429 on a sticky verb keeps the token and names back-off, not re-commissioning', function () {
    seedAgentToken();
    Http::fake(['agent.example.test/*' => Http::response('Too Many Requests', 429, ['Retry-After' => '30'])]);

    $exit = Artisan::call('dispatch:next');
    $out = Artisan::output();
    $tokenPath = config('dispatch.agent.remote.token_path');

    expect($exit)->toBe(1)
        ->and($out)->toContain('rate-limited')
        ->and($out)->toContain('Retry-After: 30s')
        ->and($out)->toContain('Do NOT re-request')
        ->and(is_file($tokenPath))->toBeTrue()          // token untouched
        ->and(is_file($tokenPath.'.dropped'))->toBeFalse(); // no drop recorded
});

test('sticky remote: a token past its expires_at warns and names the refresh pipeline before the 401 lands', function () {
    file_put_contents(config('dispatch.agent.remote.token_path'), json_encode([
        'token' => 'aging-token',
        'expires_at' => now()->subMinute()->toIso8601String(),
    ]));
    Http::fake(['agent.example.test/*' => Http::response(['task' => null], 200)]);

    $exit = Artisan::call('dispatch:next');
    $out = Artisan::output();

    expect($exit)->toBe(0)
        ->and($out)->toContain('past its expires_at')
        ->and($out)->toContain('dispatch:session:refresh')
        ->and($out)->toContain('→ remote:'); // warn-only — the server stays authoritative
});

// --- W5-2: the --count census zero-fills the non-terminal board --------------

test('dispatch:queue --count zero-fills the non-terminal census incl. verifying (W5-2)', function () {
    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'o1', 'status' => 'open']);
    $svc->create(['title' => 'v1', 'status' => 'verifying']);

    Artisan::call('dispatch:queue', ['--count' => true, '--json' => true]);
    $out = json_decode(Artisan::output(), true);

    expect($out['total'])->toBe(2)
        ->and($out['by_status'])->toBe(['open' => 1, 'in_progress' => 0, 'triage' => 0, 'verifying' => 1]);
});

test('dispatch:queue --count --status=verifying reports that single bucket, zero-filled', function () {
    Artisan::call('dispatch:queue', ['--count' => true, '--status' => 'verifying', '--json' => true]);
    $out = json_decode(Artisan::output(), true);

    expect($out['total'])->toBe(0)
        ->and($out['by_status'])->toBe(['verifying' => 0]);
});

// --- the claim → close bridge: claimed_at + a pre-filled closing command -----

test('dispatch:claim prints claimed_at and the pre-filled closing command', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'bridge me', 'status' => 'open']);

    Artisan::call('dispatch:claim', ['--json' => true]);
    $out = Artisan::output();

    expect(dispatchJson($out)['code'])->toBe($task->code)
        ->and($out)->toContain('claimed_at: ')
        ->and($out)->toContain("dispatch:done {$task->code} --status=<done|verifying>")
        ->and($out)->toContain('--commit=<sha>')
        ->and($out)->toContain('--with-metrics --since="');
});

test('dispatch:done --remote without --with-metrics prints the metrics tip', function () {
    seedAgentToken();
    Http::fake([
        'agent.example.test/*' => Http::response(['task' => ['code' => 'TASK-906', 'status' => 'done']], 200),
    ]);

    Artisan::call('dispatch:done', ['code' => 'TASK-906', '--remote' => true]);

    expect(Artisan::output())->toContain('tip: no metrics on this close');
});

// --- W7-3: done --with-metrics prints an "attached" receipt on the side channel

/**
 * A one-record main transcript (a single terminal assistant snapshot inside the
 * window) — enough for AgentMetrics::collect to locate a transcript and count
 * non-zero tokens. Inlined here rather than leaning on MetricsTest's helpers.
 */
function writeAgentCliTranscript(string $path): void
{
    file_put_contents($path, json_encode([
        'type' => 'assistant',
        'timestamp' => '2026-01-01T00:10:00Z',
        'uuid' => 'acli-1',
        'message' => [
            'role' => 'assistant',
            'id' => 'msg_A',
            'model' => 'claude-opus-4-8',
            'stop_reason' => 'tool_use',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'Read', 'input' => []]],
            'usage' => [
                'input_tokens' => 50, 'output_tokens' => 100,
                'cache_read_input_tokens' => 500, 'cache_creation_input_tokens' => 1000,
            ],
        ],
    ])."\n");
}

test('dispatch:done --with-metrics prints the metrics-attached receipt (local)', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'close with receipt', 'status' => 'in_progress']);

    $main = sys_get_temp_dir().'/dispatch-agentcli-'.uniqid().'.jsonl';
    writeAgentCliTranscript($main);

    $exit = Artisan::call('dispatch:done', [
        'code' => $task->code,
        '--commit' => 'abc1234',
        '--with-metrics' => true,
        '--since' => '2026-01-01T00:00:00Z',
        '--transcript' => $main,
    ]);
    $out = Artisan::output(); // BufferedOutput::fetch() clears — read once.
    @unlink($main);

    expect($exit)->toBe(0)
        ->and($out)->toContain('task metrics attached →')
        ->and($out)->toContain('window basis: since-option');
});

test('dispatch:done --with-metrics with no locatable transcript does NOT print the receipt', function () {
    // Force the locator to find nothing: no --transcript, and the fallback
    // discovery paths point at directories that do not exist (the default root
    // resolves to a real ~/.claude/projects on a dev box, so it must be pinned).
    config([
        'dispatch.metrics.session_file' => sys_get_temp_dir().'/dispatch-nofile-'.uniqid().'.json',
        'dispatch.metrics.transcript_root' => sys_get_temp_dir().'/dispatch-noroot-'.uniqid(),
    ]);

    $task = app(DispatchTaskService::class)->create(['title' => 'no transcript', 'status' => 'in_progress']);

    $exit = Artisan::call('dispatch:done', [
        'code' => $task->code,
        '--commit' => 'abc1234',
        '--with-metrics' => true,
        '--since' => '2026-01-01T00:00:00Z',
        '--project-dir' => sys_get_temp_dir().'/dispatch-noproj-'.uniqid(),
    ]);
    $out = Artisan::output(); // BufferedOutput::fetch() clears — read once.

    expect($exit)->toBe(0)
        ->and($out)->not->toContain('task metrics attached')
        ->and($out)->toContain('No transcript located for --with-metrics');
});

test('dispatch:done --remote --with-metrics prints the receipt and posts result.metrics', function () {
    seedAgentToken();
    Http::fake([
        'agent.example.test/*' => Http::response(['task' => ['code' => 'TASK-907', 'status' => 'done']], 200),
    ]);

    $main = sys_get_temp_dir().'/dispatch-agentcli-'.uniqid().'.jsonl';
    writeAgentCliTranscript($main);

    $exit = Artisan::call('dispatch:done', [
        'code' => 'TASK-907',
        '--commit' => 'deadbee',
        '--with-metrics' => true,
        '--since' => '2026-01-01T00:00:00Z',
        '--transcript' => $main,
        '--remote' => true,
    ]);
    $out = Artisan::output(); // BufferedOutput::fetch() clears — read once.
    @unlink($main);

    expect($exit)->toBe(0)
        ->and($out)->toContain('task metrics attached →');

    // The receipt is a side-channel echo — the posted payload still carries the
    // metrics under result.metrics (the panel's key-path), and stdout is clean JSON.
    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/api/dispatch/agent/done')
            && ($request->data()['result']['metrics']['tokens']['total'] ?? 0) > 0;
    });
});

test('dispatch:claim --json local decodes cleanly with the attachment relations loaded', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'bridge me', 'status' => 'open']);

    Artisan::call('dispatch:claim', ['--json' => true]);
    $out = Artisan::output();

    // The loadMissing() now pulls 'attachments' + 'comments.attachments' too;
    // assert at the relation level (the presenter's `attachments` key contract
    // belongs to the sibling worker) — the JSON must still decode as the claimed task.
    $decoded = dispatchJson($out);
    expect($decoded)->toBeArray()
        ->and($decoded['code'])->toBe($task->code);
});

/*
 * ── 9th-wave surfaces (§18 📦) ────────────────────────────────────────────
 * W9-3 (`note --json`) and W9-2 (`done --label`). Both close the same class of
 * seam: a single-task verb missing a flag its siblings already carry, forcing
 * an agent into a batch manifest — the detour that produced W9-1.
 */

test('dispatch:note --json emits the same {task, comment_id} shape the remote path does (W9-3)', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'note json shape']);

    $exit = Artisan::call('dispatch:note', [
        'code' => $task->code,
        'body' => 'a local comment',
        '--json' => true,
    ]);
    expect($exit)->toBe(0);

    $decoded = dispatchJson(Artisan::output());

    // The point of the flag: the LOCAL path must parse identically to the
    // remote one, so an agent piping every verb needs no per-verb special case.
    expect($decoded)->toBeArray()
        ->and($decoded)->toHaveKeys(['task', 'comment_id'])
        ->and($decoded['task']['code'])->toBe($task->code)
        ->and($decoded['comment_id'])->toBeInt();
});

test('dispatch:done --label attaches labels without replacing existing ones (W9-2)', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'park and tag'], ['keep-me']);

    $exit = Artisan::call('dispatch:done', [
        'code' => $task->code,
        '--status' => 'backburner',
        '--label' => ['rust-api', 'parked'],
    ]);
    expect($exit)->toBe(0);

    $names = $task->fresh()->labels->pluck('name')->sort()->values()->all();

    // Attach-never-replace: the pre-existing label survives the close, and the
    // new ones were auto-created — same semantics as `add --label`.
    expect($names)->toBe(['keep-me', 'parked', 'rust-api'])
        ->and($task->fresh()->status)->toBe('backburner');
});

test('dispatch:done --label posts labels on the remote path too (W9-2)', function () {
    seedAgentToken();
    Http::fake([
        'agent.example.test/*' => Http::response(['task' => ['code' => 'TASK-910', 'title' => 'remote park', 'status' => 'backburner']], 200),
    ]);

    $exit = Artisan::call('dispatch:done', [
        'code' => 'TASK-910',
        '--status' => 'backburner',
        '--label' => ['rust-api'],
        '--remote' => true,
    ]);
    expect($exit)->toBe(0);

    // The remote path is the one that matters: `dispatch:edit` is local-only and
    // `edit` is not an agent verb, so `done` is the ONLY way a remote agent can
    // label a task without a batch manifest.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/dispatch/agent/done')
        && $request->data()['labels'] === ['rust-api']);
});

test('dispatch:done without --label sends no labels key (W9-2)', function () {
    seedAgentToken();
    Http::fake([
        'agent.example.test/*' => Http::response(['task' => ['code' => 'TASK-911', 'status' => 'done']], 200),
    ]);

    Artisan::call('dispatch:done', ['code' => 'TASK-911', '--remote' => true]);

    Http::assertSent(fn ($request) => ! array_key_exists('labels', $request->data()));
});

/*
 * W9-5 — pre-expiry TTL surfacing. The failure this prevents is a HALF-APPLIED
 * close: a note that lands, then a done that 401s, leaving a task with its
 * audit trail but not its status transition.
 */

test('a token nearing expiry warns while there is still time to refresh (W9-5)', function () {
    seedAgentToken();
    // Rewrite the token file with an expiry inside the warning threshold.
    $path = config('dispatch.agent.remote.token_path');
    file_put_contents($path, json_encode([
        'token' => 'test-remote-token',
        'expires_at' => now()->addMinutes(4)->toIso8601String(),
    ]));

    Http::fake(['agent.example.test/*' => Http::response(['task' => ['code' => 'TASK-920']], 200)]);

    Artisan::call('dispatch:show', ['code' => 'TASK-920', '--remote' => true]);
    $out = Artisan::output();

    expect($out)->toContain('session token expires in')
        ->and($out)->toContain('dispatch:session:refresh')
        // the WHY must travel with the warning — this is the half-applied close
        ->and($out)->toContain('half-applies');
});

test('a token with plenty of TTL left stays quiet (W9-5)', function () {
    seedAgentToken();
    $path = config('dispatch.agent.remote.token_path');
    file_put_contents($path, json_encode([
        'token' => 'test-remote-token',
        'expires_at' => now()->addHours(2)->toIso8601String(),
    ]));

    Http::fake(['agent.example.test/*' => Http::response(['task' => ['code' => 'TASK-921']], 200)]);

    Artisan::call('dispatch:show', ['code' => 'TASK-921', '--remote' => true]);

    expect(Artisan::output())->not->toContain('session token expires in');
});

test('an already-expired token still gets the past-expiry notice, not the countdown (W9-5)', function () {
    seedAgentToken();
    $path = config('dispatch.agent.remote.token_path');
    file_put_contents($path, json_encode([
        'token' => 'test-remote-token',
        'expires_at' => now()->subMinutes(3)->toIso8601String(),
    ]));

    Http::fake(['agent.example.test/*' => Http::response(['task' => ['code' => 'TASK-922']], 200)]);

    Artisan::call('dispatch:show', ['code' => 'TASK-922', '--remote' => true]);
    $out = Artisan::output();

    expect($out)->toContain('past its expires_at')
        ->and($out)->not->toContain('session token expires in');
});

test('expiry_warning_minutes=0 disables the countdown (W9-5)', function () {
    config(['dispatch.agent.remote.expiry_warning_minutes' => 0]);
    seedAgentToken();
    $path = config('dispatch.agent.remote.token_path');
    file_put_contents($path, json_encode([
        'token' => 'test-remote-token',
        'expires_at' => now()->addMinutes(2)->toIso8601String(),
    ]));

    Http::fake(['agent.example.test/*' => Http::response(['task' => ['code' => 'TASK-923']], 200)]);

    Artisan::call('dispatch:show', ['code' => 'TASK-923', '--remote' => true]);

    expect(Artisan::output())->not->toContain('session token expires in');
});

/*
 * ── 10th wave (§18 📅) — `--due` on add + done ────────────────────────────
 * These two flags are the ONLY way a remote agent can set a review-by date:
 * `dispatch:edit --due` is local-only and `edit` is not an agent verb, so under
 * a sticky-remote session it would have edited the local dev DB. The flag
 * resolves on THIS box's clock and travels as ISO 8601, so "+3 days" means
 * three days from the agent — not from whenever the server parsed it.
 */

test('dispatch:add --due sets the due date on the new task (W10-1)', function () {
    $exit = Artisan::call('dispatch:add', [
        'title' => 'review by mid-August',
        '--due' => '2026-08-15',
    ]);

    expect($exit)->toBe(0)
        ->and(Task::where('title', 'review by mid-August')->firstOrFail()->due_at?->toDateString())
        ->toBe('2026-08-15');
});

test('dispatch:add --remote sends the resolved due date as ISO 8601 (W10-1)', function () {
    seedAgentToken();
    Http::fake([
        'agent.example.test/*' => Http::response(['task' => ['code' => 'TASK-930', 'title' => 'remote due add']], 200),
    ]);

    $exit = Artisan::call('dispatch:add', [
        'title' => 'remote due add',
        '--due' => '2026-08-15',
        '--remote' => true,
    ]);
    expect($exit)->toBe(0);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/api/dispatch/agent/add')
        && str_starts_with($request->data()['due_at'], '2026-08-15T'));

    expect(Task::count())->toBe(0);
});

test('dispatch:add --due="" is simply no due date — a blank never parses to "now" (W10-1)', function () {
    $exit = Artisan::call('dispatch:add', ['title' => 'blank due', '--due' => '']);

    // `Carbon::parse('')` quietly returns NOW, which is why the clear-sentinel
    // check comes first. Unlike the verbs that EDIT a date, a task being minted
    // has nothing to clear — so blank is simply "unset".
    expect($exit)->toBe(0)
        ->and(Task::where('title', 'blank due')->firstOrFail()->due_at)->toBeNull();
});

test('dispatch:add --remote with a blank --due sends no due_at key (W10-1)', function () {
    seedAgentToken();
    Http::fake([
        'agent.example.test/*' => Http::response(['task' => ['code' => 'TASK-933', 'title' => 'blank due']], 200),
    ]);

    Artisan::call('dispatch:add', ['title' => 'blank due', '--due' => '', '--remote' => true]);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/dispatch/agent/add')
        && ! array_key_exists('due_at', $request->data()));
});

test('dispatch:add --due rejects an unparseable date before any request or write (W10-1)', function () {
    seedAgentToken();
    Http::fake();

    $exit = Artisan::call('dispatch:add', [
        'title' => 'never filed',
        '--due' => 'not-a-real-date',
        '--remote' => true,
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('--due could not be parsed as a date');

    // The whole point of resolving up front: a bad date costs nothing but the
    // error — no request spent, no half-made task.
    Http::assertNothingSent();
    expect(Task::count())->toBe(0);
});

test('dispatch:done --due sets the review-by at close and memorializes it once (W10-2)', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'hand back for review', 'status' => 'in_progress']);

    $exit = Artisan::call('dispatch:done', [
        'code' => $task->code,
        '--status' => 'verifying',
        '--due' => '2026-08-15',
    ]);
    expect($exit)->toBe(0);

    $fresh = $task->fresh();
    expect($fresh->status)->toBe('verifying')
        ->and($fresh->due_at?->toDateString())->toBe('2026-08-15');

    // Word-for-word the Livewire editor's memorial, so the timeline reads the
    // same whether the board or an agent moved the date.
    $events = $fresh->comments()->where('event_type', TaskComment::EVENT_COMMENT)->get();
    expect($events)->toHaveCount(1)
        ->and($events[0]->body)->toBe('Due date set to 2026-08-15.')
        ->and($events[0]->meta['due_at'])->toBe(['from' => null, 'to' => '2026-08-15']);
});

test('dispatch:done --due="" clears the date and memorializes the clear (W10-2)', function () {
    $task = app(DispatchTaskService::class)->create([
        'title' => 'no longer time-boxed', 'status' => 'in_progress', 'due_at' => '2026-08-01',
    ]);

    $exit = Artisan::call('dispatch:done', ['code' => $task->code, '--due' => '']);

    expect($exit)->toBe(0)
        ->and($task->fresh()->due_at)->toBeNull();

    $event = $task->fresh()->comments()->where('event_type', TaskComment::EVENT_COMMENT)->firstOrFail();
    expect($event->body)->toBe('Due date cleared.')
        ->and($event->meta['due_at'])->toBe(['from' => '2026-08-01', 'to' => null]);
});

test('dispatch:done without --due leaves the stored due date untouched (W10-1)', function () {
    $task = app(DispatchTaskService::class)->create([
        'title' => 'keep my date', 'status' => 'in_progress', 'due_at' => '2026-08-01',
    ]);

    $exit = Artisan::call('dispatch:done', ['code' => $task->code]);

    // Absent is not clear — a close that says nothing about the date must not
    // silently blank it.
    expect($exit)->toBe(0)
        ->and($task->fresh()->due_at?->toDateString())->toBe('2026-08-01')
        ->and($task->fresh()->comments()->where('event_type', TaskComment::EVENT_COMMENT)->count())->toBe(0);
});

test('dispatch:done --remote sends ISO for a set and "" for a clear (W10-1)', function () {
    seedAgentToken();
    Http::fake([
        'agent.example.test/*' => Http::response(['task' => ['code' => 'TASK-931', 'status' => 'verifying']], 200),
    ]);

    Artisan::call('dispatch:done', [
        'code' => 'TASK-931', '--status' => 'verifying', '--due' => '2026-08-15', '--remote' => true,
    ]);
    Artisan::call('dispatch:done', ['code' => 'TASK-931', '--due' => '', '--remote' => true]);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/dispatch/agent/done')
        && str_starts_with((string) $request->data()['due_at'], '2026-08-15T'));

    // The empty string is deliberate: it survives the payload's null filter (a
    // null would have been dropped, turning "clear" back into "absent"), and the
    // server reads key-present-blank as the clear it is.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/dispatch/agent/done')
        && array_key_exists('due_at', $request->data())
        && $request->data()['due_at'] === '');
});

test('dispatch:done without --due sends no due_at key (W10-1)', function () {
    seedAgentToken();
    Http::fake([
        'agent.example.test/*' => Http::response(['task' => ['code' => 'TASK-932', 'status' => 'done']], 200),
    ]);

    Artisan::call('dispatch:done', ['code' => 'TASK-932', '--remote' => true]);

    Http::assertSent(fn ($request) => ! array_key_exists('due_at', $request->data()));
});

test('dispatch:done --due rejects an unparseable date before any request or write (W10-1)', function () {
    seedAgentToken();
    Http::fake();

    $task = app(DispatchTaskService::class)->create([
        'title' => 'guarded close', 'status' => 'open', 'due_at' => '2026-08-01',
    ]);

    // Remote first: if the guard ever slipped BEHIND the target resolution, this
    // ordering is the one that catches it (a request would be recorded).
    $remote = Artisan::call('dispatch:done', ['code' => $task->code, '--due' => 'not-a-real-date', '--remote' => true]);
    $local = Artisan::call('dispatch:done', ['code' => $task->code, '--due' => 'not-a-real-date', '--local' => true]);

    expect($remote)->toBe(1)
        ->and($local)->toBe(1);

    Http::assertNothingSent();

    // Not half-closed: the status transition never happened either, because the
    // date is resolved before any write.
    $fresh = $task->fresh();
    expect($fresh->status)->toBe('open')
        ->and($fresh->due_at?->toDateString())->toBe('2026-08-01')
        ->and($fresh->comments()->count())->toBe(0);
});

/*
 * ── W10-3: the target memo must not outlive its invocation ────────────────
 * Artisan resolves ONE command instance per process and reuses it, so
 * TalksToAgentApi's $resolvedRemoteTarget — a within-run device that keeps the
 * sticky banner to one line — leaked across in-process calls: the second
 * `Artisan::call()` of a verb answered with the FIRST call's target and ignored
 * its own --remote/--local. Invisible to a real one-shot CLI, sharp for host
 * code, queued jobs, and the suite. initialize() resets it per run.
 */

test('a second in-process call honors its own --remote after a --local first (W10-3)', function () {
    seedAgentToken();
    Http::fake([
        'agent.example.test/*' => Http::response(['tasks' => [
            ['code' => 'TASK-940', 'title' => 'the remote board'],
        ]], 200),
    ]);
    app(DispatchTaskService::class)->create(['title' => 'local only', 'status' => 'open']);

    Artisan::call('dispatch:queue', ['--local' => true, '--json' => true]);
    Artisan::output(); // BufferedOutput::fetch() clears — drop the first call's

    $exit = Artisan::call('dispatch:queue', ['--remote' => true, '--json' => true]);
    $out = Artisan::output();

    // The memo leak's exact shape: without the per-run reset the second call
    // stayed LOCAL, so it served the dev DB's throwaway task while the caller
    // believed it was reading production — no request, no banner, no signal.
    expect($exit)->toBe(0)
        ->and(dispatchJson($out)[0]['code'])->toBe('TASK-940')
        ->and($out)->not->toContain('local only');

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_contains($request->url(), '/api/dispatch/agent/queue'));
});

test('a second in-process call honors its own --local after a --remote first (W10-3)', function () {
    seedAgentToken();
    Http::fake([
        'agent.example.test/*' => Http::response(['tasks' => []], 200),
    ]);
    app(DispatchTaskService::class)->create(['title' => 'local only', 'status' => 'open']);

    Artisan::call('dispatch:queue', ['--remote' => true, '--json' => true]);
    Artisan::output();

    $exit = Artisan::call('dispatch:queue', ['--local' => true, '--json' => true]);
    $out = Artisan::output();

    // The inverse leak is the costlier direction: an inherited remote would
    // have spent a second production request for a call that asked for the
    // local DB. Exactly one request total — the first call's.
    expect($exit)->toBe(0)
        ->and(dispatchJson($out))->toHaveCount(1)
        ->and(dispatchJson($out)[0]['title'])->toBe('local only');

    Http::assertSentCount(1);
});

test('claim warns when the token cannot outlive a work cycle (W9-5)', function () {
    seedAgentToken();
    $path = config('dispatch.agent.remote.token_path');
    // Outside the 10m banner threshold, inside the 15m work-cycle window — so
    // this asserts the claim-time gate specifically, not the banner.
    file_put_contents($path, json_encode([
        'token' => 'test-remote-token',
        'expires_at' => now()->addMinutes(12)->toIso8601String(),
    ]));

    Http::fake([
        'agent.example.test/*' => Http::response([
            'task' => ['code' => 'TASK-924', 'title' => 'short fuse', 'status' => 'in_progress'],
        ], 200),
    ]);

    Artisan::call('dispatch:claim', ['code' => 'TASK-924', '--remote' => true]);
    $out = Artisan::output();

    expect($out)->toContain('claiming with only')
        ->and($out)->toContain('may not survive to the close');
});
