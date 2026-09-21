<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Schema;
use Sgrjr\Dispatch\Contracts\LaneResolver;
use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Services\AgentSessionService;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\Lane;

/*
 * TASK-999 (R24) — AGENTS OBEY THE BALL.
 *
 * An approved agent session carries a granted `lane`, and `next`/`claim` then
 * serve it only that lane, the departments ABOVE it, and (by config) the
 * no-department lane. Never a sibling sub-lane, never another department.
 * Claim-by-code stays exempt, so a human can still hand an agent any task.
 *
 * Self-contained on purpose: the fake resolver and token helpers are local,
 * so this file passes on its own rather than only as part of a whole-suite
 * run that happens to have loaded LanesTest.php's helpers first.
 */

beforeEach(fn () => dispatchFakeUsers());

/** Four lanes: `ops`, its two sub-lanes, and an unrelated department. */
function bindAgentLaneResolver(): void
{
    app()->singleton(LaneResolver::class, fn () => new class implements LaneResolver
    {
        private array $allLanes = ['ops', 'ops:triage', 'ops:billing', 'support'];

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
            return [];
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

/** An approved token for a session granted NO lane (unrestricted). */
function unlanedAgentToken(): string
{
    static $approverId = 93300;
    $approverId++;

    $svc = app(AgentSessionService::class);
    $req = $svc->request('claude-unlaned', 'no lane');
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    $svc->approve($session, dispatchMakeUser($approverId)->id);

    return $svc->poll($req['public_id'], $req['device_code'])['token'];
}

/**
 * An approved token whose session was GRANTED $lane. Deliberately not
 * unlanedAgentToken() — that one exercises the no-lane (unrestricted) session,
 * and conflating the two would hide exactly the regression this file guards.
 */
function lanedAgentToken(?string $lane, ?array $scopes = null): string
{
    static $approverId = 93500;
    $approverId++;

    $svc = app(AgentSessionService::class);
    $req = $svc->request('claude-laned', 'lane scope', $lane === null ? [] : ['lane' => $lane]);
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    $svc->approve($session, dispatchMakeUser($approverId)->id, null, $scopes);

    return $svc->poll($req['public_id'], $req['device_code'])['token'];
}

/** One open task per lane (plus one unrouted), all at the same priority. */
function seedOneTaskPerLane(string $priority = 'normal'): array
{
    $svc = app(DispatchTaskService::class);
    $out = [];

    foreach (['ops', 'ops:triage', 'ops:billing', 'support'] as $lane) {
        $out[$lane] = $svc->create([
            'title' => "work for {$lane}",
            'status' => 'open',
            'priority' => $priority,
            'lane' => $lane,
        ]);
    }

    $out['none'] = $svc->create(['title' => 'unrouted work', 'status' => 'open', 'priority' => $priority]);

    return $out;
}

// --- the key format -------------------------------------------------------

test('Lane::selfAndAncestors walks up from the most specific lane', function () {
    expect(Lane::selfAndAncestors('ops:triage'))->toBe(['ops:triage', 'ops'])
        ->and(Lane::selfAndAncestors('ops'))->toBe(['ops'])
        ->and(Lane::selfAndAncestors('a:b:c'))->toBe(['a:b:c', 'a:b', 'a']);
});

test('selfAndAncestors reaches UP, never down — the asymmetry with scopeInLane', function () {
    // A `marketing:developer` holder is reached by whole-department work but
    // never by a sibling role's unclaimed work (R22, noise by relevance),
    // whereas the `--lane=marketing` FILTER expands downward into sub-lanes.
    expect(Lane::selfAndAncestors('ops:triage'))->not->toContain('ops:billing');
});

// --- the column -----------------------------------------------------------

test('dispatch_agent_sessions.lane exists, is nullable, and round-trips', function () {
    expect(Schema::hasColumn('dispatch_agent_sessions', 'lane'))->toBeTrue();

    $svc = app(AgentSessionService::class);
    $req = $svc->request('col-agent', null);
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();

    expect($session->lane)->toBeNull()
        ->and($session->servedLanes())->toBeNull();

    $session->lane = 'ops:triage';
    $session->save();

    expect($session->fresh()->lane)->toBe('ops:triage')
        ->and($session->fresh()->servedLanes())->toBe(['ops:triage', 'ops']);
});

// --- the query scope ------------------------------------------------------

test('servedByLanes serves the given lanes plus unrouted, and nothing else', function () {
    bindAgentLaneResolver();
    seedOneTaskPerLane();

    $served = Task::query()->servedByLanes(['ops:triage', 'ops'], true)->pluck('lane')->all();

    expect($served)->toHaveCount(3)
        ->and($served)->toContain('ops:triage')
        ->and($served)->toContain('ops')
        ->and($served)->toContain(null)
        ->and($served)->not->toContain('ops:billing')
        ->and($served)->not->toContain('support');
});

test('servedByLanes can exclude the unrouted lane', function () {
    bindAgentLaneResolver();
    seedOneTaskPerLane();

    $served = Task::query()->servedByLanes(['ops:triage', 'ops'], false)->pluck('lane')->all();

    expect($served)->toHaveCount(2)->and($served)->not->toContain(null);
});

test('servedByLanes with nothing to serve matches NOTHING, never the whole board', function () {
    bindAgentLaneResolver();
    seedOneTaskPerLane();

    // The regression this guards: an empty closure adds no constraints and
    // silently widens to every task — the opposite of "serves nothing".
    expect(Task::query()->servedByLanes([], false)->count())->toBe(0)
        ->and(Task::query()->count())->toBe(5);
});

// --- next / claim over HTTP ----------------------------------------------

test('a laned agent is served its own lane, the department above it, and unrouted work', function () {
    bindAgentLaneResolver();
    config(['dispatch.agent.lane_includes_unrouted' => true]);
    seedOneTaskPerLane();

    $token = lanedAgentToken('ops:triage');

    $seen = [];
    for ($i = 0; $i < 3; $i++) {
        $code = $this->withToken($token)->postJson('api/dispatch/agent/claim')
            ->assertOk()->json('task.code');

        if ($code === null) {
            break;
        }
        $seen[] = Task::where('code', $code)->value('lane');
    }

    sort($seen);
    expect($seen)->toBe([null, 'ops', 'ops:triage']);
});

test('a laned agent is never served a sibling sub-lane or another department', function () {
    bindAgentLaneResolver();
    config(['dispatch.agent.lane_includes_unrouted' => false]);

    $svc = app(DispatchTaskService::class);
    // Both higher priority than anything the agent IS served, so a leak would
    // surface first rather than hide behind ordering.
    $svc->create(['title' => 'billing', 'status' => 'open', 'priority' => 'blocker', 'lane' => 'ops:billing']);
    $svc->create(['title' => 'support', 'status' => 'open', 'priority' => 'blocker', 'lane' => 'support']);
    $mine = $svc->create(['title' => 'mine', 'status' => 'open', 'priority' => 'low', 'lane' => 'ops:triage']);

    $token = lanedAgentToken('ops:triage');

    $this->withToken($token)->getJson('api/dispatch/agent/next')
        ->assertOk()->assertJsonPath('task.code', $mine->code);

    $this->withToken($token)->postJson('api/dispatch/agent/claim')
        ->assertOk()->assertJsonPath('task.code', $mine->code);

    // Nothing left it is served — and the two blockers are still untouched.
    $this->withToken($token)->postJson('api/dispatch/agent/claim')
        ->assertOk()->assertJsonPath('task', null);

    expect(Task::whereIn('lane', ['ops:billing', 'support'])->where('status', 'open')->count())->toBe(2);
});

test('lane_includes_unrouted=false keeps unrouted work away from an agent', function () {
    bindAgentLaneResolver();
    config(['dispatch.agent.lane_includes_unrouted' => false]);

    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'unrouted', 'status' => 'open', 'priority' => 'blocker']);

    $this->withToken(lanedAgentToken('ops:triage'))->getJson('api/dispatch/agent/next')
        ->assertOk()->assertJsonPath('task', null);
});

test('a session with NO lane still sees the whole board (the pre-TASK-999 behavior)', function () {
    bindAgentLaneResolver();
    $svc = app(DispatchTaskService::class);
    $target = $svc->create(['title' => 'far lane', 'status' => 'open', 'priority' => 'blocker', 'lane' => 'support']);

    $this->withToken(unlanedAgentToken())->getJson('api/dispatch/agent/next')
        ->assertOk()->assertJsonPath('task.code', $target->code);
});

test('an agent cannot widen its reach with ?lane — the filter narrows WITHIN what it is served', function () {
    bindAgentLaneResolver();
    config(['dispatch.agent.lane_includes_unrouted' => false]);

    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'support work', 'status' => 'open', 'priority' => 'blocker', 'lane' => 'support']);

    $token = lanedAgentToken('ops:triage');

    $this->withToken($token)->getJson('api/dispatch/agent/next?lane=support')
        ->assertOk()->assertJsonPath('task', null);

    $this->withToken($token)->postJson('api/dispatch/agent/claim', ['lane' => 'support'])
        ->assertOk()->assertJsonPath('task', null);
});

