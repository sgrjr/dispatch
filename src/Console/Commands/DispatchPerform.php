<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Services\TaskActions;

/**
 * TASK-1188 — run a task KIND's action (`dispatch:show --json` lists them under
 * `kind.actions`). Local or `--remote` (`POST agent/perform`, gated by the
 * `perform` scope). Either way it runs as an AGENT: only `agentAllowed`
 * actions. A person's action (an approval's Approve / Deny) is refused, by
 * design; people act on the task page or in the host's own screens.
 */
class DispatchPerform extends Command
{
    use TalksToAgentApi;

    protected $signature = 'dispatch:perform
        {code : The task code, e.g. TASK-042}
        {action : The action key, from the task\'s kind.actions}
        {--input=* : An input as key=value (repeatable), e.g. --input=note="Handled by phone"}
        {--remote : Act on the configured remote agent API (the default while an agent session token is active)}
        {--local : Act on the local DB even while an agent session token is active (overrides sticky-remote)}
        {--json : Emit machine-readable JSON instead of human text}';

    protected $description = "Run a task kind's action (agent-allowed actions only).";

    public function handle(TaskActions $actions): int
    {
        $input = [];
        foreach ((array) $this->option('input') as $pair) {
            if (! str_contains((string) $pair, '=')) {
                $this->error("--input must be key=value, got: {$pair}");

                return self::FAILURE;
            }
            [$k, $v] = explode('=', (string) $pair, 2);
            $input[trim($k)] = $v;
        }

        if ($this->targetsRemote()) {
            $r = $this->agentPost('perform', array_filter([
                'code' => $this->argument('code'),
                'action' => $this->argument('action'),
                'input' => $input ?: null,
            ], fn ($v) => $v !== null));

            if ($r === null) {
                return self::FAILURE;
            }

            $this->line(json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');
        $task = $taskModel::query()->where('code', $this->argument('code'))->first();
        if (! $task) {
            $this->error("Task not found: {$this->argument('code')}");

            return self::FAILURE;
        }

        try {
            $message = $actions->perform($task, (string) $this->argument('action'), null, $input, true);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode(['message' => $message, 'status' => $task->fresh()->status], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info($message ?: "Ran `{$this->argument('action')}` on {$task->code}.");
        }

        return self::SUCCESS;
    }
}
