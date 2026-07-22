<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Livewire\FocusPanel;
use Sgrjr\Dispatch\Models\Focus;

/**
 * W8-2 management surface: the staff `/focuses` page. Focuses are CREATED from
 * the board/list filter bars (a sibling feature); this panel is pure
 * management — list (active + inactive), reorder by rank, (de)activate,
 * rename, delete. The default gate treats any authenticated user as staff, so
 * dispatchMakeUser(1) + actingAs is a staff session unless a test rebinds the
 * gate (the non-staff redirect case below).
 */
function makeFocus(string $name, int $rank, bool $active = true, array $filters = []): Focus
{
    return Focus::query()->create([
        'name' => $name,
        'rank' => $rank,
        'is_active' => $active,
        'filters' => $filters,
    ]);
}

test('the page renders the ranked focuses, including an inactive one', function () {
    $this->actingAs(dispatchMakeUser(1));

    makeFocus('Accounts push', 0, true);
    makeFocus('Parked cleanup', 1, false);

    Livewire::test(FocusPanel::class)
        ->assertOk()
        ->assertSee('Accounts push')
        ->assertSee('Parked cleanup')   // inactive focuses are still shown
        ->assertSee('active')
        ->assertSee('inactive');
});

test('moveUp / moveDown swap adjacent rank values and are no-ops at the edges', function () {
    $this->actingAs(dispatchMakeUser(1));

    $top = makeFocus('Top', 0);
    $mid = makeFocus('Mid', 1);
    $bottom = makeFocus('Bottom', 2);

    $component = Livewire::test(FocusPanel::class);

    // Move the middle focus up — it swaps ranks with the top focus.
    $component->call('moveUp', $mid->id);
    expect($mid->fresh()->rank)->toBe(0);
    expect($top->fresh()->rank)->toBe(1);

    // Move it back down — swaps ranks with the (now) top focus again.
    $component->call('moveDown', $mid->id);
    expect($mid->fresh()->rank)->toBe(1);
    expect($top->fresh()->rank)->toBe(0);

    // Edge no-ops: the top can't move up, the bottom can't move down.
    $component->call('moveUp', $top->id);
    expect($top->fresh()->rank)->toBe(0);
    expect($mid->fresh()->rank)->toBe(1);

    $component->call('moveDown', $bottom->id);
    expect($bottom->fresh()->rank)->toBe(2);
    expect($mid->fresh()->rank)->toBe(1);
});

test('toggleActive flips the active flag', function () {
    $this->actingAs(dispatchMakeUser(1));

    $focus = makeFocus('Toggle me', 0, true);

    $component = Livewire::test(FocusPanel::class);

    $component->call('toggleActive', $focus->id);
    expect($focus->fresh()->is_active)->toBeFalse();

    $component->call('toggleActive', $focus->id);
    expect($focus->fresh()->is_active)->toBeTrue();
});

test('delete removes the focus', function () {
    $this->actingAs(dispatchMakeUser(1));

    $keep = makeFocus('Keep', 0);
    $drop = makeFocus('Drop', 1);

    Livewire::test(FocusPanel::class)->call('delete', $drop->id);

    expect(Focus::query()->find($drop->id))->toBeNull();
    expect(Focus::query()->find($keep->id))->not->toBeNull();
});

test('rename updates the name, and a blank name is a no-op', function () {
    $this->actingAs(dispatchMakeUser(1));

    $focus = makeFocus('Original name', 0);

    $component = Livewire::test(FocusPanel::class);

    $component->call('rename', $focus->id, '  Renamed focus  ');
    expect($focus->fresh()->name)->toBe('Renamed focus');   // trimmed

    // Blank (whitespace-only) is ignored — the name is left untouched.
    $component->call('rename', $focus->id, '   ');
    expect($focus->fresh()->name)->toBe('Renamed focus');
});

test('the /focuses route exists and a non-staff user is redirected to the portal', function () {
    // Inline custom gate à la ListFeaturesTest: only is_staff users are staff.
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

    // The named route is registered (class_exists-gated in routes/web.php).
    expect(Route::has('dispatch.focuses'))->toBeTrue();

    $submitter = dispatchMakeUser(42); // no is_staff attribute ⇒ not staff
    $this->actingAs($submitter);

    Livewire::test(FocusPanel::class)->assertRedirect(route('dispatch.portal'));
});

test('the filters summary reads "everything" for an axes-less focus and lists the axis for a labels focus', function () {
    $this->actingAs(dispatchMakeUser(1));

    makeFocus('Everything focus', 0, true, []);   // no constrained axes
    makeFocus('Accounts focus', 1, true, ['labels' => ['area:accounts', 'api']]);

    Livewire::test(FocusPanel::class)
        ->assertSee('everything')
        ->assertSee('labels: area:accounts, api');
});
