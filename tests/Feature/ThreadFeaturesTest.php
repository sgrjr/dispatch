<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Livewire;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Contracts\DispatchNotifier;
use Sgrjr\Dispatch\Livewire\TaskThread;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/**
 * TaskThread::save() — every saved comment is handed to DispatchNotifier
 * (N1), a staff commenter is auto-watched (F4), and the existing
 * internal/public visibility filter in render() keeps working (F2's
 * markdown change doesn't touch that filter).
 */

test('posting a public comment fires the notifier taskCommented hook exactly once', function () {
    $spy = new class implements DispatchNotifier
    {
        /** @var array<int,TaskComment> */
        public array $commented = [];

        public function taskCreated(Task $task): void
        {
            //
        }

        public function taskStatusChanged(Task $task, string $from, string $to, ?Authenticatable $actor): void
        {
            //
        }

        public function taskCommented(Task $task, TaskComment $comment): void
        {
            $this->commented[] = $comment;
        }

        public function taskAssigned(Task $task, ?int $from, ?int $to, ?Authenticatable $actor): void
        {
            //
        }
    };

    app()->singleton(DispatchNotifier::class, fn () => $spy);

    $author = dispatchMakeUser(31);
    $submitter = dispatchMakeUser(32);

    $task = app(DispatchTaskService::class)->create([
        'title' => 'Notify me',
        'submitter_user_id' => $submitter->id,
    ]);

    $this->actingAs($author);

    Livewire::test(TaskThread::class, ['task' => $task])
        ->set('body', 'Here is a public update')
        ->set('is_public', true)
        ->call('save');

    expect($spy->commented)->toHaveCount(1);
    expect($spy->commented[0]->is_internal)->toBeFalse();
    expect($spy->commented[0]->body)->toBe('Here is a public update');
});

test('an internal comment also fires the notifier taskCommented hook', function () {
    $spy = new class implements DispatchNotifier
    {
        /** @var array<int,TaskComment> */
        public array $commented = [];

        public function taskCreated(Task $task): void
        {
            //
        }

        public function taskStatusChanged(Task $task, string $from, string $to, ?Authenticatable $actor): void
        {
            //
        }

        public function taskCommented(Task $task, TaskComment $comment): void
        {
            $this->commented[] = $comment;
        }

        public function taskAssigned(Task $task, ?int $from, ?int $to, ?Authenticatable $actor): void
        {
            //
        }
    };

    app()->singleton(DispatchNotifier::class, fn () => $spy);

    $author = dispatchMakeUser(33);
    $task = app(DispatchTaskService::class)->create(['title' => 'Internal note']);

    $this->actingAs($author);

    // The shipped DefaultGate treats any authenticated user as staff, and a
    // staff comment is an INTERNAL note unless it opts into public.
    Livewire::test(TaskThread::class, ['task' => $task])
        ->set('body', 'Staff-only heads up')
        ->call('save');

    expect($spy->commented)->toHaveCount(1);
    expect($spy->commented[0]->is_internal)->toBeTrue();
});

test('a staff commenter is auto-added as a watcher after posting a comment', function () {
    $author = dispatchMakeUser(41);

    $task = app(DispatchTaskService::class)->create(['title' => 'Watch after commenting']);

    expect($task->isWatchedBy($author->id))->toBeFalse();

    $this->actingAs($author);

    Livewire::test(TaskThread::class, ['task' => $task])
        ->set('body', 'I am on it')
        ->call('save');

    expect($task->fresh()->isWatchedBy($author->id))->toBeTrue();
});

test('an internal comment stays hidden from a non-staff viewer', function () {
    // A gate distinguishing staff from non-staff (unlike the shipped
    // DefaultGate, where every authenticated user is staff) so the
    // is_internal filter in TaskThread::render() has something to filter.
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

    $submitter = dispatchMakeUser(51);

    $task = app(DispatchTaskService::class)->create([
        'title' => 'Visibility check',
        'is_public' => true,
        'submitter_user_id' => $submitter->id,
    ]);

    $task->comments()->create([
        'body' => 'Internal note not for customer eyes',
        'is_internal' => true,
        'event_type' => TaskComment::EVENT_COMMENT,
    ]);
    $task->comments()->create([
        'body' => 'Public update visible to everyone',
        'is_internal' => false,
        'event_type' => TaskComment::EVENT_COMMENT,
    ]);

    $this->actingAs($submitter);

    Livewire::test(TaskThread::class, ['task' => $task])
        ->assertSee('Public update visible to everyone')
        ->assertDontSee('Internal note not for customer eyes');
});

