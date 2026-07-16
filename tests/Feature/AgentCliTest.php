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
            'labels', 'due_at', 'dedupe_key', 'submitter', 'assignee',
            'created_at', 'updated_at',
        ]);
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
