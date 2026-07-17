<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Livewire\Livewire;
use Sgrjr\Dispatch\Contracts\DispatchNotifier;
use Sgrjr\Dispatch\Livewire\TaskShow;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/**
 * TaskShow feature coverage for this workstream: the inline description
 * editor's memorialize-then-overwrite behavior (F7), watch/unwatch toggling
 * (F4), DispatchNotifier routing on a status change (N3), and the
 * config-driven workflow vocab powering the status/type/priority selects
 * (F1). Staff-ness comes for free under the shipped DefaultGate: any
 * authenticated (dispatchMakeUser()) user is staff, matching how
 * BoardAccessTest/WatchersTest drive their staff-only paths.
 */

test('editing the description memorializes the previous body as a hidden event and shows the new body', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $task = app(DispatchTaskService::class)->create([
        'title' => 'Needs a better description',
        'description' => 'Old body text',
    ]);

    Livewire::test(TaskShow::class, ['task' => $task])
        ->set('editDescription', 'New body text')
        ->call('saveMeta')
        ->assertHasNoErrors();

    $fresh = $task->fresh();
    expect($fresh->description)->toBe('New body text');

    $memorial = $fresh->comments()
        ->where('event_type', TaskComment::EVENT_DESCRIPTION_EDITED)
        ->first();

    expect($memorial)->not->toBeNull();
    expect($memorial->body)->toBe('Old body text');
    expect($memorial->is_internal)->toBeTrue();
});

test('the Agent run panel renders the stamped metrics for staff', function () {
    $staff = dispatchMakeUser(40);
    $this->actingAs($staff);

    $task = app(DispatchTaskService::class)->create(['title' => 'agent-worked task']);
    $task->context = ['result' => ['commit' => 'abc1234', 'metrics' => [
        'window' => ['basis' => 'claimed_at'],
        'duration_s' => 754,
        'transcript' => ['source' => 'session-file'],
        'tokens' => ['input' => 1000, 'output' => 500, 'cache_read' => 8000, 'cache_creation' => 1500, 'total' => 11000, 'cache_hit_ratio' => 0.7273],
        'cost_usd' => 0.1234,
        'cost_partial' => false,
        'turns' => 8,
        'tool_calls' => 22,
        'tools' => ['Bash' => 10, 'Read' => 8],
        'subagents' => 2,
        'errors' => 1,
        'models' => ['claude-opus-4-8'],
    ]]];
    $task->save();

    Livewire::test(TaskShow::class, ['task' => $task])
        ->assertSee('Agent run')
        ->assertSee('11k')            // compact total tokens
        ->assertSee('12m 34s')        // humanized duration
        ->assertSee('$0.1234')        // cost
        ->assertSee('Bash · 10')      // top tool
        ->assertSee('claude-opus-4-8')
        ->assertSee('abc1234');       // commit from context.result
});

test('the Agent run panel is absent when a task has no stamped metrics', function () {
    $staff = dispatchMakeUser(41);
    $this->actingAs($staff);

    $task = app(DispatchTaskService::class)->create(['title' => 'never worked by an agent']);

    Livewire::test(TaskShow::class, ['task' => $task])
        ->assertDontSee('Agent run');
});

test('watch() and unwatch() toggle isWatchedBy for the current user', function () {
    $staff = dispatchMakeUser(2);
    $this->actingAs($staff);

    $task = app(DispatchTaskService::class)->create(['title' => 'Watch toggling']);

    expect($task->isWatchedBy($staff->id))->toBeFalse();

    $component = Livewire::test(TaskShow::class, ['task' => $task]);

    $component->call('watch');
    expect($task->fresh()->isWatchedBy($staff->id))->toBeTrue();

    $component->call('unwatch');
    expect($task->fresh()->isWatchedBy($staff->id))->toBeFalse();
});

