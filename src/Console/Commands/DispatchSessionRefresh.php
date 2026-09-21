<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;

/**
 * The baked-in resolution pipeline for an auto-expired / dropped session:
 * re-request a session with the SAME identity, scopes and lane as the one that died,
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
        // Third source, and the one that survives a token death nobody
        // announced (W14-1): the breadcrumb mirrors the renewal identity out of
        // the dotfile at approval time. Without it, refreshing after an
        // unexplained loss reached the approver as a nameless `agent` with no
        // purpose — the renewal still worked, but the human approving it had
        // nothing to approve ON.
        $crumb = $this->sessionBreadcrumb() ?? [];
        $ctx = $drop ?? ($file !== [] ? $file : $crumb);

        if ($drop === null && $file === [] && $crumb === []) {
            $this->warn('No dropped session, no session dotfile, and no session breadcrumb — nothing to renew from. Requesting a fresh default-identity session; prefer `dispatch:session:request --name=… --purpose=…` for a first commissioning.');
        } elseif ($drop === null && $file === [] && $crumb !== []) {
            $this->warn('No dropped session and no dotfile — renewing from the session breadcrumb ('.($crumb['agent_name'] ?? 'agent').', active since '.($crumb['at'] ?? 'an unknown time').'). The previous token went away without a 401.');
        }

        $name = $ctx['agent_name'] ?? 'agent';
        $purpose = $ctx['purpose'] ?? null;
        $scopes = array_values(array_filter((array) ($ctx['scopes'] ?? []), 'is_string'));
        // TASK-999 — renew into the SAME lane the dead session served. Null
        // (never requested one) stays absent so the host default still
        // applies; an explicit '' (deliberately unrestricted) is carried
        // through, which is why the array_filter below only drops nulls.
        $lane = ($ctx['lane'] ?? null);
        $lane = is_string($lane) ? $lane : null;

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
            '--lane' => $lane,
            '--wait' => $wait === null ? '60' : (string) $wait,
            '--secret' => $this->option('secret'),
        ], fn ($v) => $v !== null && $v !== []));
    }
}
