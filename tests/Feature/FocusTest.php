<?php

use Illuminate\Support\Facades\Artisan;
use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Models\Focus;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Services\AgentSessionService;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/*
 * Focus steering (roadmap W8-2). Gate 1 is behavior PRESERVATION: with no Focus
 * rows the next/claim/queue verbs must return exactly what they did before this
 * layer existed. Gates 3-4 prove steering, contention, and the --no-focus /
 * ?no_focus bypasses; gates 2/5 pin the ordering-identity + storage-rule
 * contracts. Fixtures use the house idiom (the service create() — no factories).
 */

beforeEach(fn () => dispatchFakeUsers());

/**
 * A fixture spanning priorities × open/in_progress/triage, created in a fixed
 * order so id breaks ties deterministically (all positions default to 0).
 *
 * @return array<string,Task>
 */
function focusFixture(): array
{
    $svc = app(DispatchTaskService::class);

    return [
        'triage_blocker' => $svc->create(['title' => 'triage blocker', 'status' => 'triage', 'priority' => 'blocker']),
        'open_high' => $svc->create(['title' => 'open high', 'status' => 'open', 'priority' => 'high']),
        'ip_high' => $svc->create(['title' => 'in-progress high', 'status' => 'in_progress', 'priority' => 'high']),
        'open_medium' => $svc->create(['title' => 'open medium', 'status' => 'open', 'priority' => 'medium']),
        'triage_low' => $svc->create(['title' => 'triage low', 'status' => 'triage', 'priority' => 'low']),
        'ip_low' => $svc->create(['title' => 'in-progress low', 'status' => 'in_progress', 'priority' => 'low']),
    ];
}

/**
 * Request → approve → poll a session through the SERVICE (own inline helper, per
 * the contract — not the shared AgentApiTest one), returning the bearer token.
 */
function focusApiToken(): string
{
    static $approverId = 91000;
    $approverId++;

    $svc = app(AgentSessionService::class);
    $req = $svc->request('claude-focus', 'work the backlog');
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    $svc->approve($session, dispatchMakeUser($approverId)->id, null, null);

    return $svc->poll($req['public_id'], $req['device_code'])['token'];
}

// --- Gate 1 + 2: zero-focus inertness & ordering identity --------------------

test('with no Focus rows, queue returns the exact pre-change priority order', function () {
    $f = focusFixture();

    Artisan::call('dispatch:queue', ['--json' => true]);
    $codes = array_column(json_decode(Artisan::output(), true), 'code');

    // priority rank, then position, then id — status plays NO part in queue.
    expect($codes)->toBe([
        $f['triage_blocker']->code,
        $f['open_high']->code,
        $f['ip_high']->code,
        $f['open_medium']->code,
        $f['triage_low']->code,
        $f['ip_low']->code,
    ]);
});

test('with no Focus rows, next returns the actionable-first top candidate', function () {
    $f = focusFixture();

    Artisan::call('dispatch:next', ['--json' => true]);

    // triage_blocker is the highest priority, but next groups open/in_progress
    // ahead of triage — so the open high task wins.
    expect(json_decode(Artisan::output(), true)['code'])->toBe($f['open_high']->code);
});

test('with no Focus rows, sequential claims drain open+triage in next order, then null', function () {
    $f = focusFixture();
    $svc = app(DispatchTaskService::class);

    // claim takes only open/triage (never in_progress): open group first by
    // priority, then triage group.
    expect($svc->claim()?->code)->toBe($f['open_high']->code)
        ->and($svc->claim()?->code)->toBe($f['open_medium']->code)
        ->and($svc->claim()?->code)->toBe($f['triage_blocker']->code)
        ->and($svc->claim()?->code)->toBe($f['triage_low']->code)
        ->and($svc->claim())->toBeNull();
});

test('a reordered dispatch.workflow.priorities is honored by next and queue (prioritySql, not a hardcoded CASE)', function () {
    // Flip the vocab so `low` outranks `blocker`. The old inline priority CASE
    // ignored config; prioritySql() honors it — this is the latent-bug fix.
    config(['dispatch.workflow.priorities' => ['low', 'medium', 'high', 'blocker']]);

    $svc = app(DispatchTaskService::class);
    $blocker = $svc->create(['title' => 'a blocker', 'status' => 'open', 'priority' => 'blocker']);
    $low = $svc->create(['title' => 'a low', 'status' => 'open', 'priority' => 'low']);

    $queue = $svc->queueQuery()->get()->pluck('code')->all();
    expect($queue)->toBe([$low->code, $blocker->code]);

    // Both open → same actionable group, so priority (reordered) decides next.
    expect($svc->nextCandidate()?->code)->toBe($low->code);
});

