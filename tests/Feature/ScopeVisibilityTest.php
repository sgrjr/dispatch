<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/*
 * DispatchGate::scopeVisible() is THE one visibility filter in the package —
 * board, list, show, portal, sync, and the attachment authz check all route
 * through it. These tests exercise it directly (not through a Livewire
 * component, since the UI workstream may not have landed yet) using both the
 * shipped DefaultGate and a small inline custom gate.
 */

test('a guest (null user) sees only public tasks under the shipped DefaultGate', function () {
    $tasks = app(DispatchTaskService::class);

    $public = $tasks->create(['title' => 'Public bug report', 'is_public' => true]);
    $private = $tasks->create(['title' => 'Private internal note', 'is_public' => false]);

    $taskModel = config('dispatch.models.task');
    $gate = app(DispatchGate::class);

    expect($gate->isStaff(null))->toBeFalse();
    expect($gate->canSeeAll(null))->toBeFalse();

    $visibleIds = $gate->scopeVisible($taskModel::query(), null)->pluck('id')->all();

    expect($visibleIds)->toContain($public->id);
    expect($visibleIds)->not->toContain($private->id);
});

test('an authenticated ("sees-all") user sees every task under the shipped DefaultGate', function () {
    $tasks = app(DispatchTaskService::class);

    $public = $tasks->create(['title' => 'Public bug report', 'is_public' => true]);
    $private = $tasks->create(['title' => 'Private internal note', 'is_public' => false]);

    /** @var AuthenticatableUser $user */
    $user = new class extends AuthenticatableUser {};
    $user->id = 1;

    $taskModel = config('dispatch.models.task');
    $gate = app(DispatchGate::class);

    expect($gate->isStaff($user))->toBeTrue();
    expect($gate->canSeeAll($user))->toBeTrue();

    $visibleIds = $gate->scopeVisible($taskModel::query(), $user)->pluck('id')->all();

    expect($visibleIds)->toContain($public->id);
    expect($visibleIds)->toContain($private->id);
});

test('a custom DispatchGate can split staff / submitter / guest visibility (bound in-test)', function () {
    // Inline gate exercising the split the shipped DefaultGate collapses:
    // staff sees everything, a submitter sees their own + public tasks, a
    // guest sees public tasks only. Bound over the DispatchGate singleton for
    // the duration of this test only (each test gets a fresh Testbench app).
    $customGate = new class implements DispatchGate
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
                $q->where('is_public', true)
                    ->orWhere('submitter_user_id', $user->getAuthIdentifier());
            });
        }
    };

    app()->singleton(DispatchGate::class, fn () => $customGate);

    $tasks = app(DispatchTaskService::class);

    $public = $tasks->create(['title' => 'Public', 'is_public' => true]);
    $ownedPrivate = $tasks->create(['title' => 'Mine', 'is_public' => false, 'submitter_user_id' => 42]);
    $othersPrivate = $tasks->create(['title' => 'Not mine', 'is_public' => false, 'submitter_user_id' => 99]);

    $submitter = new class extends AuthenticatableUser {};
    $submitter->id = 42;

    $staff = new class extends AuthenticatableUser {};
    $staff->id = 7;
    $staff->is_staff = true;

    $taskModel = config('dispatch.models.task');
    $gate = app(DispatchGate::class);

    $guestVisible = $gate->scopeVisible($taskModel::query(), null)->pluck('id')->all();
    expect($guestVisible)->toEqualCanonicalizing([$public->id]);

    $submitterVisible = $gate->scopeVisible($taskModel::query(), $submitter)->pluck('id')->all();
    expect($submitterVisible)->toEqualCanonicalizing([$public->id, $ownedPrivate->id]);

    expect($gate->isStaff($submitter))->toBeFalse();

    $staffVisible = $gate->scopeVisible($taskModel::query(), $staff)->pluck('id')->all();
    expect($staffVisible)->toEqualCanonicalizing([$public->id, $ownedPrivate->id, $othersPrivate->id]);

    expect($gate->isStaff($staff))->toBeTrue();
    expect($gate->canSeeAll($staff))->toBeTrue();
});
