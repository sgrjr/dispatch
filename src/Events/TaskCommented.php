<?php

namespace Sgrjr\Dispatch\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;

/**
 * A comment was added to a task. Mirrors DispatchNotifier::taskCommented();
 * fired by Sgrjr\Dispatch\Support\EventNotifier when it is bound as the
 * active notifier.
 */
class TaskCommented
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Task $task, public TaskComment $comment) {}
}
