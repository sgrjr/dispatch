<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Sgrjr\Dispatch\Contracts\DispatchNotifier;
use Sgrjr\Dispatch\Livewire\TaskBoard;
use Sgrjr\Dispatch\Models\Focus;
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

/*
 * Checkbox multi-filters (HasVocabMultiFilters): [] = all (param-free URL),
 * [''] = explicit none (still shows all), otherwise a strict vocab-ordered
 * subset — plus the column-visibility axis and the updated activity window.
 */

test('checkbox filters default to all — tasks of every type render', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'A bug', 'type' => 'bug', 'status' => 'open']);
    $svc->create(['title' => 'A chore', 'type' => 'chore', 'status' => 'open']);

    $component = Livewire::test(TaskBoard::class);

    expect($component->get('typeFilter'))->toBe([]);
    expect($component->viewData('byStatus')->get('open'))->toHaveCount(2);
});

test('unchecking a type hides it; re-checking the last one normalizes back to the param-free all state', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'A bug', 'type' => 'bug', 'status' => 'open']);
    $chore = $svc->create(['title' => 'A chore', 'type' => 'chore', 'status' => 'open']);

    $component = Livewire::test(TaskBoard::class)->call('toggleFilter', 'typeFilter', 'bug');

    // All-minus-bug, in vocab order — the stable-serialization invariant.
    expect($component->get('typeFilter'))->toBe(array_values(array_diff(Task::types(), ['bug'])));
    expect($component->viewData('byStatus')->get('open')->pluck('id')->all())->toBe([$chore->id]);

    // Re-checking bug completes the set again -> canonical [] (URL param clears).
    $component->call('toggleFilter', 'typeFilter', 'bug');
    expect($component->get('typeFilter'))->toBe([]);
    expect($component->viewData('byStatus')->get('open'))->toHaveCount(2);
});

test('unchecking every value yields the explicit-none sentinel and still shows everything', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    app(DispatchTaskService::class)->create(['title' => 'A bug', 'type' => 'bug', 'status' => 'open']);

    $component = Livewire::test(TaskBoard::class)->call('selectNoneFilter', 'typeFilter');

    expect($component->get('typeFilter'))->toBe(['']);
    expect($component->viewData('byStatus')->get('open'))->toHaveCount(1);

    $component->call('selectAllFilter', 'typeFilter');
    expect($component->get('typeFilter'))->toBe([]);
});

test('URL subsets hydrate and filter; garbage and legacy scalar params degrade to showing all', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    $bug = $svc->create(['title' => 'A bug', 'type' => 'bug', 'status' => 'open']);
    $svc->create(['title' => 'A chore', 'type' => 'chore', 'status' => 'open']);

    $component = Livewire::withQueryParams(['types' => ['bug']])->test(TaskBoard::class);
    expect($component->viewData('byStatus')->get('open')->pluck('id')->all())->toBe([$bug->id]);

    $component = Livewire::withQueryParams(['types' => ['nope']])->test(TaskBoard::class);
    expect($component->viewData('byStatus')->get('open'))->toHaveCount(2);

    // Pre-multi-select bookmark (?type=bug): scalar under the OLD alias — inert.
    $component = Livewire::withQueryParams(['type' => 'bug'])->test(TaskBoard::class);
    expect($component->viewData('byStatus')->get('open'))->toHaveCount(2);
});

test('a label subset filters via whereIn; the all state (not a whereIn of every name) keeps unlabeled tasks visible', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    $blocked = $svc->create(['title' => 'Blocked work', 'status' => 'open'], ['blocked']);
    $svc->create(['title' => 'Unlabeled work', 'status' => 'open']);
    $svc->create(['title' => 'Deferred work', 'status' => 'open'], ['deferred']);

    $component = Livewire::test(TaskBoard::class);
    expect($component->viewData('byStatus')->get('open'))->toHaveCount(3);

    // Uncheck 'deferred' -> strict subset ['blocked']: whereHas hides the
    // deferred task AND (inherently) the unlabeled one — same semantics as the
    // old single-label filter.
    $component->call('toggleFilter', 'labelFilter', 'deferred');
    expect($component->get('labelFilter'))->toBe(['blocked']);
    expect($component->viewData('byStatus')->get('open')->pluck('id')->all())->toBe([$blocked->id]);
});

