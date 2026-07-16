<?php

use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Services\AgentSessionService;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/*
 * Driver-authored authoritative security gate for the agent HTTP surface
 * (§20). AgentApiTest covers the functional happy paths; this file locks the
 * negative/adversarial matrix: no-token 401, expired 401, bootstrap-secret
 * enforcement, and the scopeVisible-bypass staying staff-equivalent (a foreign
 * PRIVATE task is visible) rather than accidentally public-only.
 */

beforeEach(fn () => dispatchFakeUsers());

function securityAgentToken(?array $scopes = null): string
{
    static $id = 95000;
    $id++;

    $svc = app(AgentSessionService::class);
    $req = $svc->request('sec-agent', null);
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    $svc->approve($session, dispatchMakeUser($id)->id, null, $scopes);

    return $svc->poll($req['public_id'], $req['device_code'])['token'];
}

test('a verb endpoint 401s when no bearer token is presented', function () {
    $this->getJson('api/dispatch/agent/next')->assertStatus(401);
    $this->postJson('api/dispatch/agent/claim')->assertStatus(401);
    $this->getJson('api/dispatch/agent/queue')->assertStatus(401);
});

test('a garbage bearer token 401s', function () {
    $this->withToken('not-a-real-token')->getJson('api/dispatch/agent/next')->assertStatus(401);
});

test('an expired session token 401s over HTTP (TTL expiry bites on the next request)', function () {
    $svc = app(AgentSessionService::class);
    $req = $svc->request('sec-agent', null);
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    $svc->approve($session, dispatchMakeUser(95500)->id);
    $token = $svc->poll($req['public_id'], $req['device_code'])['token'];

    $session->expires_at = now()->subMinute();
    $session->save();

    $this->withToken($token)->getJson('api/dispatch/agent/next')->assertStatus(401);
});

test('the bootstrap secret gates the unauthenticated session-request endpoint', function () {
    config(['dispatch.agent.bootstrap_secret' => 's3cr3t-value']);

    // No header, and a wrong header, are both rejected.
    $this->postJson('api/dispatch/agent/session', ['agent_name' => 'a'])->assertStatus(401);
    $this->withHeaders(['X-Dispatch-Bootstrap' => 'wrong'])
        ->postJson('api/dispatch/agent/session', ['agent_name' => 'a'])->assertStatus(401);

    // The correct header passes.
    $this->withHeaders(['X-Dispatch-Bootstrap' => 's3cr3t-value'])
        ->postJson('api/dispatch/agent/session', ['agent_name' => 'a'])->assertCreated();
});

test('the agent surface sees a PRIVATE task authored by someone else (staff-equivalent, not public-only)', function () {
    $other = dispatchMakeUser(96000);
    $private = app(DispatchTaskService::class)->create([
        'title' => 'Confidential ticket',
        'status' => 'open',
        'is_public' => false,
        'submitter_user_id' => $other->id,
    ]);

    $token = securityAgentToken();

    // Had the controller routed through scopeVisible(null), a private task with
    // a foreign submitter would be filtered out. It must be visible on both the
    // list (next) and the detail (show) surfaces.
    $this->withToken($token)->getJson('api/dispatch/agent/next')
        ->assertOk()->assertJsonPath('task.code', $private->code);

    $this->withToken($token)->getJson('api/dispatch/agent/show/'.$private->code)
        ->assertOk()->assertJsonPath('task.code', $private->code);
});

test('a scoped-down session is forbidden from every verb outside its grant', function () {
    $token = securityAgentToken(['next']);

    $this->withToken($token)->getJson('api/dispatch/agent/next')->assertOk();
    $this->withToken($token)->getJson('api/dispatch/agent/queue')->assertStatus(403);
    $this->withToken($token)->postJson('api/dispatch/agent/add', ['title' => 'x'])->assertStatus(403);
    $this->withToken($token)->postJson('api/dispatch/agent/done', ['code' => 'X'])->assertStatus(403);
});
