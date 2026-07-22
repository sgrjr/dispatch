<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Livewire;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Contracts\DispatchNotifier;
use Sgrjr\Dispatch\Livewire\TaskList;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/**
 * F1 (config-driven vocab), F3 (bulk operations), and F6 (staleness filter)
 * on the staff task list.
 *
 * TaskList::render() eager-loads submitter/assignee, so every test that
 * renders it with real tasks calls dispatchMakeUser() first (see
 * tests/Pest.php) — otherwise `with(['submitter', 'assignee'])` would try to
 * instantiate the unconfigured default `App\Models\User`.
 */

function listFeaturesNotifierSpy(): DispatchNotifier
{
    return new class implements DispatchNotifier
    {
        /** @var array<int,array{0:int,1:string,2:string}> */
        public array $statusChanges = [];

        public function taskCreated(Task $task): void {}

        public function taskStatusChanged(Task $task, string $from, string $to, ?Authenticatable $actor): void
        {
            $this->statusChanges[] = [$task->id, $from, $to];
        }

        public function taskCommented(Task $task, TaskComment $comment): void {}

        public function taskAssigned(Task $task, ?int $from, ?int $to, ?Authenticatable $actor): void {}
    };
}

test('the status filter renders options from a configured dispatch.workflow.statuses vocab', function () {
    $staff = dispatchMakeUser(1);
    config(['dispatch.workflow.statuses' => ['backlog', 'shipped']]);

    $this->actingAs($staff);

    Livewire::test(TaskList::class)
        ->assertSee('Backlog')
        ->assertSee('Shipped')
        ->assertDontSee('Triage');
});

test('bulkApply sets status on exactly the selected tasks, and only those, and fires the notifier once per change', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $spy = listFeaturesNotifierSpy();
    app()->singleton(DispatchNotifier::class, fn () => $spy);

    $service = app(DispatchTaskService::class);
    $taskA = $service->create(['title' => 'Fix the widget', 'status' => 'open']);
    $taskB = $service->create(['title' => 'Fix the gadget', 'status' => 'open']);
    $taskC = $service->create(['title' => 'Leave me alone', 'status' => 'open']);

    Livewire::test(TaskList::class)
        ->set('selected', [(string) $taskA->id, (string) $taskB->id])
        ->set('bulkAction', 'status')
        ->set('bulkStatusValue', 'done')
        ->call('bulkApply');

    expect($taskA->fresh()->status)->toBe('done');
    expect($taskB->fresh()->status)->toBe('done');
    expect($taskC->fresh()->status)->toBe('open');

    expect($spy->statusChanges)->toHaveCount(2);
    expect(collect($spy->statusChanges)->pluck(0)->all())->toEqualCanonicalizing([$taskA->id, $taskB->id]);

    // A status_change event was memorialized on each affected task.
    expect($taskA->fresh()->comments()->where('event_type', TaskComment::EVENT_STATUS_CHANGE)->count())->toBe(1);
});

test('a non-staff user is blocked from bulk-applying a status change (custom gate, à la ScopeVisibilityTest)', function () {
    // Inline gate splitting staff/submitter visibility, mirroring
    // ScopeVisibilityTest's custom gate: only is_staff users are staff.
    app()->singleton(DispatchGate::class, fn () => new class implements DispatchGate
    {
        public function isStaff(?Authenticatable $user): bool
        {
            return $user !== null && (bool) ($user->is_staff ?? false);
        }

        public function canSeeAll(?Authenticatable $user): bool
        {
            return $this->isStaff($user);
        }

        public function scopeVisible(Builder $query, ?Authenticatable $user): Builder
        {
            if ($this->canSeeAll($user)) {
                return $query;
            }

            if ($user === null) {
                return $query->where('is_public', true);
            }

            return $query->where(function (Builder $q) use ($user) {
                $q->where('is_public', true)->orWhere('submitter_user_id', $user->getAuthIdentifier());
            });
        }
    });

    $submitter = dispatchMakeUser(42);

    $spy = listFeaturesNotifierSpy();
    app()->singleton(DispatchNotifier::class, fn () => $spy);

    $this->actingAs($submitter);

    $task = app(DispatchTaskService::class)->create([
        'title' => 'My own bug',
        'status' => 'open',
        'submitter_user_id' => $submitter->id,
        'is_public' => false,
    ]);

    // mount() redirects a non-staff user to the portal (see BoardAccessTest);
    // bulkApply() defends independently of that redirect, so calling it
    // directly on the still-live component proves the method's own
    // authorization — not just the page gate — blocks the change.
    Livewire::test(TaskList::class)
        ->set('selected', [(string) $task->id])
        ->set('bulkAction', 'status')
        ->set('bulkStatusValue', 'done')
        ->call('bulkApply');

    expect($task->fresh()->status)->toBe('open');
    expect($spy->statusChanges)->toBe([]);
});

