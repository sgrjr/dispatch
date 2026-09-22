<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Sgrjr\Dispatch\Contracts\LaneResolver;
use Sgrjr\Dispatch\Livewire\TaskShow;
use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Models\TaskLink;
use Sgrjr\Dispatch\Notifications\TaskUpdate;
use Sgrjr\Dispatch\Services\AgentSessionService;
use Sgrjr\Dispatch\Services\DispatchBatchService;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\NullLaneResolver;
use Sgrjr\Dispatch\Support\TaskPresenter;
use Livewire\Livewire;

/*
 * TASK-997 part B — the ball: the hand-off (pass/ask) and real task->task
 * blocked-by links. Builds on part A (lanes — see LanesTest.php); the ball
 * contract (rulings R15/R22) is the spec this file exercises.
 */

beforeEach(fn () => dispatchFakeUsers());

/**
 * A dedicated fake LaneResolver for THIS file — deliberately NOT named
 * bindFakeLaneResolver() (LanesTest.php already defines that top-level
 * function; Pest loads every Feature file into one process, so a same-named
 * function here would fatal with "Cannot redeclare", and running this file
 * alone would leave LanesTest.php's helper undefined either way).
 *
 * Two lanes: `ops` (with a `ops:triage` sub-lane) and `support`.
 *
 * @param  array<int,array<int,string>>  $lanesByUser
 */
function bindHandoffLaneResolver(array $lanesByUser = []): void
{
    app()->singleton(LaneResolver::class, fn () => new class($lanesByUser) implements LaneResolver
    {
        private array $allLanes = ['ops', 'ops:triage', 'support'];

        public function __construct(private array $lanesByUser) {}

        public function isLane(string $lane): bool
        {
            return in_array($lane, $this->allLanes, true);
        }

        public function label(string $lane): ?string
        {
            return match ($lane) {
                'ops' => 'Ops',
                'ops:triage' => 'Ops · Triage',
                'support' => 'Support',
                default => null,
            };
        }

        public function lanes(): array
        {
            return $this->allLanes;
        }

        public function lanesFor(Authenticatable $user): array
        {
            return $this->lanesByUser[$user->getAuthIdentifier()] ?? [];
        }

        public function lanesManagedBy(Authenticatable $user): array
        {
            return [];
        }

        public function memberIds(string $lane): array
        {
            return [];
        }

        public function canRoute(Authenticatable $user): bool
        {
            return false;
        }
    });
}

/** Mint an approved agent session token scoped to $scopes (defaults to just 'handoff'). */
function handoffAgentToken(?array $scopes = ['handoff']): string
{
    static $approverId = 93000;
    $approverId++;

    $svc = app(AgentSessionService::class);
    $req = $svc->request('claude-remote', 'handoff');
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    $svc->approve($session, dispatchMakeUser($approverId)->id, null, $scopes);

    return $svc->poll($req['public_id'], $req['device_code'])['token'];
}

// --- migration / model / relations ---------------------------------------

test('the dispatch_task_links table exists, and TaskLink round-trips', function () {
    expect(\Illuminate\Support\Facades\Schema::hasTable('dispatch_task_links'))->toBeTrue();

    $a = app(DispatchTaskService::class)->create(['title' => 'a']);
    $b = app(DispatchTaskService::class)->create(['title' => 'b']);

    TaskLink::create(['task_id' => $a->id, 'blocked_by_task_id' => $b->id]);
    $link = TaskLink::firstOrFail();

    expect($link->kind)->toBe(TaskLink::KIND_BLOCKS) // the column DEFAULT, applied by the DB
        ->and($link->task_id)->toBe($a->id)
        ->and($link->blocked_by_task_id)->toBe($b->id);
});

test('Task::blockedBy()/blocks() are inverse relations', function () {
    $tasks = app(DispatchTaskService::class);
    $a = $tasks->create(['title' => 'a']);
    $b = $tasks->create(['title' => 'b']);

    $tasks->linkBlockedBy($a, $b);

    expect($a->blockedBy->pluck('code')->all())->toBe([$b->code])
        ->and($b->blocks->pluck('code')->all())->toBe([$a->code]);
});

