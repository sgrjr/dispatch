<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Sgrjr\Dispatch\Services\AgentSessionService;

/**
 * Housekeeping: flip stale approved/pending agent sessions to expired
 * (AgentSessionService::prune — hygiene for the "Agent Sessions" approval UI;
 * lazy expiry via AgentSession::isUsable() is the actual security boundary).
 * Runs on the authoritative (prod) instance; wire it to the scheduler.
 */
class DispatchSessionsPrune extends Command
{
    protected $signature = 'dispatch:sessions:prune {--json : Emit machine-readable JSON instead of human text}';

    protected $description = 'Expire stale pending/approved agent sessions.';

    public function handle(AgentSessionService $sessions): int
    {
        $n = $sessions->prune();

        if ($this->option('json')) {
            $this->line(json_encode(['expired' => $n], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Expired {$n} agent session(s).");

        return self::SUCCESS;
    }
}
