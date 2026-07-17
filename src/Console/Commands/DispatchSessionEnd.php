<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;

/**
 * End the current remote agent session (§20 — GAP 5). Posts to the bearer-authed
 * `session/end` route, which revokes the caller's OWN session server-side, then
 * deletes the local token file. Least-privilege: a finished agent surrenders its
 * credential immediately instead of leaving a usable token alive until TTL.
 *
 * Like the other session commands, this only talks to the remote — there is no
 * local agent session to end.
 */
class DispatchSessionEnd extends Command
{
    use TalksToAgentApi;

    protected $signature = 'dispatch:session:end';

    protected $description = 'End (revoke) the current agent session on the server and clear the local token.';

    public function handle(): int
    {
        if ($this->agentToken() === null) {
            // Nothing live locally — clear any stale dotfile and report cleanly.
            $this->forgetToken();
            $this->info('No active agent session token — nothing to end.');

            return self::SUCCESS;
        }

        $result = $this->agentPost('session/end');

        if ($result !== null) {
            $this->forgetToken();
            $this->info('Session ended — server session revoked, local token cleared.');

            return self::SUCCESS;
        }

        // agentPost() failed. On a 401 it already cleared the token — the session
        // was already dead, so the goal (no live credential) is met.
        if ($this->agentToken() === null) {
            $this->line('Session was already inactive; local token cleared.');

            return self::SUCCESS;
        }

        // A live token remains and the revoke didn't land (transport/HTTP error).
        // Keep it so the operator can retry rather than orphaning a live server
        // session until TTL.
        $this->error('Could not reach the agent API to end the session — local token kept; retry `dispatch:session:end`.');

        return self::FAILURE;
    }
}
