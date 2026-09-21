<?php

namespace Sgrjr\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-997 part B — a real task->task dependency row (the ball contract's
 * `dispatch_task_links` table). `task` is the BLOCKED task, `blocker` is the
 * BLOCKER. Not configurable via `dispatch.models.*` — like AgentSession and
 * LabelAlias, it's package-internal bookkeeping with no host customization
 * point, unlike Task/TaskComment/Label which a host may subclass.
 *
 * Callers should not normally query this table directly — use
 * {@see \Sgrjr\Dispatch\Models\Task::blockedBy()} / {@see
 * \Sgrjr\Dispatch\Models\Task::blocks()} and {@see
 * \Sgrjr\Dispatch\Services\DispatchTaskService::linkBlockedBy()}, which own
 * the cycle-detection invariant. This model exists for the pivot's own extra
 * column (`created_by_user_id` — read back by the Ask flow to return the ball
 * to whoever asked) and for direct lookups the cycle check needs.
 */
class TaskLink extends Model
{
    /** The only kind in use today — room for `relates` later (never read yet). */
    public const KIND_BLOCKS = 'blocks';

    protected $table = 'dispatch_task_links';

    protected $fillable = [
        'task_id',
        'blocked_by_task_id',
        'kind',
        'created_by_user_id',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(config('dispatch.models.task'), 'task_id');
    }

    public function blocker(): BelongsTo
    {
        return $this->belongsTo(config('dispatch.models.task'), 'blocked_by_task_id');
    }
}
