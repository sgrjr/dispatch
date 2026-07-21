<?php

namespace Sgrjr\Dispatch\Livewire\Concerns;

/**
 * Checkbox multi-filters over a fixed vocabulary (type/priority/label/column).
 *
 * State contract per filter property — forced by Livewire's #[Url] mechanics:
 * - `[]`  = ALL selected. This is the canonical default and MUST stay the
 *   declared property default: Livewire merges a URL array into the declared
 *   default on hydration (a full-vocab default would swallow any URL subset),
 *   and the query param only disappears when the value deep-equals `except`.
 * - `['']` (FILTER_NONE) = explicitly NOTHING checked. An empty selection
 *   filters nothing (shows everything), but the sentinel round-trips through
 *   the URL so the all-unchecked UI state survives a reload; '' can never
 *   collide with a real vocab value.
 * - otherwise a strict subset, kept in vocab order so the same selection
 *   always serializes identically (which is what lets a re-completed set
 *   normalize back to `[]` and clear its URL param).
 *
 * Checkboxes bind via wire:click="toggleFilter(...)" rather than wire:model —
 * a wire:model against the `[]` default would render every box unchecked,
 * inverting the all-selected-by-default contract.
 */
trait HasVocabMultiFilters
{
    /** Sentinel meaning "explicitly nothing checked" — impossible as a vocab value. */
    protected const FILTER_NONE = '';

    /**
     * Property-name => vocabulary map for every filter this trait manages,
     * e.g. ['typeFilter' => Task::types(), ...]. Also the allow-list guarding
     * the public wire-callable entry points below.
     *
     * @return array<string,array<int,string>>
     */
    abstract protected function filterVocabs(): array;

    /**
     * Post-change hook — the list resets pagination + bulk selection here
     * (its updating() hook only fires on wire:model sets, not on the in-method
     * assignments these toggles perform); the board needs nothing.
     */
    protected function afterFilterChanged(): void
    {
    }

    public function toggleFilter(string $property, string $value): void
    {
        $vocab = $this->filterVocab($property);
        if (! in_array($value, $vocab, true)) {
            return;
        }

        $checked = $this->checkedFilterValues($property);
        $next = in_array($value, $checked, true)
            ? array_diff($checked, [$value])
            : array_merge($checked, [$value]);

        $this->{$property} = $this->normalizeSelection($next, $vocab);
        $this->afterFilterChanged();
    }

    public function selectAllFilter(string $property): void
    {
        if (! $this->isManagedFilter($property)) {
            return;
        }

        $this->{$property} = [];
        $this->afterFilterChanged();
    }

    public function selectNoneFilter(string $property): void
    {
        if (! $this->isManagedFilter($property)) {
            return;
        }

        $this->{$property} = [self::FILTER_NONE];
        $this->afterFilterChanged();
    }

    /**
     * What the checkboxes should render as checked: the full vocab in the
     * "all" state, nothing in the explicit-none state, else the (sanitized)
     * subset in vocab order.
     *
     * @return array<int,string>
     */
    public function checkedFilterValues(string $property): array
    {
        if (! $this->isManagedFilter($property)) {
            return [];
        }

        $vocab = $this->filterVocab($property);
        $filter = $this->{$property};

        return $filter === [] ? $vocab : array_values(array_intersect($vocab, $filter));
    }

    public function isFilterChecked(string $property, string $value): bool
    {
        return in_array($value, $this->checkedFilterValues($property), true);
    }

    /** Summary-chip text: "All", "None" (unchecked — still shows all), or "n/total". */
    public function filterBadge(string $property): string
    {
        $vocab = $this->filterVocab($property);
        $checked = count($this->checkedFilterValues($property));

        if ($checked === count($vocab)) {
            return 'All';
        }

        return $checked === 0 ? 'None' : $checked.'/'.count($vocab);
    }

    /**
     * The whereIn payload for a selection, or null for "no WHERE clause" —
     * all selected, none selected, or nothing valid left after sanitizing.
     * array_intersect() both drops URL garbage and emits vocab order.
     *
     * @param  array<int,string>  $filter
     * @param  array<int,string>  $vocab
     * @return array<int,string>|null
     */
    protected function activeSelection(array $filter, array $vocab): ?array
    {
        if ($filter === []) {
            return null;
        }

        $selection = array_values(array_intersect($vocab, $filter));

        return ($selection === [] || count($selection) === count($vocab)) ? null : $selection;
    }

    /**
     * Canonicalize after a toggle: full vocab -> [] (the param-free "all"),
     * empty -> the explicit-none sentinel, else the subset in vocab order.
     *
     * @param  array<int,string>  $selection
     * @param  array<int,string>  $vocab
     * @return array<int,string>
     */
    protected function normalizeSelection(array $selection, array $vocab): array
    {
        $selection = array_values(array_intersect($vocab, $selection));

        if ($selection === []) {
            return [self::FILTER_NONE];
        }

        return count($selection) === count($vocab) ? [] : $selection;
    }

    /** @return array<int,string> */
    protected function filterVocab(string $property): array
    {
        return $this->filterVocabs()[$property] ?? [];
    }

    protected function isManagedFilter(string $property): bool
    {
        return array_key_exists($property, $this->filterVocabs());
    }
}
