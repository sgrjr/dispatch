<div>
    <style>
        .dispatch-list-filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        .dispatch-list-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-top: 1rem;
        }
        .dispatch-bulk-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-top: 1rem;
            padding: 0.6rem 0.9rem;
            background: var(--dispatch-surface-muted);
            border: 1px solid var(--dispatch-border);
            border-radius: var(--dispatch-radius-md);
        }
        .dispatch-bulk-controls { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }
        .dispatch-list-row { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 1rem; padding: 0.9rem 0; border-bottom: 1px solid var(--dispatch-border); }
        .dispatch-list-row:last-child { border-bottom: none; }
        .dispatch-list-title { font-weight: 600; }
        .dispatch-list-meta { display: flex; flex-wrap: wrap; gap: 0.3rem; margin-top: 0.35rem; align-items: center; }
        .dispatch-list-side { text-align: right; font-size: 0.72rem; color: var(--dispatch-text-muted); white-space: nowrap; }
        .dispatch-list-check { padding-top: 0.15rem; }
        .dispatch-pagination { margin-top: 1rem; }
    </style>

    <section class="dispatch-card">
        <div class="dispatch-list-filters">
            <div>
                <label class="dispatch-label">Search</label>
                <input type="text" wire:model.live.debounce.250ms="search" placeholder="Title or code…" class="dispatch-input">
            </div>
            <div>
                <label class="dispatch-label">Status</label>
                <select wire:model.live="statusFilter" class="dispatch-select">
                    <option value="">All statuses</option>
                    @foreach ($statusLabels as $code => $label) <option value="{{ $code }}">{{ $label }}</option> @endforeach
                    @if ($staleEnabled)
                        <option value="stale">Stale</option>
                    @endif
                </select>
            </div>
            <div>
                <label class="dispatch-label">Type</label>
                <select wire:model.live="typeFilter" class="dispatch-select">
                    <option value="">Any type</option>
                    @foreach ($typeLabels as $code => $label) <option value="{{ $code }}">{{ $label }}</option> @endforeach
                </select>
            </div>
            <div>
                <label class="dispatch-label">Priority</label>
                <select wire:model.live="priorityFilter" class="dispatch-select">
                    <option value="">Any priority</option>
                    @foreach ($priorityLabels as $code => $label) <option value="{{ $code }}">{{ $label }}</option> @endforeach
                </select>
            </div>
            <div>
                <label class="dispatch-label">Label</label>
                <select wire:model.live="labelFilter" class="dispatch-select">
                    <option value="">Any label</option>
                    @foreach ($labels as $l) <option value="{{ $l->name }}">{{ $l->name }}</option> @endforeach
                </select>
            </div>
        </div>

        <div class="dispatch-list-toolbar">
            <label class="dispatch-label" style="display:flex; align-items:center; gap:0.4rem;">
                Sort
                <select wire:model.live="sort" class="dispatch-select" style="width:auto;">
                    <option value="priority">Priority</option>
                    <option value="status">Status</option>
                    <option value="newest">Newest</option>
                    <option value="oldest">Oldest</option>
                    <option value="code">Code</option>
                    <option value="title">Title</option>
                </select>
            </label>
            <button type="button" wire:click="clearFilters" class="dispatch-btn is-secondary">Clear filters</button>
        </div>

        @if (! $tasks->isEmpty())
            <div class="dispatch-list-toolbar" style="margin-top: 0.5rem;">
                <label style="display:flex; align-items:center; gap:0.4rem; font-size:0.78rem; color: var(--dispatch-text-muted); cursor:pointer;">
                    <input type="checkbox" @checked($tasks->pluck('id')->map(fn ($id) => (string) $id)->diff($selected)->isEmpty()) wire:click="toggleSelectAllVisible(@js($tasks->pluck('id')))">
                    Select all visible
                </label>
                @if (! empty($selected))
                    <span style="font-size:0.75rem; color: var(--dispatch-text-muted);">{{ count($selected) }} selected</span>
                @endif
            </div>
        @endif

        @if (! empty($selected))
            <div class="dispatch-bulk-bar" wire:key="bulk-bar">
                <strong style="font-size:0.8rem;">With {{ count($selected) }} selected</strong>
                <div class="dispatch-bulk-controls">
                    <select wire:model.live="bulkAction" class="dispatch-select" style="width:auto;">
                        <option value="">Choose action…</option>
                        <option value="status">Set status</option>
                        <option value="label">Add label</option>
                        <option value="assign">Assign to</option>
                        <option value="decline">Decline</option>
                    </select>

                    @if ($bulkAction === 'status')
                        <select wire:model="bulkStatusValue" class="dispatch-select" style="width:auto;">
                            <option value="">Select status…</option>
                            @foreach ($statusLabels as $code => $label) <option value="{{ $code }}">{{ $label }}</option> @endforeach
                        </select>
                    @elseif ($bulkAction === 'label')
                        <input type="text" wire:model="bulkLabelValue" placeholder="Label name…" class="dispatch-input" style="width:auto;">
                    @elseif ($bulkAction === 'assign')
                        <select wire:model="bulkAssigneeId" class="dispatch-select" style="width:auto;">
                            <option value="">Unassigned</option>
                            @foreach ($assigneeOptions as $u) <option value="{{ $u->id }}">{{ $u->name }}</option> @endforeach
                        </select>
                    @endif

                    <button type="button" wire:click="bulkApply" wire:loading.attr="disabled" wire:target="bulkApply" class="dispatch-btn" @disabled($bulkAction === '')>Apply</button>
                    <button type="button" wire:click="$set('selected', [])" class="dispatch-btn is-secondary">Clear selection</button>
                </div>
            </div>
        @endif
    </section>

    <section class="dispatch-card" style="margin-top: 1rem;">
        @if (session('dispatch-status'))
            <div class="dispatch-badge is-success" style="margin-bottom:0.9rem; display:block; width:fit-content;">{{ session('dispatch-status') }}</div>
        @endif

        @if ($tasks->isEmpty())
            <div class="dispatch-empty">No tasks match these filters.</div>
        @else
            @foreach ($tasks as $task)
                <div class="dispatch-list-row" wire:key="list-row-{{ $task->id }}">
                    <div class="dispatch-list-check">
                        <input type="checkbox" wire:model="selected" value="{{ $task->id }}" wire:key="select-{{ $task->id }}">
                    </div>
                    <div style="min-width:0; flex:1;">
                        <div>
                            <a href="{{ route('dispatch.show', $task) }}" class="dispatch-card-code" style="font-weight:700; color: var(--dispatch-accent);">{{ $task->code }}</a>
                            <span class="dispatch-list-title">{{ $task->title }}</span>
                        </div>
                        <div class="dispatch-list-meta">
                            <span class="dispatch-badge is-{{ $task->priority }}">{{ $priorityLabels[$task->priority] ?? $task->priority }}</span>
                            <span class="dispatch-badge">{{ $typeLabels[$task->type] ?? $task->type }}</span>
                            <span class="dispatch-badge is-info">{{ $statusLabels[$task->status] ?? $task->status }}</span>
                            @if ($task->is_public)
                                <span class="dispatch-badge is-success">public</span>
                            @endif
                            @foreach ($task->labels as $label)
                                <span class="dispatch-badge" style="background-color: {{ $label->color ?: '#94a3b8' }}; color:#fff;">{{ $label->name }}</span>
                            @endforeach
                        </div>
                    </div>
                    <div class="dispatch-list-side">
                        @if ($task->assignee)
                            <p style="margin:0;">Assigned: {{ $task->assignee->name }}</p>
                        @endif
                        <p style="margin:0;">{{ $task->updated_at?->diffForHumans() }}</p>
                        @if ($this->isStale($task))
                            <span class="dispatch-badge is-warning">Stale</span>
                        @endif
                    </div>
                </div>
            @endforeach

            <div class="dispatch-pagination">
                {{ $tasks->links() }}
            </div>
        @endif
    </section>
</div>
