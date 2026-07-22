<?php

namespace Sgrjr\Dispatch\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved steering lens over the backlog (roadmap W8-2). A Focus narrows what
 * `next`/`claim` surface FIRST — it steers toward matching work rather than
 * windowing it away: a focus whose matches are exhausted (or all locked) falls
 * through to the next focus, then the unsteered base. See
 * DispatchTaskService::steeredFirst().
 *
 * STORAGE RULE: `filters` stores ONLY the constrained axes. A UI serializer
 * omits an axis to mean "all" — so an absent or empty key in the JSON is
 * unconstrained, never "match nothing". v1 steers on three axes only: labels
 * (any-of), types, priorities. Due/status/search are deliberately excluded —
 * a Focus steers what to work on, it does not window the board.
 */
class Focus extends Model
{
    protected $table = 'dispatch_focuses';

    protected $fillable = [
        'name',
        'filters',
        'rank',
        'is_active',
    ];

    protected $casts = [
        'filters' => 'array',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeRanked(Builder $query): Builder
    {
        return $query->orderBy('rank')->orderBy('id');
    }

    /**
     * The single highest-ranked active focus, or null when none is active.
     */
    public static function topActive(): ?self
    {
        return static::query()->active()->ranked()->first();
    }

    /**
     * Constrain $query by this focus's stored axes. Each axis is any-of
     * (whereIn); an absent or empty axis is skipped (unconstrained), per the
     * storage rule above. Labels match by name against the task's labels
     * relation.
     */
    public function applyTo(Builder $query): Builder
    {
        $filters = (array) $this->filters;

        if (! empty($filters['labels'])) {
            $labels = (array) $filters['labels'];
            $query->whereHas('labels', fn ($lq) => $lq->whereIn('name', $labels));
        }

        if (! empty($filters['types'])) {
            $query->whereIn('type', (array) $filters['types']);
        }

        if (! empty($filters['priorities'])) {
            $query->whereIn('priority', (array) $filters['priorities']);
        }

        return $query;
    }
}
