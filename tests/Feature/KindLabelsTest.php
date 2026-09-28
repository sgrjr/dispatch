<?php

use Illuminate\Support\Facades\Schema;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/*
 * TASK-1018 (R14) — `type` retires into `kind:*` labels. There is no column;
 * `type` survives one release as an attribute derived from the label, and a
 * `type` write lands as the label on save.
 */

beforeEach(fn () => dispatchFakeUsers());

function kindTask(array $attributes = []): Task
{
    return app(DispatchTaskService::class)->create(['title' => 'Kind test '.uniqid()] + $attributes);
}

test('the type column is gone', function () {
    expect(Schema::hasColumn('dispatch_tasks', 'type'))->toBeFalse();
});

test('a type written on create becomes the kind:* label, and reads back as type', function () {
    $task = kindTask(['type' => 'chore'])->fresh();

    expect($task->type)->toBe('chore')
        ->and($task->labels->pluck('name')->all())->toContain('kind:chore');
});

test('changing the type swaps the kind label; clearing it removes the label', function () {
    $task = kindTask(['type' => 'bug']);

    $task->type = 'debt';
    $task->save();
    expect($task->fresh()->labels->pluck('name')->all())->toContain('kind:debt')->not->toContain('kind:bug');

    $task->type = null;
    $task->save();
    expect($task->fresh()->type)->toBeNull()
        ->and($task->fresh()->labels->pluck('name')->filter(fn ($n) => str_starts_with($n, 'kind:'))->all())->toBe([]);
});

test('a kind:* label outside the vocabulary is an ordinary tag, not a kind', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'Refactor'], ['kind:refactor']);

    expect($task->fresh()->type)->toBeNull()
        ->and($task->fresh()->labels->pluck('name')->all())->toContain('kind:refactor');
});

test('--type is shorthand for the kind:* label filter', function () {
    $bug = kindTask(['type' => 'bug', 'status' => 'open']);
    $feature = kindTask(['type' => 'feature', 'status' => 'open']);

    $this->artisan('dispatch:queue', ['--type' => 'bug', '--json' => true, '--local' => true])->assertSuccessful();
    $codes = Task::query()->ofKind('bug')->pluck('code')->all();

    expect($codes)->toContain($bug->code)->not->toContain($feature->code);
});
