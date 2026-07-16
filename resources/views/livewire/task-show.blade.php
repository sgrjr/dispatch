<div>
    <style>
        .dispatch-show-head { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 1rem; }
        .dispatch-show-code { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--dispatch-accent); }
        .dispatch-show-title { font-size: 1.4rem; font-weight: 700; margin: 0.2rem 0 0.6rem; }
        .dispatch-show-badges { display: flex; flex-wrap: wrap; gap: 0.35rem; align-items: center; }
        .dispatch-show-side { text-align: right; font-size: 0.75rem; color: var(--dispatch-text-muted); }
        .dispatch-show-desc {
            margin-top: 1rem;
            background: var(--dispatch-surface-muted);
            border: 1px solid var(--dispatch-border);
            border-radius: var(--dispatch-radius-md);
            padding: 0.9rem;
            line-height: 1.55;
        }
        .dispatch-show-desc :first-child { margin-top: 0; }
        .dispatch-show-desc :last-child { margin-bottom: 0; }
        .dispatch-section-title { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--dispatch-text-muted); margin: 0 0 0.75rem; }
        .dispatch-meta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: 0.9rem; }
        .dispatch-label-picker { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.5rem; }
        .dispatch-label-chip { display: inline-flex; align-items: center; gap: 0.35rem; border: 1px solid var(--dispatch-border); border-radius: var(--dispatch-radius-pill); padding: 0.25rem 0.6rem; font-size: 0.72rem; font-weight: 600; cursor: pointer; }
        .dispatch-gallery { display: flex; flex-wrap: wrap; gap: 0.6rem; margin-top: 0.75rem; }
        .dispatch-gallery-thumb { width: 6rem; height: 6rem; border-radius: var(--dispatch-radius-sm); overflow: hidden; border: 1px solid var(--dispatch-border); display: block; }
        .dispatch-gallery-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .dispatch-file-row { display: flex; align-items: center; gap: 0.5rem; padding: 0.4rem 0.6rem; border: 1px solid var(--dispatch-border); border-radius: var(--dispatch-radius-sm); font-size: 0.78rem; }
    </style>

    {{-- Header --}}
    <section class="dispatch-card">
        <div class="dispatch-show-head">
            <div style="min-width:0; flex:1;">
                <p class="dispatch-show-code">{{ $task->code }}</p>
                <h1 class="dispatch-show-title">{{ $task->title }}</h1>
                <div class="dispatch-show-badges">
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
            <div class="dispatch-show-side">
                <p style="margin:0;">Submitted by: <strong>{{ $task->submitter?->name ?? '—' }}</strong></p>
                <p style="margin:0;">Assignee: <strong>{{ $task->assignee?->name ?? '—' }}</strong></p>
                @if ($task->due_at)
                    <p style="margin:0;">Due: <strong>{{ $task->due_at->toFormattedDateString() }}</strong> ({{ $task->due_at->diffForHumans() }})</p>
                @endif
                <p style="margin:0;">{{ $task->updated_at?->diffForHumans() }}</p>
                @can('watch', $task)
                    <div style="margin-top:0.5rem;">
                        @if ($task->isWatchedBy(auth()->id()))
                            <button type="button" wire:click="unwatch" wire:loading.attr="disabled" wire:target="unwatch" class="dispatch-btn is-secondary">Unwatch</button>
                        @else
                            <button type="button" wire:click="watch" wire:loading.attr="disabled" wire:target="watch" class="dispatch-btn is-secondary">Watch</button>
                        @endif
                    </div>
                @endcan
            </div>
        </div>

        @if ($task->description)
            <div class="dispatch-show-desc">{!! \Sgrjr\Dispatch\Support\Markdown::render($task->description) !!}</div>
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
            @if ($task->attachments->where('is_image', false)->isNotEmpty())
                <div class="dispatch-gallery" style="flex-direction: column; align-items: stretch;">
                    @foreach ($task->attachments->where('is_image', false) as $attachment)
                        <a href="{{ route('dispatch.attachments.download', $attachment) }}" class="dispatch-file-row">
                            📎 {{ $attachment->original_name }}
                            <span style="color: var(--dispatch-text-faint); margin-left:auto;">{{ number_format($attachment->size_bytes / 1024, 1) }} KB</span>
                        </a>
                    @endforeach
                </div>
            @endif
        @endif
    </section>

    {{-- Client diagnostics captured with the report (staff-facing). --}}
    @if ($this->canEdit() && ! empty($task->context))
        @php($ctx = $task->context)
        @php($consoleErrors = $ctx['console_errors'] ?? [])
        <section class="dispatch-card" style="margin-top: 1rem;">
            <h2 class="dispatch-section-title">Diagnostics</h2>
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
                    <ul style="list-style:none; margin:0.4rem 0 0; padding:0; display:flex; flex-direction:column; gap:0.4rem;">
                        @foreach (array_slice($consoleErrors, -10) as $err)
                            <li style="border:1px solid var(--dispatch-border); border-radius: var(--dispatch-radius-sm); padding:0.4rem 0.6rem; font-size:0.75rem;">
                                <strong style="color:#c0392b;">{{ $err['type'] ?? 'error' }}</strong>
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
        </section>
    @endif

    {{-- Meta editor (staff only, gated by the `update` policy ability) --}}
    @if ($this->canEdit())
        <section class="dispatch-card" style="margin-top: 1rem;">
            <h2 class="dispatch-section-title">Task properties</h2>
            <div class="dispatch-meta-grid">
                <div>
                    <label class="dispatch-label">Status</label>
                    <select wire:model="status" class="dispatch-select">
                        @foreach ($statusLabels as $code => $label) <option value="{{ $code }}">{{ $label }}</option> @endforeach
                    </select>
                </div>
                <div>
                    <label class="dispatch-label">Type</label>
                    <select wire:model="type" class="dispatch-select">
                        @foreach ($typeLabels as $code => $label) <option value="{{ $code }}">{{ $label }}</option> @endforeach
                    </select>
                </div>
                <div>
                    <label class="dispatch-label">Priority</label>
                    <select wire:model="priority" class="dispatch-select">
                        @foreach ($priorityLabels as $code => $label) <option value="{{ $code }}">{{ $label }}</option> @endforeach
                    </select>
                </div>
                <div>
                    <label class="dispatch-label">Assignee</label>
                    <select wire:model="assignee_user_id" class="dispatch-select">
                        <option value="">Unassigned</option>
                        @foreach ($assigneeOptions as $u)
                            <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->email }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="dispatch-label">Due date</label>
                    <input type="date" wire:model="due_at" class="dispatch-input">
                    @error('due_at') <p class="dispatch-error">{{ $message }}</p> @enderror
                </div>
                <div style="display:flex; align-items:center; gap:0.5rem;">
                    <input type="checkbox" id="is_public" wire:model="is_public">
                    <label for="is_public" class="dispatch-label" style="margin:0; cursor:pointer;">Visible to submitter/customer</label>
                </div>
            </div>

            <div style="margin-top: 0.9rem;">
                <label class="dispatch-label">Description</label>
                <textarea wire:model="editDescription" rows="6" class="dispatch-textarea" placeholder="Details, steps to reproduce, links…"></textarea>
                @error('editDescription') <p class="dispatch-error">{{ $message }}</p> @enderror
            </div>

            <div style="margin-top: 0.9rem;">
                <label class="dispatch-label">Labels</label>
                <div class="dispatch-label-picker">
                    @foreach ($allLabels as $label)
                        <label class="dispatch-label-chip">
                            <input type="checkbox" value="{{ $label->id }}" wire:model="label_ids">
                            <span>{{ $label->name }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div style="margin-top: 1rem; display:flex; justify-content:flex-end;">
                <button type="button" wire:click="saveMeta" wire:loading.attr="disabled" wire:target="saveMeta" class="dispatch-btn">
                    Save properties
                </button>
            </div>
        </section>
    @endif

    {{-- Mark-as-duplicate / merge control (staff `delete` ability — distinct
         from canEdit()'s `update` ability, so it's gated independently). --}}
    @can('delete', $task)
        <section class="dispatch-card" style="margin-top: 1rem;">
            <h2 class="dispatch-section-title">Mark as duplicate</h2>
            <p style="font-size:0.78rem; color: var(--dispatch-text-muted); margin: 0 0 0.6rem;">
                Merge this task into another as its duplicate. Comments and attachments move to the target task; this task is closed and redirected there.
            </p>
            <div style="display:flex; gap:0.5rem; max-width:22rem;">
                <input type="text" wire:model="mergeTargetCode" class="dispatch-input" placeholder="Target task code, e.g. TASK-004">
                <button type="button" wire:click="mergeInto" wire:loading.attr="disabled" wire:target="mergeInto" class="dispatch-btn is-secondary">Merge</button>
            </div>
            @error('mergeTargetCode') <p class="dispatch-error">{{ $message }}</p> @enderror
        </section>
    @endcan

    {{-- Comment thread --}}
    <div style="margin-top: 1rem;">
        <livewire:dispatch-thread :task="$task" :key="'task-thread-'.$task->id" />
    </div>
</div>
