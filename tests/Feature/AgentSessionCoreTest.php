<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Sgrjr\Dispatch\Http\Middleware\AuthenticateAgentSession;
use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Services\AgentSessionService;

/*
 * §20 Phase 1 — the session core in isolation (service / model / middleware).
 * The full HTTP request→approve→verb security matrix lives in
 * AgentApiSecurityTest once the Wave-1 controllers land.
 */

function agentSvc(): AgentSessionService
{
    return app(AgentSessionService::class);
}

test('request() creates a pending session and returns a one-time device_code + user_code', function () {
    $payload = agentSvc()->request('claude-laptop', 'work the backlog', ['scopes' => ['next', 'claim']], '10.0.0.1');

    expect($payload['public_id'])->toBeString()
        ->and($payload['device_code'])->toBeString()->toHaveLength(64)
        ->and($payload['user_code'])->toBeString()->toHaveLength(8)
        ->and($payload)->toHaveKeys(['poll_interval', 'expires_at']);

    $session = AgentSession::where('public_id', $payload['public_id'])->firstOrFail();
    expect($session->status)->toBe('pending')
        ->and($session->agent_name)->toBe('claude-laptop')
        ->and($session->token_hash)->toBeNull()
        ->and($session->poll_secret_hash)->toBe(hash('sha256', $payload['device_code']));
});

test('poll requires the device_code secret — a guessed public_id alone is useless', function () {
    $req = agentSvc()->request('a', null);

    expect(agentSvc()->poll($req['public_id'], 'wrong-secret')['status'])->toBe('invalid');
    expect(agentSvc()->poll($req['public_id'], $req['device_code'])['status'])->toBe('pending');
    expect(agentSvc()->poll('00000000-0000-0000-0000-000000000000', 'x')['status'])->toBe('invalid');
});

test('an approved session delivers its token exactly once', function () {
    $req = agentSvc()->request('a', null);
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();

    agentSvc()->approve($session, 42);

    $first = agentSvc()->poll($req['public_id'], $req['device_code']);
    expect($first['status'])->toBe('approved')
        ->and($first['token'])->toBeString()->toHaveLength(64);

    // Second poll is approved but carries NO token (delivered once).
    $second = agentSvc()->poll($req['public_id'], $req['device_code']);
    expect($second['status'])->toBe('approved')
        ->and($second)->not->toHaveKey('token');

    $session->refresh();
    expect($session->approved_by_user_id)->toBe(42)
        ->and($session->token_hash)->toBe(hash('sha256', $first['token']))
        ->and($session->token_delivered_at)->not->toBeNull();
});

test('approve intersects requested scopes with the server allowlist', function () {
    config(['dispatch.agent.verbs' => ['next', 'claim', 'done']]);

    // Requested a verb NOT in the allowlist ('delete') — it is dropped.
    $req = agentSvc()->request('a', null, ['scopes' => ['next', 'delete', 'done']]);
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    agentSvc()->approve($session, 1);

    expect($session->fresh()->scopes)->toBe(['next', 'done']);
});

test('approve with no requested scopes grants the full allowlist; explicit [] grants nothing', function () {
    config(['dispatch.agent.verbs' => ['next', 'claim']]);

    $noReq = AgentSession::where('public_id', agentSvc()->request('a', null)['public_id'])->firstOrFail();
    agentSvc()->approve($noReq, 1);
    expect($noReq->fresh()->scopes)->toBe(['next', 'claim']);

    $empty = AgentSession::where('public_id', agentSvc()->request('b', null, ['scopes' => []])['public_id'])->firstOrFail();
    agentSvc()->approve($empty, 1);
    expect($empty->fresh()->scopes)->toBe([]);
});

test('approve falls back to KNOWN_VERBS when the host config omits agent.verbs entirely (GAP-3)', function () {
    // A host that published config/dispatch.php before the `agent` block gained
    // `verbs` has the key ABSENT (not present-null) — shallow mergeConfigFrom
    // won't re-add it. The null-request grant path must then default to the
    // shipped verb set, not [] (which would grant nothing at all).
    $agent = config('dispatch.agent');
    unset($agent['verbs']);
    config(['dispatch.agent' => $agent]);

    $noReq = AgentSession::where('public_id', agentSvc()->request('a', null)['public_id'])->firstOrFail();
    agentSvc()->approve($noReq, 1);

    expect($noReq->fresh()->scopes)->toBe(AgentSessionService::KNOWN_VERBS);
});