test('the stale filter returns only non-terminal tasks past the staleness threshold', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    config(['dispatch.staleness.threshold_days' => 42]);

    /** @var class-string<Task> $taskClass */
    $taskClass = config('dispatch.models.task');
    $service = app(DispatchTaskService::class);

    $stale = $service->create(['title' => 'Stale open task', 'status' => 'open']);
    $fresh = $service->create(['title' => 'Fresh open task', 'status' => 'open']);
    $staleButDone = $service->create(['title' => 'Stale but done task', 'status' => 'done']);

    // Bypass Eloquent's auto-touch so updated_at actually lands in the past.
    $taskClass::whereKey($stale->id)->update(['updated_at' => now()->subDays(50)]);
    $taskClass::whereKey($staleButDone->id)->update(['updated_at' => now()->subDays(50)]);

    Livewire::test(TaskList::class)
        ->set('statusFilter', 'stale')
        ->assertSee($stale->code)
        ->assertDontSee($fresh->code)
        ->assertDontSee($staleButDone->code);
});

test('the updated filter buckets tasks into today / past week / past month / older windows', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    /** @var class-string<Task> $taskClass */
    $taskClass = config('dispatch.models.task');
    $service = app(DispatchTaskService::class);

    $today = $service->create(['title' => 'Touched today', 'status' => 'open']);
    $thisWeek = $service->create(['title' => 'Touched this week', 'status' => 'open']);
    $thisMonth = $service->create(['title' => 'Touched this month', 'status' => 'open']);
    $ancient = $service->create(['title' => 'Touched long ago', 'status' => 'open']);

    // Bypass Eloquent's auto-touch so updated_at actually lands in the past.
    $taskClass::whereKey($thisWeek->id)->update(['updated_at' => now()->subDays(3)]);
    $taskClass::whereKey($thisMonth->id)->update(['updated_at' => now()->subDays(20)]);
    $taskClass::whereKey($ancient->id)->update(['updated_at' => now()->subDays(90)]);

    Livewire::test(TaskList::class)
        ->set('updatedFilter', 'today')
        ->assertSee($today->code)
        ->assertDontSee($thisWeek->code)
        ->assertDontSee($ancient->code)
        ->set('updatedFilter', 'week')
        ->assertSee($today->code)
        ->assertSee($thisWeek->code)
        ->assertDontSee($thisMonth->code)
        ->set('updatedFilter', 'month')
        ->assertSee($thisMonth->code)
        ->assertDontSee($ancient->code)
        ->set('updatedFilter', 'older')
        ->assertSee($ancient->code)
        ->assertDontSee($today->code)
        ->assertDontSee($thisMonth->code);
});

test('sorting by updated_desc and updated_asc orders tasks by updated_at', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    /** @var class-string<Task> $taskClass */
    $taskClass = config('dispatch.models.task');
    $service = app(DispatchTaskService::class);

    $recent = $service->create(['title' => 'Recently touched', 'status' => 'open']);
    $middle = $service->create(['title' => 'Touched a while ago', 'status' => 'open']);
    $oldest = $service->create(['title' => 'Untouched forever', 'status' => 'open']);

    $taskClass::whereKey($middle->id)->update(['updated_at' => now()->subDays(10)]);
    $taskClass::whereKey($oldest->id)->update(['updated_at' => now()->subDays(30)]);

    Livewire::test(TaskList::class)
        ->set('sort', 'updated_desc')
        ->assertSeeInOrder([$recent->code, $middle->code, $oldest->code])
        ->set('sort', 'updated_asc')
        ->assertSeeInOrder([$oldest->code, $middle->code, $recent->code]);
});

test('the stale filter is not offered when dispatch.staleness.enabled is false', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    config(['dispatch.staleness.enabled' => false]);

    Livewire::test(TaskList::class)->assertDontSee('Stale');
});

test('list checkbox filters mirror the board: a subset narrows, none/all show everything', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'A bug', 'type' => 'bug']);
    $chore = $svc->create(['title' => 'A chore', 'type' => 'chore']);

    $component = Livewire::test(TaskList::class);
    expect($component->viewData('tasks')->total())->toBe(2);

    $component->call('toggleFilter', 'typeFilter', 'bug');
    expect($component->viewData('tasks')->total())->toBe(1);
    expect($component->viewData('tasks')->pluck('id')->all())->toBe([$chore->id]);

    $component->call('selectNoneFilter', 'typeFilter');
    expect($component->get('typeFilter'))->toBe(['']);
    expect($component->viewData('tasks')->total())->toBe(2);
});

