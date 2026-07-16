<div>
    <style>
        .dispatch-form-grid { display: grid; gap: 1rem; }
        .dispatch-form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: 1rem; }
        .dispatch-attach-list { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem; }
        .dispatch-attach-chip { display: inline-flex; align-items: center; gap: 0.4rem; border: 1px solid var(--dispatch-border); border-radius: var(--dispatch-radius-sm); padding: 0.3rem 0.5rem; font-size: 0.72rem; }
        .dispatch-attach-chip button { border: none; background: none; color: var(--dispatch-danger); cursor: pointer; font-weight: 700; }
        .dispatch-dropzone {
            border: 2px dashed var(--dispatch-border);
            border-radius: var(--dispatch-radius-md);
            padding: 1rem;
            text-align: center;
            font-size: 0.78rem;
            color: var(--dispatch-text-muted);
        }
        .dispatch-dropzone.is-drag-over { border-color: var(--dispatch-accent); color: var(--dispatch-accent); }
    </style>

    <section class="dispatch-card">
        <h1 class="dispatch-section-title" style="font-size:1rem; color: var(--dispatch-text);">New task</h1>

        <form wire:submit="save" class="dispatch-form-grid">
            <div>
                <label class="dispatch-label">Title</label>
                <input type="text" wire:model.blur="title" maxlength="255" class="dispatch-input" placeholder="Short summary…">
                @error('title') <p class="dispatch-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="dispatch-label">Description</label>
                <textarea wire:model.blur="description" rows="6" class="dispatch-textarea" placeholder="Details, steps to reproduce, links…" data-dispatch-paste="newAttachments" data-dispatch-dropzone="newAttachments"></textarea>
                @error('description') <p class="dispatch-error">{{ $message }}</p> @enderror
                <p style="font-size:0.7rem; color: var(--dispatch-text-faint); margin-top:0.25rem;">
                    Paste or drag an image/file into the description box to attach it.
                </p>
            </div>

            <div class="dispatch-form-row">
                <div>
                    <label class="dispatch-label">Type</label>
                    <select wire:model="type" class="dispatch-select">
                        @foreach ($types as $t) <option value="{{ $t }}">{{ $t }}</option> @endforeach
                    </select>
                </div>
                <div>
                    <label class="dispatch-label">Priority</label>
                    <select wire:model="priority" class="dispatch-select">
                        @foreach ($priorities as $p) <option value="{{ $p }}">{{ $p }}</option> @endforeach
                    </select>
                </div>
            </div>

            <div>
                <label class="dispatch-label">Labels</label>
                <div class="dispatch-label-picker">
                    @foreach ($existingLabels as $label)
                        <label class="dispatch-label-chip">
                            <input type="checkbox" @checked(in_array($label->name, $labelNames, true)) wire:click="toggleLabel('{{ $label->name }}')">
                            <span>{{ $label->name }}</span>
                        </label>
                    @endforeach
                </div>
                <div style="display:flex; gap:0.5rem; margin-top:0.5rem; max-width:20rem;">
                    <input type="text" wire:model="labelInput" wire:keydown.enter.prevent="addLabelFromInput" class="dispatch-input" placeholder="Add a label…">
                    <button type="button" wire:click="addLabelFromInput" class="dispatch-btn is-secondary">Add</button>
                </div>
                @if (!empty($labelNames))
                    <div class="dispatch-attach-list">
                        @foreach ($labelNames as $name)
                            <span class="dispatch-attach-chip">
                                {{ $name }}
                                <button type="button" wire:click="toggleLabel('{{ $name }}')">&times;</button>
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>

            <div style="display:flex; align-items:center; gap:0.5rem;">
                <input type="checkbox" id="is_public" wire:model="is_public">
                <label for="is_public" class="dispatch-label" style="margin:0; cursor:pointer;">Visible to submitter/customer</label>
            </div>

            <div>
                <label class="dispatch-label">Attachments</label>
                <div class="dispatch-dropzone" data-dispatch-dropzone="newAttachments" data-dispatch-paste="newAttachments">
                    Drop screenshots or files here, or paste from the clipboard.
                </div>
                @error('newAttachments.*') <p class="dispatch-error">{{ $message }}</p> @enderror
                <div wire:loading wire:target="newAttachments" style="font-size:0.72rem; color: var(--dispatch-text-muted); margin-top:0.3rem;">Uploading…</div>
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
            </div>

            <div style="display:flex; justify-content:flex-end; gap:0.5rem;">
                <button type="submit" wire:loading.attr="disabled" wire:target="save" class="dispatch-btn">Create task</button>
            </div>
        </form>
    </section>
</div>
