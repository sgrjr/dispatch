<?php

use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Services\DispatchBatchService;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/*
 * TASK-1402 — a title longer than the varchar(255) column is cut to exactly
 * 255 characters ending in `…` on EVERY write path. `Str::limit($t, 255, '…')`
 * used to append the ellipsis after 255 characters (256 → MySQL 1406), and the
 * batch-update / edit paths did not truncate at all.
 */

beforeEach(fn () => dispatchFakeUsers());

function expectFitsTitleColumn(?string $title): void
{
    expect(mb_strlen((string) $title, 'UTF-8'))->toBe(Task::TITLE_MAX)
        ->and(mb_substr((string) $title, -1, null, 'UTF-8'))->toBe('…');
}

test('service create cuts a 300-character title to 255 ending in an ellipsis', function () {
    $task = app(DispatchTaskService::class)->create(['title' => str_repeat('a', 300)]);

    expectFitsTitleColumn($task->fresh()->title);
});

test('dispatch:add cuts a 300-character title to 255', function () {
    $this->artisan('dispatch:add', ['title' => str_repeat('b', 300)])->assertOk();

    expectFitsTitleColumn(Task::latest('id')->firstOrFail()->title);
});

test('a batch add op cuts a 300-character title to 255', function () {
    $out = app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'title' => str_repeat('c', 300)],
    ]);

    expectFitsTitleColumn(Task::where('code', $out['results'][0]['code'])->value('title'));
});

test('a batch update op cuts a 300-character title to 255', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'short']);

    app(DispatchBatchService::class)->apply([
        ['op' => 'update', 'code' => $task->code, 'title' => str_repeat('d', 300)],
    ]);

    expectFitsTitleColumn($task->fresh()->title);
});

test('dispatch:edit cuts a 300-character title to 255', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'short']);

    $this->artisan('dispatch:edit', ['code' => $task->code, '--title' => str_repeat('e', 300)])->assertOk();

    expectFitsTitleColumn($task->fresh()->title);
});

test('the model counts characters, not bytes, and leaves a fitting title alone', function () {
    $task = new Task;

    $task->title = '  '.str_repeat('é', 300).'  ';
    expectFitsTitleColumn($task->title);

    $task->title = '  '.str_repeat('f', 255).'  ';
    expect($task->title)->toBe(str_repeat('f', 255));
});
