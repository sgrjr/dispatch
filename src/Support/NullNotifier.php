<?php

namespace Sgrjr\Dispatch\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Sgrjr\Dispatch\Contracts\DispatchNotifier;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;

/**
 * No-op notifications: nothing is sent. Useful for apps that want mutation
 * hooks to run (custom logging, etc.) without any delivery, or as a base to
 * override selectively.
 */
class NullNotifier implements DispatchNotifier
{
    public function taskCreated(Task $task): void
    {
        // no-op
    }

    public function taskStatusChanged(Task $task, string $from, string $to, ?Authenticatable $actor): void
    {
        // no-op
    }

    public function taskCommented(Task $task, TaskComment $comment): void
    {
        // no-op
    }

    public function taskAssigned(Task $task, ?int $from, ?int $to, ?Authenticatable $actor): void
    {
        // no-op
    }
}
