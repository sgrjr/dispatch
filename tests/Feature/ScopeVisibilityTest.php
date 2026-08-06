<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\VisibilityGates;

/*
 * DispatchGate::scopeVisible() is THE one visibility filter in the package —
 * board, list, show, portal, sync, and the attachment authz check all route
 * through it. Since W13-5 the shipped DefaultGate applies the visibility
 * gates (Support\VisibilityGates): GATE A (submitter/assignee/watchers,
 * unconditional) ∪ GATE B (visibility='staff', the per-task opt-in) for
 * staff; GATE C (own + is_public) for non-staff; GATE D (guests/everyone
 * else) sees NOTHING — there is no public visibility.
 */

test('a guest (null user) sees NOTHING under the shipped DefaultGate — even is_public tasks (W13-5 GATE D)', function () {
    $tasks = app(DispatchTaskService::class);

    $tasks->create(['title' => 'Customer-visible task', 'is_public' => true, 'visibility' => 'staff']);
    $tasks->create(['title' => 'Private internal note', 'is_public' => false]);

    $taskModel = config('dispatch.models.task');
    $gate = app(DispatchGate::class);

    expect($gate->isStaff(null))->toBeFalse();
    expect($gate->canSeeAll(null))->toBeFalse();

    expect($gate->scopeVisible($taskModel::query(), null)->count())->toBe(0);
});

test('staff see staff-shared tasks plus every task they participate in, but NOT others\' participants-only tasks (W13-5 A∪B)', function () {
    dispatchFakeUsers(); // watch() walks the configured user model

    $tasks = app(DispatchTaskService::class);

    $shared = $tasks->create(['title' => 'Shared with staff', 'visibility' => 'staff']);
    $ownSubmitted = $tasks->create(['title' => 'Mine (submitter)', 'visibility' => 'participants', 'submitter_user_id' => 7]);
    $ownAssigned = $tasks->create(['title' => 'Mine (assignee)', 'visibility' => 'participants', 'submitter_user_id' => 99, 'assignee_user_id' => 7]);
    $watched = $tasks->create(['title' => 'Mine (watcher)', 'visibility' => 'participants', 'submitter_user_id' => 99]);
    $watched->watch(7);
    $othersCircle = $tasks->create(['title' => 'Someone else\'s circle', 'visibility' => 'participants', 'submitter_user_id' => 99, 'assignee_user_id' => 98]);

    /** @var AuthenticatableUser $staff */
    $staff = new class extends AuthenticatableUser {};
    $staff->id = 7;

    $taskModel = config('dispatch.models.task');
    $gate = app(DispatchGate::class);

    expect($gate->isStaff($staff))->toBeTrue();
    expect($gate->canSeeAll($staff))->toBeFalse();

    $visible = $gate->scopeVisible($taskModel::query(), $staff)->pluck('id')->all();

    expect($visible)->toEqualCanonicalizing([$shared->id, $ownSubmitted->id, $ownAssigned->id, $watched->id]);
    expect($visible)->not->toContain($othersCircle->id);
});

test('a non-staff submitter sees only their OWN tasks with the customer toggle on (W13-5 GATE C)', function () {
    $tasks = app(DispatchTaskService::class);

    $mineVisible = $tasks->create(['title' => 'Mine, toggled visible', 'is_public' => true, 'submitter_user_id' => 42]);
    $mineHidden = $tasks->create(['title' => 'Mine, default hidden', 'is_public' => false, 'submitter_user_id' => 42]);
    $othersVisible = $tasks->create(['title' => 'Someone else\'s, visible to THEM', 'is_public' => true, 'submitter_user_id' => 99, 'visibility' => 'staff']);

    /** @var AuthenticatableUser $customer */
    $customer = new class extends AuthenticatableUser {};
    $customer->id = 42;

    $taskModel = config('dispatch.models.task');

    // GATE C is only reachable when the gate rules the viewer non-staff —
    // DefaultGate calls every authed user staff, so exercise the helper
    // directly with the non-staff verdict a host gate would pass.
    $visible = VisibilityGates::apply($taskModel::query(), $customer, false)->pluck('id')->all();

    expect($visible)->toEqualCanonicalizing([$mineVisible->id]);
    expect($visible)->not->toContain($mineHidden->id);
    expect($visible)->not->toContain($othersVisible->id);
});

test('a host gate composes VisibilityGates with its own staff test and canSeeAll superusers (W13-5)', function () {
    $customGate = new class implements DispatchGate
    {
        public function isStaff(?Authenticatable $user): bool
        {
            return $user !== null && (bool) ($user->is_staff ?? false);
        }

        public function canSeeAll(?Authenticatable $user): bool
        {
            return $user !== null && (bool) ($user->is_super ?? false);
        }

        public function scopeVisible(Builder $query, ?Authenticatable $user): Builder
        {
            if ($this->canSeeAll($user)) {
                return $query;
            }

            return VisibilityGates::apply($query, $user, $this->isStaff($user));
        }
    };

    app()->singleton(DispatchGate::class, fn () => $customGate);

    $tasks = app(DispatchTaskService::class);

    $circle = $tasks->create(['title' => 'A circle task', 'visibility' => 'participants', 'submitter_user_id' => 99]);
    $shared = $tasks->create(['title' => 'Staff-shared', 'visibility' => 'staff']);

    $staff = new class extends AuthenticatableUser {};
    $staff->id = 7;
    $staff->is_staff = true;

    $super = new class extends AuthenticatableUser {};
    $super->id = 1;
    $super->is_super = true;

    $taskModel = config('dispatch.models.task');
    $gate = app(DispatchGate::class);

    // Plain staff: staff-shared only — 99's circle stays closed.
    expect($gate->scopeVisible($taskModel::query(), $staff)->pluck('id')->all())
        ->toEqualCanonicalizing([$shared->id]);

    // Superuser short-circuits the gates entirely.
    expect($gate->scopeVisible($taskModel::query(), $super)->pluck('id')->all())
        ->toEqualCanonicalizing([$circle->id, $shared->id]);
});

test('the create default is actor-keyed: staff creator → participants; no/non-staff actor → staff (W13-5)', function () {
    $tasks = app(DispatchTaskService::class);

    // No acting user (CLI/system/reporter path): lands staff-visible so the
    // team can triage it — an invisible auto-filed task would be the exact
    // wrong failure.
    $system = $tasks->create(['title' => 'System-filed']);
    expect($system->visibility)->toBe(Task::VISIBILITY_STAFF);

    // A staff actor: their circle, participants-only.
    $staff = dispatchMakeUser(7);
    $this->actingAs($staff);
    $mine = $tasks->create(['title' => 'Staff-filed']);
    expect($mine->visibility)->toBe(Task::VISIBILITY_PARTICIPANTS);

    // An explicit valid value always wins; garbage falls to the default.
    $explicit = $tasks->create(['title' => 'Explicitly shared', 'visibility' => 'staff']);
    expect($explicit->visibility)->toBe(Task::VISIBILITY_STAFF);

    $garbage = $tasks->create(['title' => 'Garbage visibility', 'visibility' => 'everyone']);
    expect($garbage->visibility)->toBe(Task::VISIBILITY_PARTICIPANTS);
});
