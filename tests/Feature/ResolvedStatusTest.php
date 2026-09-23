<?php

use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Sgrjr\Dispatch\Exceptions\StatusNoteRequired;
use Sgrjr\Dispatch\Livewire\TaskBoard;
use Sgrjr\Dispatch\Livewire\TaskList;
use Sgrjr\Dispatch\Livewire\TaskShow;
use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\AgentSessionService;
use Sgrjr\Dispatch\Services\DispatchBatchService;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/*
 * TASK-1193 — the canon of "finished". Three CLOSED statuses, each meaning
 * exactly one thing:
 *   - done     = the prescribed work was completed, nothing left;
 *   - resolved = dealt with, but NOT as written — a note saying what happened
 *                is REQUIRED, refused server-side on every write path;
 *   - declined = not done, by decision.
 * All three close the task alike: dependents unblock, an ask returns the ball.
 */

beforeEach(fn () => dispatchFakeUsers());

function resolvedTestAgentToken(): string
{
    $svc = app(AgentSessionService::class);
    $req = $svc->request('claude-resolver', 'work the backlog');
    $svc->approve(AgentSession::where('public_id', $req['public_id'])->firstOrFail(), dispatchMakeUser(610)->id, null, ['done', 'batch']);

    return $svc->poll($req['public_id'], $req['device_code'])['token'];
}

// --- the vocabulary ----------------------------------------------------------

test('resolved is a status, and done/resolved/declined are the closed set; backburner is parked, not closed', function () {
    expect(Task::statuses())->toContain('resolved')
        ->and(Task::closedStatuses())->toBe(['done', 'resolved', 'declined'])
        ->and(Task::inactiveStatuses())->toBe(['backburner', 'done', 'resolved', 'declined'])
        ->and(Task::requiresStatusNote('resolved'))->toBeTrue()
        ->and(Task::requiresStatusNote('done'))->toBeFalse()
        ->and(Task::requiresStatusNote('declined'))->toBeFalse();

    expect((new Task(['status' => 'resolved']))->isClosed())->toBeTrue()
        ->and((new Task(['status' => 'backburner']))->isClosed())->toBeFalse()
        ->and((new Task(['status' => 'backburner']))->isInactive())->toBeTrue();
});

// --- ⛔ no note, no resolved: every write path ----------------------------------

test('⛔ the saving hook refuses resolved without a note (the one choke point)', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'Customer called', 'status' => 'open']);

    $task->status = 'resolved';
    expect(fn () => $task->save())->toThrow(StatusNoteRequired::class);
    expect($task->fresh()->status)->toBe('open');

    $task->withStatusNote('   ');
    expect(fn () => $task->save())->toThrow(StatusNoteRequired::class);
});

test('⛔ filing a task straight into resolved needs a note too', function () {
    expect(fn () => app(DispatchTaskService::class)->create(['title' => 'x', 'status' => 'resolved']))
        ->toThrow(StatusNoteRequired::class);

    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'status' => 'resolved', 'status_note' => 'Handled on the phone.']);
    expect($task->fresh()->status)->toBe('resolved');
});

test('⛔ dispatch:done --status=resolved without --note fails and writes nothing', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'Plan change', 'status' => 'open']);

    $this->artisan('dispatch:done', ['code' => $task->code, '--status' => 'resolved', '--local' => true])->assertFailed();

    expect($task->fresh()->status)->toBe('open')
        ->and($task->comments()->where('event_type', TaskComment::EVENT_STATUS_CHANGE)->count())->toBe(0);
});

test('dispatch:done --status=resolved --note closes it, and the note is the status event body', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'Plan change', 'status' => 'open']);

    $this->artisan('dispatch:done', [
        'code' => $task->code,
        '--status' => 'resolved',
        '--note' => "Customer changed their mind on the phone.\nNo plan edit needed.",
        '--local' => true,
    ])->assertOk();

    expect($task->fresh()->status)->toBe('resolved');
    $event = $task->comments()->where('event_type', TaskComment::EVENT_STATUS_CHANGE)->firstOrFail();
    expect($event->body)->toBe("Status changed from `open` to `resolved`.\n\nCustomer changed their mind on the phone.\nNo plan edit needed.")
        ->and($event->meta['note'])->toBe("Customer changed their mind on the phone.\nNo plan edit needed.")
        ->and($event->meta['to'])->toBe('resolved');
});

