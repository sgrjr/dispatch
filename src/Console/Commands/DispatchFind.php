<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\TaskPresenter;

/**
 * Text search across the board (W9-7).
 *
 * Trusted CLI surface: queries tasks directly (no DispatchGate::scopeVisible).
 *
 * Why this exists as its own verb rather than a flag on `queue`: the two have
 * OPPOSITE status defaults, and conflating them is how a search silently lies.
 * `queue` answers "what should I work on?" and so spans the actionable board;
 * `find` answers "does this already exist — filed, or already built?" and so
 * must span everything, especially the `done`/`declined`/`backburner` tasks the
 * queue deliberately hides. An agent that greps a `queue --json` dump for
 * duplicates gets a confident "no" from a query that never looked at the closed
 * board — which is precisely how already-shipped work gets rebuilt.
 *
 * Remotely this rides the existing `queue` scope via `?q=`, so a session
 * commissioned before this verb shipped can still use it — no re-commission.
 */
class DispatchFind extends Command
{
    use TalksToAgentApi;

    protected $signature = 'dispatch:find
        {term : Text to search for in the title, code, and description}
        {--status= : Restrict to a single status (default: ALL statuses — including done/resolved/declined/backburner)}
        {--type= : Filter to a single type}
        {--label=* : Filter to tasks carrying any of these labels}
        {--topic= : Filter to tasks whose topic matches "<type>[:<id>]" (id omitted matches any id of that type)}
        {--origin= : Filter to tasks whose origin matches "<type>[:<id>]"}
        {--conversation= : Filter to tasks in this conversation id}
        {--topic-account= : Filter to tasks whose topic_account_key equals this value}
        {--lane= : Filter to tasks in this lane ("<department>" matches its sub-lanes too; "<department>:<role>" matches exactly; "none" = the no-department lane)}
        {--limit= : Cap the number of matches returned (default: 50)}
        {--remote : Act on the configured remote agent API (the default while an agent session token is active)}
        {--local : Act on the local DB even while an agent session token is active (overrides sticky-remote)}
        {--json : Emit machine-readable JSON instead of a human table}';

    protected $description = 'Search tasks by text across ALL statuses — the "does this already exist?" verb.';

    public function handle(DispatchTaskService $tasks): int
    {
        $term = trim((string) $this->argument('term'));
        if ($term === '') {
            $this->error('Search term cannot be empty.');

            return self::FAILURE;
        }

        $limit = $this->option('limit');
        if ($limit !== null) {
            if (! ctype_digit((string) $limit) || (int) $limit < 1) {
                $this->error('--limit must be a positive integer.');

                return self::FAILURE;
            }
            $limit = (int) $limit;
        }
        $limit = $limit ?: 50;

        if ($this->targetsRemote()) {
            $r = $this->agentGet('queue', array_filter([
                'q' => $term,
                'status' => $this->option('status'),
                'type' => $this->option('type'),
                'label' => $this->option('label'),
                'limit' => $limit,
                // Anchor wire strings travel RAW — the server re-parses them
                // with the same Anchor::parse().
                'topic' => $this->option('topic'),
                'origin' => $this->option('origin'),
                'conversation' => $this->option('conversation'),
                'topic_account' => $this->option('topic-account'),
                'lane' => $this->option('lane'),
            ]));

            if ($r === null) {
                return self::FAILURE;
            }

            return $this->render((array) ($r['tasks'] ?? []), $term);
        }

        $filters = array_filter([
            'type' => $this->option('type'),
            'label' => $this->option('label'),
            'topic' => $this->option('topic'),
            'origin' => $this->option('origin'),
            'conversation' => $this->option('conversation'),
            'topic_account' => $this->option('topic-account'),
            'lane' => $this->option('lane'),
        ]);

        try {
            $matches = $tasks->searchQuery($term, $filters, $this->option('status'))
                ->limit($limit)
                ->get();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $this->render(TaskPresenter::collection($matches), $term);
    }

    /**
     * @param  array<int,array<string,mixed>>  $tasks
     */
    private function render(array $tasks, string $term): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($tasks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($tasks === []) {
            $this->info("No tasks match \"{$term}\".");
            // A zero-result search is a real answer, not an error — say what it
            // licenses, so "nothing found" doesn't get read as "search broken".
            $this->line('  Searched title, code, and description across ALL statuses — nothing filed yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['Code', 'Status', 'Type', 'Priority', 'Title'],
            array_map(fn ($t) => [
                $t['code'] ?? '',
                $t['status'] ?? '',
                $t['type'] ?? '',
                $t['priority'] ?? '',
                mb_strimwidth((string) ($t['title'] ?? ''), 0, 60, '…'),
            ], $tasks),
        );

        $count = count($tasks);
        $this->line("  {$count} match".($count === 1 ? '' : 'es').' — includes closed work, so check the status column before rebuilding anything.');

        return self::SUCCESS;
    }
}
