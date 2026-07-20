<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Support\AgentMetrics;
use Sgrjr\Dispatch\Support\TranscriptLocator;

/**
 * Compute agent-run metrics (tokens, cost, tool usage, duration) for a task from
 * the local Claude Code transcript, windowed to the task's claim→now span, and
 * optionally stamp them under `context.result.metrics` or as a timeline note.
 *
 * The model can't read its own token usage mid-run, so metrics come from the
 * transcript JSONL (via {@see TranscriptMetrics}); this command is the seam an
 * agent shells out to at `dispatch:done` time:
 *
 *   php artisan dispatch:done TASK-042 \
 *     --result="$(php artisan dispatch:metrics TASK-042 --json)"
 */
class DispatchMetrics extends Command
{
    protected $signature = 'dispatch:metrics
        {code : The task code, e.g. TASK-042}
        {--since= : ISO-8601 window start (default: the task\'s latest claim time)}
        {--until= : ISO-8601 window end (default: now)}
        {--transcript= : Explicit main transcript path (skips discovery)}
        {--session= : Claude Code session id to locate the transcript}
        {--project-dir= : Project dir whose transcripts to search (default: base_path())}
        {--stamp : Deep-merge the metrics into context.result.metrics}
        {--note : Post a one-line internal comment summarizing the metrics}
        {--json : Emit the metrics as JSON (for piping into dispatch:done --result)}';

    protected $description = 'Compute agent-run metrics (tokens/cost/tools/duration) for a task from the session transcript.';

    public function handle(TranscriptLocator $locator): int
    {
        $json = (bool) $this->option('json');

        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');
        $task = $taskModel::query()->where('code', $this->argument('code'))->first();
        if (! $task) {
            // Compute-only is still useful when the task lives on a remote
            // (production) instance and isn't in the local DB — the agent pipes
            // the JSON into `dispatch:done --remote --result`. But there's
            // nothing local to write to, so --stamp/--note can't apply.
            if ($this->option('stamp') || $this->option('note')) {
                $this->error("Task not found: {$this->argument('code')} — can't --stamp/--note a task that isn't in the local DB. For a remote task, pass --since=<claim time> and --json, then feed it to `dispatch:done --remote --result`.");

                return self::FAILURE;
            }
            if (! $this->option('json') && ! $this->option('since')) {
                $this->warn("Task {$this->argument('code')} not in local DB — computing over the whole transcript. Pass --since to window it.");
            }
        }

        try {
            $since = $this->option('since') ? Carbon::parse($this->option('since')) : null;
            $until = $this->option('until') ? Carbon::parse($this->option('until')) : Carbon::now();
        } catch (\Throwable $e) {
            $this->error('Invalid --since/--until: '.$e->getMessage());

            return self::FAILURE;
        }

        // Default window start: the most recent claim event on this task.
        $claim = null;
        if ($task !== null && $since === null) {
            $claim = $task->comments()
                ->where('event_type', TaskComment::EVENT_CLAIMED)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();
            $since = $claim?->created_at;
        }

        $metrics = AgentMetrics::collect(
            $locator,
            $since,
            $until,
            $this->option('since') ? 'since-option' : ($claim ? 'claimed_at' : 'unbounded'),
            [
                'transcript' => $this->option('transcript') ?: null,
                'session' => $this->option('session') ?: null,
                'projectDir' => $this->option('project-dir') ?: null,
            ],
        );

        if ($metrics['transcript']['main'] === null && ! $json) {
            $this->warn('No transcript located — token metrics are zero. Pass --transcript=, --session=, or install the SessionStart capture hook.');
        }

        if ($this->option('stamp')) {
            $ctx = $task->context ?? [];
            $result = $ctx['result'] ?? [];
            // Sibling result keys survive untouched; prior-run metrics accumulate
            // (same-window re-stamps replace — see AgentMetrics::accumulate()).
            $result['metrics'] = AgentMetrics::accumulate(
                is_array($result['metrics'] ?? null) ? $result['metrics'] : null,
                $metrics,
            );
            $ctx['result'] = $result;
            $task->context = $ctx;
            $task->save();
        }

        if ($this->option('note')) {
            $task->comments()->create([
                'user_id' => Auth::id(),
                'body' => $this->summaryLine($metrics),
                'is_internal' => true,
                'event_type' => TaskComment::EVENT_COMMENT,
            ]);
        }

        if ($json) {
            $this->line(json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderHuman($task?->code ?? (string) $this->argument('code'), $metrics);

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $m
     */
    private function summaryLine(array $m): string
    {
        return AgentMetrics::summaryLine($m);
    }

    private function humanDuration(int $s): string
    {
        if ($s < 60) {
            return "{$s}s";
        }
        $min = intdiv($s, 60);
        $sec = $s % 60;
        if ($min < 60) {
            return $sec ? "{$min}m {$sec}s" : "{$min}m";
        }
        $hr = intdiv($min, 60);
        $min %= 60;

        return "{$hr}h {$min}m";
    }

    /**
     * @param  array<string,mixed>  $m
     */
    private function renderHuman(string $code, array $m): void
    {
        $t = $m['tokens'];
        $this->info("Metrics for {$code}  (window: {$m['window']['basis']}, transcript: {$m['transcript']['source']})");

        $cost = $m['cost_usd'] !== null
            ? '$'.number_format((float) $m['cost_usd'], 4).($m['cost_partial'] ? ' (partial)' : '')
            : 'unknown';

        $this->table(['metric', 'value'], [
            ['duration', $m['duration_s'] !== null ? $this->humanDuration((int) $m['duration_s']) : '—'],
            ['tokens.total', number_format((int) $t['total'])],
            ['tokens.input', number_format((int) $t['input'])],
            ['tokens.output', number_format((int) $t['output'])],
            ['tokens.cache_read', number_format((int) $t['cache_read'])],
            ['tokens.cache_creation', number_format((int) $t['cache_creation'])],
            ['cache_hit_ratio', number_format(((float) ($t['cache_hit_ratio'] ?? 0)) * 100, 1).'%'],
            ['cost_usd', $cost],
            ['turns', (int) $m['turns']],
            ['tool_calls', (int) $m['tool_calls']],
            ['subagents', (int) $m['subagents']],
            ['errors', (int) $m['errors']],
            ['models', implode(', ', $m['models']) ?: '—'],
        ]);

        if (! empty($m['tools'])) {
            $rows = [];
            foreach (array_slice($m['tools'], 0, 8, true) as $name => $count) {
                $rows[] = [$name, $count];
            }
            $this->line('Top tools:');
            $this->table(['tool', 'calls'], $rows);
        }
    }
}
