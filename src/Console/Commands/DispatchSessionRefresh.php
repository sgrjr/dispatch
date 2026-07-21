<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;

/**
 * The baked-in resolution pipeline for an auto-expired / dropped session:
 * re-request a session with the SAME identity and scopes as the one that died,
 * flagged as a renewal in the purpose so the approving human sees the
 * extend/reset context in the Agent Sessions UI, then block for approval and
 * store the fresh token (which clears the dropped-session guard).
 *
 * Approval stays the control point — this surfaces the renewal opportunity;
 * it never self-approves, and it composes the existing commissioning flow
 * (`dispatch:session:request --wait`) rather than adding a second one. Renewal
 * context comes from the drop marker (the mid-run 401 / expired / revoked
 * case) or, when refreshing pre-emptively near expiry, the live dotfile.
 */
class DispatchSessionRefresh extends Command
{
    use TalksToAgentApi;

    protected $signature = 'dispatch:session:refresh
        {--wait= : Approval wait budget in seconds (default 60 — refresh is meant to return holding the new token; --wait=0 for the two-step flow)}
        {--secret= : Bootstrap secret; falls back to dispatch.agent.bootstrap_secret}';

    protected $description = 'Renew a dropped or expiring agent session — re-request with the same identity/scopes for a human to approve.';

    public function handle(): int
    {
        $drop = $this->sessionDropMarker();
        $file = $this->agentTokenFile() ?? [];
        $ctx = $drop ?? $file;

        if ($drop === null && $file === []) {
            $this->warn('No dropped session and no session dotfile — nothing to renew from. Requesting a fresh default-identity session; prefer `dispatch:session:request --name=… --purpose=…` for a first commissioning.');
        }

        $name = $ctx['agent_name'] ?? 'agent';
        $purpose = $ctx['purpose'] ?? null;
        $scopes = array_values(array_filter((array) ($ctx['scopes'] ?? []), 'is_string'));

        // Name the renewal for the approver: which session died, and why.
        $renewal = 'renewal'
            .(isset($ctx['public_id']) && $ctx['public_id'] ? " of {$ctx['public_id']}" : '')
            .($drop !== null && isset($drop['reason']) ? " — {$drop['reason']}" : '');
        $purpose = trim(($purpose ? $purpose.' · ' : '').$renewal);

        if ($drop === null && ($file['token'] ?? null) !== null) {
            $this->warn('A session token is still active — refreshing supersedes it locally. (The old server session stays revocable in the Agent Sessions UI until TTL; it is not ended by this.)');
        }

        // Refresh exists to RESOLVE, so it waits by default (the underlying
        // request command's default is the two-step no-wait flow instead).
        $wait = $this->option('wait');

        return $this->call('dispatch:session:request', array_filter([
            '--name' => $name,
            '--purpose' => $purpose,
            '--scope' => $scopes,
            '--wait' => $wait === null ? '60' : (string) $wait,
            '--secret' => $this->option('secret'),
        ], fn ($v) => $v !== null && $v !== []));
    }
}
