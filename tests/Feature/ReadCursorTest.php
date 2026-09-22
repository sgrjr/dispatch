<?php

use Livewire\Livewire;
use Sgrjr\Dispatch\Livewire\TaskShow;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Models\TaskRead;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/*
 * TASK-998 — a per-person read cursor on each task, and "new since you
 * looked": any timeline event by SOMEONE ELSE after this person last opened
 * the task. Their own events are never news; the system's always are.
 */

beforeEach(fn () => dispatchFakeUsers());

function newsCodesFor(int $userId): array
{
    return Task::query()->withNewsFor($userId)->orderBy('code')->pluck('code')->all();
}

test('markRead keeps ONE cursor per person per task and moves it forward', function () {
    $me = dispatchMakeUser(401);
    $task = app(DispatchTaskService::class)->create(['title' => 'cursor']);

    app(DispatchTaskService::class)->markRead($task, $me);
    $first = TaskRead::query()->where('task_id', $task->id)->where('user_id', 401)->value('read_at');

    $this->travel(5)->minutes();
    app(DispatchTaskService::class)->markRead($task, $me);

    expect(TaskRead::query()->where('task_id', $task->id)->count())->toBe(1)
        ->and(TaskRead::query()->where('task_id', $task->id)->value('read_at'))->not->toBe($first);
});

test('news is someone else\'s event since I last looked — never my own', function () {
    $me = dispatchMakeUser(402);
    $them = dispatchMakeUser(403);
    $service = app(DispatchTaskService::class);

    $quiet = $service->create(['title' => 'only my own events']);
    $busy = $service->create(['title' => 'someone else wrote']);

    $quiet->recordEvent(TaskComment::EVENT_COMMENT, $me->id, [], 'my note');
    $busy->recordEvent(TaskComment::EVENT_COMMENT, $them->id, [], 'their note');

    // Never looked: someone else's event is news, my own is not.
    expect(newsCodesFor(402))->toBe([$busy->code]);

    // Looking catches me up…
    $this->travel(1)->minutes();
    $service->markRead($busy, $me);
    expect(newsCodesFor(402))->toBe([]);

    // …until someone else moves it again.
    $this->travel(1)->minutes();
    $busy->recordEvent(TaskComment::EVENT_COMMENT, $them->id, [], 'and again');
    expect(newsCodesFor(402))->toBe([$busy->code])
        // The other person wrote it, so for THEM it is not news.
        ->and(newsCodesFor(403))->not->toContain($busy->code);
});

test('a system event (no author) is news for everyone', function () {
    dispatchMakeUser(404);
    $task = app(DispatchTaskService::class)->create(['title' => 'system moved it']);
    $task->recordEvent(TaskComment::EVENT_STATUS_CHANGE, null, ['from' => 'open', 'to' => 'done'], 'closed by the system');

    expect(newsCodesFor(404))->toBe([$task->code]);
});

test('opening the task page moves the viewer\'s cursor', function () {
    $staff = dispatchMakeUser(405);
    $this->actingAs($staff);
    $task = app(DispatchTaskService::class)->create(['title' => 'open me']);
    $task->recordEvent(TaskComment::EVENT_COMMENT, dispatchMakeUser(406)->id, [], 'hello');

    expect(newsCodesFor(405))->toBe([$task->code]);

    $this->travel(1)->minutes();
    Livewire::test(TaskShow::class, ['task' => $task]);

    expect(TaskRead::query()->where('task_id', $task->id)->where('user_id', 405)->exists())->toBeTrue()
        ->and(newsCodesFor(405))->toBe([]);
});