test('scopeBlocked/scopeUnblocked reflect only ACTIVE (non-terminal) blockers', function () {
    $tasks = app(DispatchTaskService::class);
    $a = $tasks->create(['title' => 'a', 'status' => 'open']);
    $blocker = $tasks->create(['title' => 'blocker', 'status' => 'open']);

    $tasks->linkBlockedBy($a, $blocker);

    expect(Task::blocked()->pluck('id')->all())->toContain($a->id)
        ->and(Task::unblocked()->pluck('id')->all())->not->toContain($a->id);

    $blocker->status = 'done';
    $blocker->save();

    expect(Task::blocked()->pluck('id')->all())->not->toContain($a->id)
        ->and(Task::unblocked()->pluck('id')->all())->toContain($a->id);
});

// --- linkBlockedBy: self-link / cycle refused -----------------------------

test('linkBlockedBy refuses a self-link', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'x']);

    expect(fn () => app(DispatchTaskService::class)->linkBlockedBy($task, $task))
        ->toThrow(InvalidArgumentException::class);
});

test('linkBlockedBy refuses a transitive cycle', function () {
    $tasks = app(DispatchTaskService::class);
    $a = $tasks->create(['title' => 'a']);
    $b = $tasks->create(['title' => 'b']);
    $c = $tasks->create(['title' => 'c']);

    // a is blocked-by b, b is blocked-by c.
    $tasks->linkBlockedBy($a, $b);
    $tasks->linkBlockedBy($b, $c);

    // c blocked-by a would close the loop (a -> b -> c -> a).
    expect(fn () => $tasks->linkBlockedBy($c, $a))->toThrow(InvalidArgumentException::class);

    expect(TaskLink::where('task_id', $c->id)->where('blocked_by_task_id', $a->id)->exists())->toBeFalse();
});

test('linkBlockedBy is idempotent — re-linking the same pair returns the existing row', function () {
    $tasks = app(DispatchTaskService::class);
    $a = $tasks->create(['title' => 'a']);
    $b = $tasks->create(['title' => 'b']);

    $first = $tasks->linkBlockedBy($a, $b);
    $second = $tasks->linkBlockedBy($a, $b);

    expect($second->id)->toBe($first->id)
        ->and(TaskLink::count())->toBe(1);
});

// --- handoff(): PASS, same lane -------------------------------------------

test('handoff PASS within the same lane moves the ball on the SAME task with ONE assignee_change event', function () {
    bindHandoffLaneResolver(lanesByUser: [201 => ['ops'], 202 => ['ops']]);
    $from = dispatchMakeUser(201);
    $to = dispatchMakeUser(202);
    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'lane' => 'ops', 'assignee_user_id' => $from->id]);
    $countBefore = Task::count();

    $result = app(DispatchTaskService::class)->handoff($task, $to, $from, ['note' => 'your turn']);

    expect($result->code)->toBe($task->code)
        ->and($result->assignee_user_id)->toBe(202)
        ->and($result->lane)->toBe('ops')
        ->and(Task::count())->toBe($countBefore);

    $event = $task->fresh()->comments()->where('event_type', TaskComment::EVENT_ASSIGNEE_CHANGE)->firstOrFail();
    expect($event->meta['note'])->toBe('your turn');
});

test('handoff still works for a plain reassignment with the INERT NullLaneResolver bound', function () {
    // No bindHandoffLaneResolver() call — exercises the real shipped default.
    expect(app(LaneResolver::class))->toBeInstanceOf(NullLaneResolver::class);

    $tasks = app(DispatchTaskService::class);
    $from = dispatchMakeUser(210);
    $to = dispatchMakeUser(211);
    $task = $tasks->create(['title' => 'x', 'assignee_user_id' => $from->id]); // unrouted — the only lane state possible under NullLaneResolver
    $countBefore = Task::count();

    $result = $tasks->handoff($task, $to, $from);

    expect($result->code)->toBe($task->code)
        ->and($result->assignee_user_id)->toBe(211)
        ->and($result->lane)->toBeNull()
        ->and(Task::count())->toBe($countBefore); // nothing was minted — "nothing can create a lane"
});

// --- handoff(): PASS, cross-lane -------------------------------------------

