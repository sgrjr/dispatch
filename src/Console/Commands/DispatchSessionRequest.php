<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;

/**
 * Kick off the agent-session commissioning flow (§20 Phase 1): request a
 * session from the remote agent API and stash the returned public_id +
 * device_code locally so `dispatch:session:status` can poll for approval.
 *
 * This POST is bootstrap-secret authed (X-Dispatch-Bootstrap), NOT bearer —
 * there is no session token yet — so it is built directly with Http rather
 * than the TalksToAgentApi::agentPost helper (which adds a bearer, not the
 * bootstrap header).
 */
class DispatchSessionRequest extends Command
{
    use TalksToAgentApi;

    protected $signature = 'dispatch:session:request
        {--name= : Agent name to identify this session as}
        {--purpose= : Short human-readable purpose for the session}
        {--scope=* : Narrow the requested verb set. Repeatable. OMIT to request the full grantable allowlist (the recommended default — the approver sees and controls the grant).}
        {--wait=0 : After showing the user_code, block in-process for approval for up to N seconds (bare --wait ≈60s) and collect the token — the whole commissioning in ONE command. Omit for the two-step flow (poll with dispatch:session:status).}
        {--secret= : Bootstrap secret; falls back to dispatch.agent.bootstrap_secret}';

    protected $description = 'Request a new agent session from the remote Dispatch agent API.';

    public function handle(): int
    {
        $base = $this->agentBaseUrl();
        if ($base === null) {
            $this->error('No agent remote configured. Set dispatch.agent.remote.url (DISPATCH_AGENT_REMOTE_URL).');

            return self::FAILURE;
        }

        // Fall back to the env var when the published config lacks the key
        // (GAP-3), mirroring the server-side VerifyBootstrapSecret read.
        $secret = $this->option('secret')
            ?: config('dispatch.agent.bootstrap_secret')
            ?: env('DISPATCH_AGENT_BOOTSTRAP_SECRET');

        $name = $this->option('name') ?: 'agent';
        $purpose = $this->option('purpose');
        $scopes = array_values(array_filter(array_map('trim', (array) $this->option('scope')), fn ($s) => $s !== ''));

        // Only send `scopes` when the caller deliberately narrowed them. The
        // server treats an ABSENT key as "approver grants the full allowlist"
        // but an explicit [] as request-nothing/deny-all (AgentSessionController
        // preserves that distinction on purpose) — so unconditionally sending
        // the key used to turn the no---scope default into a deny-all request.
        $payload = [
            'agent_name' => $name,
            'purpose' => $purpose,
        ];
        if ($scopes !== []) {
            $payload['scopes'] = $scopes;
        }

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('dispatch.sync.timeout', 30))
                ->withHeaders(array_filter(['X-Dispatch-Bootstrap' => $secret]))
                ->post($base.'/session', $payload);
        } catch (ConnectionException $e) {
            $this->reportConnectionFailure($e);

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error("Agent API HTTP {$response->status()}: ".substr($response->body(), 0, 300));

            // The request endpoint is bootstrap-secret gated. A 401/403 here is
            // almost always a mismatched secret — and after a server-side
            // rotation, the usual culprit is a stale config cache on production.
            if (in_array($response->status(), [401, 403], true)) {
                $this->line('Hint: the bootstrap secret sent doesn\'t match production\'s configured value. If it was rotated on the server recently, the server may be serving a STALE CONFIG CACHE — have the operator run `php artisan config:clear` and retry. Pass the value with --secret=<value> or set DISPATCH_AGENT_BOOTSTRAP_SECRET.');
            }

            return self::FAILURE;
        }

        $data = (array) $response->json();

        $this->storeToken(array_merge($this->agentTokenFile() ?? [], [
            'public_id' => $data['public_id'] ?? null,
            'device_code' => $data['device_code'] ?? null,
            'user_code' => $data['user_code'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ]));

        $this->newLine();
        $this->line('Show this code to the approver: <fg=cyan;options=bold>'.($data['user_code'] ?? '?').'</>');
        $this->line($scopes === []
            ? 'Requested: the full grantable verb set (narrow next time with --scope=… if needed).'
            : 'Requested scopes: '.implode(', ', $scopes));
        $this->newLine();

        // One-shot commissioning: with --wait, fall straight into the approval
        // poll (same loop as dispatch:session:status --wait) so a single command
        // shows the code, blocks, and returns holding the token.
        $budget = $this->resolveWaitBudget();
        if ($budget > 0) {
            $this->line("Ask a human to approve it in the Agent Sessions UI — waiting up to {$budget}s…");

            return $this->call('dispatch:session:status', ['--wait' => (string) $budget]);
        }

        $this->line('Ask a human to approve this session in the Agent Sessions UI, then run:');
        $this->line('  <fg=gray>php artisan dispatch:session:status --wait</>');
        $this->line('(Tip: next time pass --wait here to request + collect the token in one command.)');

        return self::SUCCESS;
    }
}
