<?php

use Illuminate\Support\Facades\Schema;
use Sgrjr\Dispatch\Services\DispatchTaskService;

test('the watchers migration creates the dispatch_task_watchers table', function () {
    expect(Schema::hasTable('dispatch_task_watchers'))->toBeTrue();
});

test('a task can be watched and unwatched', function () {
    dispatchMakeUser(7);

    $task = app(DispatchTaskService::class)->create(['title' => 'Watch me']);

    expect($task->isWatchedBy(7))->toBeFalse();

    $task->watch(7);

    expect($task->isWatchedBy(7))->toBeTrue();
    expect($task->watchers()->count())->toBe(1);

    // Watching again is idempotent (syncWithoutDetaching).
    $task->watch(7);
    expect($task->watchers()->count())->toBe(1);

    $task->unwatch(7);

    expect($task->isWatchedBy(7))->toBeFalse();
    expect($task->watchers()->count())->toBe(0);
});
