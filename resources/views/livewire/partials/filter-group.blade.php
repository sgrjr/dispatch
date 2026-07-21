{{--
    One checkbox multi-filter axis (Type / Priority / Label / Columns) as a
    <details> popover. Expects: $property (the owning component's array filter
    property), $options (value => display label). State and behavior live in
    the HasVocabMultiFilters trait on the component.

    wire:ignore.self keeps the browser-managed `open` attribute from being
    stripped when Livewire morphs after each checkbox click — the panel stays
    open while its children still morph, so checked states update live.
--}}
<details class="dispatch-filter-group" wire:ignore.self>
    <summary
        class="dispatch-select dispatch-filter-summary"
        @if ($this->filterBadge($property) === 'None') title="Nothing checked — showing all" @endif
    >
        <span>{{ $this->filterBadge($property) }}</span>
        <span class="dispatch-filter-caret">&#9662;</span>
    </summary>
    <div class="dispatch-filter-panel">
        <div class="dispatch-filter-actions">
            <button type="button" wire:click="selectAllFilter('{{ $property }}')">all</button>
            <span>&middot;</span>
            <button type="button" wire:click="selectNoneFilter('{{ $property }}')">none</button>
        </div>
        @forelse ($options as $value => $label)
            <label class="dispatch-filter-option" wire:key="filter-{{ $property }}-{{ $value }}">
                <input
                    type="checkbox"
                    @checked($this->isFilterChecked($property, (string) $value))
                    wire:click="toggleFilter('{{ $property }}', @js((string) $value))"
                >
                <span>{{ $label }}</span>
            </label>
        @empty
            <div class="dispatch-filter-empty">Nothing to filter on</div>
        @endforelse
    </div>
</details>