test('handoff PASS across lanes mints exactly ONE new task in the recipient\'s lane and closes the original', function () {
    bindHandoffLaneResolver(lanesByUser: [220 => ['ops'], 221 => ['support']]);
    $from = dispatchMakeUser(220);
    $to = dispatchMakeUser(221);
    $task = app(DispatchTaskService::class)->create([
        'title' => 'cross-lane work',
        'lane' => 'ops',
        'assignee_user_id' => $from->id,
        'conversation_id' => 555,
    ]);
    $countBefore = Task::count();

    $result = app(DispatchTaskService::class)->handoff($task, $to, $from, ['note' => 'over to you']);

    expect($result->code)->not->toBe($task->code)
        ->and(Task::count())->toBe($countBefore + 1)
        ->and($result->lane)->toBe('support')
        ->and($result->assignee_user_id)->toBe(221)
        ->and($result->conversation_id)->toBe(555)
        ->and($result->origin_type)->toBe('task')
        ->and($result->origin_id)->toBe($task->code)
        ->and($result->description)->toBe('over to you')
        ->and($result->status)->toBe('open');

    $original = $task->fresh();
    expect($original->status)->toBe('done');

    $event = $original->comments()->where('event_type', TaskComment::EVENT_HANDED_OFF)->firstOrFail();
    expect($event->meta['continued_as'])->toBe($result->code);
});

test('handoff PASS across lanes carries the topic and the labels — the continuation IS that work', function () {
    bindHandoffLaneResolver(lanesByUser: [222 => ['ops'], 223 => ['support']]);
    $from = dispatchMakeUser(222);
    $to = dispatchMakeUser(223);
    $task = app(DispatchTaskService::class)->create([
        'title' => 'about an account',
        'lane' => 'ops',
        'topic_type' => 'account',
        'topic_id' => 'ACCT-1',
    ], ['arc:customer-plan-requests', 'request:cancel']);

    $result = app(DispatchTaskService::class)->handoff($task, $to, $from);

    // Without these the work silently leaves every "tasks about this account"
    // view and every arc filter the moment it crosses a department line.
    expect($result->code)->not->toBe($task->code)
        ->and($result->topic_type)->toBe('account')
        ->and($result->topic_id)->toBe('ACCT-1')
        ->and($result->labels->pluck('name')->sort()->values()->all())
        ->toBe(['arc:customer-plan-requests', 'request:cancel']);
});

test('handoff PASS --keep-open (opts) leaves the original task open', function () {
    bindHandoffLaneResolver(lanesByUser: [225 => ['ops'], 226 => ['support']]);
    $from = dispatchMakeUser(225);
    $to = dispatchMakeUser(226);
    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'lane' => 'ops', 'status' => 'open']);

    app(DispatchTaskService::class)->handoff($task, $to, $from, ['keep_open' => true]);

    expect($task->fresh()->status)->toBe('open');
});

test('handoff PASS with an ambiguous recipient lane throws "pick a lane"', function () {
    bindHandoffLaneResolver(lanesByUser: [230 => ['ops'], 231 => ['ops:triage', 'support']]);
    $from = dispatchMakeUser(230);
    $to = dispatchMakeUser(231);
    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'lane' => 'ops']);

    expect(fn () => app(DispatchTaskService::class)->handoff($task, $to, $from))
        ->toThrow(InvalidArgumentException::class);
});

test('handoff PASS honors an explicit lane pick, and rejects one the recipient doesn\'t work', function () {
    bindHandoffLaneResolver(lanesByUser: [235 => ['ops'], 236 => ['ops:triage', 'support']]);
    $from = dispatchMakeUser(235);
    $to = dispatchMakeUser(236);
    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'lane' => 'ops']);

    $result = app(DispatchTaskService::class)->handoff($task, $to, $from, ['lane' => 'support']);
    expect($result->lane)->toBe('support');

    $task2 = app(DispatchTaskService::class)->create(['title' => 'y', 'lane' => 'ops']);
    expect(fn () => app(DispatchTaskService::class)->handoff($task2, $to, $from, ['lane' => 'not-a-lane']))
        ->toThrow(InvalidArgumentException::class);
});

test('handoff PASS to a recipient who works NO lane at all lands in the no-department lane', function () {
    bindHandoffLaneResolver(lanesByUser: [240 => ['ops']]); // 241 works nothing
    $from = dispatchMakeUser(240);
    $to = dispatchMakeUser(241);
    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'lane' => 'ops']);

    $result = app(DispatchTaskService::class)->handoff($task, $to, $from);

    expect($result->code)->not->toBe($task->code)
        ->and($result->lane)->toBeNull();
});

