<?php

use Sgrjr\Dispatch\Models\AgentSession;

/*
 * The shared package layout surfaces the agent-session approval queue (a link +
 * a pending-count badge) to staff, so a waiting request is discoverable from the
 * board instead of being an unlinked page nobody knows to visit.
 */

beforeEach(fn () => dispatchFakeUsers());

function seedPendingSession(string $publicId): void
{
    AgentSession::create([
        'public_id' => $publicId,
        'agent_name' => 'agent-'.$publicId,
        'user_code' => strtoupper(substr(md5($publicId), 0, 8)),
        'poll_secret_hash' => hash('sha256', $publicId),
        'status' => 'pending',
    ]);
}

test('the board nav shows staff an Agent Sessions link with the pending count', function () {
    seedPendingSession('a');
    seedPendingSession('b');

    $staff = dispatchMakeUser(1); // DefaultGate treats any authed user as staff

    $this->actingAs($staff)->get(route('dispatch.board'))
        ->assertOk()
        ->assertSee('Agent Sessions')
        ->assertSee(route('dispatch.agent-sessions'))
        ->assertSee('2 pending agent session request(s)'); // the badge title
});

test('the Agent Sessions link is hidden when the agent API is disabled', function () {
    config(['dispatch.agent.enabled' => false]);

    $this->actingAs(dispatchMakeUser(1))->get(route('dispatch.board'))
        ->assertOk()
        ->assertDontSee('Agent Sessions');
});
