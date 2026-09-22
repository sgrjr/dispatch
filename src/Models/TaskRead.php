<?php

namespace Sgrjr\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-998 — one person's read cursor on one task: when they last looked.
 * Package-internal bookkeeping (like TaskLink), not a `dispatch.models.*`
 * customization point.
 *
 * Write through {@see \Sgrjr\Dispatch\Services\DispatchTaskService::markRead()}
 * (an upsert, race-safe); read through {@see Task::scopeWithNewsFor()}.
 */
class TaskRead extends Model
{
    protected $table = 'dispatch_task_reads';

    public $timestamps = false;

    protected $fillable = ['task_id', 'user_id', 'read_at'];

    protected $casts = ['read_at' => 'datetime'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(config('dispatch.models.task'), 'task_id');
    }
}