// --- handoff(): ASK ---------------------------------------------------------

test('handoff ASK always mints a linked task, even within the SAME lane, and blocks the asker\'s task', function () {
    bindHandoffLaneResolver(lanesByUser: [250 => ['ops'], 251 => ['ops']]);
    $asker = dispatchMakeUser(250);
    $recipient = dispatchMakeUser(251);
    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'lane' => 'ops', 'assignee_user_id' => $asker->id]);

    $result = app(DispatchTaskService::class)->handoff($task, $recipient, $asker, ['ask' => true, 'note' => 'what\'s the status?']);

    // The ball never moves for an ask.
    expect($result->code)->toBe($task->code)
        ->and($result->fresh()->assignee_user_id)->toBe(250);

    $askTask = Task::query()->where('origin_type', 'task')->where('origin_id', $task->code)->firstOrFail();
    expect($askTask->lane)->toBe('ops')
        ->and($askTask->assignee_user_id)->toBe(251)
        ->and($askTask->description)->toBe('what\'s the status?');

    expect($task->fresh()->blockedBy->pluck('code')->all())->toBe([$askTask->code]);

    $event = $task->fresh()->comments()->where('event_type', TaskComment::EVENT_ASKED)->firstOrFail();
    expect($event->meta['blocked_by'])->toBe($askTask->code);
});

test('handoff ASK carries the topic but not the labels — a question ABOUT the work is not the work', function () {
    bindHandoffLaneResolver(lanesByUser: [252 => ['ops'], 253 => ['support']]);
    $asker = dispatchMakeUser(252);
    $recipient = dispatchMakeUser(253);
    $task = app(DispatchTaskService::class)->create([
        'title' => 'about an account',
        'lane' => 'ops',
        'topic_type' => 'account',
        'topic_id' => 'ACCT-2',
    ], ['source:widget', 'kind:investigate']);

    app(DispatchTaskService::class)->handoff($task, $recipient, $asker, ['ask' => true]);

    $askTask = Task::query()->where('origin_type', 'task')->where('origin_id', $task->code)->firstOrFail();
    expect($askTask->topic_type)->toBe('account')
        ->and($askTask->topic_id)->toBe('ACCT-2')
        ->and($askTask->labels)->toBeEmpty();
});

test('handoff ASK never closes the asker\'s task', function () {
    bindHandoffLaneResolver(lanesByUser: [255 => ['ops'], 256 => ['support']]);
    $asker = dispatchMakeUser(255);
    $recipient = dispatchMakeUser(256);
    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'lane' => 'ops', 'status' => 'open']);

    app(DispatchTaskService::class)->handoff($task, $recipient, $asker, ['ask' => true]);

    expect($task->fresh()->status)->toBe('open');
});

// --- the return of the ball -------------------------------------------------

test('closing an ask task returns the ball to the asker with the answer, and the dependent becomes unblocked', function () {
    bindHandoffLaneResolver(lanesByUser: [260 => ['ops'], 261 => ['ops']]);
    $asker = dispatchMakeUser(260);
    $recipient = dispatchMakeUser(261);
    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'lane' => 'ops', 'assignee_user_id' => $asker->id]);

    app(DispatchTaskService::class)->handoff($task, $recipient, $asker, ['ask' => true]);
    $askTask = $task->fresh()->blockedBy->firstOrFail();

    // Someone else picks up the ask in the meantime — the asker's task must
    // still return to the ASKER (260), not whoever currently holds it.
    $task->assignee_user_id = 999;
    $task->save();

    $askTask->recordEvent(TaskComment::EVENT_COMMENT, $recipient->id, [], 'Here is what I found.');
    $askTask->status = 'done';
    $askTask->save();

    app(DispatchTaskService::class)->notifyDependentsOfClosure($askTask, $recipient->id);

    $fresh = $task->fresh();
    expect($fresh->assignee_user_id)->toBe(260)
        ->and(Task::unblocked()->pluck('id')->all())->toContain($fresh->id);

    $answered = $fresh->comments()->where('event_type', TaskComment::EVENT_ANSWERED)->firstOrFail();
    expect($answered->meta['answer'])->toBe('Here is what I found.')
        ->and($answered->meta['from'])->toBe($askTask->code);
});

