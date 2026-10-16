<?php

namespace Sgrjr\Dispatch\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Sgrjr\Dispatch\Contracts\Approvable;
use Sgrjr\Dispatch\Models\Task;

/**
 * Something now waits on a person's yes or no (TASK-1950). Fired for EVERY new
 * request, whichever carrier `dispatch.approvals.channel` picks:
 *
 *   - 'task'  → $task is the "Approval requested" task that was filed;
 *   - 'event' → $task is null: no task was filed, and delivering the request
 *     to its approvers (a chat message, a push…) is the host's listener's job.
 *
 * Decide it with ApprovalTasks::approveItem() / denyItem() — the same staff and
 * still-pending guards the task's Approve / Deny run.
 */
class ApprovalRequested
{
    use Dispatchable;

    public function __construct(
        public Approvable $item,
        public ?Task $task = null,
    ) {}
}
