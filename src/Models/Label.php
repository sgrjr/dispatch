<?php

namespace Sgrjr\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Label extends Model
{
    protected $table = 'labels';

    protected $fillable = [
        'name',
        'color',
        'description',
    ];

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(config('dispatch.models.task'), 'task_label')->withTimestamps();
    }

    /**
     * Epics are just labels named `epic:*` — no separate table.
     */
    public function isEpic(): bool
    {
        return str_starts_with((string) $this->name, 'epic:');
    }
}