test('closing a task with a PLAIN (non-ask) blocked_by dependent notifies + records EVENT_DEPENDENCY_RESOLVED, with NO reassignment', function () {
    $tasks = app(DispatchTaskService::class);
    $dependent = $tasks->create(['title' => 'dependent', 'assignee_user_id' => 700]);
    $blocker = $tasks->create(['title' => 'blocker', 'status' => 'open']);
    $tasks->linkBlockedBy($dependent, $blocker);

    $blocker->status = 'done';
    $blocker->save();

    $tasks->notifyDependentsOfClosure($blocker);

    $fresh = $dependent->fresh();
    expect($fresh->assignee_user_id)->toBe(700); // untouched — not an ask

    $event = $fresh->comments()->where('event_type', TaskComment::EVENT_DEPENDENCY_RESOLVED)->firstOrFail();
    expect($event->meta['blocker'])->toBe($blocker->code);
});

test('notifyDependentsOfClosure is a no-op for a task with no dependents, and for a non-terminal status', function () {
    $tasks = app(DispatchTaskService::class);
    $task = $tasks->create(['title' => 'x', 'status' => 'in_progress']);

    // Non-terminal — must not throw or do anything observable.
    $tasks->notifyDependentsOfClosure($task);

    $task->status = 'done';
    $task->save();
    // No dependents — must not throw.
    $tasks->notifyDependentsOfClosure($task);

    expect(true)->toBeTrue();
});

test('dispatch:done closing an ask task returns the ball (surface-level wiring check)', function () {
    bindHandoffLaneResolver(lanesByUser: [265 => ['ops'], 266 => ['ops']]);
    $asker = dispatchMakeUser(265);
    $recipient = dispatchMakeUser(266);
    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'lane' => 'ops', 'assignee_user_id' => $asker->id]);

    app(DispatchTaskService::class)->handoff($task, $recipient, $asker, ['ask' => true]);
    $askTask = $task->fresh()->blockedBy->firstOrFail();

    Artisan::call('dispatch:done', ['code' => $askTask->code, '--status' => 'done']);

    expect($task->fresh()->assignee_user_id)->toBe(265);
});

// --- batch: blocked_by + in-batch @ref -------------------------------------

test('batch: an ADD can block-by an EARLIER op\'s @ref in the SAME manifest — no two-batch dance', function () {
    $out = app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'ref' => 'blocker', 'title' => 'the blocker'],
        ['op' => 'add', 'ref' => 'dependent', 'title' => 'the dependent', 'blocked_by' => ['@blocker']],
    ]);

    $blockerCode = $out['results'][0]['code'];
    $dependentCode = $out['results'][1]['code'];

    $dependent = Task::where('code', $dependentCode)->firstOrFail();
    expect($dependent->blockedBy->pluck('code')->all())->toBe([$blockerCode]);
});

test('batch: blocked_by accepts a literal task CODE too, and ADDS additively on update', function () {
    $tasks = app(DispatchTaskService::class);
    $a = $tasks->create(['title' => 'a']);
    $b = $tasks->create(['title' => 'b']);

    app(DispatchBatchService::class)->apply([
        ['op' => 'update', 'code' => $a->code, 'blocked_by' => [$b->code]],
    ]);

    expect($a->fresh()->blockedBy->pluck('code')->all())->toBe([$b->code]);
});

test('batch: an unknown blocked_by code fails the WHOLE batch naming the operation, nothing persists', function () {
    $tasks = app(DispatchTaskService::class);
    $a = $tasks->create(['title' => 'a']);

    expect(fn () => app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'title' => 'should not persist'],
        ['op' => 'update', 'code' => $a->code, 'blocked_by' => ['NOPE-999']],
    ]))->toThrow(InvalidArgumentException::class);

    expect(Task::where('title', 'should not persist')->exists())->toBeFalse();
});

test('batch: an unresolvable @ref fails the WHOLE batch', function () {
    expect(fn () => app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'title' => 'x', 'blocked_by' => ['@nope']],
    ]))->toThrow(InvalidArgumentException::class);
});

