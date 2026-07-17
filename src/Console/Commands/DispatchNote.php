<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Sgrjr\Dispatch\Console\Commands\Concerns\ResolvesTextInput;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;
use Sgrjr\Dispatch\Models\TaskComment;

/**
 * Trusted CLI surface: queries tasks directly (no DispatchGate::scopeVisible).
 * Comments default to public; pass --internal to mark one internal-only.
 */
class DispatchNote extends Command
{
    use ResolvesTextInput;
    use TalksToAgentApi;

    protected $signature = 'dispatch:note
        {code : The task code, e.g. TASK-042}
        {body? : The comment body (markdown ok). Omit and use --body-file for a long/multi-line body.}
        {--body-file= : Read the comment body from a file (or `-` for stdin) instead of the inline body argument}
        {--internal : Mark the comment internal (default: public)}
        {--remote : Act on the configured remote agent API instead of the local DB}';

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

        if ($this->option('remote')) {
            $r = $this->agentPost('note', array_filter([
                'code' => $this->argument('code'),
                'body' => $body,
                'internal' => $this->option('internal') ? true : null,
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

        // recordEvent() hardcodes is_internal=false, so we go through the
        // comments() relation directly to honor --internal, as the contract
        // allows.
        $comment = $task->comments()->create([
            'user_id' => Auth::id(),
            'body' => $body,
            'is_internal' => (bool) $this->option('internal'),
            'event_type' => TaskComment::EVENT_COMMENT,
        ]);

        $this->info("Noted on {$task->code} (comment id={$comment->id}, ".($comment->is_internal ? 'internal' : 'public').').');

        return self::SUCCESS;
    }
}
