<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Sgrjr\Dispatch\Contracts\LaneResolver;
use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\AgentSessionService;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\AgentHolder;

/*
 * TASK-1059 — the AGENT HOLDER: one user whose name on a task means "waiting
 * for an agent". Handing a task to it moves the ball WITHOUT re-laning the
 * task, and every session is served what it holds, whatever the session's
 * lane. With no holder configured, nothing changes.
 *
 * Self-contained: the resolver and token helpers are local (and uniquely
 * named — Pest loads every Feature file into one process).
 */

const HOLDER_ID = 15383;
const HOLDER_EMAIL = 'agent@example.test';

beforeEach(function () {
    dispatchFakeUsers();
    bindHolderLaneResolver([301 => ['ops'], 302 => ['support']]);
    dispatchMakeUser(HOLDER_ID, ['email' => HOLDER_EMAIL]);
    config(['dispatch.agent.holder' => HOLDER_EMAIL]);
});

/** Lanes `ops` (with `ops:triage`) and `support`; the holder works none. */
function bindHolderLaneResolver(array $lanesByUser = []): void
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
            return $this->isLane($lane) ? ucwords(str_replace(':', ' · ', $lane)) : null;
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

/** An approved token for a session granted $lane (all grantable scopes). */
function holderAgentToken(?string $lane): string
{
    static $approverId = 94000;
    $approverId++;

    $svc = app(AgentSessionService::class);
    $req = $svc->request('claude-holder', 'holder', $lane === null ? [] : ['lane' => $lane]);
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    $svc->approve($session, dispatchMakeUser($approverId)->id);

    return $svc->poll($req['public_id'], $req['device_code'])['token'];
}

function holderUser(): Authenticatable
{
    return config('dispatch.models.user')::query()->findOrFail(HOLDER_ID);
}

function assigneeEvents(Task $task): int
{
    return TaskComment::query()
        ->where('task_id', $task->id)
        ->where('event_type', TaskComment::EVENT_ASSIGNEE_CHANGE)
        ->count();
}

// --- resolution ------------------------------------------------------------

test('the holder resolves from an email or an id, and is off when unset or missing', function () {
    $holder = app(AgentHolder::class);

    expect($holder->id())->toBe(HOLDER_ID)
        ->and($holder->is(HOLDER_ID))->toBeTrue()
        ->and($holder->is((string) HOLDER_ID))->toBeTrue()
        ->and($holder->is(301))->toBeFalse();

    config(['dispatch.agent.holder' => (string) HOLDER_ID]);
    expect($holder->id())->toBe(HOLDER_ID);

    config(['dispatch.agent.holder' => 'nobody@example.test']);
    expect($holder->id())->toBeNull()->and($holder->is(HOLDER_ID))->toBeFalse();

    config(['dispatch.agent.holder' => null]);
    expect($holder->enabled())->toBeFalse()->and($holder->is(null))->toBeFalse();
});

// --- handoff ---------------------------------------------------------------

test('handoff to the holder keeps the SAME task and its lane, with one assignee_change event', function () {
    $from = dispatchMakeUser(301);
    $tasks = app(DispatchTaskService::class);
    $task = $tasks->create(['title' => 'reply to customer', 'status' => 'open', 'lane' => 'ops:triage', 'assignee_user_id' => $from->id]);
    $before = Task::count();

    $result = $tasks->handoff($task, holderUser(), $from, ['note' => 'draft it']);

    expect($result->code)->toBe($task->code)
        ->and($result->lane)->toBe('ops:triage')
        ->and($result->assignee_user_id)->toBe(HOLDER_ID)
        ->and($result->status)->toBe('open')
        ->and(Task::count())->toBe($before)
        ->and(assigneeEvents($result))->toBe(1);
});

test('handoff of an in_progress task to the holder reopens it so an agent can claim it', function () {
    $from = dispatchMakeUser(301);
    $tasks = app(DispatchTaskService::class);
    $task = $tasks->create(['title' => 'x', 'status' => 'in_progress', 'lane' => 'ops', 'assignee_user_id' => $from->id]);

    $result = $tasks->handoff($task, holderUser(), $from);

    expect($result->status)->toBe('open')
        ->and(TaskComment::where('task_id', $task->id)->where('event_type', TaskComment::EVENT_STATUS_CHANGE)->count())->toBe(1);
});

test('an ASK to the holder is refused, and so is a lane other than the task\'s own', function () {
    $tasks = app(DispatchTaskService::class);
    $task = $tasks->create(['title' => 'x', 'status' => 'open', 'lane' => 'ops']);

    expect(fn () => $tasks->handoff($task, holderUser(), null, ['ask' => true]))
        ->toThrow(InvalidArgumentException::class, 'ask');

    expect(fn () => $tasks->handoff($task, holderUser(), null, ['lane' => 'support']))
        ->toThrow(InvalidArgumentException::class, 'never re-lanes');

    // The task's own lane is not a re-lane.
    expect($tasks->handoff($task, holderUser(), null, ['lane' => 'ops'])->lane)->toBe('ops');
});

test('handoff to the holder over HTTP by email keeps the lane', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'status' => 'open', 'lane' => 'support']);

    $this->withToken(holderAgentToken(null))
        ->postJson('api/dispatch/agent/handoff', ['code' => $task->code, 'to' => HOLDER_EMAIL])
        ->assertOk()
        ->assertJsonPath('task.code', $task->code)
        ->assertJsonPath('task.lane', 'support');

    expect($task->fresh()->assignee_user_id)->toBe(HOLDER_ID);
});