test('a type subset filters the done column through the same closure', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'Done bug', 'type' => 'bug', 'status' => 'done']);
    $svc->create(['title' => 'Done chore', 'type' => 'chore', 'status' => 'done']);

    $component = Livewire::test(TaskBoard::class)->call('toggleFilter', 'typeFilter', 'chore');

    expect($component->viewData('doneTotal'))->toBe(1);
    expect($component->viewData('byStatus')->get('done')->pluck('type')->all())->toBe(['bug']);
});

test('unchecking a column hides it from the board, and a hidden done column skips its query entirely', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'Declined thing', 'status' => 'declined']);
    $svc->create(['title' => 'Done thing', 'status' => 'done']);

    $component = Livewire::test(TaskBoard::class)->call('toggleFilter', 'columnFilter', 'declined');

    expect($component->viewData('columns'))->toBe(array_values(array_diff(Task::statuses(), ['declined'])));

    $component->call('toggleFilter', 'columnFilter', 'done');
    expect($component->viewData('columns'))->not->toContain('done');
    expect($component->viewData('doneTotal'))->toBe(0);
});

test('the board updated window filters cards by activity, cumulatively like the list', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    $old = $svc->create(['title' => 'Ten days quiet', 'status' => 'open']);
    DB::table('dispatch_tasks')->where('id', $old->id)->update(['updated_at' => now()->subDays(10)]);
    $svc->create(['title' => 'Fresh today', 'status' => 'open']);

    $component = Livewire::test(TaskBoard::class)->set('updatedFilter', 'week');
    expect($component->viewData('byStatus')->get('open'))->toHaveCount(1);

    $component->set('updatedFilter', 'month');
    expect($component->viewData('byStatus')->get('open'))->toHaveCount(2);
});

test('the due filter thins the board, including the capped done column, through the shared closure', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'Open and overdue', 'status' => 'open', 'due_at' => now()->subDays(2)]);
    $svc->create(['title' => 'Open, no due date', 'status' => 'open']);
    $svc->create(['title' => 'Done, past-due date', 'status' => 'done', 'due_at' => now()->subDays(2)]);

    // 'overdue' never matches an inactive task — the done column empties.
    $component = Livewire::test(TaskBoard::class)->set('dueFilter', ['overdue']);
    expect($component->viewData('byStatus')->get('open'))->toHaveCount(1);
    expect($component->viewData('doneTotal'))->toBe(0);

    // 'dated' does match it, through the same closure the done cap uses.
    $component->set('dueFilter', ['dated']);
    expect($component->viewData('doneTotal'))->toBe(1);
});

test('due badges render tiered by bucket, and inactive tasks with a due date render the muted badge', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'Open and overdue', 'status' => 'open', 'due_at' => now()->subDays(2)]);
    $svc->create(['title' => 'Open, due today', 'status' => 'open', 'due_at' => now()]);
    $svc->create(['title' => 'Done, past-due date', 'status' => 'done', 'due_at' => now()->subDays(2)]);
    $svc->create(['title' => 'Open, no due date', 'status' => 'open']);

    $html = Livewire::test(TaskBoard::class)->html();

    // One badge per dated task (three), none for the undated one.
    expect(substr_count($html, 'title="Due '))->toBe(3);
    expect(substr_count($html, '>due today<'))->toBe(1);
    expect(substr_count($html, '>due 2d ago<'))->toBe(2);

    // Tiers: the open overdue task is the ONLY red badge; due-today wears
    // is-warning alongside any stale badges (none here); the done past-due
    // task renders the muted default badge — its "due 2d ago" is never red.
    expect(substr_count($html, 'dispatch-badge is-danger" title="Due '))->toBe(1);
    expect(substr_count($html, 'dispatch-badge is-warning" title="Due '))->toBe(1);
    expect(substr_count($html, 'dispatch-badge" title="Due '))->toBe(1);
});

