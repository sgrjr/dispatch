<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Livewire;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Livewire\TaskBoard;
use Sgrjr\Dispatch\Livewire\TaskList;

/**
 * The board and list are staff-only. A non-staff user should be gracefully
 * redirected to their own submissions (the portal), NOT hit a 403 — so a
 * package consumer can surface these links to everyone without gating them.
 */

function bindNonStaffGate(): void
{
    app()->bind(DispatchGate::class, fn () => new class implements DispatchGate
    {
        public function isStaff(?Authenticatable $user): bool
        {
            return false;
        }

        public function canSeeAll(?Authenticatable $user): bool
        {
            return false;
        }

        public function scopeVisible(Builder $query, ?Authenticatable $user): Builder
        {
            return $query->where('is_public', true);
        }
    });
}

function nonStaffUser(): Authenticatable
{
    return new class extends \Illuminate\Foundation\Auth\User
    {
        protected $attributes = ['id' => 7];
    };
}

test('a non-staff user is redirected from the board to their submissions', function () {
    bindNonStaffGate();
    $this->actingAs(nonStaffUser());

    Livewire::test(TaskBoard::class)->assertRedirect(route('dispatch.portal'));
});

test('a non-staff user is redirected from the list to their submissions', function () {
    bindNonStaffGate();
    $this->actingAs(nonStaffUser());

    Livewire::test(TaskList::class)->assertRedirect(route('dispatch.portal'));
});
