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

test('staff can CC a teammate — immediately watching, memorialized, and notified (W13-2)', function () {
    $staff = dispatchMakeUser(1);
    $teammate = dispatchMakeUser(2);
    $this->actingAs($staff);

    $task = app(DispatchTaskService::class)->create(['title' => 'Entered by staff B', 'priority' => 'blocker']);

    // Fake AFTER create — the creation receipt to the submitter isn't under test.
    \Illuminate\Support\Facades\Notification::fake();

    $component = \Livewire\Livewire::test(\Sgrjr\Dispatch\Livewire\TaskShow::class, ['task' => $task])
        ->set('ccUserId', $teammate->id)
        ->call('addWatcher')
        ->assertHasNoErrors();

    // Immediately a watcher with the every-update default — no opt-in state.
    expect($task->fresh()->isWatchedBy($teammate->id))->toBeTrue();
    expect($task->fresh()->watchPreferencesFor($teammate->id)['notify_on'])->toBe('any');

    // Memorialized internally with actor + watcher in meta.
    $event = $task->fresh()->comments()
        ->where('event_type', \Sgrjr\Dispatch\Models\TaskComment::EVENT_WATCHER_ADDED)
        ->first();
    expect($event)->not->toBeNull();
    expect($event->is_internal)->toBeTrue();
    expect($event->user_id)->toBe($staff->id);
    expect($event->meta['watcher_user_id'])->toBe($teammate->id);

    // The teammate gets the heads-up; the acting staff member does not.
    \Illuminate\Support\Facades\Notification::assertSentTo([$teammate], \Sgrjr\Dispatch\Notifications\TaskUpdate::class);
    \Illuminate\Support\Facades\Notification::assertNotSentTo([$staff], \Sgrjr\Dispatch\Notifications\TaskUpdate::class);

    // The picker resets for the next add.
    expect($component->get('ccUserId'))->toBeNull();
});

test('CC-ing an already-watching or non-assignable user records and sends nothing (W13-2)', function () {
    $staff = dispatchMakeUser(1);
    $teammate = dispatchMakeUser(2, ['email' => 'mate@staff.test']);
    $outsider = dispatchMakeUser(3, ['email' => 'jdoe@cp-missing-email.com']);
    $this->actingAs($staff);

    $task = app(DispatchTaskService::class)->create(['title' => 'CC edge cases']);
    $task->watch($teammate->id);

    // Fake AFTER create — the creation receipt to the submitter isn't under test.
    \Illuminate\Support\Facades\Notification::fake();

    // Already watching → idempotent: no duplicate row, no event, no mail.
    \Livewire\Livewire::test(\Sgrjr\Dispatch\Livewire\TaskShow::class, ['task' => $task])
        ->set('ccUserId', $teammate->id)
        ->call('addWatcher');

    expect($task->fresh()->watchers()->count())->toBe(1);
    expect($task->fresh()->comments()->where('event_type', \Sgrjr\Dispatch\Models\TaskComment::EVENT_WATCHER_ADDED)->count())->toBe(0);
    \Illuminate\Support\Facades\Notification::assertNothingSent();

    // Outside the assignable pool (domain filter on) → rejected with an error.
    config(['dispatch.assignees.email_domains' => ['staff.test']]);

    \Livewire\Livewire::test(\Sgrjr\Dispatch\Livewire\TaskShow::class, ['task' => $task])
        ->set('ccUserId', $outsider->id)
        ->call('addWatcher')
        ->assertHasErrors('ccUserId');

    expect($task->fresh()->isWatchedBy($outsider->id))->toBeFalse();
});