test('feature OFF: the same handoff takes the old cross-lane path (a continuation task)', function () {
    config(['dispatch.agent.holder' => null]);
    $tasks = app(DispatchTaskService::class);
    $task = $tasks->create(['title' => 'x', 'status' => 'open', 'lane' => 'ops']);

    $result = $tasks->handoff($task, holderUser(), null);

    expect($result->code)->not->toBe($task->code)
        ->and($result->lane)->toBeNull()
        ->and($task->fresh()->status)->toBe('done');
});

// --- next / claim ----------------------------------------------------------

test('a session laned elsewhere is served a task the holder holds, and the claim keeps the holder', function () {
    config(['dispatch.agent.lane_includes_unrouted' => false]);
    $tasks = app(DispatchTaskService::class);
    $held = $tasks->create(['title' => 'held', 'status' => 'open', 'lane' => 'ops:triage', 'assignee_user_id' => HOLDER_ID]);
    $tasks->create(['title' => 'not held', 'status' => 'open', 'priority' => 'blocker', 'lane' => 'ops:triage']);

    $token = holderAgentToken('support');

    $this->withToken($token)->getJson('api/dispatch/agent/next')
        ->assertOk()->assertJsonPath('task.code', $held->code);

    $this->withToken($token)->postJson('api/dispatch/agent/claim')
        ->assertOk()->assertJsonPath('task.code', $held->code);

    $fresh = $held->fresh();
    expect($fresh->status)->toBe('in_progress')
        ->and($fresh->lane)->toBe('ops:triage')
        ->and($fresh->assignee_user_id)->toBe(HOLDER_ID);

    $claimed = TaskComment::where('task_id', $held->id)->where('event_type', TaskComment::EVENT_CLAIMED)->firstOrFail();
    expect($claimed->meta['agent_name'])->toBe('claude-holder');

    // The un-held ops task stays out of a `support` session's reach.
    $this->withToken($token)->postJson('api/dispatch/agent/claim')
        ->assertOk()->assertJsonPath('task', null);
});

test('a held, unstarted task comes before the session\'s own higher-priority backlog', function () {
    $tasks = app(DispatchTaskService::class);
    $tasks->create(['title' => 'mine, urgent', 'status' => 'open', 'priority' => 'blocker', 'lane' => 'support']);
    $held = $tasks->create(['title' => 'held', 'status' => 'open', 'priority' => 'low', 'lane' => 'ops', 'assignee_user_id' => HOLDER_ID]);

    $this->withToken(holderAgentToken('support'))->getJson('api/dispatch/agent/next')
        ->assertOk()->assertJsonPath('task.code', $held->code);
});

test('a held task another session already claimed does not lead `next`', function () {
    $tasks = app(DispatchTaskService::class);
    $mine = $tasks->create(['title' => 'mine', 'status' => 'open', 'priority' => 'blocker', 'lane' => 'support']);
    $tasks->create(['title' => 'held, in flight', 'status' => 'in_progress', 'priority' => 'low', 'lane' => 'ops', 'assignee_user_id' => HOLDER_ID]);

    $this->withToken(holderAgentToken('support'))->getJson('api/dispatch/agent/next')
        ->assertOk()->assertJsonPath('task.code', $mine->code);
});

test('feature OFF: a laned session is not served another lane\'s task, whoever holds it', function () {
    config(['dispatch.agent.holder' => null, 'dispatch.agent.lane_includes_unrouted' => false]);
    app(DispatchTaskService::class)->create(['title' => 'held', 'status' => 'open', 'lane' => 'ops', 'assignee_user_id' => HOLDER_ID]);

    $this->withToken(holderAgentToken('support'))->postJson('api/dispatch/agent/claim')
        ->assertOk()->assertJsonPath('task', null);
});

test('servedByLanes with only a holder matches the held tasks, and nothing else', function () {
    $tasks = app(DispatchTaskService::class);
    $held = $tasks->create(['title' => 'held', 'lane' => 'ops', 'assignee_user_id' => HOLDER_ID]);
    $tasks->create(['title' => 'other', 'lane' => 'ops']);
    $tasks->create(['title' => 'unrouted']);

    expect(Task::query()->servedByLanes([], false, HOLDER_ID)->pluck('id')->all())->toBe([$held->id])
        ->and(Task::query()->servedByLanes([], false)->count())->toBe(0);
});

// --- queue -----------------------------------------------------------------

test('queue --held-by-agent lists the holder\'s inbox, and the census agrees', function () {
    $tasks = app(DispatchTaskService::class);
    $held = $tasks->create(['title' => 'held', 'status' => 'open', 'lane' => 'ops', 'assignee_user_id' => HOLDER_ID]);
    $tasks->create(['title' => 'other', 'status' => 'open', 'lane' => 'ops']);

    $token = holderAgentToken(null);

    $codes = collect($this->withToken($token)->getJson('api/dispatch/agent/queue?held_by_agent=1')
        ->assertOk()->json('tasks'))->pluck('code')->all();
    expect($codes)->toBe([$held->code]);

    $this->withToken($token)->getJson('api/dispatch/agent/queue?held_by_agent=1&count=1')
        ->assertOk()->assertJsonPath('total', 1);
});

test('queue --held-by-agent is refused when no holder is configured', function () {
    $token = holderAgentToken(null);
    config(['dispatch.agent.holder' => null]);

    $this->withToken($token)->getJson('api/dispatch/agent/queue?held_by_agent=1')
        ->assertStatus(422);

    expect(fn () => app(DispatchTaskService::class)->queueQuery(['held_by_agent' => true]))
        ->toThrow(InvalidArgumentException::class);
});