test('claim BY CODE is exempt — a human can still hand an agent any task', function () {
    bindAgentLaneResolver();
    config(['dispatch.agent.lane_includes_unrouted' => false]);

    $svc = app(DispatchTaskService::class);
    $other = $svc->create(['title' => 'support work', 'status' => 'open', 'priority' => 'low', 'lane' => 'support']);

    $this->withToken(lanedAgentToken('ops:triage'))
        ->postJson('api/dispatch/agent/claim', ['code' => $other->code])
        ->assertOk()
        ->assertJsonPath('task.code', $other->code)
        ->assertJsonPath('task.lane', 'support');
});

test('the service derives served lanes from the SESSION, not from the caller-supplied filters', function () {
    bindAgentLaneResolver();
    config(['dispatch.agent.lane_includes_unrouted' => false]);

    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'support work', 'status' => 'open', 'priority' => 'blocker', 'lane' => 'support']);
    $mine = $svc->create(['title' => 'mine', 'status' => 'open', 'priority' => 'low', 'lane' => 'ops:triage']);

    $req = app(AgentSessionService::class)->request('derive', null, ['lane' => 'ops:triage']);
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    app(AgentSessionService::class)->approve($session, dispatchMakeUser(93600)->id);

    // A caller passing a WIDER served_lanes must not win — the session is the
    // authority, so no transport can widen an agent's reach.
    $claimed = $svc->claim($session->fresh(), ['served_lanes' => ['support', 'ops:triage']]);

    expect($claimed?->code)->toBe($mine->code);
});

