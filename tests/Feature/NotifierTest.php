<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Notification;
use Sgrjr\Dispatch\Contracts\DispatchNotifier;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Notifications\TaskUpdate;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\MailNotifier;
use Sgrjr\Dispatch\Support\NullNotifier;

/**
 * DispatchNotifier is the 4th portability seam: fire-and-forget hooks fired
 * at task mutation points (create/status-change/comment/assign). These
 * tests exercise the binding wiring (a spy bound over the singleton), the
 * shipped no-op default, and the shipped mail-backed default's
 * never-throw guarantee.
 */

test('creating a task through the service fires the notifier taskCreated hook exactly once', function () {
    $spy = new class implements DispatchNotifier
    {
        public int $created = 0;

        public function taskCreated(Task $task): void
        {
            $this->created++;
        }

        public function taskStatusChanged(Task $task, string $from, string $to, ?Authenticatable $actor): void
        {
            //
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

    $task = app(DispatchTaskService::class)->create(['title' => 'Something broke']);

    expect($task)->toBeInstanceOf(Task::class);
    expect($spy->created)->toBe(1);
});

test('NullNotifier methods run with no error and no side effects', function () {
    // Bind it as the active notifier too, so the service's own internal
    // taskCreated hook doesn't reach out to the (default) MailNotifier.
    app()->singleton(DispatchNotifier::class, fn () => new NullNotifier());

    $task = app(DispatchTaskService::class)->create(['title' => 'Quiet task']);
    $originalStatus = $task->status;

    $notifier = new NullNotifier();
    $notifier->taskCreated($task);
    $notifier->taskStatusChanged($task, 'open', 'in_progress', null);
    $notifier->taskCommented($task, new TaskComment(['body' => 'hi']));
    $notifier->taskAssigned($task, null, 5, null);

    expect($task->fresh()->status)->toBe($originalStatus);
});

test('MailNotifier::taskCreated never throws when the task has no submitter', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'Orphan task']);
    $task->submitter_user_id = null;
    $task->save();

    (new MailNotifier())->taskCreated($task->fresh());

    expect(true)->toBeTrue();
});

test('MailNotifier fans a status change out to submitter + watchers, excluding the actor', function () {
    Notification::fake();

    $submitter = dispatchMakeUser(1);
    $watcher = dispatchMakeUser(2);
    $actor = dispatchMakeUser(3);

    $task = app(DispatchTaskService::class)->create([
        'title' => 'Fan out',
        'submitter_user_id' => $submitter->id,
    ]);
    $task->watch($watcher->id);

    (new MailNotifier())->taskStatusChanged($task->fresh(), 'open', 'in_progress', $actor);

    Notification::assertSentTo([$submitter, $watcher], TaskUpdate::class);
    Notification::assertNotSentTo([$actor], TaskUpdate::class);
});
