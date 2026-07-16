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

test('the stale filter is not offered when dispatch.staleness.enabled is false', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    config(['dispatch.staleness.enabled' => false]);

    Livewire::test(TaskList::class)->assertDontSee('Stale');
});
