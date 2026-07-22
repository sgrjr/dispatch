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
        {--code-file= : Write the user_code (JSON: user_code/public_id/expires_at) to this file the moment it is known — lets a blocked/buffered harness read the code from another process}
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

            // The session endpoint is rate-limited per IP. A 429 here does NOT
            // invalidate an existing approval or token — the observed cascade
            // is exactly an agent re-requesting over a throttle.
            if ($response->status() === 429) {
                $this->line('Hint: the session endpoint is rate-limited. A 429 does NOT invalidate an existing session — if you still hold a token, keep using it. Back off and retry once later; never re-request in a loop.');
            }

            return self::FAILURE;
        }

        $data = (array) $response->json();

        // A new request SUPERSEDES whatever the dotfile held — deliberately no
        // merge. Carrying a stale `token` forward caused a real cascade: the
        // dead token kept sticky-remote alive, the next verb's 401 wiped the
        // whole file INCLUDING this request's fresh device_code, and the poll
        // then found "no pending session". If a live token is being replaced,
        // record the drop so bare verbs stay loud until the new token lands.
        $previous = $this->agentTokenFile();
        if (($previous['token'] ?? null) !== null) {
            $this->markSessionDropped('superseded by a new session request');
            $this->warn('An agent session token was already present — this request supersedes it locally. (The old server session stays until TTL/revoke; prefer `dispatch:session:end` when a run finishes.)');
        }

        $this->storeToken([
            'public_id' => $data['public_id'] ?? null,
            'device_code' => $data['device_code'] ?? null,
            'user_code' => $data['user_code'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            // The request's identity, persisted so a dropped session can be
            // renewed as-was by dispatch:session:refresh.
            'agent_name' => $name,
            'purpose' => $purpose,
            'scopes' => $scopes,
        ]);

        // --code-file: surface the user_code out-of-band the moment it is known.
        // With --wait this command blocks in-process, so a buffered agent harness
        // can't read the printed code until the process exits — which deadlocks
        // the approval relay. A cooperating process can poll this file instead.
        // Best-effort: a bad path must never block commissioning (see below).
        if (($codeFile = $this->option('code-file')) !== null && $codeFile !== '') {
            $this->writeCodeFile($codeFile, [
                'user_code' => $data['user_code'] ?? null,
                'public_id' => $data['public_id'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
            ]);
        }

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

    /**
     * Write the freshly-issued user_code (and identity) to --code-file for a
     * cooperating out-of-band reader. Best-effort by contract: a write failure
     * WARNs and returns — the code is still on stdout, and a bad --code-file
     * path must never fail an otherwise-successful commissioning request.
     *
     * @param  array<string,mixed>  $payload
     */
    private function writeCodeFile(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        // @-suppressed: an unwritable path (e.g. parent that can't be created)
        // returns false rather than throwing — we warn on that below.
        $ok = @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($ok === false) {
            $this->warn("Could not write --code-file to {$path} — the user_code is shown above; continuing.");
        }
    }
}
