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

test('watch preferences persist on the pivot and decode through watchPreferencesFor (W13-1)', function () {
    dispatchMakeUser(7);

    $task = app(DispatchTaskService::class)->create(['title' => 'Watch me closely']);

    // A bare watch = the every-update default.
    $task->watch(7);
    expect($task->watchPreferencesFor(7))->toBe(['notify_on' => 'any', 'statuses' => null]);

    // Narrow to specific status transitions.
    $task->setWatchPreferences(7, \Sgrjr\Dispatch\Models\Task::WATCH_STATUS_CHANGE, ['verifying', 'done']);
    expect($task->watchPreferencesFor(7))->toBe(['notify_on' => 'status_change', 'statuses' => ['verifying', 'done']]);

    // Re-watching WITHOUT preferences never clobbers a stored choice.
    $task->watch(7);
    expect($task->watchPreferencesFor(7)['notify_on'])->toBe('status_change');

    // Resetting to 'any' clears both columns.
    $task->setWatchPreferences(7, \Sgrjr\Dispatch\Models\Task::WATCH_ANY);
    expect($task->watchPreferencesFor(7))->toBe(['notify_on' => 'any', 'statuses' => null]);
});

test('setWatchPreferences is a no-op for a non-watcher, and watchPreferencesFor returns null (W13-1)', function () {
    dispatchMakeUser(7);

    $task = app(DispatchTaskService::class)->create(['title' => 'Unwatched']);

    $task->setWatchPreferences(7, \Sgrjr\Dispatch\Models\Task::WATCH_STATUS_CHANGE, ['done']);

    expect($task->isWatchedBy(7))->toBeFalse();
    expect($task->watchPreferencesFor(7))->toBeNull();
});
