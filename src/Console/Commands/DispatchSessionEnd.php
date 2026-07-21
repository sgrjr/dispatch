<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;
use Sgrjr\Dispatch\Support\AgentMetrics;
use Sgrjr\Dispatch\Support\TranscriptLocator;

/**
 * End the current remote agent session (§20 — GAP 5). Posts to the bearer-authed
 * `session/end` route, which revokes the caller's OWN session server-side, then
 * deletes the local token file. Least-privilege: a finished agent surrenders its
 * credential immediately instead of leaving a usable token alive until TTL.
 *
 * SESSION-ANCHORED METRICS: by default this computes whole-session run metrics
 * from the local transcript (window: token stored → now) and folds them into the
 * end request, where the server stores them on the session row. session:end is
 * the one protocol step with a forcing function, so metrics recorded here can't
 * be forgotten the way a per-task `done --with-metrics` can. Per-task stamping
 * remains the fine-grained refinement; this is the load-bearing default.
 * `--no-metrics` opts out; metrics failures NEVER block ending the session —
 * surrendering the credential always wins.
 *
 * Like the other session commands, this only talks to the remote — there is no
 * local agent session to end.
 */
class DispatchSessionEnd extends Command
{
    use TalksToAgentApi;

    protected $signature = 'dispatch:session:end
        {--no-metrics : Skip computing/recording session metrics (they are recorded by default)}
        {--since= : ISO-8601 window start for the session metrics (default: when the token was stored locally)}
        {--transcript= : Explicit main transcript path (skips discovery)}
        {--session= : Claude Code session id to locate the transcript}
        {--project-dir= : Project dir whose transcripts to search (default: base_path())}';

    protected $description = 'End (revoke) the current agent session on the server, recording session-run metrics, and clear the local token.';

    public function handle(TranscriptLocator $locator): int
    {
        if ($this->agentToken() === null) {
            // Nothing live locally — clear any stale dotfile and report cleanly.
            // session:end is also the ACKNOWLEDGE verb for a dropped session:
            // clearing the marker restores local-by-default for bare verbs.
            $this->forgetToken();
            $hadDrop = $this->sessionDropMarker() !== null;
            $this->clearSessionDropMarker();
            $this->info('No active agent session token — nothing to end.');
            if ($hadDrop) {
                $this->line('Dropped-session guard cleared — bare verbs act on the local DB again (`dispatch:session:refresh` would have renewed it instead).');
            }

            return self::SUCCESS;
        }

        // Compute metrics BEFORE the end call (the payload rides it), but never
        // let metrics bookkeeping block the actual goal: no live credential.
        $metrics = null;
        if (! $this->option('no-metrics')) {
            try {
                $metrics = $this->collectSessionMetrics($locator);
            } catch (\Throwable $e) {
                $this->warn('Could not compute session metrics ('.$e->getMessage().') — ending the session without them.');
            }
        }

        $result = $this->agentPost('session/end', $metrics !== null ? ['metrics' => $metrics] : []);

        if ($result !== null) {
            $this->forgetToken();
            $this->clearSessionDropMarker();
            $this->info('Session ended — server session revoked, local token cleared.');
            if ($metrics !== null) {
                $this->line('Session metrics recorded: '.AgentMetrics::summaryLine($metrics));
            }

            return self::SUCCESS;
        }

        // agentPost() failed. On a 401 it already cleared the token — the session
        // was already dead, so the goal (no live credential) is met. It also
        // wrote a drop marker, but a deliberate end IS the acknowledgment.
        if ($this->agentToken() === null) {
            $this->clearSessionDropMarker();
            $this->line('Session was already inactive; local token cleared.');

            return self::SUCCESS;
        }

        // A live token remains and the revoke didn't land (transport/HTTP error).
        // Keep it so the operator can retry rather than orphaning a live server
        // session until TTL.
        $this->error('Could not reach the agent API to end the session — local token kept; retry `dispatch:session:end`.');

        return self::FAILURE;
    }

    /**
     * Whole-session metrics from the local transcript. Window start resolves
     * `--since` → the dotfile's `stored_at` (stamped at token delivery) → the
     * dotfile's mtime — so unlike the per-task path there is NO bookkeeping the
     * agent has to have captured mid-run. Returns null (with a warning) when no
     * transcript can be located: this path is default-on, and silently stamping
     * an all-zero object would read as "recorded" when nothing was measured.
     *
     * @return array<string,mixed>|null
     */
    private function collectSessionMetrics(TranscriptLocator $locator): ?array
    {
        [$since, $basis] = $this->resolveSessionWindow();

        $metrics = AgentMetrics::collect($locator, $since, null, $basis, [
            'transcript' => $this->option('transcript') ?: null,
            'session' => $this->option('session') ?: null,
            'projectDir' => $this->option('project-dir') ?: null,
        ]);

        if ($metrics['transcript']['main'] === null) {
            $this->warn('No transcript located — ending the session WITHOUT metrics. Pass --transcript=/--session=, or install the SessionStart capture hook.');

            return null;
        }

        return $metrics;
    }

    /**
     * @return array{0:?Carbon,1:string}  [window start, basis label]
     */
    private function resolveSessionWindow(): array
    {
        if ($this->option('since')) {
            return [Carbon::parse($this->option('since')), 'since-option'];
        }

        $storedAt = $this->agentTokenFile()['stored_at'] ?? null;
        if (is_string($storedAt) && $storedAt !== '') {
            return [Carbon::parse($storedAt), 'session-token'];
        }

        // Dotfiles written before stored_at existed: the file's mtime IS the
        // token-delivery moment (settle() rewrites it exactly once, then).
        $mtime = @filemtime($this->agentTokenPath());
        if ($mtime !== false) {
            return [Carbon::createFromTimestamp($mtime), 'session-token'];
        }

        return [null, 'unbounded'];
    }
}