// ── comment visibility: internal by default, public is the opt-in ──────────

test('a staff comment is an INTERNAL note by default, and the composer says so', function () {
    $this->actingAs(dispatchMakeUser(61));
    $task = app(DispatchTaskService::class)->create(['title' => 'Default visibility']);

    Livewire::test(TaskThread::class, ['task' => $task])
        ->assertSee('Internal note: staff only')
        ->assertSee('Add internal note')
        ->set('body', 'Just between us')
        ->call('save');

    expect($task->comments()->where('body', 'Just between us')->value('is_internal'))->toBeTrue();
});

test('opting into public names the consequence before it is sent', function () {
    $this->actingAs(dispatchMakeUser(62));
    $task = app(DispatchTaskService::class)->create(['title' => 'Public reply']);

    Livewire::test(TaskThread::class, ['task' => $task])
        ->set('is_public', true)
        ->assertSee('Public reply: visible to the submitter.')
        ->assertSee('Send public reply')
        ->set('body', 'Fixed — thanks for reporting it')
        ->call('save');

    expect($task->comments()->where('body', 'Fixed — thanks for reporting it')->value('is_internal'))->toBeFalse();
});

test('a submitter (not staff) always writes publicly — an internal note would hide their own reply', function () {
    $submitter = dispatchMakeUser(63);
    app()->singleton(\Sgrjr\Dispatch\Contracts\DispatchGate::class, fn () => new class implements \Sgrjr\Dispatch\Contracts\DispatchGate
    {
        public function isStaff(?Authenticatable $user): bool
        {
            return false;
        }

        public function canSeeAll(?Authenticatable $user): bool
        {
            return false;
        }

        public function scopeVisible(\Illuminate\Database\Eloquent\Builder $query, ?Authenticatable $user): \Illuminate\Database\Eloquent\Builder
        {
            return $query;
        }
    });
    $task = app(DispatchTaskService::class)->create(['title' => 'From a customer', 'submitter_user_id' => $submitter->id]);
    $this->actingAs($submitter);

    Livewire::test(TaskThread::class, ['task' => $task])
        ->assertDontSee('Add internal note')
        ->set('body', 'Any update?')
        ->call('save');

    expect($task->comments()->where('body', 'Any update?')->value('is_internal'))->toBeFalse();
});

test('dispatch:note is internal by default; --public opts in', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'CLI notes']);

    $this->artisan('dispatch:note', ['code' => $task->code, 'body' => 'agent finding'])->assertSuccessful();
    $this->artisan('dispatch:note', ['code' => $task->code, 'body' => 'shipped — try it now', '--public' => true])->assertSuccessful();

    expect($task->comments()->where('body', 'agent finding')->value('is_internal'))->toBeTrue()
        ->and($task->comments()->where('body', 'shipped — try it now')->value('is_internal'))->toBeFalse();
});

test('a batch comment is internal unless it says public (or the legacy internal:false)', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'Batch notes']);

    app(\Sgrjr\Dispatch\Services\DispatchBatchService::class)->apply([
        ['op' => 'update', 'code' => $task->code, 'comments' => [
            ['body' => 'default note'],
            ['body' => 'public note', 'public' => true],
            ['body' => 'legacy public', 'internal' => false],
        ]],
    ]);

    $vis = $task->comments()->pluck('is_internal', 'body');
    expect((bool) $vis['default note'])->toBeTrue()
        ->and((bool) $vis['public note'])->toBeFalse()
        ->and((bool) $vis['legacy public'])->toBeFalse();
});

test('the agent API note is internal unless it says public', function () {
    expect(\Sgrjr\Dispatch\Http\Controllers\AgentController::noteIsInternal([]))->toBeTrue()
        ->and(\Sgrjr\Dispatch\Http\Controllers\AgentController::noteIsInternal(['public' => true]))->toBeFalse()
        ->and(\Sgrjr\Dispatch\Http\Controllers\AgentController::noteIsInternal(['internal' => false]))->toBeFalse()
        ->and(\Sgrjr\Dispatch\Http\Controllers\AgentController::noteIsInternal(['internal' => true, 'public' => false]))->toBeTrue();
});