<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Support\MetricsPresenter;
use Sgrjr\Dispatch\Support\TaskPresenter;

/**
 * Trusted CLI surface: queries tasks directly (no DispatchGate::scopeVisible —
 * that scope is for user-facing web surfaces only). Shows the full comment
 * timeline including internal comments; there is no logged-in user to hide
 * them from in this context.
 */
class DispatchShow extends Command
{
    use TalksToAgentApi;

    protected $signature = 'dispatch:show
        {code : The task code, e.g. TASK-042}
        {--remote : Act on the configured remote agent API instead of the local DB}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Show full detail for a task including its comment timeline.';

    public function handle(): int
    {
        if ($this->option('remote')) {
            $r = $this->agentGet('show/'.$this->argument('code'));

            if ($r === null) {
                return self::FAILURE;
            }

            $this->line(json_encode($r['task'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $task = $taskModel::query()
            ->with(['labels', 'submitter', 'assignee', 'comments.user'])
            ->where('code', $this->argument('code'))
            ->first();

        if (! $task) {
            $this->error("Task not found: {$this->argument('code')}");

            return self::FAILURE;
        }

        $comments = $task->comments;

        if ($this->option('json')) {
            $this->line(json_encode(TaskPresenter::toArray($task, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('<fg=cyan;options=bold>'.$task->code.'</> <fg=white>'.$task->title.'</>');
        $this->line('  priority: '.$task->priority.'  ·  type: '.$task->type.'  ·  status: '.$task->status.'  ·  public: '.($task->is_public ? 'yes' : 'no'));
        if ($task->labels->isNotEmpty()) {
            $this->line('  labels: '.$task->labels->pluck('name')->implode(', '));
        }
        if ($task->submitter) {
            $this->line('  submitter: '.$task->submitter->email);
        }
        if ($task->assignee) {
            $this->line('  assignee:  '.$task->assignee->email);
        }

        if ($task->description) {
            $this->newLine();
            $this->line('<fg=gray># Description</>');
            $this->line($task->description);
        }

        if (! empty($task->context)) {
            $ctx = $task->context;
            $this->newLine();
            $this->line('<fg=gray># Diagnostics</>');
            if (! empty($ctx['url'])) {
                $this->line('  url: '.$ctx['url']);
            }
            if (! empty($ctx['user_agent'])) {
                $this->line('  agent: '.$ctx['user_agent']);
            }
            $errs = $ctx['console_errors'] ?? [];
            $this->line('  console errors: '.count($errs));
            foreach (array_slice($errs, -5) as $e) {
                $this->line('    <fg=red>'.($e['type'] ?? 'error').'</>: '.($e['message'] ?? ''));
            }
        }

        // Agent run metrics (parity with the staff "Agent run" panel). Present
        // only once a run has been stamped under context.result.metrics — same
        // MetricsPresenter shaping, so the CLI and the web view read identically.
        if (($m = MetricsPresenter::present($task->context)) !== null) {
            $this->newLine();
            $this->line('<fg=gray># Agent run</>');
            $this->line("  tokens: {$m['total_tokens']} ({$m['cache_pct']} cached)  ·  cost: {$m['cost']}  ·  duration: {$m['duration']}");
            $this->line("  turns: {$m['turns']}  ·  tool calls: {$m['tool_calls']}  ·  subagents: {$m['subagents']}  ·  errors: {$m['errors']}");
            $this->line("  input {$m['tokens']['input']} · output {$m['tokens']['output']} · cache read {$m['tokens']['cache_read']} · cache write {$m['tokens']['cache_creation']}");
            if (! empty($m['tools'])) {
                $this->line('  tools: '.implode(', ', array_map(fn ($t) => "{$t['name']} · {$t['count']}", $m['tools'])));
            }
            if (! empty($m['models'])) {
                $this->line('  models: '.implode(', ', $m['models']));
            }
            $tail = array_filter([
                $m['commit'] ? "commit: {$m['commit']}" : null,
                "window: {$m['window_basis']}",
                "transcript: {$m['transcript_source']}",
            ]);
            $this->line('  '.implode('  ·  ', $tail));
        }

        $this->newLine();
        $this->line('<fg=gray># Thread ('.$comments->count().' entries)</>');
        if ($comments->isEmpty()) {
            $this->line('  <fg=gray>(no comments yet)</>');
        } else {
            foreach ($comments as $c) {
                $when = optional($c->created_at)->format('Y-m-d H:i') ?? '?';
                $who = $c->user?->email ?? ($c->isSystem() ? 'system' : 'anon');
                $tag = $c->is_internal ? '[INTERNAL]' : ($c->isSystem() ? '['.$c->event_type.']' : '');
                $this->line('  <fg=gray>'.$when.'</> <fg=yellow>'.$who.'</> '.$tag);
                foreach (preg_split('/\R/', trim((string) $c->body)) as $line) {
                    $this->line('    '.$line);
                }
            }
        }

        return self::SUCCESS;
    }
}
