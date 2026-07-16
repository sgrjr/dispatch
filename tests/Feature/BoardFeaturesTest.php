<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Sgrjr\Dispatch\Contracts\DispatchNotifier;
use Sgrjr\Dispatch\Livewire\TaskBoard;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/**
 * TaskBoard feature coverage: config-driven columns/vocab (F1), the done
 * column's done_limit cap (F8), manual_order (F9), the board-move notifier
 * gap fix (N1), stale-card detection (F6), and the minimal bulk-select slice
 * (F3). Mirrors BoardAccessTest's staff-auth/mount pattern but with a real
 * persisted user (dispatchMakeUser()), since several of these tests need a
 * submitter_user_id / notifiable actor.
 *
 * Tasks are created directly through DispatchTaskService (no local helper
 * function declared here) — a top-level `function xyz()` in a Pest test
 * file lives in the global namespace for the whole test run, so a
 * similarly-named helper in a sibling workstream's test file would fatal
 * with "Cannot redeclare function". Inlining avoids that collision risk
 * entirely (same convention ScopeVisibilityTest.php already uses).
 */

test('a staff user can mount and render the board (no redirect)', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $component = Livewire::test(TaskBoard::class);

    // Proves render() actually ran (a redirect in mount() would leave no
    // view/viewData to read), and doubles as the F1 columns assertion below.
    expect($component->viewData('columns'))->toBe(Task::statuses());
});

test('the board renders one column per Task::statuses(), labeled via Task::statusLabels()', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $component = Livewire::test(TaskBoard::class);

    expect($component->viewData('columns'))->toBe(Task::statuses());
    expect($component->viewData('statusLabels'))->toBe(Task::statusLabels());

    foreach (Task::statusLabels() as $label) {
        $component->assertSee($label);
    }
});

test('a custom dispatch.workflow.statuses list drives the board columns too', function () {
    config(['dispatch.workflow.statuses' => ['backlog', 'shipped']]);

    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $component = Livewire::test(TaskBoard::class);

    expect($component->viewData('columns'))->toBe(['backlog', 'shipped']);
    $component->assertSee('Backlog')->assertSee('Shipped');
});

test('the done column is capped to dispatch.board.done_limit most-recent tasks, with a showing-N-of-M indicator', function () {
    config(['dispatch.board.done_limit' => 3]);

    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    for ($i = 0; $i < 5; $i++) {
        $svc->create(['title' => "Done task {$i}", 'status' => 'done']);
    }

    $component = Livewire::test(TaskBoard::class);

    expect($component->viewData('doneTotal'))->toBe(5);
    expect($component->viewData('doneShowing'))->toBe(3);
    expect($component->viewData('byStatus')->get('done'))->toHaveCount(3);

    $component->assertSee('3 / 5');
});

test('toggling showAllDone lifts the done_limit cap', function () {
    config(['dispatch.board.done_limit' => 3]);

    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    for ($i = 0; $i < 5; $i++) {
        $svc->create(['title' => "Done task {$i}", 'status' => 'done']);
    }

    $component = Livewire::test(TaskBoard::class)->call('toggleShowAllDone');

    expect($component->viewData('doneShowing'))->toBe(5);
    expect($component->viewData('doneTotal'))->toBe(5);
});

test('manual_order true orders each column by position/code instead of priority', function () {
    config(['dispatch.board.manual_order' => true]);

    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    /** @var class-string<Task> $taskClass */
    $taskClass = config('dispatch.models.task');
    $svc = app(DispatchTaskService::class);

    $low = $svc->create(['title' => 'Low priority', 'status' => 'open', 'priority' => 'low']);
    $blocker = $svc->create(['title' => 'Blocker priority', 'status' => 'open', 'priority' => 'blocker']);

    // Manual positions deliberately invert the priority order: if manual_order
    // is honored, $low (position 0) must render before $blocker (position 1)
    // despite the priority ranking saying the opposite.
    $taskClass::whereKey($low->id)->update(['position' => 0]);
    $taskClass::whereKey($blocker->id)->update(['position' => 1]);

    $component = Livewire::test(TaskBoard::class);

    $ids = $component->viewData('byStatus')->get('open')->pluck('id')->all();

    expect($ids)->toBe([$low->id, $blocker->id]);
});

