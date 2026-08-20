<?php

namespace Sgrjr\Dispatch\Console\Commands\Concerns;

/**
 * Guard for the LOCAL-ONLY write verbs — commands that mutate tasks but carry
 * no `--remote` path (`dispatch:edit`, `dispatch:merge`).
 *
 * Every other write verb mixes in TalksToAgentApi, so an active agent session
 * retargets it at the remote (sticky remote) and says so on STDERR. These two
 * never got that plumbing, which left them the one place a mid-session command
 * wrote to the LOCAL dev DB while the caller believed — reasonably, since every
 * neighbouring verb had just behaved that way — it was acting on the board.
 * Nothing said otherwise: no target banner, no flag to override, exit 0.
 *
 * That is worse than confusing, because task codes are minted PER-DATABASE
 * (Task::nextCode() — `max + 1` over the local rows), so TASK-042 exists on both
 * sides and names two DIFFERENT tasks. A mid-session
 * `dispatch:edit TASK-042 --description=…` could therefore overwrite an
 * unrelated local task's body and memorialize the wrong prior version onto the
 * wrong timeline, reporting success either way. The observed run escaped only
 * because its code happened to be higher than the local sequence had reached.
 *
 * Same ruling as TalksToAgentApi's dropped-session guard: fail loud and name the
 * path that does reach the remote, rather than let a local write masquerade as a
 * remote one. `--local` is the explicit "yes, the dev DB, on purpose".
 *
 * TalksToAgentApi is composed in for its session-state readers (token, base URL,
 * sticky flag, drop marker) — NOT for its client. A local-only verb must never
 * gain a request path by mixing this in; if one ever should reach the remote,
 * that is the §13 agent-verb decision, made deliberately, not a side effect.
 */
trait GuardsLocalOnlyWrites
{
    use TalksToAgentApi;

    /**
     * Refuse a local-only write while an agent session is active (or was, and
     * dropped). Returns true when the caller should bail with FAILURE — call it
     * BEFORE any lookup or mutation, so a refusal touches nothing.
     *
     * @param  string  $verb  The command name, e.g. "dispatch:edit"
     * @param  string  $consequence  What this specific verb would do to the
     *                               wrong task, as a verb phrase. Generic here
     *                               would blunt the point — "rewrite a body" and
     *                               "soft-delete a task" are not the same warning.
     * @param  array<string,string>  $alternatives  "do this instead" hints, as
     *                                              label => command. The guard
     *                                              owns the indent and the label
     *                                              column so every line — its own
     *                                              --local hint included — lands
     *                                              in the same column.
     */
    protected function blockedByActiveAgentSession(string $verb, string $consequence, array $alternatives = []): bool
    {
        // The explicit acknowledgment — same flag, same meaning as on every
        // sticky verb.
        if ($this->hasOption('local') && $this->option('local')) {
            return false;
        }

        // Sticky off is the host ruling that a live token does NOT retarget a
        // bare verb. Local is then the expected target, so there is no mismatch
        // to report and this guard has nothing to say.
        if (! $this->stickyRemoteEnabled()) {
            return false;
        }

        $base = $this->agentBaseUrl();
        if ($base === null) {
            return false;
        }

        $hasToken = $this->agentToken() !== null;
        $drop = $hasToken ? null : $this->sessionDropMarker();
        // An unexplained loss counts for exactly the same reason a drop does
        // (W14-1): the marker only describes a server-announced death, so a
        // token that vanished or went unreadable would otherwise read here as
        // "there was never a session" — the state this guard exists to deny.
        $crumb = ($hasToken || $drop !== null) ? null : $this->sessionBreadcrumb();

        // No token, no marker, no breadcrumb: an ordinary local run, which is
        // the whole point of these verbs. Say nothing.
        if (! $hasToken && $drop === null && $crumb === null) {
            return false;
        }

        // A dropped session counts. The marker exists precisely because "the
        // token is gone" and "there was never a session" are different states,
        // and the first one must not silently resolve to the local DB.
        $state = match (true) {
            $hasToken => "an agent session is ACTIVE against {$base}",
            $drop !== null => sprintf(
                'an agent session against %s was dropped (%s, %s) and has not been renewed or acknowledged',
                $base,
                $drop['reason'] ?? 'dropped',
                $drop['at'] ?? 'unknown time',
            ),
            default => sprintf(
                'an agent session against %s was active since %s and its token %s with no 401 and no drop marker',
                $base,
                $crumb['at'] ?? 'unknown time',
                $this->agentTokenState() === 'unreadable' ? 'is now UNREADABLE' : 'has vanished',
            ),
        };

        $this->error("{$verb} is LOCAL-ONLY and {$state} — it would write to the local dev DB while every neighbouring verb targets the remote. Task codes are minted per-database, so the same code names a DIFFERENT task on each side: this could {$consequence}, and report success. Refusing.");

        $hints = $alternatives + ['local on purpose' => 're-run with --local'];
        $width = max(array_map('strlen', array_keys($hints)));

        foreach ($hints as $label => $command) {
            $this->line(sprintf('  %s  %s', str_pad($label.':', $width + 1), $command));
        }

        return true;
    }
}
