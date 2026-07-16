<div>
    <style>
        .dispatch-board-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        .dispatch-board-filters label {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: var(--dispatch-text-muted);
        }
        .dispatch-board-filters select { margin-top: 0.2rem; min-width: 9rem; }
        .dispatch-board {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
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
        .dispatch-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }
        .dispatch-card-code { font-weight: 700; color: var(--dispatch-accent); font-size: 0.72rem; }
        .dispatch-card-title { margin: 0.3rem 0 0.4rem; color: var(--dispatch-text); }
        .dispatch-card-meta { display: flex; flex-wrap: wrap; gap: 0.3rem; align-items: center; }
    </style>

    {{-- Filter bar --}}
    <section class="dispatch-card dispatch-board-filters">
        <label>Type
            <select wire:model.live="typeFilter" class="dispatch-select">
                <option value="">Any</option>
                @foreach ($types as $t) <option value="{{ $t }}">{{ $t }}</option> @endforeach
            </select>
        </label>
        <label>Priority
            <select wire:model.live="priorityFilter" class="dispatch-select">
                <option value="">Any</option>
                @foreach ($priorities as $p) <option value="{{ $p }}">{{ $p }}</option> @endforeach
            </select>
        </label>
        <label>Label
            <select wire:model.live="labelFilter" class="dispatch-select">
                <option value="">Any</option>
                @foreach ($labels as $l) <option value="{{ $l->name }}">{{ $l->name }}</option> @endforeach
            </select>
        </label>
    </section>

    {{--
        Board root: dispatch.js scopes its delegated dragstart/dragover/drop
        listeners to `[data-dispatch-board]` and resolves the owning Livewire
        component via the nearest `[wire:id]` ancestor (this root element),
        so no per-card/per-column rebinding is needed after Livewire morphs
        the DOM on re-render.
    --}}
    <div class="dispatch-board" data-dispatch-board style="margin-top: 1rem;">
        @php
            $colLabels = [
                'triage' => 'Triage', 'open' => 'Open', 'in_progress' => 'In Progress',
                'verifying' => 'Verifying', 'done' => 'Done', 'declined' => 'Declined',
            ];
        @endphp

        @foreach ($columns as $col)
            @php $cards = $byStatus->get($col, collect()); @endphp
            <section class="dispatch-column">
                <header class="dispatch-column-head">
                    <span>{{ $colLabels[$col] ?? $col }}</span>
                    <span class="dispatch-column-count">{{ $cards->count() }}</span>
                </header>
                <ul class="dispatch-column-list" data-dispatch-column data-status="{{ $col }}">
                    @foreach ($cards as $task)
                        <li
                            class="dispatch-card"
                            draggable="true"
                            data-dispatch-card
                            data-task-id="{{ $task->id }}"
                            wire:key="board-card-{{ $task->id }}"
                        >
                            <div class="dispatch-card-head">
                                <a href="{{ route('dispatch.show', $task) }}" class="dispatch-card-code">{{ $task->code }}</a>
                                <span class="dispatch-badge is-{{ $task->priority }}">{{ $task->priority }}</span>
                            </div>
                            <p class="dispatch-card-title">{{ \Illuminate\Support\Str::limit($task->title, 90) }}</p>
                            <div class="dispatch-card-meta">
                                <span class="dispatch-badge">{{ $task->type }}</span>
                                @if ($task->is_public)
                                    <span class="dispatch-badge is-success">public</span>
                                @endif
                                @if ($task->assignee)
                                    <span style="font-size: 0.7rem; color: var(--dispatch-text-muted);">{{ $task->assignee->name }}</span>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
</div>