/*
 * W8-1a — grouped label filter + elevated card chips.
 * W8-2  — focus steering (view filter + save current filters as a focus).
 * W8-5  — swimlanes. Fixtures lean on the shipped namespace_kinds map
 * (area:* / epic:* = elevated, source:* / kind:* = meta) so the facet
 * behavior is exercised without touching per-label `kind` columns.
 */

test('the label filter renders grouped sections — an elevated namespace heads its own section, meta sits under Meta', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'Accounts area work', 'status' => 'open'], ['area:accounts']);
    $svc->create(['title' => 'Thrown from an exception', 'status' => 'open'], ['source:exception']);

    $component = Livewire::test(TaskBoard::class);

    // Elevated `area:*` heads an 'Area' section; `source:*` collects under 'Meta'.
    $component->assertSeeHtml('>Area</div>')->assertSee('area:accounts');
    $component->assertSeeHtml('>Meta</div>')->assertSee('source:exception');
});

test('board cards show an elevated label chip but never a meta chip', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'Accounts work', 'status' => 'open'], ['area:accounts']);
    $svc->create(['title' => 'From an exception', 'status' => 'open'], ['source:exception']);

    $html = Livewire::test(TaskBoard::class)->html();

    // Exactly one card carries a chips block — the elevated task's — and it
    // renders the elevated chip class. (Match the rendered attribute, not the
    // `.dispatch-card-chips` CSS selector in the <style> block.)
    expect(substr_count($html, 'class="dispatch-card-chips"'))->toBe(1);
    expect($html)->toContain('dispatch-label-elevated');

    // The meta label never renders as a CARD chip (that class only ever appears
    // in the detail context the board doesn't use).
    expect($html)->not->toContain('dispatch-label-meta');
});

test('an active focus constrains BOTH board queries; blank or unknown focus id shows all', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    $inFocus = $svc->create(['title' => 'Accounts work', 'status' => 'open'], ['area:accounts']);
    $svc->create(['title' => 'Unrelated work', 'status' => 'open']);

    $focus = Focus::create([
        'name' => 'Accounts',
        'filters' => ['labels' => ['area:accounts']],
        'rank' => 1,
        'is_active' => true,
    ]);

    // Selected -> only the matching task remains on the board.
    $component = Livewire::test(TaskBoard::class)->set('focusFilter', (string) $focus->id);
    expect($component->viewData('byStatus')->get('open')->pluck('id')->all())->toBe([$inFocus->id]);

    // '' -> unconstrained.
    $component->set('focusFilter', '');
    expect($component->viewData('byStatus')->get('open'))->toHaveCount(2);

    // Unknown/stale id -> unconstrained (never "match nothing").
    $component->set('focusFilter', '999999');
    expect($component->viewData('byStatus')->get('open'))->toHaveCount(2);
});

test('an inactive focus id does not constrain the board (the switcher only offers active focuses)', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'Accounts work', 'status' => 'open'], ['area:accounts']);
    $svc->create(['title' => 'Unrelated work', 'status' => 'open']);

    $inactive = Focus::create([
        'name' => 'Archived lens',
        'filters' => ['labels' => ['area:accounts']],
        'rank' => 1,
        'is_active' => false,
    ]);

    $component = Livewire::test(TaskBoard::class)->set('focusFilter', (string) $inactive->id);
    expect($component->viewData('byStatus')->get('open'))->toHaveCount(2);
    expect($component->viewData('focuses'))->toHaveCount(0);
});

