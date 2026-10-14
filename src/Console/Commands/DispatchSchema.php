<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Sgrjr\Dispatch\Console\Commands\Concerns\WritesRawLines;
use Illuminate\Console\Command;
use Sgrjr\Dispatch\Support\TaskPresenter;

/**
 * Dumps the documented `--json` task shape (TaskPresenter::schema()) so an
 * agent can parse the summary/full-detail payloads and the timeline event
 * vocabulary against a real contract instead of guessing from examples.
 */
class DispatchSchema extends Command
{
    use WritesRawLines;
    protected $signature = 'dispatch:schema';

    protected $description = "Print the JSON schema of a task's --json shape (for agent consumption).";

    public function handle(): int
    {
        $this->rawLine(json_encode(TaskPresenter::schema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
