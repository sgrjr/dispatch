<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;
use Sgrjr\Dispatch\Models\TaskComment;

/**
 * Trusted CLI surface: queries tasks directly (no DispatchGate::scopeVisible).
 * Comments default to public; pass --internal to mark one internal-only.
 */
class DispatchNote extends Command
{
    use TalksToAgentApi;

    protected $signature = 'dispatch:note
        {code : The task code, e.g. TASK-042}
        {body : The comment body (markdown ok)}
        {--internal : Mark the comment internal (default: public)}
        {--remote : Act on the configured remote agent API instead of the local DB}';

    protected $description = 'Append a comment to a task\'s discussion timeline.';

    public function handle(): int
    {
        if ($this->option('remote')) {
            $r = $this->agentPost('note', array_filter([
                'code' => $this->argument('code'),
                'body' => $this->argument('body'),
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
            'body' => $this->argument('body'),
            'is_internal' => (bool) $this->option('internal'),
            'event_type' => TaskComment::EVENT_COMMENT,
        ]);

        $this->info("Noted on {$task->code} (comment id={$comment->id}, ".($comment->is_internal ? 'internal' : 'public').').');

        return self::SUCCESS;
    }
}
