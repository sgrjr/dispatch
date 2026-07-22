<div>
    <style>
        .dispatch-focus-row {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.9rem 0;
            border-bottom: 1px solid var(--dispatch-border);
        }
        .dispatch-focus-row:last-child { border-bottom: none; }
        .dispatch-focus-rank {
            display: inline-block;
            margin-right: 0.4rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--dispatch-text-muted);
        }
        .dispatch-focus-summary {
            font-size: 0.78rem;
            color: var(--dispatch-text-muted);
            margin-top: 0.35rem;
        }
        .dispatch-focus-rename {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
            align-items: center;
            margin-top: 0.55rem;
        }
        .dispatch-focus-rename .dispatch-input { max-width: 16rem; }
        .dispatch-focus-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: flex-start;
        }
    </style>

    <section class="dispatch-card">
        <h2 style="margin:0 0 0.25rem; font-size:1rem;">Focuses</h2>
        <p class="dispatch-focus-summary" style="margin-top:0;">
            A focus steers what <code>dispatch:next</code> / <code>claim</code> surface FIRST — the top-ranked
            active focus wins (<code>--no-focus</code> bypasses). Save one from the board or list filter bar
            ("Save current filters as focus"); reorder, (de)activate, rename, or delete it here.
        </p>

        @if ($focuses->isEmpty())
            <div class="dispatch-empty">
                No focuses yet. Save one from the board or list filter bar ("Save current filters as focus")
                to steer <code>dispatch:next</code> / <code>claim</code> toward matching work — the top-ranked
                active focus wins, and <code>--no-focus</code> bypasses steering.
            </div>
        @else
            @foreach ($focuses as $focus)
                <div class="dispatch-focus-row" wire:key="focus-{{ $focus->id }}">
                    <div style="min-width:0; flex:1;">
                        <div>
                            <span class="dispatch-focus-rank">#{{ $loop->iteration }}</span>
                            <span class="dispatch-list-title">{{ $focus->name }}</span>
                            @if ($focus->is_active)
                                <span class="dispatch-badge is-success">active</span>
                            @else
                                <span class="dispatch-badge">inactive</span>
                            @endif
                        </div>
                        <div class="dispatch-focus-summary">{{ $this->summarize($focus->filters) }}</div>
                        <div class="dispatch-focus-rename">
                            <input type="text" wire:model="names.{{ $focus->id }}" class="dispatch-input" aria-label="Rename focus {{ $focus->name }}">
                            <button type="button" wire:click="rename({{ $focus->id }}, $wire.names[{{ $focus->id }}])" class="dispatch-btn is-secondary">Rename</button>
                        </div>
                    </div>
                    <div class="dispatch-focus-actions">
                        <button type="button" wire:click="moveUp({{ $focus->id }})" class="dispatch-btn is-secondary" title="Move up" @disabled($loop->first)>&uarr;</button>
                        <button type="button" wire:click="moveDown({{ $focus->id }})" class="dispatch-btn is-secondary" title="Move down" @disabled($loop->last)>&darr;</button>
                        @if ($focus->is_active)
                            <button type="button" wire:click="toggleActive({{ $focus->id }})" class="dispatch-btn is-secondary">Deactivate</button>
                        @else
                            <button type="button" wire:click="toggleActive({{ $focus->id }})" class="dispatch-btn">Activate</button>
                        @endif
                        <button type="button" wire:click="delete({{ $focus->id }})" wire:confirm="Delete this focus?" class="dispatch-btn is-secondary">Delete</button>
                    </div>
                </div>
            @endforeach
        @endif
    </section>
</div>
