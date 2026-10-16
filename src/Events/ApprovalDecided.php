<?php

namespace Sgrjr\Dispatch\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A request stopped waiting (TASK-1950): approved, denied, or expired with
 * nobody deciding. Fired once per request from ApprovalTasks::decided(),
 * whichever carrier delivered it, so a host that posted the request somewhere
 * (a chat message) can show how it ended and retire its buttons.
 *
 * Plain scalars on purpose: $outcome is ApprovalTasks::APPROVED | DENIED |
 * EXPIRED, $userId the person who decided (null for an expiry). Look the
 * approvable up again with the kind's findForApproval($id) when you need it.
 */
class ApprovalDecided
{
    use Dispatchable;

    public function __construct(
        public string $kind,
        public string $id,
        public string $outcome,
        public ?int $userId = null,
    ) {}
}