// --- Gate 3: steering --------------------------------------------------------

test('an active label-axis Focus steers next/claim to a matching task over a higher-priority non-match', function () {
    $svc = app(DispatchTaskService::class);
    $high = $svc->create(['title' => 'unrelated high', 'status' => 'open', 'priority' => 'high']);
    $billing = $svc->create(['title' => 'billing low', 'status' => 'open', 'priority' => 'low'], ['area:billing']);

    Focus::create(['name' => 'Billing', 'filters' => ['labels' => ['area:billing']], 'is_active' => true]);

    // Steered: the focus-matching low-priority task wins over the higher one.
    expect($svc->nextCandidate()?->code)->toBe($billing->code)
        ->and($svc->claim()?->code)->toBe($billing->code);

    // Un-steered ($applyFocus false): the global priority order returns.
    expect($svc->nextCandidate([], null, false)?->code)->toBe($high->code);

    // (claim already consumed $billing above; assert the un-steered claim path
    // on a fresh service call now picks the high task.)
    expect($svc->claim(null, [], null, null, false)?->code)->toBe($high->code);
});

test('rank orders the focuses: the lower-rank focus is consulted first', function () {
    $svc = app(DispatchTaskService::class);
    $api = $svc->create(['title' => 'api low', 'status' => 'open', 'priority' => 'low'], ['area:api']);
    $svc->create(['title' => 'billing blocker', 'status' => 'open', 'priority' => 'blocker'], ['area:billing']);

    Focus::create(['name' => 'API', 'rank' => 0, 'filters' => ['labels' => ['area:api']], 'is_active' => true]);
    Focus::create(['name' => 'Billing', 'rank' => 1, 'filters' => ['labels' => ['area:billing']], 'is_active' => true]);

    // Rank 0 (API) is tried first and matches, so its low-priority task wins
    // even though the Billing blocker is globally higher priority.
    expect($svc->nextCandidate()?->code)->toBe($api->code);
});

test('a Focus with zero matches falls through to the unsteered base', function () {
    $svc = app(DispatchTaskService::class);
    $task = $svc->create(['title' => 'lonely', 'status' => 'open', 'priority' => 'high']);

    Focus::create(['name' => 'Ghost', 'filters' => ['labels' => ['area:does-not-exist']], 'is_active' => true]);

    expect($svc->nextCandidate()?->code)->toBe($task->code);
});

test('an inactive Focus does not steer', function () {
    $svc = app(DispatchTaskService::class);
    $high = $svc->create(['title' => 'unrelated high', 'status' => 'open', 'priority' => 'high']);
    $svc->create(['title' => 'billing low', 'status' => 'open', 'priority' => 'low'], ['area:billing']);

    Focus::create(['name' => 'Billing', 'filters' => ['labels' => ['area:billing']], 'is_active' => false]);

    expect($svc->nextCandidate()?->code)->toBe($high->code);
});

test('--no-focus (CLI) bypasses steering on next and claim', function () {
    $svc = app(DispatchTaskService::class);
    $high = $svc->create(['title' => 'unrelated high', 'status' => 'open', 'priority' => 'high']);
    $billing = $svc->create(['title' => 'billing low', 'status' => 'open', 'priority' => 'low'], ['area:billing']);

    Focus::create(['name' => 'Billing', 'filters' => ['labels' => ['area:billing']], 'is_active' => true]);

    // Steered (no flag) → the billing task.
    Artisan::call('dispatch:next', ['--json' => true]);
    expect(json_decode(Artisan::output(), true)['code'])->toBe($billing->code);

    // --no-focus → the global-priority top instead.
    Artisan::call('dispatch:next', ['--no-focus' => true, '--json' => true]);
    expect(json_decode(Artisan::output(), true)['code'])->toBe($high->code);

    // claim --no-focus takes the high task too (not the steered billing one).
    Artisan::call('dispatch:claim', ['--no-focus' => true, '--json' => true]);
    expect(dispatchJson(Artisan::output())['code'])->toBe($high->code);
});

