<?php

use Illuminate\Support\Facades\Event;
use Sgrjr\Dispatch\Events\AgentSessionEnded;
use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Services\AgentSessionService;

/*
 * AgentSessionEnded: a session CLOSED on purpose — the agent's own
 * `session:end`, or a person's Revoke — fires once, with who closed it. A
 * session left to expire, or one already over, fires nothing.
 */

beforeEach(function () {
    dispatchFakeUsers();
});

/** @return array{0: AgentSession, 1: string} an approved session and its token */
function endedSession(): array
{
    $svc = app(AgentSessionService::class);
    $req = $svc->request('claude-remote', 'work the backlog');
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    $svc->approve($session, dispatchMakeUser(88600 + random_int(1, 999))->id);

    return [$session, $svc->poll($req['public_id'], $req['device_code'])['token']];
}

test('the agent\'s own session:end fires AgentSessionEnded by the agent, metrics on the session', function () {
    [$session, $token] = endedSession();
    Event::fake([AgentSessionEnded::class]);

    $this->withToken($token)->postJson('api/dispatch/agent/session/end', ['metrics' => ['duration_s' => 90, 'tool_calls' => 4]])
        ->assertOk();

    Event::assertDispatchedTimes(AgentSessionEnded::class, 1);
    Event::assertDispatched(AgentSessionEnded::class, fn ($e) => $e->session->is($session)
        && $e->endedBy === 'agent'
        && $e->userId === null
        && $e->wasPending === false
        && ($e->session->metrics['duration_s'] ?? null) === 90);
});

test('a person\'s Revoke fires it by staff, naming who', function () {
    [$session] = endedSession();
    Event::fake([AgentSessionEnded::class]);

    app(AgentSessionService::class)->revoke($session, AgentSessionEnded::BY_STAFF, 88001);

    Event::assertDispatched(AgentSessionEnded::class, fn ($e) => $e->endedBy === 'staff' && $e->userId === 88001);
});

test('a session already over, or one that expired, fires nothing', function () {
    [$session] = endedSession();
    app(AgentSessionService::class)->revoke($session);
    Event::fake([AgentSessionEnded::class]);

    app(AgentSessionService::class)->revoke($session->fresh());

    [$lapsing] = endedSession();
    $this->travel(4)->hours();
    app(AgentSessionService::class)->prune();

    expect($lapsing->fresh()->status)->toBe(AgentSession::STATUS_EXPIRED);
    Event::assertNotDispatched(AgentSessionEnded::class);
});
