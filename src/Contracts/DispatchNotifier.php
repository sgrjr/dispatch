<?php

namespace Sgrjr\Dispatch\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;

/**
 * Notification seam.
 *
 * Fire-and-forget hooks called synchronously in-request at every mutation
 * point (create, status change, comment, assignment) directly from a
 * Livewire action or the create path — with no surrounding try/catch of
 * their own. Implementations MUST NEVER THROW: catch and swallow internally,
 * since a thrown exception here would break the caller. Implementations
 * SHOULD queue their own delivery (e.g. a ShouldQueue notification) so this
 * synchronous call stays cheap.
 */
interface DispatchNotifier
{
    /**
     * A new task was created (submission-acknowledgement receipt).
     */
    public function taskCreated(Task $task): void;

    /**
     * A task's status changed.
     */
    public function taskStatusChanged(Task $task, string $from, string $to, ?Authenticatable $actor): void;

    /**
     * A comment was added to a task.
     */
    public function taskCommented(Task $task, TaskComment $comment): void;

    /**
     * A task's assignee changed.
     */
    public function taskAssigned(Task $task, ?int $from, ?int $to, ?Authenticatable $actor): void;
}
