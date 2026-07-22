<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Livewire;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Livewire\AgentSessions;
use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Services\AgentSessionService;

/*
 * T3 [pkg]: per-approval TTL in the Agent Sessions approve flow. The service's
 * approve() already accepts a per-session TTL (defaulting to the config
 * backstop); these drive the Livewire seam that finally feeds it — the
 * `approveTtl.{id}` "session length" select on each pending row — plus the
 * staff gate that guards the action.
 */

beforeEach(fn () => dispatchFakeUsers());

/**
 * Register a pending session through the SERVICE (mirrors AgentApiTest's
 * agentApiToken() request pattern inline — the approve step is exactly what the
 * Livewire component now drives, so we stop at the pending row).
 */
function pendingAgentSession(string $name = 'claude-remote'): AgentSession
{
    $svc = app(AgentSessionService::class);
    $req = $svc->request($name, 'work the backlog');

    return AgentSession::where('public_id', $req['public_id'])->firstOrFail();
}

test('approve with the session-length select untouched applies the config default TTL', function () {
    $this->actingAs(dispatchMakeUser(1));

    $session = pendingAgentSession();

    Livewire::test(AgentSessions::class)
        ->call('approve', $session->id);

    // Select untouched → the array key is unset → the service applies its config
    // default (the 3h backstop). Window idiom: AgentSessionCoreTest.php:62-64.
    $fresh = $session->fresh();
    expect($fresh->status)->toBe('approved')
        ->and($fresh->expires_at->timestamp - now()->timestamp)->toBeGreaterThan(10700)
        ->and($fresh->expires_at->timestamp - now()->timestamp)->toBeLessThanOrEqual(10800);
});

test('a chosen preset TTL rides the approve action and right-sizes expires_at', function () {
    $this->actingAs(dispatchMakeUser(1));

    $session = pendingAgentSession();

    Livewire::test(AgentSessions::class)
        ->set('approveTtl.'.$session->id, '3600')
        ->call('approve', $session->id);

    $fresh = $session->fresh();
    expect($fresh->status)->toBe('approved')
        ->and($fresh->expires_at->timestamp - now()->timestamp)->toBeGreaterThan(3500)
        ->and($fresh->expires_at->timestamp - now()->timestamp)->toBeLessThanOrEqual(3600);
});

test('each pending row keeps its own approveTtl key — a TTL set for one session does not leak to another', function () {
    $this->actingAs(dispatchMakeUser(1));

    $a = pendingAgentSession('agent-a');
    $b = pendingAgentSession('agent-b');

    // Set a short TTL for A only, then approve B untouched — B must fall back to
    // the default (its own key is unset), proving the keying is per session id.
    Livewire::test(AgentSessions::class)
        ->set('approveTtl.'.$a->id, '3600')
        ->call('approve', $b->id);

    $freshB = $b->fresh();
    expect($freshB->status)->toBe('approved')
        ->and($freshB->expires_at->timestamp - now()->timestamp)->toBeGreaterThan(10700)
        ->and($freshB->expires_at->timestamp - now()->timestamp)->toBeLessThanOrEqual(10800);

    // A was never approved — no window burned by B's action.
    expect($a->fresh()->status)->toBe('pending');
});

test('the pending row renders the session-length select with the Default (3h) option', function () {
    $this->actingAs(dispatchMakeUser(1));

    pendingAgentSession();

    Livewire::test(AgentSessions::class)
        ->assertSee('session length')
        ->assertSee('Default (3h)')
        ->assertSee('1 hour')
        ->assertSee('24 hours');
});

test('a non-staff user is blocked from approving — the action aborts 403 (custom gate)', function () {
    // Inline gate splitting staff/submitter, mirroring ListFeaturesTest: only
    // is_staff users are staff. The fixture users table carries no is_staff
    // column, so a plain user reads as non-staff.
    app()->singleton(DispatchGate::class, fn () => new class implements DispatchGate
    {
        public function isStaff(?Authenticatable $user): bool
        {
            return $user !== null && (bool) ($user->is_staff ?? false);
        }

        public function canSeeAll(?Authenticatable $user): bool
        {
            return $this->isStaff($user);
        }

        public function scopeVisible(Builder $query, ?Authenticatable $user): Builder
        {
            if ($this->canSeeAll($user)) {
                return $query;
            }

            if ($user === null) {
                return $query->where('is_public', true);
            }

            return $query->where(function (Builder $q) use ($user) {
                $q->where('is_public', true)->orWhere('submitter_user_id', $user->getAuthIdentifier());
            });
        }
    });

    $submitter = dispatchMakeUser(42);
    $this->actingAs($submitter);

    $session = pendingAgentSession();

    // mount() only redirects a non-staff user; approve() defends independently
    // with abort_unless(...403). Calling it directly on the still-live component
    // proves the action's own authorization — not just the page gate. Livewire's
    // RequestBroker keeps HttpException in its still-handled list, so the 403 is
    // rendered (not re-thrown); the load-bearing proof is the outcome: the
    // service approve() is never reached, so the session never leaves pending.
    Livewire::test(AgentSessions::class)
        ->call('approve', $session->id);

    $fresh = $session->fresh();
    expect($fresh->status)->toBe('pending')
        ->and($fresh->approved_at)->toBeNull()
        ->and($fresh->expires_at->timestamp)->toBe($session->expires_at->timestamp); // request-ttl window untouched
});