test('⛔ the agent API refuses resolved without a note (422), accepts it with one', function () {
    $token = resolvedTestAgentToken();
    $task = app(DispatchTaskService::class)->create(['title' => 'Agent-handled', 'status' => 'open']);

    $response = $this->withToken($token)->postJson('api/dispatch/agent/done', ['code' => $task->code, 'status' => 'resolved'])
        ->assertStatus(422);
    expect($response->json('message'))->toContain('with a note')
        ->and($task->fresh()->status)->toBe('open');

    $this->withToken($token)->postJson('api/dispatch/agent/done', ['code' => $task->code, 'status' => 'resolved', 'note' => 'Fixed upstream instead.'])
        ->assertOk();

    expect($task->fresh()->status)->toBe('resolved');
    $event = $task->comments()->where('event_type', TaskComment::EVENT_STATUS_CHANGE)->firstOrFail();
    expect($event->body)->toContain('Fixed upstream instead.')
        ->and($event->meta['note'])->toBe('Fixed upstream instead.');
});

test('⛔ a batch update to resolved needs a note or a comment in the same op', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'Batch me', 'status' => 'open']);
    $batch = app(DispatchBatchService::class);

    expect(fn () => $batch->apply([['code' => $task->code, 'status' => 'resolved']]))
        ->toThrow(InvalidArgumentException::class, 'needs a note');
    expect($task->fresh()->status)->toBe('open');

    $batch->apply([['code' => $task->code, 'status' => 'resolved', 'comments' => [['body' => 'Superseded by TASK-9.']]]]);
    expect($task->fresh()->status)->toBe('resolved');

    $other = app(DispatchTaskService::class)->create(['title' => 'Batch me too', 'status' => 'open']);
    $batch->apply([['code' => $other->code, 'status' => 'resolved', 'note' => 'Done a different way.']]);
    expect($other->comments()->where('event_type', TaskComment::EVENT_STATUS_CHANGE)->firstOrFail()->meta['note'])
        ->toBe('Done a different way.');
});

test('⛔ a batch add straight into resolved needs a note, and the note lands on the timeline', function () {
    $batch = app(DispatchBatchService::class);

    expect(fn () => $batch->apply([['op' => 'add', 'title' => 'x', 'status' => 'resolved']]))
        ->toThrow(InvalidArgumentException::class, 'needs a note');

    $batch->apply([['op' => 'add', 'title' => 'Handled already', 'status' => 'resolved', 'note' => 'Handled in person.']]);
    $task = Task::query()->where('title', 'Handled already')->firstOrFail();
    expect($task->status)->toBe('resolved')
        ->and($task->comments()->where('event_type', TaskComment::EVENT_COMMENT)->pluck('body')->all())->toBe(['Handled in person.']);
});

test('⛔ TaskShow refuses resolved without a note, then records it with one', function () {
    $this->actingAs(dispatchMakeUser(620));
    $task = app(DispatchTaskService::class)->create(['title' => 'In the UI', 'status' => 'open']);

    Livewire::test(TaskShow::class, ['task' => $task])
        ->set('status', 'resolved')
        ->call('saveMeta')
        ->assertHasErrors('statusNote');
    expect($task->fresh()->status)->toBe('open');

    Livewire::test(TaskShow::class, ['task' => $task->fresh()])
        ->set('status', 'resolved')
        ->set('statusNote', 'Partly done: the rest is TASK-12.')
        ->call('saveMeta')
        ->assertHasNoErrors();

    expect($task->fresh()->status)->toBe('resolved');
    $event = $task->comments()->where('event_type', TaskComment::EVENT_STATUS_CHANGE)->firstOrFail();
    expect($event->body)->toContain('Partly done: the rest is TASK-12.')
        ->and($event->meta['note'])->toBe('Partly done: the rest is TASK-12.');
});

test('⛔ a board drag or bulk move into resolved is refused with a notice (there is nowhere to type the note)', function () {
    $this->actingAs(dispatchMakeUser(621));
    $task = app(DispatchTaskService::class)->create(['title' => 'Dragged', 'status' => 'open']);

    Livewire::test(TaskBoard::class)
        ->call('moveCard', $task->id, 'resolved', 0)
        ->assertSet('statusNotice', fn ($v) => is_string($v) && str_contains($v, 'note'));
    expect($task->fresh()->status)->toBe('open');

    Livewire::test(TaskBoard::class)
        ->set('selectedIds', [$task->id])
        ->set('bulkStatus', 'resolved')
        ->call('bulkApplyStatus')
        ->assertSet('statusNotice', fn ($v) => is_string($v) && str_contains($v, 'note'));
    expect($task->fresh()->status)->toBe('open');

    Livewire::test(TaskList::class)
        ->set('selected', [$task->id])
        ->set('bulkAction', 'status')
        ->set('bulkStatusValue', 'resolved')
        ->call('bulkApply');
    expect($task->fresh()->status)->toBe('open');
});

