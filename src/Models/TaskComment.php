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
    public const EVENT_PUBLIC_TOGGLE = 'is_public_toggle';
    public const EVENT_PROMOTED = 'promoted';
    public const EVENT_EXCEPTION = 'exception_occurrence';

    protected $table = 'task_comments';

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
