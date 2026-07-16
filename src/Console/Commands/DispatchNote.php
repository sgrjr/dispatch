<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Sgrjr\Dispatch\Models\TaskComment;

/**
 * Trusted CLI surface: queries tasks directly (no DispatchGate::scopeVisible).
 * Comments default to public; pass --internal to mark one internal-only.
 */
class DispatchNote extends Command
{
    protected $signature = 'dispatch:note
        {code : The task code, e.g. TASK-042}
        {body : The comment body (markdown ok)}
        {--internal : Mark the comment internal (default: public)}';

    protected $description = 'Append a comment to a task\'s discussion timeline.';

    public function handle(): int
    {
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