test('import/sync replay history: a mirrored resolved task needs no fresh note', function () {
    $task = Task::replayingHistory(fn () => app(DispatchTaskService::class)->create(['title' => 'Mirrored', 'status' => 'resolved']));

    expect($task->fresh()->status)->toBe('resolved');
});

// --- closed means closed ------------------------------------------------------

test('resolved is CLOSED: a plain dependent is told its blocker resolved', function () {
    $tasks = app(DispatchTaskService::class);
    $dependent = $tasks->create(['title' => 'Waits', 'status' => 'open']);
    $blocker = $tasks->create(['title' => 'Blocks', 'status' => 'open']);
    $tasks->linkBlockedBy($dependent, $blocker);

    expect(Task::query()->blocked()->pluck('code')->all())->toBe([$dependent->code]);

    $this->artisan('dispatch:done', ['code' => $blocker->code, '--status' => 'resolved', '--note' => 'Went away.', '--local' => true])->assertOk();

    expect(Task::query()->blocked()->pluck('code')->all())->toBe([])
        ->and($dependent->comments()->where('event_type', TaskComment::EVENT_DEPENDENCY_RESOLVED)->firstOrFail()->meta['status'])
        ->toBe('resolved');
});

test('resolved is CLOSED: an ask closed as resolved returns the ball, with the note as the answer', function () {
    $asker = dispatchMakeUser(630);
    $recipient = dispatchMakeUser(631);
    $tasks = app(DispatchTaskService::class);
    $task = $tasks->create(['title' => 'Mine', 'assignee_user_id' => $asker->id]);
    $tasks->handoff($task, $recipient, $asker, ['ask' => true, 'note' => 'Can you check X?']);
    $ask = Task::query()->where('origin_type', 'task')->where('origin_id', $task->code)->firstOrFail();

    $this->artisan('dispatch:done', ['code' => $ask->code, '--status' => 'resolved', '--note' => 'Checked a different way: Y is fine.', '--local' => true])->assertOk();

    $fresh = $task->fresh();
    expect($fresh->assignee_user_id)->toBe($asker->id);
    $answer = $fresh->comments()->where('event_type', TaskComment::EVENT_ANSWERED)->firstOrFail();
    expect($answer->meta['answer'])->toBe('Checked a different way: Y is fine.');
});

test('a capture never revives a resolved task (a recurrence files fresh)', function () {
    $tasks = app(DispatchTaskService::class);
    $first = $tasks->capture('sig-resolved', ['title' => 'Boom']);
    $first->withStatusNote('Worked around in config.');
    $first->status = 'resolved';
    $first->save();

    $second = $tasks->capture('sig-resolved', ['title' => 'Boom']);

    expect($second->code)->not->toBe($first->code);
});

test('the actionable census and the queue leave resolved out; --status=resolved counts it', function () {
    $tasks = app(DispatchTaskService::class);
    $tasks->create(['title' => 'Open', 'status' => 'open']);
    $tasks->create(['title' => 'R', 'status' => 'resolved', 'status_note' => 'Handled.']);

    Artisan::call('dispatch:queue', ['--count' => true, '--json' => true, '--local' => true]);
    expect(dispatchJson(Artisan::output())['total'])->toBe(1);

    Artisan::call('dispatch:queue', ['--count' => true, '--status' => 'resolved', '--json' => true, '--local' => true]);
    expect(dispatchJson(Artisan::output()))->toBe(['total' => 1, 'by_status' => ['resolved' => 1]]);
});

// --- the guard: never again a hard-coded closed list ----------------------------

test('no hard-coded done/declined status list remains in the package (use Task::closedStatuses())', function () {
    $root = dirname(__DIR__, 2);
    $offenders = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS));
    $files = iterator_to_array($it);
    foreach (glob($root.'/resources/views/**/*.blade.php') ?: [] as $f) {
        $files[] = new SplFileInfo($f);
    }
    foreach (glob($root.'/resources/views/*/*/*.blade.php') ?: [] as $f) {
        $files[] = new SplFileInfo($f);
    }

    foreach ($files as $file) {
        if (! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }
        $path = str_replace('\\', '/', $file->getPathname());
        if (str_ends_with($path, 'src/Models/Task.php')) {
            continue; // the one definition
        }
        foreach (file($path) as $n => $line) {
            if (preg_match("/\\[\\s*(?:'backburner',\\s*)?'done',\\s*(?:'resolved',\\s*)?'declined'\\s*\\]/", $line)) {
                $offenders[] = $path.':'.($n + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});
