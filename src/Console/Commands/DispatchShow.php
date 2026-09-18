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

    /**
     * How many stack frames a client-error task prints before it is cut off.
     * A browser stack routinely runs to 60+ frames, almost all of them
     * framework internals; the head is where the app's own code sits.
     */
    private const STACK_FRAMES = 15;

    protected $signature = 'dispatch:show
        {code : The task code, e.g. TASK-042}
        {--remote : Act on the configured remote agent API (the default while an agent session token is active)}
        {--local : Act on the local DB even while an agent session token is active (overrides sticky-remote)}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Show full detail for a task including its comment timeline.';

    public function handle(): int
    {
        if ($this->targetsRemote()) {
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
            ->with(['labels', 'submitter', 'assignee', 'comments.user', 'attachments', 'comments.attachments'])
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
        if ($task->attachments->isNotEmpty()) {
            // Existence signal (W8-6): the binaries live on a private, auth-gated
            // disk and never travel the CLI/JSON surface — print the metadata so a
            // human (or agent reading `--json`) knows evidence exists.
            $this->line('  <fg=gray>Attachments:</>');
            foreach ($task->attachments as $a) {
                $this->line('    '.$a->original_name.' ('.$a->mime_type.', '.$a->size_bytes.' bytes)'.($a->is_image ? ' · image' : ''));
            }
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

            // What broke, and how loudly. Client-error tasks carry these from
            // DispatchTask::bug(); everything older carries neither, so every
            // key here is optional and absent keys simply print nothing.
            $this->lineOfBits([
                ! empty($ctx['error_type']) ? '<fg=red>'.$this->esc($ctx['error_type']).'</>' : null,
                ! empty($ctx['severity']) ? 'severity: '.$this->esc($ctx['severity']) : null,
            ]);

            // "Is this still firing?" — the single most useful triage field, and
            // the one that used to require a hand query against user_events to
            // tell a live bug from one that died three releases ago.
            $this->lineOfBits([
                isset($ctx['times_seen']) ? 'seen '.$ctx['times_seen'].'x' : null,
                ! empty($ctx['last_seen']) ? 'last seen: '.$this->esc($ctx['last_seen']) : null,
            ]);

            if (! empty($ctx['url'])) {
                $this->line('  url: '.$this->esc($ctx['url']));
            }
            if (! empty($ctx['inertia_page'])) {
                $this->line('  page: '.$this->esc($ctx['inertia_page']));
            }
            // `component_path` is the newer spelling; `component` is what the
            // earlier payloads used. Print whichever is there, never both.
            foreach (['component_path', 'component'] as $key) {
                if (! empty($ctx[$key])) {
                    $this->line('  component: '.$this->esc($ctx[$key]));
                    break;
                }
            }
            if (! empty($ctx['user_agent'])) {
                $this->line('  agent: '.$this->esc($ctx['user_agent']));
            }

            $errs = $ctx['console_errors'] ?? [];
            $this->line('  console errors: '.count($errs));
            foreach (array_slice($errs, -5) as $e) {
                $this->line('    <fg=red>'.($e['type'] ?? 'error').'</>: '.$this->esc($e['message'] ?? ''));
            }

            // The stack goes last because it is the longest thing in the block.
            // Trimmed to the frames that carry the signal — the tail of a browser
            // stack is framework noise, and an untrimmed one buries every field
            // above it.
            if (! empty($ctx['stack'])) {
                $frames = is_array($ctx['stack'])
                    ? $ctx['stack']
                    : preg_split('/\R/', (string) $ctx['stack']);

                $frames = array_values(array_filter(
                    array_map(fn ($f) => trim((string) (is_array($f) ? json_encode($f) : $f)), $frames),
                    fn ($f) => $f !== ''
                ));

                $shown = array_slice($frames, 0, self::STACK_FRAMES);

                if ($shown !== []) {
                    $this->line('  stack:');
                    foreach ($shown as $frame) {
                        $this->line('    '.$this->esc($frame));
                    }
                    if (($more = count($frames) - count($shown)) > 0) {
                        $this->line('    <fg=gray>... '.$more.' more frame'.($more === 1 ? '' : 's').'</>');
                    }
                }
            }
        }

        // Agent run metrics (parity with the staff "Agent run" panel). Present
        // only once a run has been stamped under context.result.metrics — same
        // MetricsPresenter shaping, so the CLI and the web view read identically.
        if (($m = MetricsPresenter::present($task->context, $task->type)) !== null) {
            $this->newLine();
            $this->line('<fg=gray># Agent run</>');
            $this->line("  tokens: {$m['total_tokens']} ({$m['cache_pct']} cached)  ·  cost: {$m['cost']}  ·  duration: {$m['duration']}");
            if ($m['touch_time'] !== null) {
                $this->line("  est. human time ({$m['touch_time_version']}): {$m['touch_time']}");
            }
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
                // Per-comment attachment signal (W8-6) — evidence hung off a reply.
                $atts = $c->relationLoaded('attachments') ? $c->attachments->count() : $c->attachments()->count();
                $suffix = $atts > 0 ? ' [+'.$atts.' attachment(s)]' : '';
                $this->line('  <fg=gray>'.$when.'</> <fg=yellow>'.$who.'</> '.$tag.$suffix);
                foreach (preg_split('/\R/', trim((string) $c->body)) as $line) {
                    $this->line('    '.$line);
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * Console-format-escape a value that came from a browser payload. Stack
     * frames routinely contain <anonymous>, and Symfony would otherwise read
     * that as a (broken) style tag and swallow it.
     */
    private function esc(mixed $value): string
    {
        return \Symfony\Component\Console\Formatter\OutputFormatter::escape((string) $value);
    }

    /** Print "  a  ·  b", skipping nulls; print nothing when all are null. */
    private function lineOfBits(array $bits): void
    {
        $bits = array_values(array_filter($bits, fn ($b) => $b !== null && $b !== ''));

        if ($bits !== []) {
            $this->line('  '.implode('  ·  ', $bits));
        }
    }
}
