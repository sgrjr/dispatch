<?php

namespace Sgrjr\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Sgrjr\Dispatch\Support\LabelFacets;

class Label extends Model
{
    /**
     * The two label facet kinds. `elevated` labels are the load-bearing axis a
     * board can swim-lane / group by (area:*, epic:*); `meta` labels are
     * provenance/plumbing (source:*, kind:*) surfaced only in detail views.
     * Everything else is a plain label. See {@see LabelFacets}.
     */
    public const KIND_ELEVATED = 'elevated';
    public const KIND_META = 'meta';

    protected $table = 'dispatch_labels';

    protected $fillable = [
        'name',
        'color',
        'description',
        'kind',
    ];

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(config('dispatch.models.task'), 'dispatch_task_label')->withTimestamps();
    }

    /**
     * The namespace prefix — the substring before the FIRST ':' — or null when
     * the name carries no colon. A prefixless name is never its own prefix
     * (returns null), so {@see effectiveKind()} falls through to the plain
     * bucket rather than treating the whole name as a namespace.
     */
    public function prefix(): ?string
    {
        $name = (string) $this->name;
        $pos = strpos($name, ':');

        return $pos === false ? null : substr($name, 0, $pos);
    }

    /**
     * This label's facet kind: the explicit per-label `kind` column when set,
     * otherwise the namespace default from dispatch.labels.namespace_kinds
     * (keyed on {@see prefix()}), otherwise null (a plain label).
     */
    public function effectiveKind(): ?string
    {
        return $this->kind ?? (LabelFacets::namespaceKinds()[$this->prefix()] ?? null);
    }
}
