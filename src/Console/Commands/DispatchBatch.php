<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;
use Sgrjr\Dispatch\Services\DispatchBatchService;

/**
 * Apply a MANIFEST of task operations in one shot — the batch "memorialize"
 * path (§20). Point it at a JSON file the agent authored while working the
 * backlog offline; the whole run commits in a single transaction instead of a
 * verb call per task.
 *
 * Local by default (applies to this install's DB); `--remote` posts the same
 * manifest to the agent API's `batch` verb on the authoritative instance. The
 * file shape is identical either way — see `dispatch:schema` (the `batch` key)
 * for the documented operation contract.
 *
 *   {
 *     "operations": [
 *       {"op": "add", "ref": "a1", "title": "New bug", "type": "bug",
 *        "priority": "high", "labels": ["area:api"],
 *        "comments": [{"body": "spotted while working TASK-042"}]},
 *       {"op": "update", "code": "TASK-042", "status": "in_progress",
 *        "commit": "abc123", "labels": ["needs-review"],
 *        "comments": [{"body": "partial: A done, B remains", "internal": true}]}
 *     ]
 *   }
 *
 * `op` is optional — an object with a `code` is inferred as `update`, otherwise
 * `add`. A bare top-level array of operations is accepted too.
 */
class DispatchBatch extends Command
{
    use TalksToAgentApi;

    protected $signature = 'dispatch:batch
        {path : JSON manifest file (an {"operations":[…]} object, or a bare […] array)}
        {--remote : Apply against the configured remote agent API instead of the local DB}
        {--dry-run : Validate + report what would change without writing}
        {--no-notify : Suppress per-add create notifications + reactive automation (local bulk memorialize)}
        {--json : Emit machine-readable JSON instead of human text}';

    protected $description = 'Apply a manifest of add/update task ops in one transaction (batch memorialize).';

    public function handle(DispatchBatchService $batch): int
    {
        $path = $this->argument('path');
        // Resolve as given (absolute or cwd-relative) first, then fall back to
        // the app base path — an agent's manifest usually lives in its cwd, not
        // under the consuming app's root the way dispatch:import expects.
        $resolved = is_file($path) ? $path : base_path($path);

        if (! is_file($resolved)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $doc = json_decode((string) file_get_contents($resolved), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Invalid JSON: '.json_last_error_msg());

            return self::FAILURE;
        }

        // Accept {"operations":[…]} or a bare […] array.
        $operations = is_array($doc) && array_key_exists('operations', $doc) ? $doc['operations'] : $doc;
        if (! is_array($operations) || $operations === []) {
            $this->error('Manifest has no operations. Expected {"operations":[…]} or a non-empty array.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        return $this->option('remote')
            ? $this->applyRemote($operations, $dryRun)
            : $this->applyLocal($batch, $operations, $dryRun);
    }

    /**
     * @param  array<int,mixed>  $operations
     */
    protected function applyLocal(DispatchBatchService $batch, array $operations, bool $dryRun): int
    {
        try {
            $outcome = $batch->apply($operations, [], Auth::id(), $dryRun, (bool) $this->option('no-notify'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $this->report([
            'applied' => ! $dryRun,
            'dry_run' => $dryRun,
        ] + $outcome);
    }

    /**
     * @param  array<int,mixed>  $operations
     */
    protected function applyRemote(array $operations, bool $dryRun): int
    {
        $r = $this->agentPost('batch', array_filter([
            'operations' => $operations,
            'dry_run' => $dryRun ?: null,
        ], fn ($v) => $v !== null));

        if ($r === null) {
            return self::FAILURE;
        }

        return $this->report($r);
    }

    /**
     * @param  array<string,mixed>  $r
     */
    protected function report(array $r): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($r['dry_run'] ?? false) {
            $this->warn('Dry run — no changes persisted.');
        } else {
            $this->info('Batch applied.');
        }

        foreach (($r['summary'] ?? []) as $k => $v) {
            $this->line("  {$k}: {$v}");
        }

        foreach (($r['results'] ?? []) as $res) {
            $ref = isset($res['ref']) ? " (ref {$res['ref']})" : '';
            if (($res['op'] ?? null) === 'add') {
                $verb = ($res['created'] ?? false) ? 'created' : 'exists';
                $this->line("  add    → {$res['code']} {$verb}{$ref}");
            } else {
                $this->line("  update → {$res['code']} [{$res['status']}]{$ref}");
            }
        }

        return self::SUCCESS;
    }
}
