<?php

namespace Sgrjr\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TaskAttachment extends Model
{
    protected $table = 'task_attachments';

    protected $fillable = [
        'uploaded_by_user_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'is_image',
        'meta',
    ];

    protected $casts = [
        'is_image' => 'boolean',
        'size_bytes' => 'integer',
        'meta' => 'array',
    ];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(config('dispatch.models.user'), 'uploaded_by_user_id');
    }

    /**
     * The owning Task — directly (task attachment) or via the parent comment.
     * Used by the download authorization check so a single visibility rule
     * governs both task-level and comment-level attachments.
     */
    public function ownerTask(): ?Task
    {
        $parent = $this->attachable;

        if ($parent instanceof TaskComment) {
            return $parent->task;
        }

        return $parent instanceof Task ? $parent : null;
    }
}