test('batch: a self-link/cycle via blocked_by is refused and rolls back the WHOLE batch', function () {
    $tasks = app(DispatchTaskService::class);
    $a = $tasks->create(['title' => 'a']);

    expect(fn () => app(DispatchBatchService::class)->apply([
        ['op' => 'update', 'code' => $a->code, 'blocked_by' => [$a->code]],
    ]))->toThrow(InvalidArgumentException::class);

    expect($a->fresh()->blockedBy)->toBeEmpty();
});

// --- presenter / schema -----------------------------------------------------

test('TaskPresenter: blocked_by/blocks are FULL-shape only', function () {
    $tasks = app(DispatchTaskService::class);
    $a = $tasks->create(['title' => 'a']);
    $b = $tasks->create(['title' => 'b']);
    $tasks->linkBlockedBy($a, $b);

    $summary = TaskPresenter::toArray($a->fresh());
    expect($summary)->not->toHaveKey('blocked_by')
        ->and($summary)->not->toHaveKey('blocks');

    $full = TaskPresenter::toArray($a->fresh()->load('blockedBy', 'blocks'), true);
    expect($full['blocked_by'])->toBe([$b->code])
        ->and($full['blocks'])->toBe([]);
});

test('schema() documents blocked_by (batch op) and the four new event types', function () {
    $schema = TaskPresenter::schema();

    expect($schema['full_adds'])->toHaveKey('blocked_by')
        ->and($schema['full_adds'])->toHaveKey('blocks')
        ->and($schema['batch']['op'])->toHaveKey('blocked_by')
        ->and($schema['event_types'])->toContain(TaskComment::EVENT_HANDED_OFF)
        ->and($schema['event_types'])->toContain(TaskComment::EVENT_ASKED)
        ->and($schema['event_types'])->toContain(TaskComment::EVENT_ANSWERED)
        ->and($schema['event_types'])->toContain(TaskComment::EVENT_DEPENDENCY_RESOLVED);
});

// --- CLI ---------------------------------------------------------------

test('dispatch:handoff --to= passes locally and prints the recipient', function () {
    bindHandoffLaneResolver(lanesByUser: [270 => ['ops'], 271 => ['ops']]);
    $from = dispatchMakeUser(270);
    $to = dispatchMakeUser(271);
    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'lane' => 'ops', 'assignee_user_id' => $from->id]);

    $this->actingAs($from);
    $exit = Artisan::call('dispatch:handoff', ['code' => $task->code, '--to' => (string) $to->id]);

    expect($exit)->toBe(0)
        ->and($task->fresh()->assignee_user_id)->toBe(271);
});

test('dispatch:handoff --ask creates the linked task locally', function () {
    bindHandoffLaneResolver(lanesByUser: [275 => ['ops'], 276 => ['ops']]);
    $from = dispatchMakeUser(275);
    $to = dispatchMakeUser(276);
    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'lane' => 'ops', 'assignee_user_id' => $from->id]);

    $this->actingAs($from);
    $exit = Artisan::call('dispatch:handoff', ['code' => $task->code, '--to' => $to->email, '--ask' => true]);

    expect($exit)->toBe(0)
        ->and($task->fresh()->blockedBy)->toHaveCount(1)
        ->and($task->fresh()->assignee_user_id)->toBe(275);
});

test('dispatch:handoff --remote forwards the raw payload to the agent API', function () {
    config([
        'dispatch.agent.remote.url' => 'https://agent.example.test/api/dispatch/agent',
        'dispatch.agent.remote.token_path' => sys_get_temp_dir().'/dispatch-handoff-test-'.uniqid().'.json',
    ]);
    seedAgentToken();
    Http::fake([
        'agent.example.test/*' => Http::response(['task' => ['code' => 'TASK-042', 'title' => 'remote']], 200),
    ]);

    $exit = Artisan::call('dispatch:handoff', [
        'code' => 'TASK-042', '--to' => 'someone@example.test', '--ask' => true, '--remote' => true,
    ]);

    expect($exit)->toBe(0);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/dispatch/agent/handoff')
        && ($request->data()['to'] ?? null) === 'someone@example.test'
        && ($request->data()['ask'] ?? null) === true);
});

// --- agent API ---------------------------------------------------------

