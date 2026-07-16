<?php

use Illuminate\Support\Facades\Schema;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Services\DispatchTaskService;

test('migrations create the core tables', function () {
    expect(Schema::hasTable('tasks'))->toBeTrue();
    expect(Schema::hasTable('labels'))->toBeTrue();
    expect(Schema::hasTable('task_label'))->toBeTrue();
    expect(Schema::hasTable('task_comments'))->toBeTrue();
    expect(Schema::hasTable('task_attachments'))->toBeTrue();
});

test('mintCode produces sequential prefixed codes', function () {
    $a = Task::createWithCode(['title' => 'A']);
    $b = Task::createWithCode(['title' => 'B']);

    expect($a->code)->toBe('TASK-001');
    expect($b->code)->toBe('TASK-002');
});

test('createWithCode reminting skips past an existing code', function () {
    Task::createWithCode(['title' => 'A']);                       // TASK-001
    Task::createWithCode(['code' => 'TASK-002', 'title' => 'M']); // explicit
    $c = Task::createWithCode(['title' => 'C']);                  // mint sees 002 -> 003

    expect($c->code)->toBe('TASK-003');
});

test('the service applies defaults and attaches labels through the contracts', function () {
    $task = app(DispatchTaskService::class)->create(
        ['title' => 'Widget is broken on the dashboard'],
        ['source:widget'],
    );

    expect($task->type)->toBe('feature');
    expect($task->priority)->toBe('medium');
    expect($task->status)->toBe('triage');
    expect($task->is_public)->toBeFalse();
    expect($task->labels)->toHaveCount(1);
    expect($task->labels->first()->name)->toBe('source:widget');
});

test('capture dedupes recurring errors onto the same task', function () {
    $svc = app(DispatchTaskService::class);

    $first = $svc->capture('boom-signature', ['title' => 'Undefined index']);
    $again = $svc->capture('boom-signature', ['title' => 'Undefined index (repeat)']);

    expect($again->id)->toBe($first->id);
    expect($first->type)->toBe('bug');
    expect($first->comments()->where('event_type', 'exception_occurrence')->count())->toBe(1);
});
