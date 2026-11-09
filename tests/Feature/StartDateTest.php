<?php

use Illuminate\Support\Facades\Artisan;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchBatchService;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\TaskPresenter;

/*
 * PU-2.12 (TASK-1060) — a task has a START date: "not before". With due_at
 * ("needed by") it makes scheduling honest. A task scheduled for later is not
 * in anyone's pile yet — next / claim / queue skip it, and so does the census
 * (R30: it surfaces in the agent queue on its day) — while claim-by-code still
 * hands it over and `queue --scheduled=only` lists what is waiting.
 */

beforeEach(function () {
    dispatchFakeUsers();
    config(['dispatch.agent.remote.token_path' => sys_get_temp_dir().'/dispatch-start-test-'.uniqid().'.json']);
});

function startTasks(): array
{
    $svc = app(DispatchTaskService::class);

    return [
        'now' => $svc->create(['title' => 'no start date', 'status' => 'open']),
        'started' => $svc->create(['title' => 'started yesterday', 'status' => 'open', 'starts_at' => now()->subDay()]),
        'later' => $svc->create(['title' => 'starts in five days', 'status' => 'open', 'starts_at' => now()->addDays(5)]),
    ];
}

test('started() keeps undated and arrived tasks; scheduled() is the rest', function () {
    $t = startTasks();

    expect(Task::query()->started()->pluck('code')->all())->toContain($t['now']->code, $t['started']->code)
        ->and(Task::query()->started()->pluck('code')->all())->not->toContain($t['later']->code)
        ->and(Task::query()->scheduled()->pluck('code')->all())->toBe([$t['later']->code])
        ->and($t['later']->isScheduled())->toBeTrue()
        ->and($t['started']->isScheduled())->toBeFalse();
});

test('the queue and its census leave scheduled work out; --scheduled=only lists it, =include lists both', function () {
    $t = startTasks();
    $svc = app(DispatchTaskService::class);

    expect($svc->queueQuery()->pluck('code')->all())->not->toContain($t['later']->code)
        ->and($svc->queueCensus()['total'])->toBe(2)
        ->and($svc->queueQuery(['scheduled' => 'only'])->pluck('code')->all())->toBe([$t['later']->code])
        ->and($svc->queueQuery(['scheduled' => 'include'])->count())->toBe(3);

    Artisan::call('dispatch:queue', ['--scheduled' => 'only', '--json' => true]);
    expect(collect(json_decode(Artisan::output(), true))->pluck('code')->all())->toBe([$t['later']->code]);
});

test('next and claim never offer a task before its start date — naming it by code still hands it over', function () {
    $svc = app(DispatchTaskService::class);
    $later = $svc->create(['title' => 'only a scheduled task', 'status' => 'open', 'priority' => 'blocker', 'starts_at' => now()->addDays(2)]);

    expect($svc->nextCandidate())->toBeNull()
        ->and($svc->claim())->toBeNull();

    $claimed = $svc->claim(code: $later->code);
    expect($claimed?->code)->toBe($later->code)
        ->and($claimed->status)->toBe('in_progress');
});

test('a task whose start date has arrived is offered like any other', function () {
    $svc = app(DispatchTaskService::class);
    $task = $svc->create(['title' => 'starts soon', 'status' => 'open', 'starts_at' => now()->addHour()]);
    expect($svc->nextCandidate())->toBeNull();

    $this->travel(2)->hours();

    expect($svc->nextCandidate()?->code)->toBe($task->code);
});

test('dispatch:add --start sets it; dispatch:done --start sets, keeps or clears it, and a change is on the timeline', function () {
    Artisan::call('dispatch:add', ['title' => 'start me later', '--start' => '2030-01-15', '--json' => true]);
    $task = Task::query()->where('title', 'start me later')->firstOrFail();
    expect($task->starts_at->toDateString())->toBe('2030-01-15');

    Artisan::call('dispatch:done', ['code' => $task->code, '--status' => 'open', '--start' => '2030-02-01']);
    expect($task->fresh()->starts_at->toDateString())->toBe('2030-02-01')
        ->and($task->comments()->where('event_type', TaskComment::EVENT_COMMENT)->pluck('body')->all())->toContain('Start date set to 2030-02-01.');

    Artisan::call('dispatch:done', ['code' => $task->code, '--status' => 'triage']);
    expect($task->fresh()->starts_at->toDateString())->toBe('2030-02-01'); // absent flag = untouched

    Artisan::call('dispatch:done', ['code' => $task->code, '--status' => 'open', '--start' => '']);
    expect($task->fresh()->starts_at)->toBeNull()
        ->and($task->comments()->pluck('body')->all())->toContain('Start date cleared.');

    expect(Artisan::call('dispatch:add', ['title' => 'bad', '--start' => 'not a date']))->toBe(1);
});

test('batch: add sets starts_at, update is tri-state, and a bad date fails the whole manifest', function () {
    $out = app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'ref' => 's', 'title' => 'batch scheduled', 'starts_at' => '2030-03-01'],
    ]);
    $task = Task::query()->where('code', $out['results'][0]['code'])->firstOrFail();
    expect($task->starts_at->toDateString())->toBe('2030-03-01');

    app(DispatchBatchService::class)->apply([['code' => $task->code, 'title' => 'renamed']]);
    expect($task->fresh()->starts_at->toDateString())->toBe('2030-03-01');

    app(DispatchBatchService::class)->apply([['code' => $task->code, 'starts_at' => null]]);
    expect($task->fresh()->starts_at)->toBeNull()
        ->and($task->comments()->pluck('body')->all())->toContain('Start date cleared.');

    $before = Task::count();
    expect(fn () => app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'title' => 'fine'],
        ['op' => 'add', 'title' => 'broken', 'starts_at' => 'whenever'],
    ]))->toThrow(InvalidArgumentException::class);
    expect(Task::count())->toBe($before);
});

test('the JSON shape and the schema carry starts_at', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'starts_at' => '2030-04-01']);

    expect(TaskPresenter::toArray($task))->toHaveKey('starts_at')
        ->and(TaskPresenter::toArray($task)['starts_at'])->toStartWith('2030-04-01');
});
