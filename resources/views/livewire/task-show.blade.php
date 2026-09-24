<div class="dispatch-show">
    <style>
        /* ── Page frame ─────────────────────────────────────────────────────
           Hierarchy, loudest first: title + status/priority → the kind's
           actions → the description → the discussion. Properties and routing
           live in the right rail, visible without scrolling. Reference data
           (diagnostics, agent metrics) is collapsed and deliberately quiet. */
        .dispatch-show { max-width: 88rem; margin: 0 auto; }
        .dispatch-show-grid { display: grid; grid-template-columns: minmax(0, 1fr) 21rem; gap: 1.25rem; align-items: start; margin-top: 1.25rem; }
        .dispatch-show-grid.is-single { grid-template-columns: minmax(0, 1fr); max-width: 60rem; }
        .dispatch-show-main, .dispatch-show-rail { display: flex; flex-direction: column; gap: 1.25rem; min-width: 0; }
        @media (max-width: 960px) {
            .dispatch-show-grid { grid-template-columns: minmax(0, 1fr); }
        }

        /* ── Header ─────────────────────────────────────────────────────── */
        .dispatch-show-header { padding: 1.4rem 1.5rem 1.2rem; }
        .dispatch-show-crumbs { display: flex; align-items: center; gap: 0.5rem; font-size: 0.75rem; color: var(--dispatch-text-muted); margin: 0 0 0.35rem; }
        .dispatch-show-crumbs a { color: var(--dispatch-text-muted); font-weight: 600; }
        .dispatch-show-code { font-weight: 700; letter-spacing: 0.03em; color: var(--dispatch-accent); font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
        .dispatch-show-headrow { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 1rem; }
        .dispatch-show-title { font-size: 1.65rem; line-height: 1.25; font-weight: 750; letter-spacing: -0.015em; margin: 0; flex: 1 1 24rem; min-width: 0; overflow-wrap: anywhere; }
        .dispatch-show-headactions { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
        .dispatch-show-jump { display: none; }
        .dispatch-comments-link:hover { text-decoration: none; }
        .dispatch-count { display: inline-flex; align-items: center; justify-content: center; min-width: 1.35rem; padding: 0 0.4rem; border-radius: var(--dispatch-radius-pill); background: var(--dispatch-accent); color: var(--dispatch-accent-contrast); font-size: 0.7rem; font-weight: 700; line-height: 1.35rem; }
        .dispatch-comments-anchor { scroll-margin-top: 1rem; }
        @media (max-width: 960px) { .dispatch-show-jump { display: inline-flex; } }

        /* Status + priority are the signal: bigger, filled, first. */
        .dispatch-show-signals { display: flex; flex-wrap: wrap; gap: 0.45rem; align-items: center; margin-top: 0.85rem; }
        .dispatch-pill { display: inline-flex; align-items: center; gap: 0.4rem; border-radius: var(--dispatch-radius-pill); font-size: 0.8rem; font-weight: 700; padding: 0.3rem 0.8rem; text-transform: capitalize; background: var(--dispatch-info-bg); color: var(--dispatch-info); border: 1px solid currentColor; }
        .dispatch-pill::before { content: ""; width: 0.5rem; height: 0.5rem; border-radius: 50%; background: currentColor; }
        .dispatch-pill.is-blocker { background: var(--dispatch-danger); color: #fff; border-color: var(--dispatch-danger); }
        .dispatch-pill.is-blocker::before { background: #fff; }
        .dispatch-pill.is-high { background: var(--dispatch-danger-bg); color: var(--dispatch-danger); }
        .dispatch-pill.is-medium { background: var(--dispatch-warning-bg); color: var(--dispatch-warning); }
        .dispatch-pill.is-low { background: var(--dispatch-surface-muted); color: var(--dispatch-text-muted); border-color: var(--dispatch-border); }

        .dispatch-show-facts { display: flex; flex-wrap: wrap; gap: 0.35rem 1.5rem; margin: 1rem 0 0; padding: 0.85rem 0 0; border-top: 1px solid var(--dispatch-border); font-size: 0.82rem; }
        .dispatch-show-fact { display: flex; flex-direction: column; gap: 0.05rem; }
        .dispatch-show-fact dt { font-size: 0.66rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--dispatch-text-faint); }
        .dispatch-show-fact dd { margin: 0; font-weight: 600; }
        .dispatch-show-fact .is-overdue { color: var(--dispatch-danger); }

        .dispatch-show-deps { margin-top: 0.85rem; display: flex; flex-direction: column; gap: 0.3rem; font-size: 0.82rem; }
        .dispatch-show-dep { display: flex; flex-wrap: wrap; align-items: center; gap: 0.35rem 0.6rem; padding: 0.45rem 0.7rem; border-radius: var(--dispatch-radius-sm); background: var(--dispatch-surface-muted); border: 1px solid var(--dispatch-border); }
        .dispatch-show-dep.is-blocked { background: var(--dispatch-warning-bg); border-color: var(--dispatch-warning); }
        .dispatch-show-dep strong { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; }

        /* ── Cards & sections ───────────────────────────────────────────── */
        .dispatch-show .dispatch-card { border-radius: var(--dispatch-radius-md); }
        .dispatch-section-title { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--dispatch-text-muted); margin: 0 0 0.75rem; }
        .dispatch-card-head { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; margin: 0 0 0.9rem; }
        .dispatch-card-head .dispatch-section-title { margin: 0; }
        .dispatch-hint { font-size: 0.74rem; color: var(--dispatch-text-muted); margin: 0.45rem 0 0; }

        /* The kind's own actions (Approve, Deny…) are the page's call to action. */
        .dispatch-show-kind { border-left: 4px solid var(--dispatch-accent); }
        .dispatch-show-kind .dispatch-section-title { color: var(--dispatch-accent); }

        /* Description: the reading surface — full-size text, no grey box. */
        .dispatch-show-desc { font-size: 0.95rem; line-height: 1.65; overflow-wrap: anywhere; }
        .dispatch-show-desc :first-child { margin-top: 0; }
        .dispatch-show-desc :last-child { margin-bottom: 0; }
        .dispatch-show-desc pre { background: var(--dispatch-surface-muted); border: 1px solid var(--dispatch-border); border-radius: var(--dispatch-radius-sm); padding: 0.7rem; overflow-x: auto; font-size: 0.8rem; }
        .dispatch-show-desc-empty { color: var(--dispatch-text-muted); font-style: italic; margin: 0; }

        .dispatch-disclosure { margin-top: 1rem; }
        .dispatch-disclosure > summary { cursor: pointer; list-style: none; display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.78rem; font-weight: 600; color: var(--dispatch-accent); user-select: none; }
        .dispatch-disclosure > summary::-webkit-details-marker { display: none; }
        .dispatch-disclosure > summary::before { content: "▸"; font-size: 0.7rem; transition: transform 0.15s; }
        .dispatch-disclosure[open] > summary::before { transform: rotate(90deg); }
        .dispatch-disclosure-body { margin-top: 0.6rem; }

        .dispatch-gallery { display: flex; flex-wrap: wrap; gap: 0.6rem; margin-top: 1.1rem; }
        .dispatch-gallery-thumb { width: 6.5rem; height: 6.5rem; border-radius: var(--dispatch-radius-sm); overflow: hidden; border: 1px solid var(--dispatch-border); display: block; transition: transform 0.12s, box-shadow 0.12s; }
        .dispatch-gallery-thumb:hover { transform: translateY(-1px); box-shadow: var(--dispatch-shadow); }
        .dispatch-gallery-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .dispatch-file-list { display: flex; flex-direction: column; gap: 0.4rem; margin-top: 0.75rem; }
        .dispatch-file-row { display: flex; align-items: center; gap: 0.5rem; padding: 0.45rem 0.7rem; border: 1px solid var(--dispatch-border); border-radius: var(--dispatch-radius-sm); font-size: 0.78rem; background: var(--dispatch-surface); }

        /* ── Right rail: properties & routing ───────────────────────────── */
        .dispatch-rail-card { padding: 1rem 1.1rem; }
        .dispatch-field { display: flex; flex-direction: column; gap: 0.2rem; }
        .dispatch-field + .dispatch-field { margin-top: 0.7rem; }
        .dispatch-field .dispatch-label { margin: 0; }
        .dispatch-field-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 0.6rem; margin-top: 0.7rem; }
        .dispatch-field-pair .dispatch-field + .dispatch-field { margin-top: 0; }
        .dispatch-rail-card .dispatch-select, .dispatch-rail-card .dispatch-input, .dispatch-rail-card .dispatch-textarea { font-size: 0.82rem; padding: 0.42rem 0.55rem; }
        .dispatch-check { display: flex; align-items: flex-start; gap: 0.45rem; font-size: 0.8rem; cursor: pointer; margin-top: 0.7rem; }
        .dispatch-check input { margin-top: 0.2rem; }
        .dispatch-btn.is-block { width: 100%; justify-content: center; }
        .dispatch-btn.is-small { padding: 0.35rem 0.8rem; font-size: 0.75rem; }
        .dispatch-savebar { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-top: 1rem; padding-top: 0.85rem; border-top: 1px solid var(--dispatch-border); }
        .dispatch-dirty { font-size: 0.72rem; font-weight: 700; color: var(--dispatch-warning); }
        .dispatch-row { display: flex; gap: 0.45rem; align-items: center; }
        .dispatch-row > .dispatch-select, .dispatch-row > .dispatch-input { flex: 1; min-width: 0; }
        .dispatch-rail-sub { margin-top: 0.9rem; padding-top: 0.9rem; border-top: 1px dashed var(--dispatch-border); }

        .dispatch-label-picker { display: flex; flex-wrap: wrap; gap: 0.35rem; }
        .dispatch-label-chip { display: inline-flex; align-items: center; gap: 0.3rem; border: 1px solid var(--dispatch-border); border-radius: var(--dispatch-radius-pill); padding: 0.2rem 0.55rem; font-size: 0.72rem; font-weight: 600; cursor: pointer; background: var(--dispatch-surface); }
        .dispatch-label-chip:has(input:checked) { background: var(--dispatch-info-bg); border-color: var(--dispatch-info); color: var(--dispatch-info); }
        .dispatch-label-chip input { margin: 0; }

        .dispatch-watchers { display: flex; flex-wrap: wrap; gap: 0.3rem; margin-bottom: 0.7rem; }
        .dispatch-avatar-chip { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.75rem; font-weight: 600; padding: 0.15rem 0.55rem 0.15rem 0.15rem; border-radius: var(--dispatch-radius-pill); background: var(--dispatch-surface-muted); border: 1px solid var(--dispatch-border); }
        .dispatch-avatar { width: 1.3rem; height: 1.3rem; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 0.62rem; font-weight: 800; background: var(--dispatch-accent); color: var(--dispatch-accent-contrast); }

        .dispatch-rail-danger > summary { color: var(--dispatch-text-muted); }

        /* ── Reference zone: collapsed, quiet ───────────────────────────── */
        .dispatch-reference { display: flex; flex-direction: column; gap: 0.5rem; }
        .dispatch-reference-panel { border: 1px solid var(--dispatch-border); border-radius: var(--dispatch-radius-sm); background: var(--dispatch-surface-muted); }
        .dispatch-reference-panel > summary { cursor: pointer; list-style: none; display: flex; align-items: center; gap: 0.6rem; padding: 0.55rem 0.8rem; font-size: 0.75rem; color: var(--dispatch-text-muted); user-select: none; }
        .dispatch-reference-panel > summary::-webkit-details-marker { display: none; }
        .dispatch-reference-panel > summary::before { content: "▸"; font-size: 0.65rem; transition: transform 0.15s; }
        .dispatch-reference-panel[open] > summary::before { transform: rotate(90deg); }
        .dispatch-reference-panel > summary strong { font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; font-size: 0.68rem; }
        .dispatch-reference-panel > summary .dispatch-reference-gist { margin-left: auto; color: var(--dispatch-text-faint); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .dispatch-reference-body { padding: 0.2rem 0.9rem 0.9rem; font-size: 0.8rem; }
        .dispatch-meta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: 0.8rem; }
        .dispatch-stat-row { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .dispatch-stat { flex: 1 1 6rem; min-width: 6rem; background: var(--dispatch-surface); border: 1px solid var(--dispatch-border); border-radius: var(--dispatch-radius-sm); padding: 0.45rem 0.65rem; }
        .dispatch-stat-value { font-size: 1rem; font-weight: 700; line-height: 1.15; }
        .dispatch-stat-label { font-size: 0.64rem; text-transform: uppercase; letter-spacing: 0.03em; color: var(--dispatch-text-muted); margin-top: 0.15rem; }
        .dispatch-errlist { list-style: none; margin: 0.4rem 0 0; padding: 0; display: flex; flex-direction: column; gap: 0.4rem; }
        .dispatch-errlist li { border: 1px solid var(--dispatch-border); border-radius: var(--dispatch-radius-sm); padding: 0.4rem 0.6rem; font-size: 0.75rem; background: var(--dispatch-surface); }

        /* Facet chip tiers (label-chips partial, 'detail' context): elevated
           leads with a faint inset ring; meta is provenance, held subdued. */
        .dispatch-badge.dispatch-label-elevated { box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.35); }
        .dispatch-badge.dispatch-label-meta { font-size: 0.68rem; }
    </style>

    @php
        $taskClass = \Sgrjr\Dispatch\Models\Task::class;
        $canEdit = $this->canEdit();
        $isOverdue = $task->due_at && $task->due_at->isPast() && ! $task->isClosed();
        $initials = fn ($name) => collect(preg_split('/\s+/', trim((string) $name)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
    @endphp

    {{-- ═══ Header: what is this, what state is it in, who has it ═══ --}}
    <section class="dispatch-card dispatch-show-header">
        <p class="dispatch-show-crumbs">
            <a href="{{ route('dispatch.board') }}">Board</a>
            <span aria-hidden="true">/</span>
            <span class="dispatch-show-code">{{ $task->code }}</span>
        </p>

        <div class="dispatch-show-headrow">
            <h1 class="dispatch-show-title">{{ $task->title }}</h1>

            <div class="dispatch-show-headactions">
                {{-- Plain in-page anchor to the thread below — no JS. --}}
                <a href="#comments" class="dispatch-btn is-secondary is-small dispatch-comments-link">
                    Comments <span class="dispatch-count">{{ $commentCount }}</span>
                </a>
                @if ($canEdit)
                    <a href="#dispatch-properties" class="dispatch-btn is-secondary is-small dispatch-show-jump">Edit properties</a>
                @endif
                @can('watch', $task)
                    @if ($watchPrefs !== null)
                        {{--
                            Watching → a preferences popover (W13-1). Reuses the
                            filter-group popover chrome from the shared layout.
                            Radios pick the mode; under "status changes only" the
                            status checkboxes narrow WHICH transitions notify
                            (all checked = any status change; unchecking every box
                            falls back to all rather than a never-notify state).
                        --}}
                        <details class="dispatch-filter-group" wire:ignore.self style="display:inline-block; text-align:left;">
                            <summary class="dispatch-btn is-secondary is-small dispatch-filter-summary">
                                <span>Watching{{ $watchPrefs['notify_on'] === $taskClass::WATCH_STATUS_CHANGE ? ' · status only' : '' }}</span>
                                <span class="dispatch-filter-caret">&#9662;</span>
                            </summary>
                            <div class="dispatch-filter-panel" style="right:0; left:auto;">
                                <label class="dispatch-filter-option" wire:key="watch-mode-any">
                                    <input type="radio" name="dispatch-watch-mode" @checked($watchPrefs['notify_on'] !== $taskClass::WATCH_STATUS_CHANGE) wire:click="setWatchMode('any')">
                                    <span>Any update</span>
                                </label>
                                <label class="dispatch-filter-option" wire:key="watch-mode-status">
                                    <input type="radio" name="dispatch-watch-mode" @checked($watchPrefs['notify_on'] === $taskClass::WATCH_STATUS_CHANGE) wire:click="setWatchMode('status_change')">
                                    <span>Status changes only</span>
                                </label>
                                @if ($watchPrefs['notify_on'] === $taskClass::WATCH_STATUS_CHANGE)
                                    <div class="dispatch-filter-actions"><span>which statuses</span></div>
                                    @foreach ($statusLabels as $code => $label)
                                        <label class="dispatch-filter-option" style="padding-left:1.1rem;" wire:key="watch-status-{{ $code }}">
                                            <input
                                                type="checkbox"
                                                @checked($watchPrefs['statuses'] === null || in_array($code, $watchPrefs['statuses'], true))
                                                wire:click="toggleWatchStatus(@js((string) $code))"
                                            >
                                            <span>{{ $label }}</span>
                                        </label>
                                    @endforeach
                                @endif
                                <div style="margin-top:0.45rem;">
                                    <button type="button" wire:click="unwatch" wire:loading.attr="disabled" wire:target="unwatch" class="dispatch-btn is-secondary is-small">Stop watching</button>
                                </div>
                            </div>
                        </details>
                    @else
                        <button type="button" wire:click="startWatching" wire:loading.attr="disabled" wire:target="startWatching" class="dispatch-btn is-secondary is-small">Watch</button>
                    @endif
                @endcan
            </div>
        </div>

        <div class="dispatch-show-signals">
            <span class="dispatch-pill" title="Status">{{ $statusLabels[$task->status] ?? str_replace('_', ' ', $task->status) }}</span>
            <span class="dispatch-pill is-{{ $task->priority }}" title="Priority">{{ $task->priority }}</span>
            <span class="dispatch-badge">{{ $task->type }}</span>
            @if ($task->is_public)
                <span class="dispatch-badge is-success">public</span>
            @endif
            @if (($task->visibility ?? '') === $taskClass::VISIBILITY_PARTICIPANTS)
                <span class="dispatch-badge is-warning" title="Visible only to the submitter, assignee, and watchers">participants only</span>
            @endif
            {{-- TASK-997 part A: the lane badge, shown only when a real
                 LaneResolver is bound (see TaskShow::render()'s $laneActive) —
                 routing metadata, never a visibility signal. --}}
            @if ($laneActive)
                <span class="dispatch-badge" title="Lane">{{ $laneLabel ?? 'No department' }}</span>
            @endif
            @include('dispatch::livewire.partials.label-chips', ['labels' => $task->labels, 'context' => 'detail'])
        </div>

        <dl class="dispatch-show-facts">
            <div class="dispatch-show-fact"><dt>Assignee</dt><dd>{{ $task->assigneeLabel() ?? 'Unassigned' }}</dd></div>
            @if ($task->due_at)
                <div class="dispatch-show-fact">
                    <dt>Due</dt>
                    <dd @class(['is-overdue' => $isOverdue])>{{ $task->due_at->toFormattedDateString() }} <span style="font-weight:400; color: var(--dispatch-text-muted);">({{ $task->due_at->diffForHumans() }})</span></dd>
                </div>
            @endif
            <div class="dispatch-show-fact"><dt>Submitted by</dt><dd>{{ $task->submitter?->name ?? '—' }}</dd></div>
            <div class="dispatch-show-fact"><dt>Updated</dt><dd style="font-weight:400;">{{ $task->updated_at?->diffForHumans() }}</dd></div>
        </dl>

        {{-- Dependencies — real blockers are high-priority context, so they
             sit in the header rather than inside the hand-off panel. --}}
        @if ($canEdit && ($blockedByTasks->isNotEmpty() || $blocksTasks->isNotEmpty()))
            <div class="dispatch-show-deps">
                @if ($blockedByTasks->isNotEmpty())
                    <div class="dispatch-show-dep is-blocked">
                        <strong>Blocked by</strong>
                        @foreach ($blockedByTasks as $b)
                            <span wire:key="blocked-by-{{ $b->id }}">
                                <a href="{{ route('dispatch.show', $b) }}" class="dispatch-card-code">{{ $b->code }}</a>
                                <span style="color: var(--dispatch-text-muted);">{{ $b->title }} ({{ str_replace('_', ' ', $b->status) }})</span>{{ ! $loop->last ? ',' : '' }}
                            </span>
                        @endforeach
                    </div>
                @endif
                @if ($blocksTasks->isNotEmpty())
                    <div class="dispatch-show-dep">
                        <strong>Blocks</strong>
                        @foreach ($blocksTasks as $b)
                            <span wire:key="blocks-{{ $b->id }}">
                                <a href="{{ route('dispatch.show', $b) }}" class="dispatch-card-code">{{ $b->code }}</a>
                                <span style="color: var(--dispatch-text-muted);">{{ $b->title }} ({{ str_replace('_', ' ', $b->status) }})</span>{{ ! $loop->last ? ',' : '' }}
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </section>

    @php($hasRail = $canEdit || \Illuminate\Support\Facades\Gate::allows('delete', $task))
    <div @class(['dispatch-show-grid', 'is-single' => ! $hasRail])>
        {{-- ═══ Main column: act, read, discuss ═══ --}}
        <div class="dispatch-show-main">

            {{-- TASK-1188: the task's KIND: its panel and its own actions (Approve,
                 Deny...), run through the one action path. The defaults it hides are
                 gated below on $hiddenControls. --}}
            @if ($kindView)
                <section class="dispatch-card dispatch-show-kind" data-dispatch-kind="{{ $kindView['key'] }}">
                    @php($panel = $kindView['panel'] ?? null)
                    <h2 class="dispatch-section-title">{{ $panel['title'] ?? str_replace('_', ' ', $kindView['key']) }}
                        @if (! empty($panel['state']))
                            <span class="dispatch-badge is-info" style="margin-left:0.4rem;">{{ $panel['state'] }}</span>
                        @endif
                    </h2>
                    @if (! empty($panel['rows']))
                        <dl style="display:grid; grid-template-columns:max-content 1fr; gap:0.3rem 0.8rem; margin:0 0 0.9rem; font-size:0.88rem;">
                            @foreach ($panel['rows'] as $row)
                                <dt style="color: var(--dispatch-text-muted);">{{ $row['label'] }}</dt>
                                <dd style="margin:0; {{ ! empty($row['emphasis']) ? 'font-family:monospace; font-size:1.1rem; font-weight:700; letter-spacing:0.1em;' : '' }}">@if (! empty($row['url']))<a href="{{ $row['url'] }}" target="_blank" rel="noopener">{{ $row['value'] }}</a>@else{{ $row['value'] }}@endif</dd>
                            @endforeach
                        </dl>
                    @endif
                    @if (! empty($panel['note']))
                        <p style="font-size:0.82rem; margin:0 0 0.9rem;">{{ $panel['note'] }}</p>
                    @endif
                    @if (session('dispatch-kind-status'))
                        <div class="dispatch-badge is-success" style="margin-bottom:0.7rem; display:block; width:fit-content; text-transform:none; letter-spacing:0; font-size:0.78rem;">{{ session('dispatch-kind-status') }}</div>
                    @endif
                    @if (! empty($kindView['actions']))
                        <div style="display:flex; flex-wrap:wrap; gap:0.6rem; align-items:flex-end;">
                            @foreach ($kindView['actions'] as $action)
                                <div style="display:flex; flex-wrap:wrap; gap:0.4rem; align-items:flex-end;" wire:key="kind-action-{{ $action['key'] }}">
                                    @foreach ($action['inputs'] as $input)
                                        <label style="display:flex; flex-direction:column; font-size:0.75rem; gap:0.15rem;">
                                            {{ $input['label'] ?? $input['key'] }}
                                            @if (($input['type'] ?? 'text') === 'select')
                                                <select wire:model="actionInput.{{ $action['key'] }}.{{ $input['key'] }}" class="dispatch-select" style="width:auto;">
                                                    @foreach ($input['options'] ?? [] as $opt)
                                                        <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                                    @endforeach
                                                </select>
                                            @elseif (($input['type'] ?? 'text') === 'textarea')
                                                <textarea wire:model="actionInput.{{ $action['key'] }}.{{ $input['key'] }}" rows="2" class="dispatch-textarea" placeholder="{{ $input['placeholder'] ?? '' }}"></textarea>
                                            @else
                                                <input type="text" wire:model="actionInput.{{ $action['key'] }}.{{ $input['key'] }}" class="dispatch-input" placeholder="{{ $input['placeholder'] ?? '' }}">
                                            @endif
                                        </label>
                                    @endforeach
                                    @if (! empty($action['url']))
                                        <a href="{{ $action['url'] }}" class="dispatch-btn {{ $action['style'] === 'primary' ? '' : 'is-secondary' }}">{{ $action['label'] }}</a>
                                    @else
                                    <button
                                        type="button"
                                        wire:click="performAction('{{ $action['key'] }}')"
                                        @if ($action['confirm']) wire:confirm="{{ $action['confirm'] }}" @endif
                                        wire:loading.attr="disabled"
                                        class="dispatch-btn {{ $action['style'] === 'primary' ? '' : 'is-secondary' }}"
                                        @if ($action['style'] === 'danger') style="color: var(--dispatch-danger);" @endif
                                    >{{ $action['label'] }}</button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @error('kindAction') <p class="dispatch-error">{{ $message }}</p> @enderror
                </section>
            @endif

            {{-- Description — the reading surface. Staff edit it inline; the
                 edit rides saveMeta() with the rest of the properties. --}}
            <section class="dispatch-card">
                @if ($task->description)
                    <div class="dispatch-show-desc">{!! \Sgrjr\Dispatch\Support\Markdown::render($task->description) !!}</div>
                @else
                    <p class="dispatch-show-desc-empty">No description.</p>
                @endif

                {{--
                    Attachment gallery. Files live on a private disk; the download
                    route is the ONLY authorized way to reach one — used both as the
                    <img> src (browsers render embedded images regardless of the
                    Content-Disposition header the download response sends) and as
                    the lightbox's full-size source. Non-image files are plain
                    download-link rows.
                --}}
                @if ($task->attachments->isNotEmpty())
                    @if ($task->attachments->where('is_image', true)->isNotEmpty())
                        <div class="dispatch-gallery">
                            @foreach ($task->attachments->where('is_image', true) as $attachment)
                                <a
                                    href="{{ route('dispatch.attachments.download', $attachment) }}"
                                    class="dispatch-gallery-thumb"
                                    data-dispatch-lightbox
                                    data-dispatch-lightbox-src="{{ route('dispatch.attachments.download', $attachment) }}"
                                    title="{{ $attachment->original_name }}"
                                >
                                    <img src="{{ route('dispatch.attachments.download', $attachment) }}" alt="{{ $attachment->original_name }}" loading="lazy">
                                </a>
                            @endforeach
                        </div>
                    @endif
                    @if ($task->attachments->where('is_image', false)->isNotEmpty())
                        <div class="dispatch-file-list">
                            @foreach ($task->attachments->where('is_image', false) as $attachment)
                                {{-- Viewable (PDF / CSV / text) opens in a new tab; anything else downloads. --}}
                                <a href="{{ route($attachment->viewerKind() ? 'dispatch.attachments.view' : 'dispatch.attachments.download', $attachment) }}" @if ($attachment->viewerKind()) target="_blank" rel="noopener" @endif class="dispatch-file-row">
                                    📎 {{ $attachment->original_name }}
                                    <span style="color: var(--dispatch-text-faint); margin-left:auto;">{{ number_format($attachment->size_bytes / 1024, 1) }} KB</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                @endif

                @if ($canEdit)
                    <details class="dispatch-disclosure" wire:ignore.self>
                        <summary>Edit description</summary>
                        <div class="dispatch-disclosure-body">
                            <textarea wire:model="editDescription" rows="8" class="dispatch-textarea" placeholder="Details, steps to reproduce, links…"></textarea>
                            @error('editDescription') <p class="dispatch-error">{{ $message }}</p> @enderror
                            <div style="display:flex; justify-content:flex-end; align-items:center; gap:0.6rem; margin-top:0.5rem;">
                                <span class="dispatch-dirty" wire:dirty wire:target="editDescription">Unsaved</span>
                                <button type="button" wire:click="saveMeta" wire:loading.attr="disabled" wire:target="saveMeta" class="dispatch-btn is-small">Save description</button>
                            </div>
                        </div>
                    </details>
                @endif
            </section>

            {{-- Comment thread — the header's #comments link lands here. --}}
            <div id="comments" class="dispatch-comments-anchor">
                <livewire:dispatch-thread :task="$task" :key="'task-thread-'.$task->id" />
            </div>

            {{-- ═══ Reference: collapsed and quiet — there when you need it ═══ --}}
            @php($agentMetrics = \Sgrjr\Dispatch\Support\MetricsPresenter::present($task->context, $task->type))
            @php($showDiagnostics = $canEdit && $task->hasDiagnostics())
            @php($showMetrics = $canEdit && $agentMetrics !== null)
            @if ($showDiagnostics || $showMetrics)
                <div class="dispatch-reference">
                    {{-- Client diagnostics captured with the report (staff-facing). --}}
                    @if ($showDiagnostics)
                        @php($ctx = $task->context)
                        @php($consoleErrors = $ctx['console_errors'] ?? [])
                        <details class="dispatch-reference-panel" wire:ignore.self>
                            <summary>
                                <strong>Diagnostics</strong>
                                <span class="dispatch-reference-gist">
                                    {{ count($consoleErrors) }} console {{ \Illuminate\Support\Str::plural('error', count($consoleErrors)) }}@if (! empty($ctx['viewport'])) · {{ $ctx['viewport']['w'] ?? '?' }}×{{ $ctx['viewport']['h'] ?? '?' }}@endif
                                </span>
                            </summary>
                            <div class="dispatch-reference-body">
                                <div class="dispatch-meta-grid">
                                    @if (! empty($ctx['url']))
                                        <div><label class="dispatch-label">URL</label><div style="font-size:0.78rem; word-break: break-all;">{{ $ctx['url'] }}</div></div>
                                    @endif
                                    @if (! empty($ctx['viewport']))
                                        <div><label class="dispatch-label">Viewport</label><div style="font-size:0.78rem;">{{ $ctx['viewport']['w'] ?? '?' }}×{{ $ctx['viewport']['h'] ?? '?' }} (dpr {{ $ctx['viewport']['dpr'] ?? 1 }})</div></div>
                                    @endif
                                    @if (! empty($ctx['user_agent']))
                                        <div style="grid-column: 1 / -1;"><label class="dispatch-label">User agent</label><div style="font-size:0.72rem; color: var(--dispatch-text-muted); word-break: break-all;">{{ $ctx['user_agent'] }}</div></div>
                                    @endif
                                </div>

                                <div style="margin-top: 0.9rem;">
                                    <label class="dispatch-label">Console errors ({{ count($consoleErrors) }})</label>
                                    @if (empty($consoleErrors))
                                        <p style="font-size:0.78rem; color: var(--dispatch-text-muted); margin:0.3rem 0 0;">None captured.</p>
                                    @else
                                        <ul class="dispatch-errlist">
                                            @foreach (array_slice($consoleErrors, -10) as $err)
                                                <li>
                                                    <strong style="color: var(--dispatch-danger);">{{ $err['type'] ?? 'error' }}</strong>
                                                    <span>{{ $err['message'] ?? '' }}</span>
                                                    @if (! empty($err['source']))
                                                        <div style="color: var(--dispatch-text-faint); font-size:0.7rem;">{{ $err['source'] }}</div>
                                                    @endif
                                                    @if (! empty($err['stack']))
                                                        <pre style="white-space:pre-wrap; margin:0.3rem 0 0; font-size:0.68rem; color: var(--dispatch-text-muted);">{{ $err['stack'] }}</pre>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            </div>
                        </details>
                    @endif

                    {{--
                        Agent run metrics (staff-facing). The token / cost / tool footprint an
                        agent stamps at dispatch:done time under context.result.metrics (see
                        dispatch:metrics --stamp). Rendered ONLY once a run has been stamped, so
                        the panel's presence is the confirmation that metrics are captured and
                        stored — its absence means nothing has been stamped for this task yet.
                    --}}
                    @if ($showMetrics)
                        <details class="dispatch-reference-panel" wire:ignore.self>
                            <summary>
                                <strong>Agent run</strong>
                                <span class="dispatch-reference-gist">{{ $agentMetrics['total_tokens'] }} tokens · {{ $agentMetrics['cost'] }} · {{ $agentMetrics['duration'] }}</span>
                            </summary>
                            <div class="dispatch-reference-body">
                                <div class="dispatch-stat-row">
                                    <div class="dispatch-stat">
                                        <div class="dispatch-stat-value" title="{{ $agentMetrics['total_tokens_full'] }} tokens">{{ $agentMetrics['total_tokens'] }}</div>
                                        <div class="dispatch-stat-label">tokens · {{ $agentMetrics['cache_pct'] }} cached</div>
                                    </div>
                                    <div class="dispatch-stat">
                                        <div class="dispatch-stat-value">{{ $agentMetrics['cost'] }}</div>
                                        <div class="dispatch-stat-label">cost</div>
                                    </div>
                                    <div class="dispatch-stat">
                                        <div class="dispatch-stat-value">{{ $agentMetrics['duration'] }}</div>
                                        <div class="dispatch-stat-label">duration</div>
                                    </div>
                                    @if ($agentMetrics['touch_time'] !== null)
                                        <div class="dispatch-stat">
                                            <div class="dispatch-stat-value" title="{{ $agentMetrics['touch_time_title'] }}">{{ $agentMetrics['touch_time'] }}</div>
                                            <div class="dispatch-stat-label">est. human time ({{ $agentMetrics['touch_time_version'] }})</div>
                                        </div>
                                    @endif
                                    <div class="dispatch-stat">
                                        <div class="dispatch-stat-value">{{ $agentMetrics['tool_calls'] }}</div>
                                        <div class="dispatch-stat-label">tool calls</div>
                                    </div>
                                    <div class="dispatch-stat">
                                        <div class="dispatch-stat-value">{{ $agentMetrics['turns'] }}</div>
                                        <div class="dispatch-stat-label">turns</div>
                                    </div>
                                    <div class="dispatch-stat">
                                        <div class="dispatch-stat-value">{{ $agentMetrics['subagents'] }}</div>
                                        <div class="dispatch-stat-label">subagents</div>
                                    </div>
                                    @if ($agentMetrics['errors'] > 0)
                                        <div class="dispatch-stat">
                                            <div class="dispatch-stat-value" style="color: var(--dispatch-danger);">{{ $agentMetrics['errors'] }}</div>
                                            <div class="dispatch-stat-label">errors</div>
                                        </div>
                                    @endif
                                </div>

                                <div class="dispatch-meta-grid" style="margin-top:0.9rem;">
                                    <div><label class="dispatch-label">Input</label><div>{{ $agentMetrics['tokens']['input'] }}</div></div>
                                    <div><label class="dispatch-label">Output</label><div>{{ $agentMetrics['tokens']['output'] }}</div></div>
                                    <div><label class="dispatch-label">Cache read</label><div>{{ $agentMetrics['tokens']['cache_read'] }}</div></div>
                                    <div><label class="dispatch-label">Cache write</label><div>{{ $agentMetrics['tokens']['cache_creation'] }}</div></div>
                                </div>

                                @if (! empty($agentMetrics['tools']))
                                    <div style="margin-top:0.9rem;">
                                        <label class="dispatch-label">Tools</label>
                                        <div style="display:flex; flex-wrap:wrap; gap:0.3rem; margin-top:0.35rem;">
                                            @foreach ($agentMetrics['tools'] as $tool)
                                                <span class="dispatch-badge">{{ $tool['name'] }} · {{ $tool['count'] }}</span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                <div class="dispatch-meta-grid" style="margin-top:0.9rem;">
                                    @if (! empty($agentMetrics['models']))
                                        <div style="grid-column: 1 / -1;"><label class="dispatch-label">Models</label><div style="font-size:0.75rem; color: var(--dispatch-text-muted); word-break: break-all;">{{ implode(', ', $agentMetrics['models']) }}</div></div>
                                    @endif
                                    @if ($agentMetrics['commit'])
                                        <div><label class="dispatch-label">Commit</label><div style="font-family: monospace;">{{ $agentMetrics['commit'] }}</div></div>
                                    @endif
                                    <div><label class="dispatch-label">Window</label><div>{{ $agentMetrics['window_basis'] }}</div></div>
                                    <div><label class="dispatch-label">Transcript</label><div>{{ $agentMetrics['transcript_source'] }}</div></div>
                                </div>
                            </div>
                        </details>
                    @endif
                </div>
            @endif
        </div>

        {{-- ═══ Right rail: properties and routing (staff) ═══ --}}
        @if ($canEdit)
            <aside class="dispatch-show-rail">

                {{-- Meta editor (staff only, gated by the `update` policy ability) --}}
                <section class="dispatch-card dispatch-rail-card" id="dispatch-properties">
                    <div class="dispatch-card-head">
                        <h2 class="dispatch-section-title">Properties</h2>
                        <span class="dispatch-dirty" wire:dirty wire:target="status,statusNote,type,priority,assignee_choice,due_at,visibility,is_public,label_ids,editDescription">Unsaved changes</span>
                    </div>

                    @if (in_array('status', $hiddenControls, true) && ! empty($kindView['lock_reason']))
                    <div class="dispatch-field">
                        <label class="dispatch-label">Status</label>
                        <p style="font-size:0.8rem; margin:0.1rem 0 0;" data-dispatch-lock>🔒 {{ $kindView['lock_reason'] }}</p>
                    </div>
                    @endif
                    @unless (in_array('status', $hiddenControls, true))
                    <div class="dispatch-field">
                        <label class="dispatch-label">Status</label>
                        <select wire:model.live="status" class="dispatch-select">
                            @foreach ($statusLabels as $code => $label) <option value="{{ $code }}">{{ $label }}</option> @endforeach
                        </select>
                        {{-- TASK-1193: resolved = dealt with, but not as written; say what happened. --}}
                        @if ($status !== $task->status && $taskClass::requiresStatusNote($status))
                            <textarea wire:model="statusNote" rows="3" class="dispatch-textarea" style="margin-top:0.35rem;" placeholder="Required: what actually happened (done partly, differently, or the need went away)"></textarea>
                        @endif
                        @error('statusNote') <p class="dispatch-error">{{ $message }}</p> @enderror
                        @error('status') <p class="dispatch-error">{{ $message }}</p> @enderror
                    </div>
                    @endunless

                    <div class="dispatch-field-pair">
                        <div class="dispatch-field">
                            <label class="dispatch-label">Priority</label>
                            <select wire:model="priority" class="dispatch-select">
                                @foreach ($priorityLabels as $code => $label) <option value="{{ $code }}">{{ $label }}</option> @endforeach
                            </select>
                        </div>
                        <div class="dispatch-field">
                            <label class="dispatch-label">Type</label>
                            <select wire:model="type" class="dispatch-select">
                                @foreach ($typeLabels as $code => $label) <option value="{{ $code }}">{{ $label }}</option> @endforeach
                            </select>
                        </div>
                    </div>

                    @unless (in_array('assignee', $hiddenControls, true))
                    <div class="dispatch-field" style="margin-top:0.7rem;">
                        <label class="dispatch-label">Assignee</label>
                        <select wire:model="assignee_choice" class="dispatch-select">
                            <option value="">Unassigned</option>
                            @if (! empty($groupOptions))
                                <optgroup label="Teams">
                                    @foreach ($groupOptions as $g)
                                        <option value="group:{{ $g }}">Team {{ $g }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                            @foreach ($assigneeOptions as $u)
                                <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->email }})</option>
                            @endforeach
                        </select>
                        @error('assignee_choice') <p class="dispatch-error">{{ $message }}</p> @enderror
                    </div>
                    @endunless

                    <div class="dispatch-field-pair">
                        <div class="dispatch-field">
                            <label class="dispatch-label">Due date</label>
                            <input type="date" wire:model="due_at" class="dispatch-input">
                            @error('due_at') <p class="dispatch-error">{{ $message }}</p> @enderror
                        </div>
                        <div class="dispatch-field">
                            <label class="dispatch-label">Staff visibility</label>
                            <select wire:model="visibility" class="dispatch-select">
                                <option value="participants">Participants only</option>
                                <option value="staff">All staff</option>
                            </select>
                        </div>
                    </div>

                    <label class="dispatch-check" for="is_public">
                        <input type="checkbox" id="is_public" wire:model="is_public">
                        <span>Visible to submitter/customer</span>
                    </label>

                    <details class="dispatch-disclosure" wire:ignore.self style="margin-top:0.8rem;">
                        <summary>Labels ({{ count($label_ids) }} selected)</summary>
                        <div class="dispatch-disclosure-body dispatch-label-picker">
                            @foreach ($allLabels as $label)
                                <label class="dispatch-label-chip" wire:key="label-pick-{{ $label->id }}">
                                    <input type="checkbox" value="{{ $label->id }}" wire:model="label_ids">
                                    <span>{{ $label->name }}</span>
                                </label>
                            @endforeach
                        </div>
                    </details>

                    <div class="dispatch-savebar">
                        <span class="dispatch-hint" style="margin:0;" wire:loading wire:target="saveMeta">Saving…</span>
                        <span></span>
                        <button type="button" wire:click="saveMeta" wire:loading.attr="disabled" wire:target="saveMeta" class="dispatch-btn">
                            Save properties
                        </button>
                    </div>
                </section>

                {{-- TASK-997 part B — the ball: pass ("your turn") or ask ("I need this
                     from you, then it's back to me"). Staff (`update`-ability) only,
                     same gate as the meta editor and the Lane panel — but NOT gated on
                     $laneActive: with the inert NullLaneResolver bound, a "pass" simply
                     degrades to a plain reassignment (see
                     DispatchTaskService::sameLane()), so the panel stays useful on a
                     host that hasn't adopted lanes at all. Blocked-by/blocks moved to
                     the header, where dependencies read as high-priority context. --}}
                @if (! (in_array('pass', $hiddenControls, true) && in_array('ask', $hiddenControls, true)))
                    <section class="dispatch-card dispatch-rail-card" data-dispatch-control="handoff">
                        <h2 class="dispatch-section-title">Hand off</h2>

                        <div class="dispatch-field">
                            <select wire:model="handoffToUserId" class="dispatch-select" aria-label="Hand off to">
                                <option value="">Hand off to…</option>
                                @foreach ($handoffOptions as $opt)
                                    <option value="{{ $opt->id }}">{{ $opt->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        @if ($laneActive)
                            <div class="dispatch-field">
                                <select wire:model="handoffLaneChoice" class="dispatch-select" aria-label="Lane">
                                    <option value="">Lane (if ambiguous)…</option>
                                    @foreach (($allLaneOptions ?: $myLaneOptions) as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        <textarea wire:model="handoffNote" rows="2" placeholder="Note (optional) — becomes the hand-off's description, or rides the pass event" class="dispatch-textarea" style="margin-top:0.6rem;"></textarea>

                        @unless (in_array('ask', $hiddenControls, true) || in_array('pass', $hiddenControls, true))
                        <label class="dispatch-check">
                            <input type="checkbox" wire:model="handoffAsk">
                            <span>Ask (blocks this task instead of closing it)</span>
                        </label>
                        @endunless

                        <button type="button" wire:click="handoffTask" wire:loading.attr="disabled" wire:target="handoffTask" class="dispatch-btn is-secondary is-block" style="margin-top:0.7rem;">
                            {{ $handoffAsk ? 'Ask' : 'Hand off' }}
                        </button>
                        @error('handoffToUserId') <p class="dispatch-error">{{ $message }}</p> @enderror
                        @error('handoffLaneChoice') <p class="dispatch-error">{{ $message }}</p> @enderror
                    </section>
                @endif

                {{-- TASK-997 part A — lane routing actions. Staff (`update`-ability) only,
                     same gate as the meta editor: routing a task is not a new visibility
                     surface. Hidden entirely unless a real LaneResolver is bound (see
                     TaskShow::render()'s $laneActive) — nothing to offer against the inert
                     NullLaneResolver. "Claim for me" self-assigns and — only for an
                     UNROUTED task — auto-joins one of the claimer's lanes (a picker
                     appears when they work more than one); "Route to…" is the
                     admin/lane-member action that moves a task's lane WITHOUT assigning
                     it to anyone (see DispatchTaskService::claimForUser()/routeToLane()). --}}
                @if ($laneActive)
                    <section class="dispatch-card dispatch-rail-card">
                        <div class="dispatch-card-head">
                            <h2 class="dispatch-section-title">Lane</h2>
                            <span style="font-size:0.8rem; font-weight:600;">{{ $laneLabel ?? 'No department' }}</span>
                        </div>

                        @if (! empty($myLaneOptions) && ! in_array('claim', $hiddenControls, true))
                            <div class="dispatch-row">
                                @if ($task->lane === null && count($myLaneOptions) > 1)
                                    <select wire:model="claimLaneChoice" class="dispatch-select">
                                        <option value="">Pick a lane…</option>
                                        @foreach ($myLaneOptions as $key => $label)
                                            <option value="{{ $key }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <button type="button" wire:click="claimForSelf" wire:loading.attr="disabled" wire:target="claimForSelf" class="dispatch-btn {{ $task->lane === null && count($myLaneOptions) > 1 ? 'is-secondary' : 'is-secondary is-block' }}">
                                    Claim for me
                                </button>
                            </div>
                            @error('claimLaneChoice') <p class="dispatch-error">{{ $message }}</p> @enderror
                        @endif

                        @if ($canRouteLane || ($task->lane === null && ! empty($myLaneOptions)))
                            <div class="dispatch-row" style="margin-top:0.6rem;">
                                <select wire:model="routeLaneChoice" class="dispatch-select">
                                    <option value="">Route to…</option>
                                    @foreach (($canRouteLane ? $allLaneOptions : $myLaneOptions) as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <button type="button" wire:click="routeTask" wire:loading.attr="disabled" wire:target="routeTask" class="dispatch-btn is-secondary">
                                    Route
                                </button>
                            </div>
                            @error('routeLaneChoice') <p class="dispatch-error">{{ $message }}</p> @enderror
                        @endif
                    </section>
                @endif

                {{-- Watchers (W13-2): who's subscribed, plus "watch on behalf of" — the
                     picked teammate is immediately watching (no opt-in step; they decline
                     via Stop watching on their own visit). Pool = the assignable-users
                     seam, same as the assignee select. --}}
                <section class="dispatch-card dispatch-rail-card">
                    <div class="dispatch-card-head">
                        <h2 class="dispatch-section-title">Watchers</h2>
                        <span style="font-size:0.75rem; color: var(--dispatch-text-muted);">{{ $task->watchers->count() }}</span>
                    </div>
                    @if ($task->watchers->isNotEmpty())
                        <div class="dispatch-watchers">
                            @foreach ($task->watchers as $w)
                                <span class="dispatch-avatar-chip" wire:key="watcher-{{ $w->id }}">
                                    <span class="dispatch-avatar" aria-hidden="true">{{ $initials($w->name) }}</span>{{ $w->name }}
                                </span>
                            @endforeach
                        </div>
                    @else
                        <p style="font-size:0.78rem; color: var(--dispatch-text-muted); margin: 0 0 0.6rem;">No watchers yet.</p>
                    @endif
                    <div class="dispatch-row">
                        <select wire:model="ccUserId" class="dispatch-select" aria-label="Add a watcher">
                            <option value="">Add a watcher…</option>
                            @foreach ($assigneeOptions as $u)
                                <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->email }})</option>
                            @endforeach
                        </select>
                        <button type="button" wire:click="addWatcher" wire:loading.attr="disabled" wire:target="addWatcher" class="dispatch-btn is-secondary" title="Watch on their behalf">Add</button>
                    </div>
                    @error('ccUserId') <p class="dispatch-error">{{ $message }}</p> @enderror
                    <p class="dispatch-hint">
                        Watch on their behalf: they start watching immediately and get a heads-up; they can adjust their notifications or stop watching themselves.
                    </p>
                </section>

                {{-- Mark-as-duplicate / merge control (staff `delete` ability — distinct
                     from canEdit()'s `update` ability, so it's gated independently). --}}
                @can('delete', $task)
                    <section class="dispatch-card dispatch-rail-card">
                        <details class="dispatch-disclosure dispatch-rail-danger" wire:ignore.self style="margin:0;">
                            <summary>Mark as duplicate</summary>
                            <div class="dispatch-disclosure-body">
                                <p class="dispatch-hint" style="margin:0 0 0.6rem;">
                                    Merge this task into another as its duplicate. Comments and attachments move to the target task; this task is closed and redirected there.
                                </p>
                                <div class="dispatch-row">
                                    <input type="text" wire:model="mergeTargetCode" class="dispatch-input" placeholder="Target task code, e.g. TASK-004">
                                    <button type="button" wire:click="mergeInto" wire:loading.attr="disabled" wire:target="mergeInto" class="dispatch-btn is-secondary">Merge</button>
                                </div>
                                @error('mergeTargetCode') <p class="dispatch-error">{{ $message }}</p> @enderror
                            </div>
                        </details>
                    </section>
                @endcan
            </aside>
        @else
            {{-- Non-staff: no rail. A lone merge control can still apply to a
                 `delete`-able viewer that can't `update` — keep it reachable. --}}
            @can('delete', $task)
                <aside class="dispatch-show-rail">
                    <section class="dispatch-card dispatch-rail-card">
                        <h2 class="dispatch-section-title">Mark as duplicate</h2>
                        <p class="dispatch-hint" style="margin:0 0 0.6rem;">
                            Merge this task into another as its duplicate. Comments and attachments move to the target task; this task is closed and redirected there.
                        </p>
                        <div class="dispatch-row">
                            <input type="text" wire:model="mergeTargetCode" class="dispatch-input" placeholder="Target task code, e.g. TASK-004">
                            <button type="button" wire:click="mergeInto" wire:loading.attr="disabled" wire:target="mergeInto" class="dispatch-btn is-secondary">Merge</button>
                        </div>
                        @error('mergeTargetCode') <p class="dispatch-error">{{ $message }}</p> @enderror
                    </section>
                </aside>
            @endcan
        @endif
    </div>
</div>