test('a checkbox filter toggle clears the bulk selection (afterFilterChanged, not the updating() hook)', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    $bug = $svc->create(['title' => 'A bug', 'type' => 'bug']);

    $component = Livewire::test(TaskList::class)
        ->set('selected', [(string) $bug->id])
        ->call('toggleFilter', 'typeFilter', 'bug');

    expect($component->get('selected'))->toBe([]);
});

test('clearFilters restores the checkbox filters to their canonical all state', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    app(DispatchTaskService::class)->create(['title' => 'A bug', 'type' => 'bug']);

    $component = Livewire::test(TaskList::class)
        ->call('toggleFilter', 'typeFilter', 'bug')
        ->call('toggleFilter', 'priorityFilter', 'low')
        ->call('toggleFilter', 'dueFilter', 'none')
        ->call('clearFilters');

    expect($component->get('typeFilter'))->toBe([]);
    expect($component->get('priorityFilter'))->toBe([]);
    expect($component->get('dueFilter'))->toBe([]);
    expect($component->viewData('tasks')->total())->toBe(1);
});

/*
 * Due filter (Task::dueBuckets()): MECE windows rolling from today —
 * overdue / today / week / month / later / none — plus the 'dated'
 * convenience union. OR within the axis, AND with everything else.
 */

test('the due filter buckets tasks into overdue / today / week / month / later / none windows with OR semantics', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    $overdue = $svc->create(['title' => 'Past due', 'status' => 'open', 'due_at' => now()->subDays(2)]);
    $today = $svc->create(['title' => 'Due today', 'status' => 'open', 'due_at' => now()]);
    $week = $svc->create(['title' => 'Due within the week', 'status' => 'open', 'due_at' => now()->addDays(3)]);
    $month = $svc->create(['title' => 'Due within the month', 'status' => 'open', 'due_at' => now()->addDays(15)]);
    $later = $svc->create(['title' => 'Due way out', 'status' => 'open', 'due_at' => now()->addDays(45)]);
    $undated = $svc->create(['title' => 'No due date', 'status' => 'open']);

    $component = Livewire::test(TaskList::class);
    $idsFor = fn (array $buckets): array => $component->set('dueFilter', $buckets)->viewData('tasks')->pluck('id')->all();

    expect($idsFor(['overdue']))->toBe([$overdue->id]);
    expect($idsFor(['today']))->toBe([$today->id]);
    expect($idsFor(['week']))->toBe([$week->id]);
    expect($idsFor(['month']))->toBe([$month->id]);
    expect($idsFor(['later']))->toBe([$later->id]);
    expect($idsFor(['none']))->toBe([$undated->id]);

    // OR within the axis: two buckets, both sets of tasks.
    expect($idsFor(['overdue', 'none']))->toEqualCanonicalizing([$overdue->id, $undated->id]);

    // The convenience union: everything with a due date, nothing without.
    expect($idsFor(['dated']))->toEqualCanonicalizing([$overdue->id, $today->id, $week->id, $month->id, $later->id]);
});

test('an overdue-range due date on an inactive task matches only the dated bucket, never overdue', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    $open = $svc->create(['title' => 'Actually overdue', 'status' => 'open', 'due_at' => now()->subDays(2)]);
    $done = $svc->create(['title' => 'Shipped late', 'status' => 'done', 'due_at' => now()->subDays(2)]);
    $parked = $svc->create(['title' => 'Parked past-due', 'status' => 'backburner', 'due_at' => now()->subDays(2)]);

    $component = Livewire::test(TaskList::class)->set('dueFilter', ['overdue']);
    expect($component->viewData('tasks')->pluck('id')->all())->toBe([$open->id]);

    $component->set('dueFilter', ['dated']);
    expect($component->viewData('tasks')->pluck('id')->all())->toEqualCanonicalizing([$open->id, $done->id, $parked->id]);
});

test('sorting by due_asc and due_desc puts null due dates last in both directions', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    $near = $svc->create(['title' => 'Due soon', 'status' => 'open', 'due_at' => now()->addDay()]);
    $far = $svc->create(['title' => 'Due further out', 'status' => 'open', 'due_at' => now()->addDays(10)]);
    $undated = $svc->create(['title' => 'No due date at all', 'status' => 'open']);

    Livewire::test(TaskList::class)
        ->set('sort', 'due_asc')
        ->assertSeeInOrder([$near->code, $far->code, $undated->code])
        ->set('sort', 'due_desc')
        ->assertSeeInOrder([$far->code, $near->code, $undated->code]);
});