test('approve grants an explicitly-requested KNOWN verb even when the published allowlist is stale (GAP-3)', function () {
    // Simulate a host whose *published* config.verbs predates `batch` being shipped.
    config(['dispatch.agent.verbs' => ['next', 'claim', 'done']]);

    $req = agentSvc()->request('a', null, ['scopes' => ['claim', 'batch']]);
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    agentSvc()->approve($session, 1);

    // `batch` is a package KNOWN_VERB, so the union ceiling grants it despite the
    // stale allowlist. `claim` (in both) is granted; a bogus verb still wouldn't be.
    expect($session->fresh()->scopes)->toBe(['claim', 'batch']);
});

test('agent.disabled_verbs withholds a KNOWN verb even when explicitly requested', function () {
    config([
        'dispatch.agent.verbs' => ['next', 'claim', 'done', 'batch'],
        'dispatch.agent.disabled_verbs' => ['batch'],
    ]);

    $req = agentSvc()->request('a', null, ['scopes' => ['claim', 'batch']]);
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    agentSvc()->approve($session, 1);

    expect($session->fresh()->scopes)->toBe(['claim']);
});

test('resolveToken returns the session for a valid token and null for anything else', function () {
    $req = agentSvc()->request('a', null);
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    agentSvc()->approve($session, 60);
    $token = agentSvc()->poll($req['public_id'], $req['device_code'])['token'];

    expect(agentSvc()->resolveToken($token)?->public_id)->toBe($req['public_id']);
    expect(agentSvc()->resolveToken('nope'))->toBeNull();
    expect(agentSvc()->resolveToken(''))->toBeNull();
    expect(agentSvc()->resolveToken(null))->toBeNull();
});

test('revoke and expiry kill a token on the very next resolve', function () {
    $req = agentSvc()->request('a', null);
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    agentSvc()->approve($session, 3600);
    $token = agentSvc()->poll($req['public_id'], $req['device_code'])['token'];

    expect(agentSvc()->resolveToken($token))->not->toBeNull();

    agentSvc()->revoke($session);
    expect(agentSvc()->resolveToken($token))->toBeNull();

    // A separately-approved-then-expired session is also unusable.
    $req2 = agentSvc()->request('b', null);
    $s2 = AgentSession::where('public_id', $req2['public_id'])->firstOrFail();
    agentSvc()->approve($s2, 3600);
    $token2 = agentSvc()->poll($req2['public_id'], $req2['device_code'])['token'];
    $s2->expires_at = now()->subMinute();
    $s2->save();
    expect($s2->isUsable())->toBeFalse();
    expect(agentSvc()->resolveToken($token2))->toBeNull();
});

test('prune expires stale approved and pending sessions', function () {
    $req = agentSvc()->request('a', null);
    $s = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    agentSvc()->approve($s, 3600);
    $s->expires_at = now()->subMinute();
    $s->save();

    $pending = AgentSession::where('public_id', agentSvc()->request('b', null)['public_id'])->firstOrFail();
    $pending->expires_at = now()->subMinute();
    $pending->save();

    expect(agentSvc()->prune())->toBe(2);
    expect($s->fresh()->status)->toBe('expired')
        ->and($pending->fresh()->status)->toBe('expired');
});

test('AuthenticateAgentSession middleware binds the session and 401s an invalid token', function () {
    Route::middleware(['dispatch.agent'])->get('/__dispatch-agent-probe', function (Request $request) {
        return response()->json([
            'public_id' => $request->attributes->get(AuthenticateAgentSession::ATTRIBUTE)?->public_id,
        ]);
    });

    $req = agentSvc()->request('a', null);
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    agentSvc()->approve($session, 3600);
    $token = agentSvc()->poll($req['public_id'], $req['device_code'])['token'];

    $this->getJson('/__dispatch-agent-probe')->assertStatus(401);
    $this->withToken('garbage')->getJson('/__dispatch-agent-probe')->assertStatus(401);

    $this->withToken($token)->getJson('/__dispatch-agent-probe')
        ->assertOk()
        ->assertJson(['public_id' => $req['public_id']]);

    // last_used_at was stamped by the successful request.
    expect($session->fresh()->last_used_at)->not->toBeNull();
});
