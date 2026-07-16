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
        .dispatch-list-row {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.9rem 0;
            border-bottom: 1px solid var(--dispatch-border);
        }
        .dispatch-list-row:last-child { border-bottom: none; }
        .dispatch-list-title { font-weight: 600; }
        .dispatch-list-meta { display: flex; flex-wrap: wrap; gap: 0.3rem; margin-top: 0.35rem; align-items: center; }
        .dispatch-list-side { text-align: right; font-size: 0.72rem; color: var(--dispatch-text-muted); white-space: nowrap; }
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
                    @foreach ($statuses as $s) <option value="{{ $s }}">{{ str_replace('_', ' ', $s) }}</option> @endforeach
                </select>
            </div>
            <div>
                <label class="dispatch-label">Type</label>
                <select wire:model.live="typeFilter" class="dispatch-select">
                    <option value="">Any type</option>
                    @foreach ($types as $t) <option value="{{ $t }}">{{ $t }}</option> @endforeach
                </select>
            </div>
            <div>
                <label class="dispatch-label">Priority</label>
                <select wire:model.live="priorityFilter" class="dispatch-select">
                    <option value="">Any priority</option>
                    @foreach ($priorities as $p) <option value="{{ $p }}">{{ $p }}</option> @endforeach
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
    </section>

    <section class="dispatch-card" style="margin-top: 1rem;">
        @if ($tasks->isEmpty())
            <div class="dispatch-empty">No tasks match these filters.</div>
        @else
            @foreach ($tasks as $task)
                <div class="dispatch-list-row" wire:key="list-row-{{ $task->id }}">
                    <div style="min-width:0; flex:1;">
                        <div>
                            <a href="{{ route('dispatch.show', $task) }}" class="dispatch-card-code" style="font-weight:700; color: var(--dispatch-accent);">{{ $task->code }}</a>
                            <span class="dispatch-list-title">{{ $task->title }}</span>
                        </div>
                        <div class="dispatch-list-meta">
                            <span class="dispatch-badge is-{{ $task->priority }}">{{ $task->priority }}</span>
                            <span class="dispatch-badge">{{ $task->type }}</span>
                            <span class="dispatch-badge is-info">{{ str_replace('_', ' ', $task->status) }}</span>
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
                    </div>
                </div>
            @endforeach

            <div class="dispatch-pagination">
                {{ $tasks->links() }}
            </div>
        @endif
    </section>
</div>