test('?no_focus=1 (API) bypasses steering on next and claim', function () {
    $svc = app(DispatchTaskService::class);
    $high = $svc->create(['title' => 'unrelated high', 'status' => 'open', 'priority' => 'high']);
    $billing = $svc->create(['title' => 'billing low', 'status' => 'open', 'priority' => 'low'], ['area:billing']);

    Focus::create(['name' => 'Billing', 'filters' => ['labels' => ['area:billing']], 'is_active' => true]);

    $token = focusApiToken();

    // Steered API next → billing.
    $this->withToken($token)->getJson('api/dispatch/agent/next')
        ->assertOk()->assertJsonPath('task.code', $billing->code);

    // ?no_focus=1 → global top.
    $this->withToken($token)->getJson('api/dispatch/agent/next?no_focus=1')
        ->assertOk()->assertJsonPath('task.code', $high->code);

    // claim with no_focus in the body bypasses steering → the high task.
    $this->withToken($token)->postJson('api/dispatch/agent/claim', ['no_focus' => 1])
        ->assertOk()->assertJsonPath('task.code', $high->code);
});

// --- Gate 4: claim contention / atomicity ------------------------------------

test('two sequential claims under one focus with two matches yield two distinct tasks (no starvation)', function () {
    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'noise', 'status' => 'open', 'priority' => 'blocker']); // non-matching, higher priority
    $svc->create(['title' => 'api one', 'status' => 'open', 'priority' => 'low'], ['area:api']);
    $svc->create(['title' => 'api two', 'status' => 'open', 'priority' => 'low'], ['area:api']);

    Focus::create(['name' => 'API', 'filters' => ['labels' => ['area:api']], 'is_active' => true]);

    $first = $svc->claim();
    $second = $svc->claim();

    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull()
        ->and($first->code)->not->toBe($second->code)
        // both are the focus-matching tasks, never the higher-priority noise
        ->and($first->title)->toContain('api')
        ->and($second->title)->toContain('api');
});

test('claim-by-code returns the named task regardless of active focuses, and still refuses a non-open/triage code', function () {
    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'api match', 'status' => 'open', 'priority' => 'blocker'], ['area:api']);
    $named = $svc->create(['title' => 'named low', 'status' => 'open', 'priority' => 'low']); // no matching label
    $done = $svc->create(['title' => 'already done', 'status' => 'done']);

    Focus::create(['name' => 'API', 'filters' => ['labels' => ['area:api']], 'is_active' => true]);

    // The exact code wins over steering (which would otherwise pick the api task).
    expect($svc->claim(code: $named->code)?->code)->toBe($named->code);

    // The unstarted guard still applies — a done code yields null.
    expect($svc->claim(code: $done->code))->toBeNull();
});

// --- Gate 5: applyTo storage rule --------------------------------------------

test('applyTo constrains only the axes present in filters', function () {
    $svc = app(DispatchTaskService::class);
    $apiFeature = $svc->create(['title' => 'api feature', 'type' => 'feature', 'priority' => 'low'], ['area:api']);
    $apiBug = $svc->create(['title' => 'api bug', 'type' => 'bug', 'priority' => 'high'], ['area:api']);
    $other = $svc->create(['title' => 'no label', 'type' => 'feature', 'priority' => 'high']);

    $focus = Focus::create(['name' => 'API', 'filters' => ['labels' => ['area:api']]]);

    $codes = $focus->applyTo(Task::query())->pluck('code')->all();

    // Only the labels axis is set: both api tasks match (any type/priority),
    // the unlabeled one does not.
    expect($codes)->toContain($apiFeature->code)
        ->and($codes)->toContain($apiBug->code)
        ->and($codes)->not->toContain($other->code);
});

test('applyTo with empty or absent axes is unconstrained', function () {
    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'a']);
    $svc->create(['title' => 'b']);

    $empty = Focus::create(['name' => 'empty', 'filters' => ['labels' => [], 'types' => []]]);
    $none = Focus::create(['name' => 'none', 'filters' => []]);

    expect($empty->applyTo(Task::query())->count())->toBe(2)
        ->and($none->applyTo(Task::query())->count())->toBe(2);
});

test('a multi-axis Focus ANDs its axes (types constrains within the label match)', function () {
    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'api feature', 'type' => 'feature'], ['area:api']);
    $apiBug = $svc->create(['title' => 'api bug', 'type' => 'bug'], ['area:api']);

    $focus = Focus::create(['name' => 'API bugs', 'filters' => ['labels' => ['area:api'], 'types' => ['bug']]]);

    $codes = $focus->applyTo(Task::query())->pluck('code')->all();
    expect($codes)->toBe([$apiBug->code]);
});

test('topActive returns the highest-ranked active focus, or null when none is active', function () {
    expect(Focus::topActive())->toBeNull();

    Focus::create(['name' => 'second', 'rank' => 5, 'is_active' => true]);
    $first = Focus::create(['name' => 'first', 'rank' => 1, 'is_active' => true]);
    Focus::create(['name' => 'parked', 'rank' => 0, 'is_active' => false]);

    expect(Focus::topActive()?->id)->toBe($first->id);
});
