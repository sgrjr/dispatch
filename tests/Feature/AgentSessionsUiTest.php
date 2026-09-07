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

/**
 * A pending session that NAMED its scopes — the `requested_meta.scopes` shape
 * AgentSessionController writes when the CLI was run with `--scope=…`.
 *
 * @param  array<int,string>  $scopes
 */
function pendingScopedSession(array $scopes, string $name = 'claude-scoped'): AgentSession
{
    $svc = app(AgentSessionService::class);
    $req = $svc->request($name, 'work the backlog', ['scopes' => $scopes]);

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

/*
 * TASK-749: the approval was blind — the pending card showed an agent name, a
 * purpose and a TTL, never the scopes being handed over. These drive the
 * consent card and the per-scope checkboxes that now feed the `$scopes`
 * argument AgentSessionService::approve() already accepted.
 */

test('the pending card shows the scopes the agent requested', function () {
    $this->actingAs(dispatchMakeUser(1));

    pendingScopedSession(['next', 'claim', 'done']);

    Livewire::test(AgentSessions::class)
        ->assertSee('Board verbs')
        ->assertSee('as requested by the agent')
        ->assertSee('claim')
        ->assertSee('done');
});

test('a scope-less request still shows the approver the default allowlist it is about to grant', function () {
    $this->actingAs(dispatchMakeUser(1));

    config(['dispatch.agent.verbs' => ['next', 'queue', 'claim']]);
    pendingAgentSession();

    // The blind case that made this a task: the agent named nothing, so the
    // grant comes from config — the human must still see what that is.
    Livewire::test(AgentSessions::class)
        ->assertSee('the default allowlist (the agent named none)')
        ->assertSee('queue');
});

test('host capability scopes are rendered in their own group, not mixed in with board verbs', function () {
    $this->actingAs(dispatchMakeUser(1));

    // Centerpoint's live shape: `app.*` tool-surface scopes sharing the column
    // with board verbs. They are a different vocabulary and must read as one.
    config(['dispatch.agent.verbs' => ['next', 'claim', 'done', 'app.read', 'app.write', 'app.destructive']]);
    pendingScopedSession(['claim', 'done', 'app.write', 'app.destructive']);

    Livewire::test(AgentSessions::class)
        ->assertSee('Board verbs')
        ->assertSee('Application capabilities')
        ->assertSee('app.destructive')
        ->assertSeeInOrder(['Board verbs', 'Application capabilities']);
});

test('a requested scope outside the allowlist is shown as not grantable', function () {
    $this->actingAs(dispatchMakeUser(1));

    config(['dispatch.agent.verbs' => ['next', 'claim']]);
    pendingScopedSession(['claim', 'app.destructive']);

    Livewire::test(AgentSessions::class)
        ->assertSee('Requested but not grantable here')
        ->assertSee('app.destructive');
});

test('approving untouched grants exactly what it did before the checkboxes existed', function () {
    $this->actingAs(dispatchMakeUser(1));

    config(['dispatch.agent.verbs' => ['next', 'queue', 'claim', 'app.read', 'app.write']]);
    $session = pendingAgentSession();

    // The seeded selection must not turn a scope-less request into an explicit
    // one: `requested_meta.scopes` stays absent, and the grant stays the whole
    // allowlist. A host keying its own rules on that distinction (Centerpoint's
    // AgentAuthority::grantFor) depends on it.
    Livewire::test(AgentSessions::class)
        ->call('approve', $session->id);

    $fresh = $session->fresh();
    expect($fresh->status)->toBe('approved')
        ->and($fresh->scopes)->toEqualCanonicalizing(['next', 'queue', 'claim', 'app.read', 'app.write'])
        ->and($fresh->requested_meta['scopes'] ?? null)->toBeNull();
});

test('unchecking a capability narrows the grant that is actually recorded', function () {
    $this->actingAs(dispatchMakeUser(1));

    config(['dispatch.agent.verbs' => ['next', 'claim', 'done', 'app.read', 'app.write']]);
    $session = pendingScopedSession(['next', 'claim', 'done', 'app.read', 'app.write']);

    // The approver keeps the board verbs and the read capability, drops the write.
    Livewire::test(AgentSessions::class)
        ->set('approveScopes.'.$session->id, ['next', 'claim', 'done', 'app.read'])
        ->call('approve', $session->id);

    $fresh = $session->fresh();
    expect($fresh->status)->toBe('approved')
        ->and($fresh->scopes)->toEqualCanonicalizing(['next', 'claim', 'done', 'app.read'])
        ->and($fresh->scopes)->not->toContain('app.write');
});

test('the approver cannot check a scope past the server ceiling', function () {
    $this->actingAs(dispatchMakeUser(1));

    config(['dispatch.agent.verbs' => ['next', 'claim']]);
    $session = pendingScopedSession(['next', 'claim']);

    // A tampered form posting a scope the instance never allows is still bounded
    // by AgentSessionService::grantCeiling() — the UI narrows, it never widens.
    Livewire::test(AgentSessions::class)
        ->set('approveScopes.'.$session->id, ['next', 'claim', 'app.destructive'])
        ->call('approve', $session->id);

    expect($session->fresh()->scopes)->toEqualCanonicalizing(['next', 'claim']);
});

test('each pending row keeps its own scope selection — narrowing one does not narrow another', function () {
    $this->actingAs(dispatchMakeUser(1));

    config(['dispatch.agent.verbs' => ['next', 'claim', 'done']]);
    $a = pendingScopedSession(['next', 'claim', 'done'], 'agent-a');
    $b = pendingScopedSession(['next', 'claim', 'done'], 'agent-b');

    Livewire::test(AgentSessions::class)
        ->set('approveScopes.'.$a->id, ['next'])
        ->call('approve', $b->id);

    expect($b->fresh()->scopes)->toEqualCanonicalizing(['next', 'claim', 'done'])
        ->and($a->fresh()->status)->toBe('pending');
});

test('clearing every box approves a session that grants nothing, and the card says so', function () {
    $this->actingAs(dispatchMakeUser(1));

    config(['dispatch.agent.verbs' => ['next', 'claim']]);
    $session = pendingScopedSession(['next', 'claim']);

    Livewire::test(AgentSessions::class)
        ->set('approveScopes.'.$session->id, [])
        ->assertSee('grants nothing')
        ->call('approve', $session->id);

    expect($session->fresh()->scopes)->toBe([]);
});

test('the seeded boxes render pre-checked — Livewire does not set checkbox state client-side', function () {
    $this->actingAs(dispatchMakeUser(1));

    config(['dispatch.agent.verbs' => ['next', 'claim', 'app.read']]);
    $session = pendingScopedSession(['next', 'claim', 'app.read']);

    // A `wire:model` checkbox is rendered by the SERVER; without @checked the
    // card would show every scope unticked while the component state says the
    // opposite, and the first re-render would then narrow the grant to nothing.
    $html = Livewire::test(AgentSessions::class)->html();

    foreach (['next', 'claim', 'app.read'] as $scope) {
        expect($html)->toContain('value="'.$scope.'" wire:model="approveScopes.'.$session->id.'" checked');
    }
});
