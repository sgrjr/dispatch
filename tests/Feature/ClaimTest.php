<?php

use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/*
 * C1/C2/C4 — the DispatchTaskService agent additions. SQLite :memory: can't
 * exercise real row-lock contention (each connection gets its own DB), so
 * atomicity is proven by the property it buys: claim() never hands out the same
 * task twice. The true-contention gate is an optional MySQL integration test.
 */

function taskSvc(): DispatchTaskService
{
    return app(DispatchTaskService::class);
}

test('claim hands out each actionable task exactly once, in next() order, then null', function () {
    $svc = taskSvc();
    $a = $svc->create(['title' => 'A open high', 'status' => 'open', 'priority' => 'high']);
    $c = $svc->create(['title' => 'C open low', 'status' => 'open', 'priority' => 'low']);
    $b = $svc->create(['title' => 'B triage blocker', 'status' => 'triage', 'priority' => 'blocker']);

    // Open work outranks triage regardless of priority (mirrors dispatch:next).
    expect($svc->claim()->code)->toBe($a->code);
    expect($svc->claim()->code)->toBe($c->code);
    expect($svc->claim()->code)->toBe($b->code);
    expect($svc->claim())->toBeNull();

    foreach ([$a, $c, $b] as $t) {
        expect($t->fresh()->status)->toBe('in_progress');
    }
});

test('claim never re-hands an already in_progress task', function () {
    $svc = taskSvc();
    $svc->create(['title' => 'only', 'status' => 'open']);

    $first = $svc->claim();
    expect($first)->not->toBeNull();
    // The single task is now in_progress; a second claim finds nothing.
    expect($svc->claim())->toBeNull();
});

test('claim respects --type and --label filters', function () {
    $svc = taskSvc();
    $svc->create(['title' => 'a feature', 'status' => 'open', 'type' => 'feature']);
    $bug = $svc->create(['title' => 'a bug', 'status' => 'open', 'type' => 'bug']);

    expect($svc->claim(filters: ['type' => 'bug'])->code)->toBe($bug->code);

    $svc->create(['title' => 'unlabeled', 'status' => 'open']);
    $labeled = $svc->create(['title' => 'labeled', 'status' => 'open'], ['area:api']);

    expect($svc->claim(filters: ['label' => 'area:api'])->code)->toBe($labeled->code);
});

test('claim by code claims that specific task, not the top candidate', function () {
    $svc = taskSvc();
    // A higher-ranked open task would win the next-candidate race...
    $svc->create(['title' => 'top', 'status' => 'open', 'priority' => 'blocker']);
    $target = $svc->create(['title' => 'wanted', 'status' => 'open', 'priority' => 'low']);

    $claimed = $svc->claim(code: $target->code);

    expect($claimed)->not->toBeNull()
        ->and($claimed->code)->toBe($target->code)
        ->and($claimed->status)->toBe('in_progress');
});

test('claim by code ignores type/label filters — the code already picks the task', function () {
    $svc = taskSvc();
    $target = $svc->create(['title' => 'a feature', 'status' => 'open', 'type' => 'feature']);

    // Filters that would EXCLUDE the target must not veto an explicit code.
    $claimed = $svc->claim(filters: ['type' => 'bug', 'label' => 'nope'], code: $target->code);

    expect($claimed?->code)->toBe($target->code);
});

test('claim by code refuses an already in_progress task (never steals in-flight work)', function () {
    $svc = taskSvc();
    $target = $svc->create(['title' => 'busy', 'status' => 'open']);

    // First claim starts it; a second claim by the same code finds nothing.
    expect($svc->claim(code: $target->code)?->code)->toBe($target->code);
    expect($svc->claim(code: $target->code))->toBeNull();
});

test('claim by code returns null for a done task and for a code that does not exist', function () {
    $svc = taskSvc();
    $done = $svc->create(['title' => 'shipped', 'status' => 'done']);

    expect($svc->claim(code: $done->code))->toBeNull()
        ->and($svc->claim(code: 'TASK-999999'))->toBeNull();
});

test('claim stamps agent attribution into the timeline (null user + meta)', function () {
    $svc = taskSvc();
    $svc->create(['title' => 'x', 'status' => 'open']);

    $session = AgentSession::create([
        'public_id' => 'pub-123',
        'agent_name' => 'claude-ci',
        'user_code' => 'ABCDEFGH',
        'poll_secret_hash' => hash('sha256', 'x'),
        'status' => 'approved',
    ]);

    $task = $svc->claim($session, [], null);

    $event = TaskComment::where('task_id', $task->id)
        ->where('event_type', TaskComment::EVENT_CLAIMED)
        ->firstOrFail();

    expect($event->user_id)->toBeNull()
        ->and($event->meta['agent_session_id'])->toBe('pub-123')
        ->and($event->meta['agent_name'])->toBe('claude-ci')
        ->and($task->assignee_user_id)->toBeNull();
});

test('firstOrCreateByKey is idempotent for the same key', function () {
    $svc = taskSvc();

    $first = $svc->firstOrCreateByKey('monitor:disk-full', ['title' => 'Disk full', 'type' => 'bug']);
    $second = $svc->firstOrCreateByKey('monitor:disk-full', ['title' => 'Disk full again', 'type' => 'bug']);

    expect($second->id)->toBe($first->id)
        ->and($first->dedupe_key)->toBe('monitor:disk-full')
        ->and(Task::where('dedupe_key', 'monitor:disk-full')->count())->toBe(1);
});

test('recordResult writes commit + result under context.result', function () {
    $svc = taskSvc();
    $task = $svc->create(['title' => 'ship it', 'status' => 'open']);

    $svc->recordResult($task, ['tests' => 'green', 'notes' => 'done'], 'abc1234');

    $ctx = $task->fresh()->context;
    expect($ctx['result']['commit'])->toBe('abc1234')
        ->and($ctx['result']['tests'])->toBe('green')
        ->and($ctx['result']['notes'])->toBe('done');
});

test('create honors an explicit null submitter (agent principal) without stamping a default', function () {
    $svc = taskSvc();

    $task = $svc->create(['title' => 'agent task', 'submitter_user_id' => null]);

    expect($task->submitter_user_id)->toBeNull();
});
