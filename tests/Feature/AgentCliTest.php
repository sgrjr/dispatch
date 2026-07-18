<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Sgrjr\Dispatch\Models\Task;
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
 * The --remote tests point dispatch.agent.remote.url at a fake host and seed
 * a token file so TalksToAgentApi's requireToken guard doesn't short-circuit
 * before Http::fake ever sees a request.
 */

beforeEach(function () {
    dispatchFakeUsers();

    $tokenPath = sys_get_temp_dir().'/dispatch-agent-cli-test-'.uniqid().'.json';

    config([
        'dispatch.agent.remote.url' => 'https://agent.example.test/api/dispatch/agent',
        'dispatch.agent.remote.token_path' => $tokenPath,
    ]);

    file_put_contents($tokenPath, json_encode(['token' => 'test-remote-token']));
});

afterEach(function () {
    $path = config('dispatch.agent.remote.token_path');
    if (is_string($path) && is_file($path)) {
        @unlink($path);
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
            'labels', 'comment_count', 'due_at', 'dedupe_key', 'submitter', 'assignee',
            'created_at', 'updated_at',
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

    Artisan::call('dispatch:show', ['code' => $task->code]);
    $out = Artisan::output();

    expect($out)->toContain('# Agent run')
        ->and($out)->toContain('11k (72.7% cached)')
        ->and($out)->toContain('duration: 12m 34s')
        ->and($out)->toContain('cost: $0.1234')
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

    $decoded = json_decode(Artisan::output(), true);
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
