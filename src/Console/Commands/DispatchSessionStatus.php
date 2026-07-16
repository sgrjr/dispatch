<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;

/**
 * Poll a previously-requested agent session for approval (§20 Phase 1). The
 * poll is device_code-authed (RFC 8628), NOT bearer — there is no session
 * token until this succeeds — so it is built directly with Http rather than
 * TalksToAgentApi::agentGet (which sends a bearer, not a device_code).
 *
 * Approval is a human, out-of-band action — this command polls ONCE and
 * exits; it deliberately does not loop/spin, so re-run it (or wire it to a
 * cron) rather than hammering the endpoint.
 */
class DispatchSessionStatus extends Command
{
    use TalksToAgentApi;

    protected $signature = 'dispatch:session:status';

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

        $response = Http::acceptJson()
            ->timeout((int) config('dispatch.sync.timeout', 30))
            ->get($base.'/session/'.$publicId, ['device_code' => $deviceCode]);

        if (! $response->successful()) {
            $this->error("Agent API HTTP {$response->status()}: ".substr($response->body(), 0, 300));

            return self::FAILURE;
        }

        $body = (array) $response->json();
        $status = $body['status'] ?? null;

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

            case 'pending':
                $this->line('Still pending — a human must approve it. Approval is async; back off and re-run later (do NOT spin).');

                return self::SUCCESS;

            case 'denied':
            case 'revoked':
            case 'expired':
                $this->forgetToken();
                $this->error("Session {$status}. Local token cleared — run `dispatch:session:request` to start over.");

                return self::FAILURE;

            default:
                $this->error("Unexpected session status: ".json_encode($status));

                return self::FAILURE;
        }
    }
}
