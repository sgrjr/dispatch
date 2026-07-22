<div>
    <style>
        .dispatch-board-filters {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        .dispatch-board-filters .dispatch-select { width: auto; min-width: 9rem; }
        .dispatch-board-filters-reset { margin-left: auto; }
        .dispatch-board-bulkbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.6rem;
            margin-bottom: 1rem;
        }
        .dispatch-board-bulkbar select { width: auto; min-width: 9rem; }
        .dispatch-board-focusbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.6rem;
            margin-bottom: 1rem;
        }
        .dispatch-board-focusbar .dispatch-select { width: auto; min-width: 9rem; }
        .dispatch-focus-save { display: flex; align-items: center; gap: 0.4rem; }
        .dispatch-focus-save .dispatch-input { width: auto; min-width: 12rem; }
        .dispatch-link {
            font-size: 0.78rem;
            color: var(--dispatch-accent);
            text-decoration: none;
        }
        .dispatch-link:hover { text-decoration: underline; }
        .dispatch-swimlane-toggle { margin-left: auto; }
        .dispatch-swimlane-head {
            margin: 1.25rem 0 0.35rem;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--dispatch-text-muted);
        }
        .dispatch-board {
            display: grid;
            grid-template-columns: repeat({{ count($columns) }}, minmax(0, 1fr));
            gap: 0.75rem;
            align-items: start;
        }
        @media (max-width: 1100px) {
            .dispatch-board { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (max-width: 640px) {
            .dispatch-board { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        .dispatch-column {
            background: var(--dispatch-surface-muted);
            border: 1px solid var(--dispatch-border);
            border-radius: var(--dispatch-radius-lg);
            padding: 0.6rem;
            min-height: 12rem;
            display: flex;
            flex-direction: column;
        }
        .dispatch-column-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: var(--dispatch-text-muted);
            padding: 0.2rem 0.3rem 0.6rem;
        }
        .dispatch-column-count {
            background: var(--dispatch-surface);
            border-radius: var(--dispatch-radius-pill);
            padding: 0.05rem 0.5rem;
        }
        .dispatch-column-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            flex: 1;
            min-height: 2rem;
        }
        .dispatch-column-list.is-drag-over {
            outline: 2px dashed var(--dispatch-accent);
            outline-offset: 2px;
            border-radius: var(--dispatch-radius-md);
        }
        .dispatch-column-loadmore {
            margin-top: 0.5rem;
            align-self: flex-start;
            font-size: 0.68rem;
            padding: 0.3rem 0.6rem;
        }
        .dispatch-card {
            background: var(--dispatch-surface);
            border: 1px solid var(--dispatch-border);
            border-radius: var(--dispatch-radius-md);
            box-shadow: var(--dispatch-shadow);
            padding: 0.55rem 0.65rem;
            font-size: 0.78rem;
            cursor: grab;
        }
        .dispatch-card:active { cursor: grabbing; }
        .dispatch-card.is-dragging { opacity: 0.4; }
        .dispatch-card.is-select-mode { cursor: default; }
        .dispatch-card.is-select-mode:active { cursor: default; }
        .dispatch-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }
        .dispatch-card-select { margin-right: 0.15rem; }
        .dispatch-card-code { font-weight: 700; color: var(--dispatch-accent); font-size: 0.72rem; }
        .dispatch-card-title { margin: 0.3rem 0 0.4rem; color: var(--dispatch-text); }
        .dispatch-card-meta { display: flex; flex-wrap: wrap; gap: 0.3rem; align-items: center; }
        /* Elevated label chips sit tight on the card, just under the title. */
        .dispatch-card-chips { display: flex; flex-wrap: wrap; gap: 0.25rem; margin: 0 0 0.35rem; }
        .dispatch-card-chips .dispatch-badge { font-size: 0.66rem; padding: 0.05rem 0.4rem; }
    </style>

    {{-- Filter bar: checkbox multi-filters (all-on by default; uncheck to
         hide noise), column visibility, and the same cumulative activity
         window as the list view. --}}
    <section class="dispatch-card dispatch-board-filters">
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
            @include('dispatch::livewire.partials.filter-group-grouped', ['property' => 'labelFilter', 'label' => 'Label', 'groups' => $labelFilterGroups])
        </div>
        <div>
            <span class="dispatch-label">Due</span>
            @include('dispatch::livewire.partials.filter-group', ['property' => 'dueFilter', 'options' => $dueBucketLabels])
        </div>
        <div>
            <span class="dispatch-label">Columns</span>
            @include('dispatch::livewire.partials.filter-group', ['property' => 'columnFilter', 'options' => $statusLabels])
        </div>
        <div>
            <span class="dispatch-label">Updated</span>
            <select wire:model.live="updatedFilter" class="dispatch-select">
                <option value="">Any time</option>
                <option value="today">Today</option>
                <option value="week">Past week</option>
                <option value="month">Past month</option>
                <option value="older">Older</option>
            </select>
        </div>
        <div class="dispatch-board-filters-reset">
            <button type="button" wire:click="clearFilters" class="dispatch-btn is-secondary">Reset filters</button>
        </div>
    </section>

    {{--
        Focus bar (W8-2 / W8-5): the steering-lens switcher, a "save current
        filters as a Focus" form, a guarded link to the manage-focuses page,
        and the swimlanes view toggle. A Focus narrows BOTH board queries when
        selected; swimlanes is a pure view mode (see the board body below).
    --}}
    <section class="dispatch-card dispatch-board-focusbar">
        <div>
            <span class="dispatch-label">Focus</span>
            @include('dispatch::livewire.partials.focus-switcher', ['focuses' => $focuses])
        </div>

        <form wire:submit="saveCurrentAsFocus" class="dispatch-focus-save">
            <input type="text" wire:model="newFocusName" class="dispatch-input" placeholder="Save current filters as…" aria-label="New focus name">
            <button type="submit" class="dispatch-btn is-secondary">Save focus</button>
        </form>

        @if (\Illuminate\Support\Facades\Route::has(config('dispatch.routes.name_prefix', 'dispatch.').'focuses'))
            <a href="{{ route(config('dispatch.routes.name_prefix', 'dispatch.').'focuses') }}" class="dispatch-link">Manage focuses</a>
        @endif

        <button type="button" wire:click="$toggle('swimlanes')" class="dispatch-btn is-secondary dispatch-swimlane-toggle">
            {{ $swimlanes ? 'Lanes: on' : 'Lanes: off' }}
        </button>
    </section>

    {{--
        Bulk-select bar (F3, minimal slice: bulk status + bulk decline).
        Select-mode is opt-in and off by default — the board behaves exactly
        as before until "Select tasks" is toggled on. While it's on, cards
        render with draggable="false" (see below) so bulk-select can never
        race with the native HTML5 drag-and-drop.
    --}}
    <section class="dispatch-card dispatch-board-bulkbar">
        <button type="button" wire:click="toggleSelectMode" class="dispatch-btn is-secondary">
            {{ $selectMode ? 'Cancel select' : 'Select tasks' }}
        </button>

        @if ($selectMode)
            <span style="font-size: 0.75rem; color: var(--dispatch-text-muted);">{{ count($selectedIds) }} selected</span>

            {{-- Full vocab (statusLabels), not $columns — hiding a column must
                 not remove it as a bulk-status target. --}}
            <select wire:model.live="bulkStatus" class="dispatch-select">
                <option value="">Set status…</option>
                @foreach ($statusLabels as $status => $label)
                    <option value="{{ $status }}">{{ $label }}</option>
                @endforeach
            </select>
            <button
                type="button"
                wire:click="bulkApplyStatus"
                class="dispatch-btn"
                @if (empty($selectedIds) || $bulkStatus === '') disabled @endif
            >
                Apply status
            </button>
            <button
                type="button"
                wire:click="bulkDecline"
                class="dispatch-btn is-secondary"
                @if (empty($selectedIds)) disabled @endif
            >
                Decline selected
            </button>
        @endif
    </section>

    {{--
        Board body. Swimlanes (W8-5) render one grid per elevated lane, each
        headed by the lane name ('—' = no elevated label, sorted last); off,
        $laneRows is a single unlabeled row so this exact grid renders once,
        byte-identical to the pre-lanes board.

        dispatch.js scopes its delegated dragstart/dragover/drop listeners to
        `[data-dispatch-board]` and resolves the owning Livewire component via
        the nearest `[wire:id]` ancestor, so no per-card/per-column rebinding is
        needed after a morph. In swimlane mode cards render draggable="false"
        (lane-relative drops have no meaning), so DnD is inert there without
        touching dispatch.js.
    --}}
    @foreach ($laneRows as $laneRow)
        @if ($laneRow['label'] !== null)
            <h3 class="dispatch-swimlane-head">{{ $laneRow['label'] }}</h3>
        @endif
        @php $laneStatus = $laneRow['byStatus']; @endphp
        <div class="dispatch-board" data-dispatch-board style="margin-top: 1rem;">
            @foreach ($columns as $col)
                @php $cards = $laneStatus->get($col, collect()); @endphp
                <section class="dispatch-column">
                    <header class="dispatch-column-head">
                        <span>{{ $statusLabels[$col] ?? $col }}</span>
                        <span class="dispatch-column-count">
                            @if (! $swimlanes && $col === 'done' && $doneTotal > $doneShowing)
                                {{ $doneShowing }} / {{ $doneTotal }}
                            @else
                                {{ $cards->count() }}
                            @endif
                        </span>
                    </header>
                    <ul class="dispatch-column-list" data-dispatch-column data-status="{{ $col }}">
                        @foreach ($cards as $task)
                            @php
                                $isStale = $stalenessEnabled
                                    && ! in_array($task->status, ['backburner', 'done', 'declined'], true)
                                    && $task->updated_at
                                    && $task->updated_at->lt(now()->subDays($staleThresholdDays));
                                $elevatedLabels = \Sgrjr\Dispatch\Support\LabelFacets::split($task->labels)['elevated'];
                            @endphp
                            <li
                                class="dispatch-card @if ($selectMode) is-select-mode @endif"
                                draggable="{{ ($selectMode || $swimlanes) ? 'false' : 'true' }}"
                                data-dispatch-card
                                data-task-id="{{ $task->id }}"
                                wire:key="board-card-{{ $task->id }}"
                            >
                                <div class="dispatch-card-head">
                                    @if ($selectMode)
                                        <input
                                            type="checkbox"
                                            class="dispatch-card-select"
                                            wire:model.live="selectedIds"
                                            value="{{ $task->id }}"
                                            draggable="false"
                                        >
                                    @endif
                                    <a href="{{ route('dispatch.show', $task) }}" class="dispatch-card-code">{{ $task->code }}</a>
                                    <span class="dispatch-badge is-{{ $task->priority }}">{{ $task->priority }}</span>
                                </div>
                                <p class="dispatch-card-title">{{ \Illuminate\Support\Str::limit($task->title, 90) }}</p>
                                @if ($elevatedLabels->isNotEmpty())
                                    <div class="dispatch-card-chips">
                                        @include('dispatch::livewire.partials.label-chips', ['labels' => $task->labels, 'context' => 'card'])
                                    </div>
                                @endif
                                <div class="dispatch-card-meta">
                                    <span class="dispatch-badge">{{ $task->type }}</span>
                                    @if ($task->is_public)
                                        <span class="dispatch-badge is-success">public</span>
                                    @endif
                                    @if ($isStale)
                                        <span class="dispatch-badge is-warning" title="No update in over {{ $staleThresholdDays }} days">stale</span>
                                    @endif
                                    @include('dispatch::livewire.partials.due-badge', ['task' => $task])
                                    @if ($task->assignee)
                                        <span style="font-size: 0.7rem; color: var(--dispatch-text-muted);">{{ $task->assignee->name }}</span>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    @if (! $swimlanes && $col === 'done' && $doneLimit > 0 && $doneTotal > $doneLimit)
                        <button type="button" wire:click="toggleShowAllDone" class="dispatch-btn is-secondary dispatch-column-loadmore">
                            {{ $showAllDone ? 'Show recent only' : 'Load all ('.$doneTotal.')' }}
                        </button>
                    @endif
                </section>
            @endforeach
        </div>
    @endforeach
</div>
