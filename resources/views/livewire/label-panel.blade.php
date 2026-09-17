<div>
    <style>
        .dispatch-labels-intro { font-size: 0.78rem; color: var(--dispatch-text-muted); margin: 0 0 0.9rem; }
        .dispatch-labels-census { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-bottom: 0.9rem; }
        .dispatch-labels-filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
            gap: 0.75rem;
            margin-bottom: 0.9rem;
        }
        .dispatch-labels-bar {
            position: sticky;
            top: 0;
            z-index: 20;
            display: flex;
            flex-direction: column;
            gap: 0.55rem;
            margin-bottom: 0.9rem;
            padding: 0.7rem 0.9rem;
            background: var(--dispatch-surface-muted);
            border: 1px solid var(--dispatch-border);
            border-radius: var(--dispatch-radius-md);
        }
        .dispatch-labels-bar-row { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }
        .dispatch-labels-bar-row .dispatch-input { width: auto; min-width: 16rem; }
        .dispatch-labels-hint { font-size: 0.74rem; color: var(--dispatch-text-muted); }
        .dispatch-labels-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
        .dispatch-labels-table th {
            text-align: left;
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: var(--dispatch-text-muted);
            padding: 0.4rem 0.5rem;
            border-bottom: 1px solid var(--dispatch-border);
        }
        .dispatch-labels-table td { padding: 0.45rem 0.5rem; border-bottom: 1px solid var(--dispatch-border); vertical-align: middle; }
        .dispatch-labels-table tr:last-child td { border-bottom: none; }
        .dispatch-labels-table tr.is-selected td { background: var(--dispatch-info-bg); }
        .dispatch-labels-table .is-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .dispatch-labels-aliases { display: flex; flex-wrap: wrap; gap: 0.3rem; }
        .dispatch-labels-alias {
            display: inline-flex;
            align-items: center;
            gap: 0.2rem;
            font-size: 0.7rem;
            color: var(--dispatch-text-muted);
            border: 1px dashed var(--dispatch-border);
            border-radius: var(--dispatch-radius-pill);
            padding: 0 0.2rem 0 0.45rem;
        }
        .dispatch-labels-alias button {
            background: none;
            border: none;
            padding: 0 0.2rem;
            cursor: pointer;
            color: var(--dispatch-text-faint);
            font-size: 0.8rem;
            line-height: 1;
        }
        .dispatch-labels-alias button:hover { color: var(--dispatch-danger); }
        .dispatch-labels-scroll { overflow-x: auto; }
        .dispatch-labels-linkbtn { background: none; border: none; padding: 0; cursor: pointer; color: var(--dispatch-accent); font-size: 0.74rem; font-weight: 600; }
        .dispatch-labels-linkbtn:hover { text-decoration: underline; }
    </style>

    <section class="dispatch-card">
        <h2 style="margin:0 0 0.25rem; font-size:1rem;">Labels</h2>
        <p class="dispatch-labels-intro">
            Clean up the label vocabulary across <strong>every</strong> task (closed ones included). Select labels, then
            <strong>replace</strong> them with one canonical label — an existing one or a new name — or <strong>retire</strong>
            them outright. A replaced name keeps redirecting to its canonical label, so the next
            <code>--label=</code> with the old name lands on the right one instead of bringing it back.
        </p>

        <div class="dispatch-labels-census">
            <span class="dispatch-badge">{{ $totals['labels'] }} labels</span>
            <button type="button" wire:click="$set('maxUses', '0')" class="dispatch-badge is-warning" style="border:none; cursor:pointer;">{{ $totals['unused'] }} unused</button>
            <button type="button" wire:click="$set('maxUses', '1')" class="dispatch-badge is-info" style="border:none; cursor:pointer;">{{ $totals['single'] }} used once</button>
        </div>

        @if (session('dispatch-status'))
            <div class="dispatch-badge is-success" style="margin-bottom:0.9rem; display:block; width:fit-content; text-transform:none; letter-spacing:0;">{{ session('dispatch-status') }}</div>
        @endif
        @if (session('dispatch-label-error'))
            <div class="dispatch-error" style="margin-bottom:0.9rem;">{{ session('dispatch-label-error') }}</div>
        @endif

        <div class="dispatch-labels-filters">
            <div>
                <label class="dispatch-label" for="dispatch-labels-search">Search</label>
                <input id="dispatch-labels-search" type="search" wire:model.live.debounce.250ms="search" placeholder="Label or old name…" class="dispatch-input">
            </div>
            <div>
                <label class="dispatch-label" for="dispatch-labels-uses">Usage</label>
                <select id="dispatch-labels-uses" wire:model.live="maxUses" class="dispatch-select">
                    <option value="">Any</option>
                    <option value="0">Unused (0 tasks)</option>
                    <option value="1">Used once or less</option>
                    <option value="3">Used 3 times or less</option>
                    <option value="5">Used 5 times or less</option>
                </select>
            </div>
            <div>
                <label class="dispatch-label" for="dispatch-labels-sort">Sort</label>
                <select id="dispatch-labels-sort" wire:model.live="sort" class="dispatch-select">
                    <option value="usage">Fewest tasks first</option>
                    <option value="usage_desc">Most tasks first</option>
                    <option value="name">Name</option>
                </select>
            </div>
        </div>

        @if ($selectedIds !== [])
            <div class="dispatch-labels-bar" wire:key="labels-bar">
                <div class="dispatch-labels-bar-row">
                    <strong>{{ count($selectedIds) }} selected</strong>
                    <span class="dispatch-labels-hint">
                        on {{ $retirePreview['tasks'] }} task(s)@if ($retirePreview['focuses'] > 0), named by {{ $retirePreview['focuses'] }} focus(es)@endif
                    </span>
                    <button type="button" wire:click="clearSelection" class="dispatch-btn is-secondary">Clear</button>
                </div>

                <div class="dispatch-labels-bar-row">
                    <input type="text" list="dispatch-label-names" wire:model.live.debounce.300ms="replaceWith" placeholder="Replace with… (existing or new label)" class="dispatch-input" aria-label="Replacement label name">
                    <datalist id="dispatch-label-names">
                        @foreach ($allNames as $name)
                            <option value="{{ $name }}"></option>
                        @endforeach
                    </datalist>
                    <button
                        type="button"
                        wire:click="replaceSelected"
                        wire:loading.attr="disabled"
                        wire:target="replaceSelected"
                        @if ($replacePreview)
                            wire:confirm="Replace {{ count($selectedIds) }} label(s) with “{{ $replacePreview['target'] }}” on {{ $replacePreview['tasks'] }} task(s)? The old names will redirect to it."
                        @endif
                        class="dispatch-btn"
                        @disabled($replacePreview === null)
                    >Replace</button>
                    @if ($replacePreview)
                        <span class="dispatch-labels-hint">
                            → <strong>{{ $replacePreview['target'] }}</strong>
                            ({{ $replacePreview['target_exists'] ? 'existing label' : 'new label — the most-used selection is renamed' }});
                            {{ $replacePreview['tasks'] }} task(s) change@if ($replacePreview['already_on_target'] > 0), {{ $replacePreview['already_on_target'] }} already have it@endif.
                        </span>
                    @endif
                </div>

                <div class="dispatch-labels-bar-row">
                    <button
                        type="button"
                        wire:click="retireSelected"
                        wire:loading.attr="disabled"
                        wire:target="retireSelected"
                        wire:confirm="Retire {{ count($selectedIds) }} label(s) from {{ $retirePreview['tasks'] }} task(s)? This removes them everywhere and cannot be undone."
                        class="dispatch-btn is-secondary"
                        style="color: var(--dispatch-danger);"
                    >Retire selected</button>
                    <span class="dispatch-labels-hint">
                        Removes the label(s) from every task and deletes them.
                        @if ($retirePreview['focuses_emptied'] !== [])
                            <strong>Focus(es) left with no labels will be deactivated:</strong> {{ implode(', ', $retirePreview['focuses_emptied']) }}.
                        @endif
                    </span>
                </div>
            </div>
        @endif

        @if ($labels->isEmpty())
            <div class="dispatch-empty">
                @if ($totals['labels'] === 0)
                    No labels yet — they're created the first time a task is labeled.
                @else
                    No labels match these filters.
                @endif
            </div>
        @else
            <div class="dispatch-labels-hint" style="margin-bottom:0.4rem;">
                Showing {{ $labels->count() }} of {{ $totals['labels'] }}.
                <button type="button" wire:click="selectShown" class="dispatch-labels-linkbtn">Select all shown</button>
            </div>
            <div class="dispatch-labels-scroll">
                <table class="dispatch-labels-table">
                    <thead>
                        <tr>
                            <th style="width:2rem;" aria-label="Select"></th>
                            <th>Label</th>
                            <th>Kind</th>
                            <th class="is-num">Tasks</th>
                            <th>Last attached</th>
                            <th>Redirects from</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($labels as $label)
                            <tr wire:key="label-{{ $label->id }}" @class(['is-selected' => in_array($label->id, $selectedIds, true)])>
                                <td>
                                    <input type="checkbox" wire:model.live="selected" value="{{ $label->id }}" aria-label="Select {{ $label->name }}">
                                </td>
                                <td>
                                    <span class="dispatch-badge" style="background-color: {{ $label->color ?: '#94a3b8' }}; color:#fff; text-transform:none; letter-spacing:0;">{{ $label->name }}</span>
                                </td>
                                <td>
                                    <span class="dispatch-labels-hint">{{ $label->effectiveKind() ?? 'plain' }}</span>
                                </td>
                                <td class="is-num">
                                    @if ($label->tasks_count > 0)
                                        <a href="{{ route($listRoute, ['labels' => [$label->name]]) }}" title="Open these tasks in the list">{{ $label->tasks_count }}</a>
                                    @else
                                        <span style="color: var(--dispatch-text-faint);">0</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="dispatch-labels-hint">
                                        {{ $label->last_used_at ? \Illuminate\Support\Carbon::parse($label->last_used_at)->diffForHumans() : '—' }}
                                    </span>
                                </td>
                                <td>
                                    <div class="dispatch-labels-aliases">
                                        @foreach ($label->aliases as $alias)
                                            <span class="dispatch-labels-alias" wire:key="alias-{{ $alias->id }}">
                                                {{ $alias->name }}
                                                <button type="button" wire:click="removeAlias({{ $alias->id }})" wire:confirm="Stop redirecting “{{ $alias->name }}” to “{{ $label->name }}”? The name becomes free to use as its own label again." title="Stop redirecting this name">&times;</button>
                                            </span>
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
