<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Sgrjr\Dispatch\Console\Commands\Concerns\ResolvesTextInput;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\TaskPresenter;

/**
 * TASK-997 part B — the hand-off ("the ball"): pass ("your turn") or ask
 * ("I need this from you, then it's back to me"). See {@see
 * DispatchTaskService::handoff()} for the full lane/close/link semantics —
 * this command is a thin CLI wrapper, local or `--remote` (`POST
 * agent/handoff`, gated by the `handoff` scope — a host must add it to its
 * published `agent.verbs`, see UPGRADING.md, or the verb 403s "not scoped").
 *
 * Like every other trusted CLI verb, `--to` accepts an email or a numeric
 * user id (same convention as `dispatch:import`'s submitter/assignee
 * resolution) — there is no logged-in actor to pick "the current user" from.
 */
class DispatchHandoff extends Command
{
    use ResolvesTextInput;
    use TalksToAgentApi;

    protected $signature = 'dispatch:handoff
        {code : The task code to hand off, e.g. TASK-042}
        {--to= : The recipient — a user id or email}
        {--ask : Ask instead of pass — mints a task that BLOCKS this one; closing it returns the ball with the answer}
        {--lane= : Disambiguate which of the recipient\'s lanes gets the (new) task, when they work more than one}
        {--note= : A note carried onto the hand-off — the minted task\'s description (pass/ask) or the assignee_change event (a same-lane pass)}
        {--note-file= : Read the note from a file (or `-` for stdin) instead of inline --note}
        {--due= : Due date for the task the recipient now holds (parseable date/time string, e.g. "2026-08-01" or "+3 days")}
        {--keep-open : On a cross-lane/no-lane PASS, leave the passing task open instead of closing it (ignored for a same-lane pass or an ask)}
        {--remote : Act on the configured remote agent API (the default while an agent session token is active)}
        {--local : Act on the local DB even while an agent session token is active (overrides sticky-remote)}
        {--json : Emit machine-readable JSON instead of human text}';

    protected $description = 'Pass or ask — hand a task to someone else (moving the ball, or blocking your task on theirs).';

    public function handle(DispatchTaskService $tasks): int
    {
        [$note, $err] = $this->resolveInlineOrFile(
            $this->option('note'),
            $this->option('note-file'),
            '--note',
            '--note-file',
        );
        if ($err !== null) {
            $this->error($err);

            return self::FAILURE;
        }

        $to = $this->option('to');
        if ($to === null || trim($to) === '') {
            $this->error('--to is required (a user id or email).');

            return self::FAILURE;
        }

        if ($this->targetsRemote()) {
            $r = $this->agentPost('handoff', array_filter([
                'code' => $this->argument('code'),
                'to' => $to,
                'ask' => $this->option('ask') ? true : null,
                'lane' => $this->option('lane'),
                'note' => $note,
                // Sent RAW — the server re-parses it on ITS clock, same
                // posture as every other --due flag that forwards remote.
                'due_at' => $this->option('due'),
                'keep_open' => $this->option('keep-open') ? true : null,
            ], fn ($v) => $v !== null));

            if ($r === null) {
                return self::FAILURE;
            }

            $this->line(json_encode($r['task'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');
        $task = $taskModel::query()->where('code', $this->argument('code'))->first();
        if (! $task) {
            $this->error("Task not found: {$this->argument('code')}");

            return self::FAILURE;
        }

        $recipient = $this->resolveUser($to);
        if ($recipient === null) {
            $this->error("No user found for --to: {$to}");

            return self::FAILURE;
        }

        try {
            $result = $tasks->handoff($task, $recipient, Auth::user(), array_filter([
                'ask' => $this->option('ask') ? true : null,
                'lane' => $this->option('lane'),
                'note' => $note,
                'due' => $this->option('due'),
                'keep_open' => $this->option('keep-open') ? true : null,
            ], fn ($v) => $v !== null));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode(TaskPresenter::toArray($result, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($result->code !== $task->code) {
            $this->info("Passed {$task->code} to {$recipient->name} — continued as {$result->code}.");
        } elseif ($this->option('ask')) {
            $this->info("Asked {$recipient->name} on {$task->code}.");
        } else {
            $this->info("Passed {$task->code} to {$recipient->name}.");
        }

        return self::SUCCESS;
    }

    /**
     * `--to` resolution: an email (contains `@`) or a numeric user id. Same
     * convention as DispatchImport's submitter/assignee resolution.
     */
    protected function resolveUser(string $ref): mixed
    {
        /** @var class-string $userModel */
        $userModel = config('dispatch.models.user');

        return str_contains($ref, '@')
            ? $userModel::query()->where('email', $ref)->first()
            : $userModel::query()->find((int) $ref);
    }
}