// --- requesting and granting the lane ------------------------------------

test('a session request carries its lane, and approval grants it', function () {
    bindAgentLaneResolver();

    $svc = app(AgentSessionService::class);
    $req = $svc->request('req-agent', null, ['lane' => 'ops:billing']);
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();

    expect($session->requested_meta['lane'])->toBe('ops:billing');

    $svc->approve($session, dispatchMakeUser(93700)->id);

    expect($session->fresh()->lane)->toBe('ops:billing');
});

test('the approver can change the lane the agent asked for', function () {
    bindAgentLaneResolver();

    $svc = app(AgentSessionService::class);
    $req = $svc->request('override-agent', null, ['lane' => 'ops:billing']);
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();

    $svc->approve($session, dispatchMakeUser(93800)->id, null, null, 'support');

    expect($session->fresh()->lane)->toBe('support');
});

test("an approver's explicit empty lane grants an UNRESTRICTED session, never the config default", function () {
    bindAgentLaneResolver();
    config(['dispatch.agent.lane' => 'ops']);

    $svc = app(AgentSessionService::class);
    $req = $svc->request('unrestricted-agent', null, ['lane' => 'ops:billing']);
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();

    $svc->approve($session, dispatchMakeUser(93900)->id, null, null, '');

    expect($session->fresh()->lane)->toBeNull()
        ->and($session->fresh()->servedLanes())->toBeNull();
});

test('a request that names no lane falls back to the host default', function () {
    bindAgentLaneResolver();
    config(['dispatch.agent.lane' => 'ops:triage']);

    $svc = app(AgentSessionService::class);
    $req = $svc->request('default-agent', null);
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    $svc->approve($session, dispatchMakeUser(94000)->id);

    expect($session->fresh()->lane)->toBe('ops:triage');
});

test('an unrecognized lane falls back to the default rather than widening to unrestricted', function () {
    bindAgentLaneResolver();
    config(['dispatch.agent.lane' => 'ops']);

    // A lane that was renamed or retired between request and approval must not
    // silently become a WIDER grant than the requester asked for.
    expect(app(AgentSessionService::class)->resolveLane('ops:retired'))->toBe('ops');

    config(['dispatch.agent.lane' => null]);
    expect(app(AgentSessionService::class)->resolveLane('ops:retired'))->toBeNull();
});

test('POST agent/session 422s on a lane the resolver does not recognize', function () {
    bindAgentLaneResolver();
    config(['dispatch.agent.bootstrap_secret' => 's3cr3t-value']);

    $this->withHeaders(['X-Dispatch-Bootstrap' => 's3cr3t-value'])
        ->postJson('api/dispatch/agent/session', [
            'agent_name' => 'typo-agent',
            'lane' => 'ops:developr',
        ])
        ->assertStatus(422);
});

test('the approved poll tells the agent which lane it was granted', function () {
    bindAgentLaneResolver();

    $svc = app(AgentSessionService::class);
    $req = $svc->request('poll-agent', null, ['lane' => 'ops:triage']);
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    $svc->approve($session, dispatchMakeUser(94100)->id);

    expect($svc->poll($req['public_id'], $req['device_code'])['lane'])->toBe('ops:triage');
});