test('a cross-column moveCard fires the notifier taskStatusChanged hook exactly once', function () {
    $spy = new class implements DispatchNotifier
    {
        public int $calls = 0;

        public ?string $from = null;

        public ?string $to = null;

        public function taskCreated(Task $task): void {}

        public function taskStatusChanged(Task $task, string $from, string $to, ?Authenticatable $actor): void
        {
            $this->calls++;
            $this->from = $from;
            $this->to = $to;
        }

        public function taskCommented(Task $task, TaskComment $comment): void {}

        public function taskAssigned(Task $task, ?int $from, ?int $to, ?Authenticatable $actor): void {}
    };
    app()->singleton(DispatchNotifier::class, fn () => $spy);

    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $task = app(DispatchTaskService::class)->create(['title' => 'Move me', 'status' => 'open']);

    Livewire::test(TaskBoard::class)->call('moveCard', $task->id, 'in_progress', 0);

    expect($spy->calls)->toBe(1);
    expect($spy->from)->toBe('open');
    expect($spy->to)->toBe('in_progress');
    expect($task->fresh()->status)->toBe('in_progress');

    $event = $task->fresh()->comments()->where('event_type', TaskComment::EVENT_STATUS_CHANGE)->first();
    expect($event)->not->toBeNull();
});

test('moveCard within the same column reorders but does not fire the notifier', function () {
    $spy = new class implements DispatchNotifier
    {
        public int $calls = 0;

        public function taskCreated(Task $task): void {}

        public function taskStatusChanged(Task $task, string $from, string $to, ?Authenticatable $actor): void
        {
            $this->calls++;
        }

        public function taskCommented(Task $task, TaskComment $comment): void {}

        public function taskAssigned(Task $task, ?int $from, ?int $to, ?Authenticatable $actor): void {}
    };
    app()->singleton(DispatchNotifier::class, fn () => $spy);

    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    $taskA = $svc->create(['title' => 'A', 'status' => 'open']);
    $svc->create(['title' => 'B', 'status' => 'open']);

    Livewire::test(TaskBoard::class)->call('moveCard', $taskA->id, 'open', 1);

    expect($spy->calls)->toBe(0);
});

test('stale detection flags a task past the threshold and leaves a fresh task unflagged', function () {
    config(['dispatch.staleness.enabled' => true, 'dispatch.staleness.threshold_days' => 42]);

    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    $old = $svc->create(['title' => 'Ancient bug report', 'status' => 'open']);
    DB::table('dispatch_tasks')->where('id', $old->id)->update(['updated_at' => now()->subDays(50)]);

    $svc->create(['title' => 'Recent bug report', 'status' => 'open']);

    $component = Livewire::test(TaskBoard::class);

    // Exactly one "stale" badge should render (the old task only).
    expect(substr_count($component->html(), '>stale<'))->toBe(1);
});

test('disabling dispatch.staleness.enabled suppresses the stale badge entirely', function () {
    config(['dispatch.staleness.enabled' => false]);

    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $old = app(DispatchTaskService::class)->create(['title' => 'Ancient bug report', 'status' => 'open']);
    DB::table('dispatch_tasks')->where('id', $old->id)->update(['updated_at' => now()->subDays(90)]);

    $component = Livewire::test(TaskBoard::class);

    expect(substr_count($component->html(), '>stale<'))->toBe(0);
});

test('bulkApplyStatus moves every authorized selected task and fires the notifier for each', function () {
    $spy = new class implements DispatchNotifier
    {
        public int $calls = 0;

        public function taskCreated(Task $task): void {}

        public function taskStatusChanged(Task $task, string $from, string $to, ?Authenticatable $actor): void
        {
            $this->calls++;
        }

        public function taskCommented(Task $task, TaskComment $comment): void {}

        public function taskAssigned(Task $task, ?int $from, ?int $to, ?Authenticatable $actor): void {}
    };
    app()->singleton(DispatchNotifier::class, fn () => $spy);

    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    $selected = $svc->create(['title' => 'Selected', 'status' => 'open']);
    $untouched = $svc->create(['title' => 'Not selected', 'status' => 'open']);

    Livewire::test(TaskBoard::class)
        ->set('selectMode', true)
        ->set('selectedIds', [$selected->id])
        ->set('bulkStatus', 'in_progress')
        ->call('bulkApplyStatus');

    expect($selected->fresh()->status)->toBe('in_progress');
    expect($untouched->fresh()->status)->toBe('open');
    expect($spy->calls)->toBe(1);
});

test('bulkDecline declines every selected task', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    $selected = $svc->create(['title' => 'Selected', 'status' => 'open']);

    Livewire::test(TaskBoard::class)
        ->set('selectMode', true)
        ->set('selectedIds', [$selected->id])
        ->call('bulkDecline');

    expect($selected->fresh()->status)->toBe('declined');

    $event = $selected->fresh()->comments()->where('event_type', TaskComment::EVENT_STATUS_CHANGE)->first();
    expect($event)->not->toBeNull();
});
