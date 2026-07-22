{{--
    A grouped checkbox multi-filter axis: the same <details> popover as
    filter-group, but its options are split into titled SECTIONS. Params:
      $property  the owning component's array filter property
      $label     the axis name (e.g. 'Label') — used for the summary tooltip
      $groups    [ ['title' => string, 'options' => [value => display]], ... ]
                 (e.g. LabelFacets::grouped())

    State and behavior live in the HasVocabMultiFilters trait, exactly as
    filter-group: toggleFilter / isFilterChecked / selectAllFilter /
    selectNoneFilter / filterBadge.

    wire:ignore.self keeps the browser-managed `open` attribute from being
    stripped when Livewire morphs after each checkbox click — the panel stays
    open while its children still morph, so checked states update live.
--}}
<details class="dispatch-filter-group" wire:ignore.self>
    <summary
        class="dispatch-select dispatch-filter-summary"
        title="{{ $this->filterBadge($property) === 'None' ? $label.': nothing checked — showing all' : $label }}"
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
        @forelse ($groups as $group)
            <div class="dispatch-filter-section">
                <div class="dispatch-filter-section-title" style="font-size:0.68rem; text-transform:uppercase; letter-spacing:0.04em; color: var(--dispatch-text-muted); margin:0.45rem 0 0.15rem;">{{ $group['title'] }}</div>
                @foreach ($group['options'] as $value => $display)
                    <label class="dispatch-filter-option" wire:key="filter-{{ $property }}-{{ $value }}">
                        <input
                            type="checkbox"
                            @checked($this->isFilterChecked($property, (string) $value))
                            wire:click="toggleFilter('{{ $property }}', @js((string) $value))"
                        >
                        <span>{{ $display }}</span>
                    </label>
                @endforeach
            </div>
        @empty
            <div class="dispatch-filter-empty">Nothing to filter on</div>
        @endforelse
    </div>
</details>
