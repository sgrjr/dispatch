<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Sgrjr\Dispatch\Livewire\TaskBoard;
use Sgrjr\Dispatch\Livewire\TaskList;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/**
 * The `backburner` parked status: triaged and consciously judged not
 * actionable now or anytime soon — or code-done but blocked on an external
 * date — shelved without being declined. Non-terminal and enterable from any
 * status; excluded from the actionable queue/claim/census defaults and from
 * staleness nagging, but still inside the exception-capture revivable set.
 *
 * Tasks are created inline through DispatchTaskService (no local helper
 * functions — see BoardFeaturesTest's redeclare rationale).
 */

test('dispatch:done --status=backburner parks a task and --status=open unparks it, both on the timeline', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'Someday idea']);
    expect($task->status)->toBe('triage');

    $this->artisan('dispatch:done', ['code' => $task->code, '--status' => 'backburner'])->assertOk();
    expect($task->fresh()->status)->toBe('backburner');

    $this->artisan('dispatch:done', ['code' => $task->code, '--status' => 'open'])->assertOk();
    expect($task->fresh()->status)->toBe('open');

    $events = $task->comments()->where('event_type', TaskComment::EVENT_STATUS_CHANGE)->orderBy('id')->get();
    expect($events)->toHaveCount(2);
    expect($events[0]->meta)->toBe(['from' => 'triage', 'to' => 'backburner']);
    expect($events[1]->meta)->toBe(['from' => 'backburner', 'to' => 'open']);
});

test('next/queue defaults never surface a backburnered task; explicit --status=backburner does', function () {
    $svc = app(DispatchTaskService::class);
    $parked = $svc->create(['title' => 'Parked blocker', 'status' => 'backburner', 'priority' => 'blocker']);
    $open = $svc->create(['title' => 'Ordinary chore', 'status' => 'open', 'priority' => 'low']);

    // Even at blocker priority, the parked task loses to a low-priority open one.
    Artisan::call('dispatch:next', ['--json' => true]);
    expect(dispatchJson(Artisan::output())['code'] ?? null)->toBe($open->code);

    Artisan::call('dispatch:queue', ['--json' => true]);
    $codes = array_column(dispatchJson(Artisan::output()), 'code');
    expect($codes)->toContain($open->code);
    expect($codes)->not->toContain($parked->code);

    Artisan::call('dispatch:queue', ['--status' => 'backburner', '--json' => true]);
    expect(array_column(dispatchJson(Artisan::output()), 'code'))->toBe([$parked->code]);
});

test('the --count census excludes backburner from backlog size; --status=backburner yields its bucket', function () {
    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'Parked', 'status' => 'backburner']);
    $svc->create(['title' => 'Open', 'status' => 'open']);

    Artisan::call('dispatch:queue', ['--count' => true, '--json' => true]);
    $census = dispatchJson(Artisan::output());

    expect($census['total'])->toBe(1);
    expect($census['by_status'])->toBe(['open' => 1, 'in_progress' => 0, 'triage' => 0, 'verifying' => 0]);

    Artisan::call('dispatch:queue', ['--count' => true, '--status' => 'backburner', '--json' => true]);
    expect(dispatchJson(Artisan::output()))->toBe(['total' => 1, 'by_status' => ['backburner' => 1]]);
});

test('claim() never grabs a backburnered task, even when it outranks everything', function () {
    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'Parked blocker', 'status' => 'backburner', 'priority' => 'blocker']);

    expect($svc->claim())->toBeNull();

    $open = $svc->create(['title' => 'Ordinary chore', 'status' => 'open', 'priority' => 'low']);
    $claimed = $svc->claim();

    expect($claimed?->code)->toBe($open->code);
    expect($claimed->status)->toBe('in_progress');
});

test('a recurring exception revives a backburnered task instead of filing a duplicate, without unparking it', function () {
    $svc = app(DispatchTaskService::class);
    $task = $svc->create(['title' => 'Flaky import crash', 'status' => 'backburner']);
    DB::table('dispatch_tasks')->where('id', $task->id)->update(['exception_signature' => 'sig-abc']);

    $revived = $svc->capture('sig-abc', ['title' => 'Flaky import crash (again)']);

    expect($revived->id)->toBe($task->id);
    expect(Task::query()->count())->toBe(1);
    expect($revived->status)->toBe('backburner');
    expect($revived->context['times_seen'] ?? null)->toBe(2);
    expect($task->comments()->where('event_type', TaskComment::EVENT_EXCEPTION)->count())->toBe(1);
});

test('a long-untouched backburnered task is never flagged stale, on the board or via the list stale filter', function () {
    config(['dispatch.staleness.enabled' => true, 'dispatch.staleness.threshold_days' => 42]);

    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $parked = app(DispatchTaskService::class)->create(['title' => 'Long-parked idea', 'status' => 'backburner']);
    DB::table('dispatch_tasks')->where('id', $parked->id)->update(['updated_at' => now()->subDays(90)]);

    expect(substr_count(Livewire::test(TaskBoard::class)->html(), '>stale<'))->toBe(0);

    $list = Livewire::test(TaskList::class)->set('statusFilter', 'stale');
    expect($list->viewData('tasks')->total())->toBe(0);
});

test('backburner sits between verifying and done — active, then parked, then terminal', function () {
    $statuses = Task::statuses();
    $at = array_search('backburner', $statuses, true);

    expect($statuses[$at - 1])->toBe('verifying');
    expect($statuses[$at + 1])->toBe('done');

    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $component = Livewire::test(TaskBoard::class);
    expect($component->viewData('columns'))->toBe($statuses);
    $component->assertSee('Backburner');
});
