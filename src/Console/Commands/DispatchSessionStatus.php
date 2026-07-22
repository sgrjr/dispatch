<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;

/**
 * Probe the local agent-session state — and, when a request is still pending,
 * poll the remote for approval (§20 Phase 1). This is a THREE-STATE local probe
 * that always exits 0 except when the remote is unconfigured or an actual poll
 * settles as denied/revoked/expired:
 *   ACTIVE  — a token is stored: report it from the dotfile, NO HTTP call.
 *   PENDING — public_id+device_code but no token: run the approval poll below.
 *   DROPPED — a drop marker but no token: name refresh / session:end.
 *   NONE    — nothing at all: name dispatch:session:request.
 *
 * The pending poll is device_code-authed (RFC 8628), NOT bearer — there is no
 * session token until it succeeds — so it is built directly with Http rather
 * than TalksToAgentApi::agentGet (which sends a bearer, not a device_code).
 *
 * By default it polls ONCE and exits (re-run it, or wire it to a cron). Pass
 * `--wait[=secs]` to poll IN-PROCESS — sleep + retry while `pending`, up to the
 * budget — so the driving agent can show the user_code once and then block on a
 * single call that returns the moment a human approves (most land in ~10s),
 * instead of the operator having to come back and say "approved."
 */
class DispatchSessionStatus extends Command
{
    use TalksToAgentApi;

    protected $signature = 'dispatch:session:status
        {--wait=0 : Poll in-process for up to N seconds (sleep+retry while pending). Bare --wait waits ~60s; omit for a single poll.}';

    protected $description = 'Check (and, once approved, collect) the pending agent session token.';

    public function handle(): int
    {
        $base = $this->agentBaseUrl();
        if ($base === null) {
            $this->error('No agent remote configured. Set dispatch.agent.remote.url (DISPATCH_AGENT_REMOTE_URL).');

            return self::FAILURE;
        }

        $data = $this->agentTokenFile() ?? [];
        $publicId = $data['public_id'] ?? null;
        $deviceCode = $data['device_code'] ?? null;

        // STATE: ACTIVE. A stored token means the session is already commissioned.
        // After approval the dotfile RETAINS public_id+device_code beside the
        // token, so this branch must sit ABOVE the pending poll — otherwise an
        // active session would keep re-polling the (now approved) request. This
        // is a purely LOCAL probe: liveness is read off the stored expires_at, no
        // HTTP round-trip (the server stays authoritative for the verbs).
        if (! empty($data['token'])) {
            return $this->reportActive($data);
        }

        // STATE: DROPPED or NONE. No pending request (public_id/device_code) and
        // no token. A drop marker turns "token gone" into a first-class state
        // (why it died, and the identity to renew); its absence is a clean NONE.
        // Either way this is a local report that exits 0 (config failure already
        // returned above) — name the next verb, don't error.
        if (! $publicId || ! $deviceCode) {
            if (($drop = $this->sessionDropMarker()) !== null) {
                return $this->reportDropped($drop);
            }

            $this->info('No active token and no pending agent session. Request one with `dispatch:session:request` (add --wait to request + collect the token in one command).');

            return self::SUCCESS;
        }

        // STATE: PENDING — a request is out but not yet approved. The existing
        // device_code poll/settle loop owns this (still exits 1 on denied /
        // revoked / expired via settle()).
        $budget = $this->resolveWaitBudget();
        $deadline = microtime(true) + $budget;
        $announced = false;

        while (true) {
            $body = $this->pollOnce($base, $publicId, $deviceCode);
            if ($body === null) {
                return self::FAILURE; // pollOnce already reported the failure
            }

            $status = $body['status'] ?? null;

            if ($status !== 'pending') {
                return $this->settle($status, $body, $data);
            }

            // Pending. Stop if the wait budget is spent (budget 0 = single poll).
            if (microtime(true) >= $deadline) {
                $this->line($budget > 0
                    ? "Still pending after ~{$budget}s — a human must approve it. Back off and re-run later (do NOT spin)."
                    : 'Still pending — a human must approve it. Approval is async; back off and re-run later (do NOT spin).');

                return self::SUCCESS;
            }

            $interval = max(1, (int) ($body['poll_interval'] ?? config('dispatch.agent.poll_interval', 5)));
            $remaining = (int) ceil($deadline - microtime(true));
            $sleep = max(0, min($interval, $remaining));

            if (! $announced) {
                $this->line("Pending — waiting up to {$budget}s for approval (re-checking every ~{$interval}s)…");
                $announced = true;
            }

            if ($sleep > 0) {
                sleep($sleep);
            }
        }
    }

