<?php

use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/**
 * Exercises the F7 `dispatch:edit` and F5 `dispatch:merge` CLI verbs.
 */

test('dispatch:edit memorializes the old description before applying the new one', function () {
    $service = app(DispatchTaskService::class);
    $task = $service->create(['title' => 'Editable task', 'description' => 'Old body']);

    $this->artisan('dispatch:edit', [
        'code' => $task->code,
        '--description' => 'New body',
    ])->assertOk();

    $fresh = $task->fresh();
    expect($fresh->description)->toBe('New body');

    $memorial = $fresh->comments()->where('event_type', TaskComment::EVENT_DESCRIPTION_EDITED)->first();
    expect($memorial)->not->toBeNull();
    expect($memorial->body)->toBe('Old body');
    expect($memorial->is_internal)->toBeTrue();
    expect($memorial->meta)->toBe(['source' => 'cli']);
});

test('dispatch:edit leaves the description (and its history) untouched when --description is omitted', function () {
    $service = app(DispatchTaskService::class);
    $task = $service->create(['title' => 'Untouched description', 'description' => 'Stays the same']);

    $this->artisan('dispatch:edit', [
        'code' => $task->code,
        '--title' => 'Renamed task',
        '--due' => '2026-08-01',
    ])->assertOk();

    $fresh = $task->fresh();
    expect($fresh->title)->toBe('Renamed task');
    expect($fresh->description)->toBe('Stays the same');
    expect($fresh->due_at?->toDateString())->toBe('2026-08-01');
    expect($fresh->comments()->where('event_type', TaskComment::EVENT_DESCRIPTION_EDITED)->exists())->toBeFalse();
});

test('dispatch:edit clears the due date when --due is passed an empty string', function () {
    $service = app(DispatchTaskService::class);
    $task = $service->create(['title' => 'Has a due date', 'due_at' => now()->addWeek()]);

    $this->artisan('dispatch:edit', [
        'code' => $task->code,
        '--due' => '',
    ])->assertOk();

    expect($task->fresh()->due_at)->toBeNull();
});

test('dispatch:edit fails clearly for an unknown code', function () {
    $this->artisan('dispatch:edit', [
        'code' => 'TASK-DOES-NOT-EXIST',
        '--title' => 'Nope',
    ])->assertFailed();
});

test('dispatch:merge folds a loser task into a winner task', function () {
    $service = app(DispatchTaskService::class);
    $winner = $service->create(['title' => 'Winner task']);
    $loser = $service->create(['title' => 'Loser task']);

    $comment = $loser->comments()->create([
        'user_id' => null,
        'body' => 'Duplicate report',
        'event_type' => TaskComment::EVENT_COMMENT,
    ]);

    $this->artisan('dispatch:merge', [
        'loser' => $loser->code,
        'winner' => $winner->code,
    ])->assertOk();

    expect($comment->fresh()->task_id)->toBe($winner->id);

    $freshLoser = Task::withTrashed()->find($loser->id);
    expect($freshLoser->trashed())->toBeTrue();
    expect($freshLoser->duplicate_of)->toBe($winner->id);
    expect($freshLoser->status)->toBe('declined');

    $freshWinner = $winner->fresh();
    expect($freshWinner->comments()->where('event_type', TaskComment::EVENT_MERGED)->exists())->toBeTrue();
});

test('dispatch:merge refuses to merge a task into itself', function () {
    $service = app(DispatchTaskService::class);
    $task = $service->create(['title' => 'Solo task']);

    $this->artisan('dispatch:merge', [
        'loser' => $task->code,
        'winner' => $task->code,
    ])->assertFailed();
});

test('dispatch:merge fails clearly when a code does not exist', function () {
    $service = app(DispatchTaskService::class);
    $winner = $service->create(['title' => 'Real winner']);

    $this->artisan('dispatch:merge', [
        'loser' => 'TASK-DOES-NOT-EXIST',
        'winner' => $winner->code,
    ])->assertFailed();
});
