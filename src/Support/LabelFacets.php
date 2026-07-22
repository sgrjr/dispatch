<?php

namespace Sgrjr\Dispatch\Support;

use Illuminate\Support\Collection;
use Sgrjr\Dispatch\Models\Label;
use Sgrjr\Dispatch\Models\Task;

/**
 * The one place label facets are derived. A label's "kind" — elevated, meta, or
 * plain — decides where it renders (elevated chips lead, meta hides outside
 * detail views) and which axis a board can swim-lane by. Kind comes from the
 * per-label `kind` column, falling back to the namespace map keyed on the name
 * prefix (see Label::effectiveKind()); everything here is a pure derivation of
 * that, shared by the chip/filter partials and later swimlane UI so they never
 * diverge.
 */
class LabelFacets
{
    /**
     * Shipped namespace → kind map, the in-code fallback for a host whose
     * published config predates the `labels` block (mergeConfigFrom is shallow).
     */
    public const DEFAULT_NAMESPACE_KINDS = [
        'area' => 'elevated',
        'epic' => 'elevated',
        'source' => 'meta',
        'kind' => 'meta',
    ];

    /**
     * The configured namespace → kind map, falling back to the shipped default
     * when config is absent entirely (published-config drift).
     *
     * @return array<string,string>
     */
    public static function namespaceKinds(): array
    {
        return (array) config('dispatch.labels.namespace_kinds', self::DEFAULT_NAMESPACE_KINDS);
    }

    /**
     * Bucket labels by effectiveKind() into elevated / plain / meta, preserving
     * input order within each bucket.
     *
     * @param  iterable<int,Label>  $labels
     * @return array{elevated:Collection,plain:Collection,meta:Collection}
     */
    public static function split(iterable $labels): array
    {
        $elevated = new Collection();
        $plain = new Collection();
        $meta = new Collection();

        foreach ($labels as $label) {
            match ($label->effectiveKind()) {
                Label::KIND_ELEVATED => $elevated->push($label),
                Label::KIND_META => $meta->push($label),
                default => $plain->push($label),
            };
        }

        return ['elevated' => $elevated, 'plain' => $plain, 'meta' => $meta];
    }

    /**
     * Ordered filter sections: one section per elevated namespace present (title
     * = ucfirst(prefix); elevated labels with no prefix collect under 'Pinned'),
     * then a 'Labels' section for plain labels, then a 'Meta' section. Each
     * section is `['title' => string, 'options' => [name => name]]`; empty
     * sections are omitted. Elevated namespace sections keep first-appearance
     * order.
     *
     * @param  iterable<int,Label>  $labels
     * @return array<int,array{title:string,options:array<string,string>}>
     */
    public static function grouped(iterable $labels): array
    {
        $split = self::split($labels);

        $sections = [];

        // Elevated: group by namespace prefix, first-appearance order. A
        // prefixless elevated label lands in a single 'Pinned' section.
        $elevatedGroups = [];
        foreach ($split['elevated'] as $label) {
            $prefix = $label->prefix();
            $key = $prefix ?? '__pinned__';
            if (! isset($elevatedGroups[$key])) {
                $elevatedGroups[$key] = [
                    'title' => $prefix !== null ? ucfirst($prefix) : 'Pinned',
                    'options' => [],
                ];
            }
            $elevatedGroups[$key]['options'][$label->name] = $label->name;
        }
        foreach ($elevatedGroups as $group) {
            $sections[] = $group;
        }

        if ($split['plain']->isNotEmpty()) {
            $sections[] = [
                'title' => 'Labels',
                'options' => $split['plain']->mapWithKeys(fn (Label $l) => [$l->name => $l->name])->all(),
            ];
        }

        if ($split['meta']->isNotEmpty()) {
            $sections[] = [
                'title' => 'Meta',
                'options' => $split['meta']->mapWithKeys(fn (Label $l) => [$l->name => $l->name])->all(),
            ];
        }

        return $sections;
    }

    /**
     * The swimlane/group-by key for a task: derived from its FIRST elevated
     * label (in relation order) — the portion after the first ':' when present,
     * else the whole name. Null when the task carries no elevated label. Shared
     * so board grouping and this derivation never drift.
     */
    public static function laneKey(Task $task): ?string
    {
        foreach ($task->labels as $label) {
            if ($label->effectiveKind() === Label::KIND_ELEVATED) {
                $name = (string) $label->name;
                $pos = strpos($name, ':');

                return $pos === false ? $name : substr($name, $pos + 1);
            }
        }

        return null;
    }
}