test('a status change fires the notifier taskStatusChanged hook with the correct from/to', function () {
    $staff = dispatchMakeUser(3);
    $this->actingAs($staff);

    $spy = new class implements DispatchNotifier
    {
        /** @var array<int, array{0:string,1:string,2:mixed}> */
        public array $statusChanges = [];

        public function taskCreated(Task $task): void
        {
            //
        }

        public function taskStatusChanged(Task $task, string $from, string $to, ?Authenticatable $actor): void
        {
            $this->statusChanges[] = [$from, $to, $actor?->getAuthIdentifier()];
        }

        public function taskCommented(Task $task, TaskComment $comment): void
        {
            //
        }

        public function taskAssigned(Task $task, ?int $from, ?int $to, ?Authenticatable $actor): void
        {
            //
        }
    };

    app()->singleton(DispatchNotifier::class, fn () => $spy);

    $task = app(DispatchTaskService::class)->create([
        'title' => 'Status change fan-out',
        'status' => 'triage',
    ]);

    Livewire::test(TaskShow::class, ['task' => $task])
        ->set('status', 'open')
        ->call('saveMeta')
        ->assertHasNoErrors();

    expect($spy->statusChanges)->toHaveCount(1);
    expect($spy->statusChanges[0])->toBe(['triage', 'open', $staff->id]);
});

test('the status/type/priority selects are driven by the configured workflow vocab', function () {
    $staff = dispatchMakeUser(4);
    $this->actingAs($staff);

    config(['dispatch.workflow.statuses' => ['queued', 'active', 'closed']]);
    config(['dispatch.workflow.status_labels' => []]);

    $task = app(DispatchTaskService::class)->create([
        'title' => 'Config-driven statuses',
        'status' => 'queued',
    ]);

    $component = Livewire::test(TaskShow::class, ['task' => $task]);

    expect($component->viewData('statuses'))->toBe(['queued', 'active', 'closed']);
    expect($component->viewData('statusLabels'))->toBe([
        'queued' => 'Queued',
        'active' => 'Active',
        'closed' => 'Closed',
    ]);

    // The custom status passes saveMeta()'s validation because the `in:`
    // rule is now built from Task::statuses() (config-driven), not the
    // hardcoded Task::STATUSES const.
    $component->set('status', 'active')->call('saveMeta')->assertHasNoErrors();

    expect($task->fresh()->status)->toBe('active');
});

test('saving a due date persists it and clearing it back out nulls the column', function () {
    $staff = dispatchMakeUser(5);
    $this->actingAs($staff);

    $task = app(DispatchTaskService::class)->create(['title' => 'Due date round-trip']);

    Livewire::test(TaskShow::class, ['task' => $task])
        ->set('due_at', '2026-08-01')
        ->call('saveMeta')
        ->assertHasNoErrors();

    expect($task->fresh()->due_at?->toDateString())->toBe('2026-08-01');

    Livewire::test(TaskShow::class, ['task' => $task->fresh()])
        ->set('due_at', '')
        ->call('saveMeta')
        ->assertHasNoErrors();

    expect($task->fresh()->due_at)->toBeNull();
});

test('mergeInto() folds this task (loser) into the resolved target (winner) and redirects there', function () {
    $staff = dispatchMakeUser(6);
    $this->actingAs($staff);

    $service = app(DispatchTaskService::class);
    $loser = $service->create(['title' => 'Duplicate report']);
    $winner = $service->create(['title' => 'Canonical report']);

    Livewire::test(TaskShow::class, ['task' => $loser])
        ->set('mergeTargetCode', $winner->code)
        ->call('mergeInto')
        ->assertRedirect(route('dispatch.show', $winner));

    $freshLoser = Task::withTrashed()->find($loser->id);
    expect($freshLoser->trashed())->toBeTrue();
    expect($freshLoser->duplicate_of)->toBe($winner->id);

    expect($winner->fresh()->comments()->where('event_type', TaskComment::EVENT_MERGED)->exists())->toBeTrue();
});

test('the meta editor renders and keeps a working error bag even when the diagnostics panel is shown', function () {
    // Regression: the diagnostics panel reused a local $errors variable, which
    // clobbered the shared ViewErrorBag so the later @error(...) directives blew
    // up with "Call to a member function getBag() on array" — but only for a
    // task that HAS context (so the panel actually renders). Earlier tests all
    // used context-free tasks, so it slipped through.
    $staff = dispatchMakeUser(7);
    $this->actingAs($staff);

    $task = app(DispatchTaskService::class)->create([
        'title' => 'Has diagnostics context',
        'context' => ['console_errors' => [['type' => 'error', 'message' => 'boom', 'source' => 'app.js:1']]],
    ]);

    // Initial render must not throw on the @error directives after the panel...
    $component = Livewire::test(TaskShow::class, ['task' => $task])
        ->assertOk()
        ->assertSee('Diagnostics')
        ->assertSee('Console errors');

    // ...and the real validation error bag still flows through a re-render.
    $component->set('status', 'not-a-real-status')->call('saveMeta')->assertHasErrors('status');
});
