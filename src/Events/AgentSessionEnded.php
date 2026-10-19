<?php

namespace Sgrjr\Dispatch\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Sgrjr\Dispatch\Models\AgentSession;

/**
 * An agent session was CLOSED on purpose — not left to expire. Fired once, when
 * AgentSessionService::revoke() actually moves the row to revoked:
 *
 *   - $endedBy 'agent' — the agent's own `dispatch:session:end` (its run
 *     metrics, when it sent them, are already on $session->metrics);
 *   - $endedBy 'staff' — a person's Revoke on the Agent Sessions screen
 *     ($userId says who).
 *
 * $wasPending: the request was never approved, so ending it was a refusal
 * rather than the end of a run. A host that reports sessions (Centerpoint: an
 * application alert in the Brutus thread) listens here.
 */
class AgentSessionEnded
{
    use Dispatchable;

    public const BY_AGENT = 'agent';

    public const BY_STAFF = 'staff';

    public function __construct(
        public AgentSession $session,
        public string $endedBy,
        public ?int $userId = null,
        public bool $wasPending = false,
    ) {}
}
