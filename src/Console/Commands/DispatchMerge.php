<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Sgrjr\Dispatch\Console\Commands\Concerns\GuardsLocalOnlyWrites;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/**
 * Trusted CLI surface: queries tasks directly (no DispatchGate::scopeVisible).
 * Folds a duplicate ("loser") task into the canonical ("winner") task via
 * DispatchTaskService::merge() — comments reparent, labels union, both sides
 * get a memorial EVENT_MERGED comment, and the loser is soft-deleted with
 * duplicate_of/status stamped. See DispatchTaskService::merge() for detail.
 *
 * LOCAL-ONLY, and the sharpest case for GuardsLocalOnlyWrites: it takes TWO
 * per-database codes and SOFT-DELETES one of the tasks they name. Run
 * mid-session against the wrong DB it could fold together two unrelated local
 * tasks — a destructive write, reported as a success.
 */
class DispatchMerge extends Command
{
    use GuardsLocalOnlyWrites;

    protected $signature = 'dispatch:merge
        {loser : Code of the duplicate task to merge away, e.g. TASK-042}
        {winner : Code of the canonical task it merges into}
        {--local : Confirm the LOCAL dev DB is the intended target even while an agent session is active}
        {--json : Emit machine-readable JSON instead of human text}';

    protected $description = 'Merge a duplicate task into its canonical counterpart.';

    public function handle(DispatchTaskService $tasks): int
    {
        // No remote merge exists to point at — unlike edit, there is no batch op
        // for it — so the alternative is the board or a deliberate --local.
        if ($this->blockedByActiveAgentSession('dispatch:merge', 'fold together two unrelated local tasks and SOFT-DELETE one of them', [
            'no remote merge verb exists' => 'merge on the board (task detail → "merge into"), or ask the human running the session',
        ])) {
            return self::FAILURE;
        }

        $loserCode = $this->argument('loser');
        $winnerCode = $this->argument('winner');

        if ($loserCode === $winnerCode) {
            $this->error('The loser and winner must be different tasks.');

            return self::FAILURE;
        }

        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $loser = $taskModel::query()->where('code', $loserCode)->first();
        if (! $loser) {
            $this->error("Task not found: {$loserCode}");

            return self::FAILURE;
        }

        $winner = $taskModel::query()->where('code', $winnerCode)->first();
        if (! $winner) {
            $this->error("Task not found: {$winnerCode}");

            return self::FAILURE;
        }

        if ($loser->id === $winner->id) {
            $this->error('The loser and winner must be different tasks.');

            return self::FAILURE;
        }

        $merged = $tasks->merge($loser, $winner, Auth::id());

        if ($this->option('json')) {
            $this->line(json_encode([
                'loser' => $loserCode,
                'winner' => $merged->code,
                'title' => $merged->title,
                'type' => $merged->type,
                'priority' => $merged->priority,
                'status' => $merged->status,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Merged {$loserCode} into {$merged->code}.");
        $this->line("  {$merged->code}: {$merged->title}");
        $this->line('  priority: '.$merged->priority.'  ·  type: '.$merged->type.'  ·  status: '.$merged->status);

        return self::SUCCESS;
    }
}
