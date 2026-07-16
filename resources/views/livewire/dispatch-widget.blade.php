<div x-data='{
        trigger: null,
        focusFirstField() {
            this.$nextTick(() => {
                var target = this.$refs.dwFirstField || this.$refs.dwModal
                if (target) {
                    target.focus()
                }
            })
        },
        restoreFocus() {
            if (this.trigger) {
                this.trigger.focus()
                this.trigger = null
            }
        },
        trapFocus(event) {
            var modal = this.$refs.dwModal
            if (!modal) {
                return
            }
            var selector = "button, [href], input, select, textarea, [tabindex]:not([tabindex=\"-1\"])"
            var nodes = modal.querySelectorAll(selector)
            var list = Array.prototype.filter.call(nodes, function (el) {
                return !el.disabled && el.offsetParent !== null
            })
            if (!list.length) {
                return
            }
            var first = list[0]
            var last = list[list.length - 1]
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault()
                last.focus()
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault()
                first.focus()
            }
        }
    }'>
    @if (config('dispatch.widget.enabled', true))
        <style>
            .dispatch-widget-fab {
                position: fixed;
                right: 1.25rem;
                bottom: 1.25rem;
                z-index: 9998;
                border-radius: var(--dispatch-radius-pill);
                background: var(--dispatch-accent);
                color: var(--dispatch-accent-contrast);
                border: none;
                padding: 0.65rem 1.1rem;
                font-weight: 700;
                font-size: 0.8rem;
                box-shadow: 0 6px 16px rgba(15, 23, 42, 0.25);
                cursor: pointer;
            }
            .dispatch-widget-fab:hover { background: var(--dispatch-accent-hover); }
            .dispatch-widget-overlay {
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, 0.5);
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 1rem;
                z-index: 9999;
            }
            .dispatch-widget-modal {
                background: var(--dispatch-surface);
                border-radius: var(--dispatch-radius-lg);
                max-width: 28rem;
                width: 100%;
                padding: 1.25rem;
                box-shadow: var(--dispatch-shadow);
            }
            .dispatch-widget-success { text-align: center; padding: 1rem 0; }
            .dispatch-sr-only {
                position: absolute;
                width: 1px;
                height: 1px;
                padding: 0;
                margin: -1px;
                overflow: hidden;
                clip: rect(0, 0, 0, 0);
                white-space: nowrap;
                border: 0;
            }
        </style>

        {{-- x-init re-fires each time this overlay enters the DOM (i.e. each
             time $open flips true), capturing the CURRENT page's URL from the
             browser into the $wire-bound pageUrl property — this is how the
             server-side component learns "what page was I reported from"
             without the host app having to pass it in as a prop. It also moves
             focus into the dialog (focusFirstField(), defined on the root
             x-data above) each time the modal opens. --}}
        <button type="button" wire:click="openModal" x-on:click="trigger = $el" class="dispatch-widget-fab" aria-label="Report a bug or suggest a feature">
            Report a bug / idea
        </button>

        @if ($open)
            <div
                class="dispatch-widget-overlay"
                wire:click="closeModal"
                x-init="$wire.pageUrl = window.location.href; focusFirstField()"
                x-on:click="restoreFocus()"
                x-on:keydown.escape.window="restoreFocus(); $wire.closeModal()"
            >
                <div
                    class="dispatch-widget-modal"
                    wire:click.stop
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="dispatch-widget-modal-title"
                    x-ref="dwModal"
                    tabindex="-1"
                    x-on:keydown.tab="trapFocus($event)"
                >
                    {{-- Persistent (not conditionally mounted) so screen readers
                         reliably pick up the announcement when its text changes. --}}
                    <div aria-live="polite" class="dispatch-sr-only">
                        @if ($createdCode)
                            Thanks — tracked as {{ $createdCode }}.
                        @elseif ($errors->any())
                            There are errors in the form. Please review and try again.
                        @endif
                    </div>

                    @if ($createdCode)
                        <div class="dispatch-widget-success">
                            <p id="dispatch-widget-modal-title" style="font-weight:700;">Thanks — tracked as {{ $createdCode }}.</p>
                            <button type="button" wire:click="closeModal" x-on:click="restoreFocus()" class="dispatch-btn" style="margin-top:0.75rem;">Close</button>
                        </div>
                    @else
                        <h2 id="dispatch-widget-modal-title" class="dispatch-section-title" style="color: var(--dispatch-text); font-size:1rem;">Report a bug or suggest a feature</h2>
                        <form wire:submit="submit" style="display:grid; gap:0.75rem;">
                            <div>
                                <label for="dispatch-widget-type" class="dispatch-label">Type</label>
                                <select id="dispatch-widget-type" wire:model="type" class="dispatch-select">
                                    <option value="bug">Bug</option>
                                    <option value="feature">Feature suggestion</option>
                                </select>
                            </div>
                            <div>
                                <label for="dispatch-widget-title" class="dispatch-label">Title</label>
                                <input id="dispatch-widget-title" x-ref="dwFirstField" type="text" wire:model.blur="title" maxlength="255" class="dispatch-input" placeholder="Short summary…">
                                @error('title') <p class="dispatch-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="dispatch-widget-description" class="dispatch-label">Description</label>
                                <textarea
                                    id="dispatch-widget-description"
                                    wire:model.blur="description"
                                    rows="4"
                                    class="dispatch-textarea"
                                    placeholder="What happened? What did you expect?"
                                    data-dispatch-paste="screenshots"
                                    data-dispatch-dropzone="screenshots"
                                ></textarea>
                                @error('description') <p class="dispatch-error">{{ $message }}</p> @enderror
                            </div>
                            <div class="dispatch-dropzone" data-dispatch-dropzone="screenshots" data-dispatch-paste="screenshots">
                                Paste or drop a screenshot here.
                            </div>
                            @error('screenshots.*') <p class="dispatch-error">{{ $message }}</p> @enderror
                            @if (!empty($screenshots))
                                <div class="dispatch-attach-list">
                                    @foreach ($screenshots as $index => $file)
                                        <span class="dispatch-attach-chip">
                                            {{ $file->getClientOriginalName() }}
                                            <button type="button" wire:click="removeScreenshot({{ $index }})" aria-label="Remove {{ $file->getClientOriginalName() }}">&times;</button>
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                            <div style="display:flex; justify-content:flex-end; gap:0.5rem;">
                                <button type="button" wire:click="closeModal" x-on:click="restoreFocus()" class="dispatch-btn is-secondary">Cancel</button>
                                <button type="submit" wire:loading.attr="disabled" wire:target="submit" class="dispatch-btn">Send</button>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        @endif
    @endif
</div>