test('saveCurrentAsFocus persists ONLY the constrained axes; a blank name creates nothing', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    // Two labels so a one-label selection is a STRICT subset (a constrained axis).
    $svc->create(['title' => 'Blocked work', 'status' => 'open'], ['blocked']);
    $svc->create(['title' => 'Deferred work', 'status' => 'open'], ['deferred']);

    $component = Livewire::test(TaskBoard::class)
        ->set('labelFilter', ['blocked'])
        ->set('newFocusName', 'Blocked only')
        ->call('saveCurrentAsFocus');

    $focus = Focus::query()->firstWhere('name', 'Blocked only');
    expect($focus)->not->toBeNull();
    // type/priority stayed "all" -> omitted; only the labels axis is stored.
    expect($focus->filters)->toBe(['labels' => ['blocked']]);
    expect(array_keys($focus->filters))->toBe(['labels']);
    expect($focus->is_active)->toBeTrue();
    expect($focus->rank)->toBe(1);
    expect($component->get('newFocusName'))->toBe('');

    // A blank/whitespace name is a silent no-op.
    $before = Focus::query()->count();
    $component->set('newFocusName', '   ')->call('saveCurrentAsFocus');
    expect(Focus::query()->count())->toBe($before);
});

test('saveCurrentAsFocus ranks each new focus above the current max', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    Focus::create(['name' => 'Existing', 'filters' => [], 'rank' => 7, 'is_active' => true]);

    Livewire::test(TaskBoard::class)
        ->set('newFocusName', 'Next up')
        ->call('saveCurrentAsFocus');

    expect(Focus::query()->firstWhere('name', 'Next up')->rank)->toBe(8);
});

test('swimlanes off renders the plain board; on splits each task into exactly one lane with the unlabeled task last under —', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    $accounts = $svc->create(['title' => 'Accounts work', 'status' => 'open'], ['area:accounts']);
    $onboarding = $svc->create(['title' => 'Onboarding epic', 'status' => 'open'], ['epic:onboarding']);
    $bare = $svc->create(['title' => 'No lane at all', 'status' => 'open']);

    // OFF: no lane headers, but the cards still render (spot-check one).
    $off = Livewire::test(TaskBoard::class);
    expect($off->viewData('lanes'))->toBe([]);
    $offHtml = $off->html();
    // Match the rendered <h3 class="dispatch-swimlane-head">, not the CSS rule.
    expect($offHtml)->not->toContain('class="dispatch-swimlane-head"');
    expect($offHtml)->toContain('data-task-id="'.$accounts->id.'"');

    // ON: lanes sorted with '—' last; every task on exactly one card.
    $on = Livewire::test(TaskBoard::class)->set('swimlanes', true);
    expect($on->viewData('lanes'))->toBe(['accounts', 'onboarding', '—']);

    $onHtml = $on->html();
    expect($onHtml)->toContain('class="dispatch-swimlane-head"');
    foreach ([$accounts, $onboarding, $bare] as $task) {
        expect(substr_count($onHtml, 'data-task-id="'.$task->id.'"'))->toBe(1);
    }

    // The '—' lane holds the unlabeled task.
    $dashRow = collect($on->viewData('laneRows'))->firstWhere('label', '—');
    expect($dashRow['byStatus']->get('open')->pluck('id')->all())->toBe([$bare->id]);
});

test('the done cap still bounds the total card count with swimlanes on', function () {
    config(['dispatch.board.done_limit' => 2]);

    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'Done A', 'status' => 'done'], ['area:accounts']);
    $svc->create(['title' => 'Done B', 'status' => 'done'], ['epic:onboarding']);
    $svc->create(['title' => 'Done C', 'status' => 'done']);
    $svc->create(['title' => 'Done D', 'status' => 'done']);

    $component = Livewire::test(TaskBoard::class)->set('swimlanes', true);

    // Global cap unchanged: only `done_limit` done tasks are selected...
    expect($component->viewData('doneShowing'))->toBe(2);
    expect($component->viewData('doneTotal'))->toBe(4);

    // ...and splitting them across lanes preserves that total — no duplication,
    // no lane lifting the cap.
    $doneAcrossLanes = collect($component->viewData('laneRows'))
        ->sum(fn ($row) => $row['byStatus']->get('done', collect())->count());
    expect($doneAcrossLanes)->toBe(2);
});
