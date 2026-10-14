<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Artisan;
use Sgrjr\Dispatch\Contracts\DispatchNotifier;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;

/*
 * TASK-1530: `dispatch:edit --assignee=` is a plain reassign of the SAME task
 * in any status. No lane move and no new task (handoff mints one across
 * lanes; claim refuses anything past open/triage). Recorded and announced the
 * way the board's assignee control does it.
 */

beforeEach(function () {
    dispatchFakeUsers();

    $this->spy = new class implements DispatchNotifier
    {
        /** @var array<int,array{0:?int,1:?int}> */
        public array $assigned = [];

        public function taskCreated(Task $task): void {}

        public function taskStatusChanged(Task $task, string $from, string $to, ?Authenticatable $actor): void {}

        public function taskCommented(Task $task, TaskComment $comment): void {}

        public function taskAssigned(Task $task, ?int $from, ?int $to, ?Authenticatable $actor): void
        {
            $this->assigned[] = [$from, $to];
        }
    };
    app()->singleton(DispatchNotifier::class, fn () => $this->spy);
});

test('an in-progress task is reassigned in place: same task, same lane, same status', function () {
    $from = dispatchMakeUser(1501);
    $to = dispatchMakeUser(1502, ['email' => 'next@example.test']);
    $task = Task::createWithCode([
        'title' => 'Stale-order watch', 'status' => 'in_progress',
        'lane' => 'fulfillment', 'assignee_user_id' => $from->id,
    ]);

    expect(Artisan::call('dispatch:edit', ['code' => $task->code, '--assignee' => 'next@example.test']))->toBe(0);

    $task->refresh();
    expect($task->assignee_user_id)->toBe($to->id)
        ->and($task->lane)->toBe('fulfillment')
        ->and($task->status)->toBe('in_progress')
        ->and(Task::count())->toBe(1)
        ->and($task->comments()->where('event_type', TaskComment::EVENT_ASSIGNEE_CHANGE)->sole()->body)->toBe('Assignee updated.')
        ->and($this->spy->assigned)->toBe([[$from->id, $to->id]]);
});

test('--assignee=none unassigns, and the same holder again records nothing', function () {
    $holder = dispatchMakeUser(1503);
    $task = Task::createWithCode(['title' => 'Held', 'status' => 'in_progress', 'assignee_user_id' => $holder->id]);

    Artisan::call('dispatch:edit', ['code' => $task->code, '--assignee' => 'none']);
    expect($task->fresh()->assignee_user_id)->toBeNull();

    Artisan::call('dispatch:edit', ['code' => $task->code, '--assignee' => 'none']);
    expect($task->comments()->where('event_type', TaskComment::EVENT_ASSIGNEE_CHANGE)->count())->toBe(1)
        ->and($this->spy->assigned)->toBe([[$holder->id, null]]);
});

test('an unknown assignee is refused before anything is written', function () {
    $task = Task::createWithCode(['title' => 'Untouched', 'status' => 'in_progress']);

    expect(Artisan::call('dispatch:edit', ['code' => $task->code, '--assignee' => 'nobody@example.test', '--title' => 'Changed']))->toBe(1)
        ->and($task->fresh()->title)->toBe('Untouched')
        ->and($this->spy->assigned)->toBe([]);
});
