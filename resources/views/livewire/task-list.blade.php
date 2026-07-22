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
        /* Focus steering: save-current-as-focus form + manage link, sitting under
           the filter grid next to the switcher. */
        .dispatch-focus-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; margin-bottom: 1rem; }
        .dispatch-link { color: var(--dispatch-accent); font-size: 0.78rem; font-weight: 600; text-decoration: none; }
        .dispatch-link:hover { text-decoration: underline; }
        /* Page-scoped group-by: lane header + the muted grouping hint. */
        .dispatch-list-lane { display: flex; align-items: baseline; gap: 0.5rem; margin: 0.9rem 0 0.15rem; padding-top: 0.4rem; border-top: 2px solid var(--dispatch-border); }
        .dispatch-list-lane:first-child { border-top: none; padding-top: 0; margin-top: 0.25rem; }
        .dispatch-list-lane-name { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--dispatch-text-muted); }
        .dispatch-list-lane-count { font-size: 0.68rem; color: var(--dispatch-text-faint); }
        .dispatch-group-hint { font-size: 0.72rem; color: var(--dispatch-text-muted); font-style: italic; }
        /* Facet chip tiers (label-chips partial): elevated leads with a faint
           inset ring; meta (detail views only) is already subdued inline. */
        .dispatch-badge.dispatch-label-elevated { box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.35); }
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
                <span class="dispatch-label">Type</span>
                @include('dispatch::livewire.partials.filter-group', ['property' => 'typeFilter', 'options' => $typeLabels])
            </div>
            <div>
                <span class="dispatch-label">Priority</span>
                @include('dispatch::livewire.partials.filter-group', ['property' => 'priorityFilter', 'options' => $priorityLabels])
            </div>
            <div>
                <span class="dispatch-label">Label</span>
                @include('dispatch::livewire.partials.filter-group-grouped', ['property' => 'labelFilter', 'label' => 'Label', 'groups' => $this->labelFilterGroups()])
            </div>
            <div>
                <span class="dispatch-label">Due</span>
                @include('dispatch::livewire.partials.filter-group', ['property' => 'dueFilter', 'options' => $dueBucketLabels])
            </div>
            <div>
                <label class="dispatch-label">Updated</label>
                <select wire:model.live="updatedFilter" class="dispatch-select">
                    <option value="">Any time</option>
                    <option value="today">Today</option>
                    <option value="week">Past week</option>
                    <option value="month">Past month</option>
                    <option value="older">Older</option>
                </select>
            </div>
            <div>
                <label class="dispatch-label">Focus</label>
                @include('dispatch::livewire.partials.focus-switcher', ['focuses' => $focuses])
            </div>
        </div>

        <div class="dispatch-focus-actions">
            <input type="text" wire:model="newFocusName" placeholder="Save current filters as a focus…" class="dispatch-input" style="max-width:18rem;">
            <button type="button" wire:click="saveCurrentAsFocus" wire:loading.attr="disabled" wire:target="saveCurrentAsFocus" class="dispatch-btn is-secondary">Save focus</button>
            @if (\Illuminate\Support\Facades\Route::has(config('dispatch.routes.name_prefix', 'dispatch.').'focuses'))
                <a href="{{ route(config('dispatch.routes.name_prefix', 'dispatch.').'focuses') }}" class="dispatch-link">Manage focuses</a>
            @endif
        </div>

        <div class="dispatch-list-toolbar">
            <div style="display:flex; flex-wrap:wrap; align-items:center; gap:0.75rem;">
                <label class="dispatch-label" style="display:flex; align-items:center; gap:0.4rem;">
                    Sort
                    <select wire:model.live="sort" class="dispatch-select" style="width:auto;">
                        <option value="priority">Priority</option>
                        <option value="status">Status</option>
                        <option value="newest">Newest</option>
                        <option value="oldest">Oldest</option>
                        <option value="updated_desc">Recently updated</option>
                        <option value="updated_asc">Least recently updated</option>
                        <option value="due_asc">Due (closest first)</option>
                        <option value="due_desc">Due (furthest first)</option>
                        <option value="code">Code</option>
                        <option value="title">Title</option>
                    </select>
                </label>
                <label class="dispatch-label" style="display:flex; align-items:center; gap:0.4rem;">
                    Group
                    <select wire:model.live="groupBy" class="dispatch-select" style="width:auto;">
                        <option value="">No grouping</option>
                        @foreach ($this->groupByOptions() as $ns)
                            <option value="{{ $ns }}">{{ ucfirst($ns) }}</option>
                        @endforeach
                    </select>
                </label>
                @if ($groupedLanes !== null)
                    <span class="dispatch-group-hint">grouped within this page</span>
                @endif
            </div>
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
        @elseif ($groupedLanes !== null)
            {{-- Page-scoped group-by (W8-5): the SAME rows as the flat @else
                 branch below, partitioned under lane headers. Entered only when
                 grouping is active; groupBy off falls to the byte-identical
                 flat path. --}}
            @foreach ($groupedLanes as $laneGroup)
                <div class="dispatch-list-lane" wire:key="lane-{{ $loop->index }}">
                    <span class="dispatch-list-lane-name">{{ $laneGroup['lane'] }}</span>
                    <span class="dispatch-list-lane-count">{{ $laneGroup['tasks']->count() }}</span>
                </div>
                @foreach ($laneGroup['tasks'] as $task)
                    <div class="dispatch-list-row" wire:key="list-row-{{ $task->id }}">
                        <div class="dispatch-list-check">
                            <input type="checkbox" wire:model.live="selected" value="{{ $task->id }}" wire:key="select-{{ $task->id }}">
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
                                @include('dispatch::livewire.partials.due-badge', ['task' => $task])
                                @include('dispatch::livewire.partials.label-chips', ['labels' => $task->labels, 'context' => 'row'])
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
            @endforeach

            <div class="dispatch-pagination">
                {{ $tasks->links() }}
            </div>
        @else
            @foreach ($tasks as $task)
                <div class="dispatch-list-row" wire:key="list-row-{{ $task->id }}">
                    <div class="dispatch-list-check">
                        <input type="checkbox" wire:model.live="selected" value="{{ $task->id }}" wire:key="select-{{ $task->id }}">
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
                            @include('dispatch::livewire.partials.due-badge', ['task' => $task])
                            @include('dispatch::livewire.partials.label-chips', ['labels' => $task->labels, 'context' => 'row'])
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