test('POST agent/handoff is scope-gated — 403 without the handoff scope', function () {
    bindHandoffLaneResolver(lanesByUser: [280 => ['ops'], 281 => ['ops']]);
    $from = dispatchMakeUser(280);
    $to = dispatchMakeUser(281);
    app(DispatchTaskService::class)->create(['title' => 'x', 'code' => 'TASK-900', 'lane' => 'ops', 'assignee_user_id' => $from->id]);

    $token = handoffAgentToken(scopes: ['next']); // no `handoff` scope granted

    $this->withToken($token)->postJson('api/dispatch/agent/handoff', [
        'code' => 'TASK-900', 'to' => (string) $to->id,
    ])->assertStatus(403);
});

test('POST agent/handoff passes the ball, resolving `to` by numeric id or email', function () {
    bindHandoffLaneResolver(lanesByUser: [285 => ['ops'], 286 => ['ops']]);
    $from = dispatchMakeUser(285);
    $to = dispatchMakeUser(286);
    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'lane' => 'ops', 'assignee_user_id' => $from->id]);

    $token = handoffAgentToken();

    $this->withToken($token)->postJson('api/dispatch/agent/handoff', [
        'code' => $task->code, 'to' => $to->email,
    ])->assertOk()->assertJsonPath('task.assignee', $to->email);

    expect($task->fresh()->assignee_user_id)->toBe(286);
});

// --- notifier reuse (no new channel) ----------------------------------------

test('the ask return notifies the asker via the EXISTING taskAssigned seam — no new channel', function () {
    dispatchFakeUsers();
    bindHandoffLaneResolver(lanesByUser: [290 => ['ops'], 291 => ['ops']]);
    $asker = dispatchMakeUser(290);
    $recipient = dispatchMakeUser(291);
    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'lane' => 'ops', 'assignee_user_id' => $asker->id]);

    app(DispatchTaskService::class)->handoff($task, $recipient, $asker, ['ask' => true]);
    $askTask = $task->fresh()->blockedBy->firstOrFail();

    // The asker's task gets reassigned to someone else in the meantime, so
    // the return is a REAL reassignment worth a taskAssigned notification.
    $task->assignee_user_id = 999;
    $task->save();

    Notification::fake();

    $askTask->status = 'done';
    $askTask->save();
    app(DispatchTaskService::class)->notifyDependentsOfClosure($askTask);

    Notification::assertSentTo([$asker], TaskUpdate::class);
});

// --- Livewire TaskShow ---------------------------------------------------

test('TaskShow shows the Hand off panel with the inert NullLaneResolver bound, and it works as a plain reassignment', function () {
    $staff = dispatchMakeUser(295);
    $recipient = dispatchMakeUser(296);
    $this->actingAs($staff);

    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'assignee_user_id' => $staff->id]);

    Livewire::test(TaskShow::class, ['task' => $task])
        ->set('handoffToUserId', (string) $recipient->id)
        ->call('handoffTask')
        ->assertHasNoErrors();

    expect($task->fresh()->assignee_user_id)->toBe(296);
});

test('TaskShow::handoffTask() redirects to the continuation task on a cross-lane pass', function () {
    bindHandoffLaneResolver(lanesByUser: [300 => ['ops'], 301 => ['support']]);
    $staff = dispatchMakeUser(300);
    $recipient = dispatchMakeUser(301);
    $this->actingAs($staff);

    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'lane' => 'ops', 'assignee_user_id' => $staff->id]);

    $component = Livewire::test(TaskShow::class, ['task' => $task])
        ->set('handoffToUserId', (string) $recipient->id)
        ->call('handoffTask');

    $continuation = Task::where('assignee_user_id', 301)->where('origin_id', $task->code)->firstOrFail();
    $component->assertRedirect(route('dispatch.show', $continuation));
});

// --- visibility pin -------------------------------------------------------

test('a blocked_by link never widens who can see a task — VisibilityGates never references it', function () {
    $source = file_get_contents((new ReflectionClass(\Sgrjr\Dispatch\Support\VisibilityGates::class))->getFileName());

    expect($source)->not->toContain('dispatch_task_links')
        ->and($source)->not->toContain('blockedBy')
        ->and($source)->not->toContain('->blocks');
});