    /**
     * One device_code-authed poll. Returns the decoded body, or null after
     * emitting a human error (transport/TLS failure or non-2xx).
     *
     * @return array<string,mixed>|null
     */
    private function pollOnce(string $base, string $publicId, string $deviceCode): ?array
    {
        try {
            $response = Http::acceptJson()
                ->timeout((int) config('dispatch.sync.timeout', 30))
                ->get($base.'/session/'.$publicId, ['device_code' => $deviceCode]);
        } catch (ConnectionException $e) {
            $this->reportConnectionFailure($e);

            return null;
        }

        if (! $response->successful()) {
            $this->error("Agent API HTTP {$response->status()}: ".substr($response->body(), 0, 300));

            // Throttled ≠ dead: a 429 on the poll endpoint invalidates nothing.
            if ($response->status() === 429) {
                $this->line('Hint: the session endpoint is rate-limited. Back off before polling again — a 429 does NOT invalidate the pending request or an existing token.');
            }

            return null;
        }

        return (array) $response->json();
    }

    /**
     * Act on a terminal poll status (everything except `pending`).
     *
     * @param  array<string,mixed>  $body
     * @param  array<string,mixed>  $data  the current token dotfile contents
     */
    private function settle(?string $status, array $body, array $data): int
    {
        switch ($status) {
            case 'approved':
                if (! empty($body['token'])) {
                    $this->storeToken(array_merge($data, [
                        'token' => $body['token'],
                        'expires_at' => $body['expires_at'] ?? ($data['expires_at'] ?? null),
                        // Session-start marker: session:end's default metrics
                        // window opens here (token delivery = session begins).
                        'stored_at' => now()->toIso8601String(),
                    ]));
                    // Say what the token CHANGES (sticky-remote) and what to run
                    // next, so the loop starts from this output, not from a doc.
                    $this->info($this->stickyRemoteEnabled()
                        ? 'Approved — token stored. dispatch verbs now target the remote by default while this session is active (pass --local for the local DB).'
                        : 'Approved — token stored; run the verbs with --remote.');
                    $this->line('Start with:  <fg=gray>php artisan dispatch:queue --count</>   then claim what you\'ll work: <fg=gray>dispatch:claim <CODE></>');
                    $this->line('When all work is closed out:  <fg=gray>php artisan dispatch:session:end</>');
                } else {
                    $this->info('Already approved (token issued earlier).');
                }

                return self::SUCCESS;

            case 'denied':
            case 'revoked':
            case 'expired':
                // Mark before forgetting (the marker copies identity out of the
                // dotfile) — bare verbs must not quietly serve local data after
                // a session the agent believed in died.
                $this->markSessionDropped("session {$status}");
                $this->forgetToken();
                $this->error("Session {$status}. Local token cleared; bare verbs now refuse the silent local fallback. ".($status === 'denied'
                    ? 'A human said no — report and stop (a `dispatch:session:refresh --wait` would just re-ask them). `--local` works locally; `dispatch:session:end` clears the guard.'
                    : 'Renew with `dispatch:session:refresh --wait` (a human approves again), or `dispatch:session:end` to acknowledge and work locally.'));

                return self::FAILURE;

            default:
                $this->error('Unexpected session status: '.json_encode($status));

                return self::FAILURE;
        }
    }

    /**
     * STATE: ACTIVE. Report a stored token entirely from the dotfile — NO HTTP.
     * The token's own expires_at tells us liveness; when it's past, name the
     * clean renewal (the server would 401 the next verb otherwise).
     *
     * @param  array<string,mixed>  $data  the token dotfile contents
     */
    private function reportActive(array $data): int
    {
        $name = $data['agent_name'] ?? 'agent';
        $expiresAt = $data['expires_at'] ?? null;
        $storedAt = $data['stored_at'] ?? null;

        $this->info("Active agent session: {$name} (token stored locally).");
        if (is_string($storedAt) && $storedAt !== '') {
            $this->line("  Approved / token stored: {$storedAt}");
        }

        $past = false;
        if (is_string($expiresAt) && $expiresAt !== '') {
            try {
                $past = Carbon::parse($expiresAt)->isPast();
            } catch (\Throwable) {
                $past = false;
            }
            $this->line("  Expires: {$expiresAt}".($past ? ' (past)' : ''));
        }

        if ($past) {
            $this->warn('This token is past its expires_at — the server will likely 401 on the next verb. Renew cleanly with `dispatch:session:refresh --wait` before it interrupts the loop.');
        } else {
            $this->line('Session is live — run the verbs (sticky-remote targets production while active), then close out with `dispatch:session:end`.');
        }

        return self::SUCCESS;
    }

    /**
     * STATE: DROPPED. A drop marker with no live token — the session died
     * involuntarily (revoked / expired / superseded). Name both recoveries:
     * refresh renews the same identity; session:end acknowledges and works local.
     *
     * @param  array<string,mixed>  $drop  the drop marker contents
     */
    private function reportDropped(array $drop): int
    {
        $reason = $drop['reason'] ?? 'dropped';
        $at = $drop['at'] ?? 'unknown time';

        $this->info("Agent session dropped — {$reason} ({$at}). No active token.");
        $this->line('  renew (a human approves again, same identity):  <fg=gray>php artisan dispatch:session:refresh --wait</>');
        $this->line('  acknowledge and work locally:                   <fg=gray>php artisan dispatch:session:end</>');

        return self::SUCCESS;
    }

    // --wait budget resolution lives in TalksToAgentApi::resolveWaitBudget(),
    // shared with dispatch:session:request's one-shot mode.
}
