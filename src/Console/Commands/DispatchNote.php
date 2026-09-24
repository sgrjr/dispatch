<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Sgrjr\Dispatch\Console\Commands\Concerns\ResolvesTextInput;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Support\TaskPresenter;

/**
 * Trusted CLI surface: queries tasks directly (no DispatchGate::scopeVisible).
 * Notes are INTERNAL (staff only) by default; pass --public for one the
 * submitter sees (and is emailed about). --internal is still accepted.
 */
class DispatchNote extends Command
{
    use ResolvesTextInput;
    use TalksToAgentApi;

    protected $signature = 'dispatch:note
        {code : The task code, e.g. TASK-042}
        {body? : The comment body (markdown ok). Omit and use --body-file for a long/multi-line body.}
        {--body-file= : Read the comment body from a file (or `-` for stdin) instead of the inline body argument}
        {--public : Make the note public — the submitter sees it and is emailed (default: internal, staff only)}
        {--internal : Internal (staff only) — the default; kept for older scripts}
        {--remote : Act on the configured remote agent API (the default while an agent session token is active)}
        {--local : Act on the local DB even while an agent session token is active (overrides sticky-remote)}
        {--json : Emit machine-readable JSON instead of human text}';

    protected $description = 'Append a comment to a task\'s discussion timeline.';

    public function handle(): int
    {
        [$body, $err] = $this->resolveInlineOrFile(
            $this->argument('body'),
            $this->option('body-file'),
            'a body argument',
            '--body-file',
            required: true,
        );
        if ($err !== null) {
            $this->error($err);

            return self::FAILURE;
        }

        if ($this->targetsRemote()) {
            $r = $this->agentPost('note', array_filter([
                'code' => $this->argument('code'),
                'body' => $body,
                // Explicit either way, so the answer never depends on the
                // server's default.
                'internal' => ! $this->option('public'),
            ], fn ($v) => $v !== null));

            if ($r === null) {
                return self::FAILURE;
            }

            $this->line(json_encode([
                'task' => $r['task'] ?? null,
                'comment_id' => $r['comment_id'] ?? null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $taskModel = config('dispatch.models.task');

        $task = $taskModel::query()->where('code', $this->argument('code'))->first();
        if (! $task) {
            $this->error("Task not found: {$this->argument('code')}");

            return self::FAILURE;
        }

        // Through the comments() relation directly, so the visibility is
        // exactly what was asked (internal unless --public).
        $comment = $task->comments()->create([
            'user_id' => Auth::id(),
            'body' => $body,
            'is_internal' => ! $this->option('public'),
            'event_type' => TaskComment::EVENT_COMMENT,
        ]);

        // Same {task, comment_id} shape the remote path emits, so `--json` reads
        // identically whichever DB answered (W9-3).
        if ($this->option('json')) {
            $this->line(json_encode([
                'task' => TaskPresenter::toArray($task->fresh(), false),
                'comment_id' => $comment->id,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Noted on {$task->code} (comment id={$comment->id}, ".($comment->is_internal ? 'internal' : 'public').').');

        return self::SUCCESS;
    }
}
