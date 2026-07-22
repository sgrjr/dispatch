<div>
    <section class="dispatch-card">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
            <h1 class="dispatch-section-title" style="font-size:1rem; color: var(--dispatch-text); margin:0;">My submissions</h1>
            <label style="display:flex; align-items:center; gap:0.4rem; font-size:0.7rem; font-weight:700; text-transform:uppercase; color: var(--dispatch-text-muted);">
                Status
                <select wire:model.live="statusFilter" class="dispatch-select" style="width:auto;">
                    <option value="">All</option>
                    @foreach ($statusLabels as $code => $label) <option value="{{ $code }}">{{ $label }}</option> @endforeach
                </select>
            </label>
        </div>
    </section>

    <section class="dispatch-card" style="margin-top: 1rem;">
        @if ($tasks->isEmpty())
            <div class="dispatch-empty">You haven't submitted anything yet — use "New" to report a bug or request a feature.</div>
        @else
            @foreach ($tasks as $task)
                <div class="dispatch-list-row" wire:key="mine-row-{{ $task->id }}">
                    <div style="min-width:0; flex:1;">
                        <div>
                            <a href="{{ route('dispatch.show', $task) }}" class="dispatch-card-code" style="font-weight:700; color: var(--dispatch-accent);">{{ $task->code }}</a>
                            <span class="dispatch-list-title">{{ $task->title }}</span>
                        </div>
                        <div class="dispatch-list-meta">
                            <span class="dispatch-badge is-{{ $task->priority }}">{{ $task->priority }}</span>
                            <span class="dispatch-badge">{{ $task->type }}</span>
                            <span class="dispatch-badge is-info">{{ $statusLabels[$task->status] ?? $task->status }}</span>
                            @include('dispatch::livewire.partials.label-chips', ['labels' => $task->labels, 'context' => 'row'])
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
