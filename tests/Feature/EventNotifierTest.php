<?php

use Illuminate\Support\Facades\Event;
use Sgrjr\Dispatch\Contracts\DispatchNotifier;
use Sgrjr\Dispatch\Events\TaskAssigned;
use Sgrjr\Dispatch\Events\TaskCommented;
use Sgrjr\Dispatch\Events\TaskCreated;
use Sgrjr\Dispatch\Events\TaskStatusChanged;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\EventNotifier;

/**
 * EventNotifier (C6) turns the DispatchNotifier fire-and-forget hooks into
 * real Laravel events (TaskCreated, TaskStatusChanged, TaskCommented,
 * TaskAssigned) so a host can bind it via
 * `config('dispatch.contracts.notifier')` and react — e.g. auto-spawn an
 * agent on TaskCreated — without the package editing any mutation site.
 */

test('creating a task through the service fires the TaskCreated event when EventNotifier is bound', function () {
    Event::fake([TaskCreated::class, TaskStatusChanged::class, TaskCommented::class, TaskAssigned::class]);

    app()->singleton(DispatchNotifier::class, fn () => new EventNotifier());

    $task = app(DispatchTaskService::class)->create(['title' => 'Reactive task']);

    Event::assertDispatched(TaskCreated::class, fn (TaskCreated $event) => $event->task->is($task));
});

test('EventNotifier::taskCreated dispatches TaskCreated with the task', function () {
    Event::fake([TaskCreated::class]);

    $task = app(DispatchTaskService::class)->create(['title' => 'Direct call']);

    (new EventNotifier())->taskCreated($task);

    Event::assertDispatched(TaskCreated::class, fn (TaskCreated $event) => $event->task->is($task));
});

test('EventNotifier::taskStatusChanged dispatches TaskStatusChanged with from/to/actor', function () {
    Event::fake([TaskStatusChanged::class]);

    $actor = dispatchMakeUser(1);
    $task = app(DispatchTaskService::class)->create(['title' => 'Status change']);

    (new EventNotifier())->taskStatusChanged($task, 'open', 'in_progress', $actor);

    Event::assertDispatched(TaskStatusChanged::class, function (TaskStatusChanged $event) use ($task, $actor) {
        return $event->task->is($task)
            && $event->from === 'open'
            && $event->to === 'in_progress'
            && $event->actor->is($actor);
    });
});

test('EventNotifier::taskCommented dispatches TaskCommented with the comment', function () {
    Event::fake([TaskCommented::class]);

    $task = app(DispatchTaskService::class)->create(['title' => 'Commented task']);
    $comment = new \Sgrjr\Dispatch\Models\TaskComment(['body' => 'hello']);

    (new EventNotifier())->taskCommented($task, $comment);

    Event::assertDispatched(TaskCommented::class, function (TaskCommented $event) use ($task, $comment) {
        return $event->task->is($task) && $event->comment === $comment;
    });
});

test('EventNotifier::taskAssigned dispatches TaskAssigned with from/to/actor', function () {
    Event::fake([TaskAssigned::class]);

    $actor = dispatchMakeUser(2);
    $task = app(DispatchTaskService::class)->create(['title' => 'Assigned task']);

    (new EventNotifier())->taskAssigned($task, null, 5, $actor);

    Event::assertDispatched(TaskAssigned::class, function (TaskAssigned $event) use ($task, $actor) {
        return $event->task->is($task)
            && $event->from === null
            && $event->to === 5
            && $event->actor->is($actor);
    });
});

test('EventNotifier methods never throw with null actor and minimal args', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'No throw']);
    $notifier = new EventNotifier();

    $notifier->taskCreated($task);
    $notifier->taskStatusChanged($task, 'open', 'in_progress', null);
    $notifier->taskCommented($task, new \Sgrjr\Dispatch\Models\TaskComment(['body' => 'hi']));
    $notifier->taskAssigned($task, null, null, null);

    expect(true)->toBeTrue();
});

test('EventNotifier never throws even when a listener itself throws', function () {
    // Proves the try/catch is doing real work, not just decoration: a
    // synchronously-invoked listener that blows up must not escape the
    // notifier call, since callers (DispatchTaskService, Livewire actions)
    // invoke DispatchNotifier methods with no guard of their own.
    Event::listen(TaskCreated::class, function () {
        throw new \RuntimeException('listener boom');
    });

    $task = app(DispatchTaskService::class)->create(['title' => 'Listener throws']);

    (new EventNotifier())->taskCreated($task);

    expect(true)->toBeTrue();
});
