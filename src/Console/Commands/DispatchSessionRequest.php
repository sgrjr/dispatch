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
        {--scope=* : Requested scope(s)/verb(s). Repeatable. Omit to request the full allowlist.}
        {--secret= : Bootstrap secret; falls back to dispatch.agent.bootstrap_secret}';

    protected $description = 'Request a new agent session from the remote Dispatch agent API.';

    public function handle(): int
    {
        $base = $this->agentBaseUrl();
        if ($base === null) {
            $this->error('No agent remote configured. Set dispatch.agent.remote.url (DISPATCH_AGENT_REMOTE_URL).');

            return self::FAILURE;
        }

        $secret = $this->option('secret') ?: config('dispatch.agent.bootstrap_secret');

        $name = $this->option('name') ?: 'agent';
        $purpose = $this->option('purpose');
        $scopes = array_values(array_filter(array_map('trim', (array) $this->option('scope')), fn ($s) => $s !== ''));

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('dispatch.sync.timeout', 30))
                ->withHeaders(array_filter(['X-Dispatch-Bootstrap' => $secret]))
                ->post($base.'/session', [
                    'agent_name' => $name,
                    'purpose' => $purpose,
                    'scopes' => $scopes,
                ]);
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
        $this->newLine();
        $this->line('Ask a human to approve this session in the Agent Sessions UI, then run:');
        $this->line('  <fg=gray>php artisan dispatch:session:status</>');

        return self::SUCCESS;
    }
}
