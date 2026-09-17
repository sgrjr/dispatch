<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Livewire\LabelPanel;
use Sgrjr\Dispatch\Models\Label;
use Sgrjr\Dispatch\Models\LabelAlias;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/**
 * The staff `/labels` cleanup page: usage census + filters, multi-select
 * replace/retire through LabelCleanupService, alias removal. The default gate
 * treats any authenticated user as staff.
 */
function panelTask(string $title, array $labels): void
{
    app(DispatchTaskService::class)->create(['title' => $title], $labels);
}

test('the page lists every label with its task count and the unused / used-once census', function () {
    $this->actingAs(dispatchMakeUser(1));
    panelTask('A', ['busy', 'once']);
    panelTask('B', ['busy']);
    Label::query()->create(['name' => 'orphan']);

    Livewire::test(LabelPanel::class)
        ->assertOk()
        ->assertSeeInOrder(['orphan', 'once', 'busy'])   // fewest tasks first
        ->assertSee('3 labels')
        ->assertSee('1 unused')
        ->assertSee('1 used once');
});

test('the usage filter and search narrow the rows', function () {
    $this->actingAs(dispatchMakeUser(1));
    panelTask('A', ['heavy-label', 'solo-label']);
    panelTask('B', ['heavy-label']);

    Livewire::test(LabelPanel::class)
        ->set('maxUses', '1')
        ->assertSee('solo-label')
        ->assertDontSee('heavy-label')
        ->set('maxUses', '')
        ->set('search', 'heav')
        ->assertSee('heavy-label')
        ->assertDontSee('solo-label');
});

test('selecting labels and replacing folds them into the canonical label', function () {
    $this->actingAs(dispatchMakeUser(1));
    panelTask('A', ['area:acct']);
    panelTask('B', ['acct']);
    panelTask('C', ['area:accounts']);
    $ids = Label::query()->whereIn('name', ['area:acct', 'acct'])->pluck('id')->map(fn ($id) => (string) $id)->all();

    Livewire::test(LabelPanel::class)
        ->set('selected', $ids)
        ->set('replaceWith', 'area:accounts')
        ->assertSee('existing label')
        ->assertSee('2 task(s) change')
        ->call('replaceSelected')
        ->assertSet('selected', [])
        ->assertSet('replaceWith', '')
        ->assertSee('Replaced 2 labels with “area:accounts” on 2 task(s)');

    expect(Label::query()->pluck('name')->all())->toBe(['area:accounts'])
        ->and(LabelAlias::query()->orderBy('name')->pluck('name')->all())->toBe(['acct', 'area:acct']);
});

test('retire removes the selected labels and the census follows', function () {
    $this->actingAs(dispatchMakeUser(1));
    panelTask('A', ['noise', 'keep']);

    Livewire::test(LabelPanel::class)
        ->set('selected', [(string) Label::query()->where('name', 'noise')->value('id')])
        ->assertSee('Retire selected')
        ->call('retireSelected')
        ->assertSee('Retired “noise” from 1 task(s).')
        ->assertSee('1 labels');

    expect(Label::query()->pluck('name')->all())->toBe(['keep']);
});

test('select all shown adds exactly the filtered rows', function () {
    $this->actingAs(dispatchMakeUser(1));
    panelTask('A', ['busy', 'once']);
    panelTask('B', ['busy']);
    Label::query()->create(['name' => 'orphan']);

    $component = Livewire::test(LabelPanel::class)
        ->set('maxUses', '1')
        ->call('selectShown');

    $selectedNames = Label::query()->whereKey($component->get('selected'))->orderBy('name')->pluck('name')->all();
    expect($selectedNames)->toBe(['once', 'orphan']);
});

test('removing an alias frees the old name', function () {
    $this->actingAs(dispatchMakeUser(1));
    panelTask('A', ['old']);
    panelTask('B', ['new']);
    app(\Sgrjr\Dispatch\Services\LabelCleanupService::class)->replace([Label::query()->where('name', 'old')->value('id')], 'new');
    $alias = LabelAlias::query()->sole();

    Livewire::test(LabelPanel::class)
        ->assertSee('old')
        ->call('removeAlias', $alias->id);

    expect(LabelAlias::query()->count())->toBe(0)
        ->and(LabelAlias::canonicalize(['old']))->toBe(['old']);
});

test('the /labels route is registered, linked in the nav for staff, and redirects non-staff to the portal', function () {
    expect(Route::has('dispatch.labels'))->toBeTrue();

    $this->actingAs(dispatchMakeUser(1));
    $this->get(route('dispatch.labels'))->assertOk()->assertSee('href="'.route('dispatch.labels').'"', false);

    app()->singleton(DispatchGate::class, fn () => new class implements DispatchGate
    {
        public function isStaff(?Authenticatable $user): bool
        {
            return $user !== null && (bool) ($user->is_staff ?? false);
        }

        public function canSeeAll(?Authenticatable $user): bool
        {
            return false;
        }

        public function scopeVisible(Builder $query, ?Authenticatable $user): Builder
        {
            return $query->where('submitter_user_id', $user?->getAuthIdentifier());
        }
    });

    $this->actingAs(dispatchMakeUser(42));
    Livewire::test(LabelPanel::class)->assertRedirect(route('dispatch.portal'));
});
