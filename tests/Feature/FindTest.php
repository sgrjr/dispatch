<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/*
 * `dispatch:find` (W9-7) — the "does this already exist?" verb.
 *
 * The load-bearing property under test is the STATUS DEFAULT: search spans the
 * whole board, including the done/declined/backburner work `queue` hides. A
 * search that silently skipped closed tasks would answer "no, nothing filed" to
 * the one question it exists to answer, which is how already-shipped work gets
 * rebuilt.
 */

beforeEach(function () {
    dispatchFakeUsers();

    config([
        'dispatch.agent.remote.url' => 'https://agent.example.test/api/dispatch/agent',
        'dispatch.agent.remote.token_path' => sys_get_temp_dir().'/dispatch-find-test-'.uniqid().'.json',
    ]);
});

afterEach(function () {
    $path = config('dispatch.agent.remote.token_path');
    if (is_string($path) && is_file($path)) {
        @unlink($path);
    }
});

test('find matches a DONE task — the case queue structurally cannot answer (W9-7)', function () {
    $svc = app(DispatchTaskService::class);
    $done = $svc->create(['title' => 'Rewrite the coupon allocator', 'status' => 'done']);
    $svc->create(['title' => 'Something else entirely']);

    $exit = Artisan::call('dispatch:find', ['term' => 'coupon allocator', '--json' => true]);
    expect($exit)->toBe(0);

    $decoded = json_decode(Artisan::output(), true);

    expect($decoded)->toHaveCount(1)
        ->and($decoded[0]['code'])->toBe($done->code)
        ->and($decoded[0]['status'])->toBe('done');
});

test('find searches the description, not just the title (W9-7)', function () {
    $svc = app(DispatchTaskService::class);
    $task = $svc->create([
        'title' => 'Opaque title that names nothing',
        'description' => 'The failure is in resolveCouponBucket() on PROD_NO 4417.',
    ]);

    Artisan::call('dispatch:find', ['term' => 'resolveCouponBucket', '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    // A duplicate is often recognisable only from the body — a wiring identifier
    // that never made it into the title.
    expect($decoded)->toHaveCount(1)
        ->and($decoded[0]['code'])->toBe($task->code);
});

test('find matches on code (W9-7)', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'findable by code']);

    Artisan::call('dispatch:find', ['term' => $task->code, '--json' => true]);

    expect(json_decode(Artisan::output(), true))->toHaveCount(1);
});

test('find --status narrows to one status (W9-7)', function () {
    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'widget alpha', 'status' => 'done']);
    $open = $svc->create(['title' => 'widget beta', 'status' => 'open']);

    Artisan::call('dispatch:find', ['term' => 'widget', '--status' => 'open', '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded)->toHaveCount(1)
        ->and($decoded[0]['code'])->toBe($open->code);
});

test('find treats LIKE wildcards as literal text (W9-7)', function () {
    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'a 100% real task']);
    $svc->create(['title' => 'unrelated']);

    // An unescaped % would match everything and report a false duplicate.
    Artisan::call('dispatch:find', ['term' => '100%', '--json' => true]);

    expect(json_decode(Artisan::output(), true))->toHaveCount(1);
});

test('find reports an empty result as an answer, not an error (W9-7)', function () {
    app(DispatchTaskService::class)->create(['title' => 'nothing alike']);

    $exit = Artisan::call('dispatch:find', ['term' => 'zzz-no-such-thing']);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('across ALL statuses');
});

test('find rejects an empty term (W9-7)', function () {
    expect(Artisan::call('dispatch:find', ['term' => '   ']))->toBe(1);
});

test('find --remote rides the existing queue scope via ?q= (W9-7)', function () {
    seedAgentToken();
    Http::fake([
        'agent.example.test/*' => Http::response(['tasks' => [
            ['code' => 'TASK-930', 'title' => 'remote match', 'status' => 'done'],
        ]], 200),
    ]);

    $exit = Artisan::call('dispatch:find', ['term' => 'coupon', '--remote' => true, '--json' => true]);
    expect($exit)->toBe(0);

    // No new route and no new scope — an already-commissioned session gains the
    // verb without re-approval.
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/api/dispatch/agent/queue')
            && str_contains($request->url(), 'q=coupon');
    });

    expect(dispatchJson(Artisan::output()))->toHaveCount(1);
});

test('the queue endpoint spans all statuses when ?q= is present (W9-7)', function () {
    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'archived widget', 'status' => 'done']);
    $svc->create(['title' => 'live widget', 'status' => 'open']);

    $tasks = app(DispatchTaskService::class)->searchQuery('widget')->get();

    // Both, not just the actionable one — the inverse of queueQuery's default.
    expect($tasks)->toHaveCount(2)
        ->and($tasks->pluck('status')->sort()->values()->all())->toBe(['done', 'open']);
});

test('searchQuery still honors an explicit status alongside filters (W9-7)', function () {
    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'bug widget', 'type' => 'bug', 'status' => 'done']);
    $svc->create(['title' => 'chore widget', 'type' => 'chore', 'status' => 'done']);

    $tasks = app(DispatchTaskService::class)->searchQuery('widget', ['type' => 'bug'], 'done')->get();

    expect($tasks)->toHaveCount(1)
        ->and($tasks->first()->type)->toBe('bug');
});

test('find treats an underscore as literal text too (W9-7)', function () {
    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'uses snake_case naming']);
    $svc->create(['title' => 'uses camelCase naming']);

    // Unescaped, `e_c` would match both via the single-character wildcard.
    Artisan::call('dispatch:find', ['term' => 'e_c', '--json' => true]);

    expect(json_decode(Artisan::output(), true))->toHaveCount(1);
});
