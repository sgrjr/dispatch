<?php

namespace Sgrjr\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class TaskComment extends Model
{
    public const EVENT_COMMENT = 'comment';
    public const EVENT_STATUS_CHANGE = 'status_change';
    public const EVENT_ASSIGNEE_CHANGE = 'assignee_change';
    public const EVENT_LABEL_ADDED = 'label_added';
    public const EVENT_LABEL_REMOVED = 'label_removed';
    public const EVENT_LABEL_REPLACED = 'label_replaced';
    public const EVENT_PUBLIC_TOGGLE = 'is_public_toggle';
    public const EVENT_PROMOTED = 'promoted';
    public const EVENT_EXCEPTION = 'exception_occurrence';
    public const EVENT_DESCRIPTION_EDITED = 'description_edited';
    public const EVENT_MERGED = 'merged';
    public const EVENT_CLAIMED = 'claimed';
    public const EVENT_WATCHER_ADDED = 'watcher_added';
    public const EVENT_VISIBILITY_CHANGE = 'visibility_change';
    // TASK-997 part A — a task's `lane` changed (routeToLane(), claimForUser()
    // joining a lane, or a batch `update` op's tri-state `lane`).
    public const EVENT_LANE_CHANGE = 'lane_change';
    // TASK-997 part B (the ball / hand-off) — recorded on the PASSING task
    // when a cross-lane/no-lane pass mints a continuation task and closes
    // this one.
    public const EVENT_HANDED_OFF = 'handed_off';
    // Recorded on the ASKER's task when an ask mints the recipient's linked
    // (blocking) task.
    public const EVENT_ASKED = 'asked';
    // Recorded on the ASKER's task when the ask closes and the ball returns
    // — carries the answer (the ask's closing note/result).
    public const EVENT_ANSWERED = 'answered';
    // Recorded on a dependent task when one of its blockers reaches a
    // terminal status, for a plain (non-ask) `blocked_by` link.
    public const EVENT_DEPENDENCY_RESOLVED = 'dependency_resolved';

    protected $table = 'dispatch_task_comments';

    protected $fillable = [
        'task_id',
        'user_id',
        'body',
        'is_internal',
        'notified_submitter',
        'event_type',
        'meta',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
        'notified_submitter' => 'boolean',
        'meta' => 'array',
    ];

    public function getMorphClass(): string
    {
        return 'dispatch_comment';
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(config('dispatch.models.task'), 'task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('dispatch.models.user'), 'user_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(config('dispatch.models.task_attachment'), 'attachable');
    }

    public function isSystem(): bool
    {
        return $this->event_type !== self::EVENT_COMMENT;
    }
}
