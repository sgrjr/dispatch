<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;

/**
 * Poll a previously-requested agent session for approval (§20 Phase 1). The
 * poll is device_code-authed (RFC 8628), NOT bearer — there is no session
 * token until this succeeds — so it is built directly with Http rather than
 * TalksToAgentApi::agentGet (which sends a bearer, not a device_code).
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

        $data = $this->agentTokenFile();
        $publicId = $data['public_id'] ?? null;
        $deviceCode = $data['device_code'] ?? null;

        if (! $publicId || ! $deviceCode) {
            $this->error('No pending agent session found. Run `dispatch:session:request` first.');

            return self::FAILURE;
        }

        $budget = $this->waitBudget();
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
                    ]));
                    $this->info('Approved — token stored; run the verbs with --remote.');
                } else {
                    $this->info('Already approved (token issued earlier).');
                }

                return self::SUCCESS;

            case 'denied':
            case 'revoked':
            case 'expired':
                $this->forgetToken();
                $this->error("Session {$status}. Local token cleared — run `dispatch:session:request` to start over.");

                return self::FAILURE;

            default:
                $this->error('Unexpected session status: '.json_encode($status));

                return self::FAILURE;
        }
    }

    /**
     * Resolve the wait budget in seconds from `--wait`:
     *   omitted        -> 0   (single poll, backward-compatible default)
     *   bare `--wait`  -> 60  (VALUE_OPTIONAL yields null when passed with no value)
     *   `--wait=N`     -> N
     */
    private function waitBudget(): int
    {
        $opt = $this->option('wait');

        if ($opt === null) {
            return 60;
        }

        return max(0, (int) $opt);
    }
}
