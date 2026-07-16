<section class="dispatch-card">
    <style>
        .dispatch-thread-list { list-style: none; margin: 0 0 1rem; padding: 0; display: flex; flex-direction: column; gap: 0.6rem; }
        .dispatch-thread-item { border: 1px solid var(--dispatch-border); border-radius: var(--dispatch-radius-md); padding: 0.7rem 0.9rem; font-size: 0.82rem; background: var(--dispatch-surface); }
        .dispatch-thread-item.is-internal { background: var(--dispatch-warning-bg); border-color: var(--dispatch-warning); }
        .dispatch-thread-item.is-system { background: var(--dispatch-surface-muted); color: var(--dispatch-text-muted); }
        .dispatch-thread-item-head { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.02em; }
        .dispatch-thread-body { margin-top: 0.4rem; white-space: pre-wrap; }
        .dispatch-thread-attachments { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.5rem; }
        .dispatch-thread-attachments img { width: 4rem; height: 4rem; object-fit: cover; border-radius: var(--dispatch-radius-sm); border: 1px solid var(--dispatch-border); }
    </style>

    <h2 class="dispatch-section-title">Discussion &amp; activity</h2>

    <ul class="dispatch-thread-list">
        @forelse ($comments as $c)
            @php $isSystem = $c->isSystem(); @endphp
            <li @class([
                'dispatch-thread-item',
                'is-internal' => !$isSystem && $c->is_internal,
                'is-system' => $isSystem,
            ])>
                <div class="dispatch-thread-item-head">
                    <span>
                        <strong>{{ $c->user?->name ?? ($isSystem ? 'System' : 'Anonymous') }}</strong>
                        @if ($isSystem)
                            <span class="dispatch-badge">{{ str_replace('_', ' ', $c->event_type) }}</span>
                        @endif
                        @if ($c->is_internal)
                            <span class="dispatch-badge is-warning">internal</span>
                        @endif
                    </span>
                    <span style="color: var(--dispatch-text-faint); text-transform:none;">{{ $c->created_at?->diffForHumans() }}</span>
                </div>
                @if ($c->body !== '')
                    <div class="dispatch-thread-body">{{ $c->body }}</div>
                @endif
                @if ($c->attachments->isNotEmpty())
                    <div class="dispatch-thread-attachments">
                        @foreach ($c->attachments as $attachment)
                            @if ($attachment->is_image)
                                <a href="{{ route('dispatch.attachments.download', $attachment) }}" data-dispatch-lightbox data-dispatch-lightbox-src="{{ route('dispatch.attachments.download', $attachment) }}">
                                    <img src="{{ route('dispatch.attachments.download', $attachment) }}" alt="{{ $attachment->original_name }}" loading="lazy">
                                </a>
                            @else
                                <a href="{{ route('dispatch.attachments.download', $attachment) }}" class="dispatch-file-row">📎 {{ $attachment->original_name }}</a>
                            @endif
                        @endforeach
                    </div>
                @endif
            </li>
        @empty
            <li class="dispatch-empty">No comments yet. Start the conversation.</li>
        @endforelse
    </ul>

    @if ($canComment)
        <div>
            <label for="task-comment-body" class="dispatch-label">Add a comment</label>
            <textarea
                id="task-comment-body"
                wire:model.blur="body"
                rows="3"
                class="dispatch-textarea"
                placeholder="What's the update?"
                data-dispatch-paste="newAttachments"
                data-dispatch-dropzone="newAttachments"
            ></textarea>
            @error('body') <p class="dispatch-error">{{ $message }}</p> @enderror

            @if (!empty($newAttachments))
                <div class="dispatch-attach-list">
                    @foreach ($newAttachments as $index => $file)
                        <span class="dispatch-attach-chip">
                            {{ $file->getClientOriginalName() }}
                            <button type="button" wire:click="removeAttachment({{ $index }})">&times;</button>
                        </span>
                    @endforeach
                </div>
            @endif

            <div style="display:flex; align-items:center; justify-content:space-between; gap:0.5rem; margin-top:0.5rem;">
                @if ($canCommentInternal)
                    <label style="display:flex; align-items:center; gap:0.4rem; font-size:0.72rem; font-weight:600; color: var(--dispatch-warning);">
                        <input type="checkbox" wire:model="is_internal">
                        Internal — hide from submitter
                    </label>
                @else
                    <span></span>
                @endif
                <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="dispatch-btn">Post comment</button>
            </div>
        </div>
    @endif
</section>
