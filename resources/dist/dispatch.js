/**
 * sgrjr/dispatch — vanilla JS glue for the Livewire UI.
 *
 * No external dependencies (no SortableJS, no jQuery). Everything is wired
 * with native HTML5 Drag & Drop, the Clipboard API, and Livewire 3's public
 * JS API (`Livewire.find(id)` -> a `$wire` proxy with `.call()`, `.upload()`,
 * `.uploadMultiple()`).
 *
 * Every listener is attached ONCE at the `document` level and dispatches by
 * inspecting `event.target` with `.closest(selector)`. This "event
 * delegation" approach means nothing needs to be re-bound after Livewire
 * morphs the DOM on a re-render (the classic pain point with libraries like
 * SortableJS that attach listeners to specific elements) — the listeners
 * live on `document`, which never goes away.
 *
 * Markup contract (see the `dispatch::livewire.*` views for real usage):
 *   - Kanban board:
 *       column: [data-dispatch-column][data-status="open"]
 *       card:   [data-dispatch-card][data-task-id="123"][draggable="true"]
 *   - Paste-to-upload (image/file pasted while an element is focused):
 *       [data-dispatch-paste="propertyName"]   (propertyName = a public
 *       array property on the owning Livewire component using WithFileUploads)
 *   - Drag-and-drop file upload:
 *       [data-dispatch-dropzone="propertyName"]
 *   - Attachment lightbox:
 *       [data-dispatch-lightbox][data-dispatch-lightbox-src="/download/url"]
 *
 * All handlers are defensive: missing elements, a missing `window.Livewire`,
 * or a component that doesn't expose the expected method are all silent
 * no-ops rather than thrown errors.
 */
(function () {
    'use strict';

    /**
     * Resolve the Livewire `$wire` proxy for the component that owns `el`,
     * by walking up to the nearest `wire:id` root — exactly the pattern the
     * Dispatch contract documents:
     *   Livewire.find(el.closest('[wire\\:id]').getAttribute('wire:id'))
     */
    function findWire(el) {
        if (typeof window.Livewire === 'undefined' || !el || typeof el.closest !== 'function') {
            return null;
        }

        var root = el.closest('[wire\\:id]');
        if (!root) {
            return null;
        }

        var id = root.getAttribute('wire:id');
        if (!id) {
            return null;
        }

        try {
            return window.Livewire.find(id) || null;
        } catch (e) {
            return null;
        }
    }

    // =========================================================================
    // 1) Kanban board drag-and-drop
    // =========================================================================

    var draggingCard = null;

    document.addEventListener('dragstart', function (e) {
        var card = e.target.closest('[data-dispatch-card]');
        if (!card) {
            return;
        }

        draggingCard = card;
        card.classList.add('is-dragging');
        e.dataTransfer.effectAllowed = 'move';

        // Firefox requires data to be set on the drag event for the drag to
        // start at all; the actual payload is read from `draggingCard` on
        // drop, not from dataTransfer (simpler, and avoids serialization).
        try {
            e.dataTransfer.setData('text/plain', card.getAttribute('data-task-id') || '');
        } catch (err) {
            // Some browsers throw if setData is called at the wrong time —
            // harmless, the drag still works via `draggingCard`.
        }
    });

    document.addEventListener('dragend', function (e) {
        var card = e.target.closest('[data-dispatch-card]');
        if (card) {
            card.classList.remove('is-dragging');
        }
        draggingCard = null;

        var columns = document.querySelectorAll('[data-dispatch-column].is-drag-over');
        for (var i = 0; i < columns.length; i++) {
            columns[i].classList.remove('is-drag-over');
        }
    });

    document.addEventListener('dragover', function (e) {
        var column = e.target.closest('[data-dispatch-column]');
        if (!column || !draggingCard) {
            return; // Not a kanban column, or not one of our card drags.
        }

        e.preventDefault(); // Required for the element to accept a drop.
        e.dataTransfer.dropEffect = 'move';
        column.classList.add('is-drag-over');

        // Live-reorder the DOM as the user drags, so the drop target is
        // visually obvious. Purely cosmetic — the server-side position is
        // recomputed authoritatively in TaskBoard::moveCard() on drop.
        var afterElement = cardAfterPoint(column, e.clientY);
        if (afterElement == null) {
            column.appendChild(draggingCard);
        } else if (afterElement !== draggingCard) {
            column.insertBefore(draggingCard, afterElement);
        }
    });

    document.addEventListener('dragleave', function (e) {
        var column = e.target.closest('[data-dispatch-column]');
        if (column && !column.contains(e.relatedTarget)) {
            column.classList.remove('is-drag-over');
        }
    });

    document.addEventListener('drop', function (e) {
        var column = e.target.closest('[data-dispatch-column]');
        if (!column || !draggingCard) {
            return;
        }

        e.preventDefault();
        column.classList.remove('is-drag-over');

        var taskId = draggingCard.getAttribute('data-task-id');
        var toStatus = column.getAttribute('data-status');
        var siblings = Array.prototype.slice.call(column.querySelectorAll('[data-dispatch-card]'));
        var toPosition = siblings.indexOf(draggingCard);

        var wire = findWire(column);
        if (wire && typeof wire.call === 'function' && taskId && toStatus && toPosition > -1) {
            wire.call('moveCard', parseInt(taskId, 10), toStatus, toPosition);
        }
    });

    /**
     * Given a column and a vertical cursor position, find the card the
     * dragged item should be inserted BEFORE (or null to append at the end).
     */
    function cardAfterPoint(column, y) {
        var cards = Array.prototype.slice.call(
            column.querySelectorAll('[data-dispatch-card]:not(.is-dragging)')
        );

        var closest = { offset: -Infinity, element: null };
        for (var i = 0; i < cards.length; i++) {
            var box = cards[i].getBoundingClientRect();
            var offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closest.offset) {
                closest = { offset: offset, element: cards[i] };
            }
        }
        return closest.element;
    }

    // =========================================================================
    // 2) Paste-to-upload
    // =========================================================================
    // When an image (or any file) is pasted while a [data-dispatch-paste]
    // element has focus, convert the clipboard item(s) to File objects and
    // hand them to the owning Livewire component's uploadMultiple() for the
    // named property — which is exactly what a WithFileUploads component's
    // <input type="file" multiple wire:model="prop"> would produce.

    document.addEventListener('paste', function (e) {
        var active = document.activeElement;
        var host = active && active.closest ? active.closest('[data-dispatch-paste]') : null;
        if (!host) {
            return;
        }

        var clipboardData = e.clipboardData || window.clipboardData;
        var items = clipboardData && clipboardData.items;
        if (!items) {
            return;
        }

        var files = [];
        for (var i = 0; i < items.length; i++) {
            if (items[i].kind === 'file') {
                var file = items[i].getAsFile();
                if (file) {
                    files.push(file);
                }
            }
        }

        if (files.length === 0) {
            return; // Plain text paste — let the browser handle it normally.
        }

        e.preventDefault();
        uploadFilesFor(host, files);
    });

    // =========================================================================
    // 3) Drag-and-drop file upload onto a dropzone
    // =========================================================================

    document.addEventListener('dragover', function (e) {
        var zone = e.target.closest('[data-dispatch-dropzone]');
        if (!zone || !dataTransferCarriesFiles(e.dataTransfer)) {
            return; // Leave kanban card drags (handled above) alone.
        }
        e.preventDefault();
        zone.classList.add('is-drag-over');
    });

    document.addEventListener('dragleave', function (e) {
        var zone = e.target.closest('[data-dispatch-dropzone]');
        if (zone && !zone.contains(e.relatedTarget)) {
            zone.classList.remove('is-drag-over');
        }
    });

    document.addEventListener('drop', function (e) {
        var zone = e.target.closest('[data-dispatch-dropzone]');
        if (!zone || !e.dataTransfer || !e.dataTransfer.files || e.dataTransfer.files.length === 0) {
            return;
        }
        e.preventDefault();
        zone.classList.remove('is-drag-over');
        uploadFilesFor(zone, Array.prototype.slice.call(e.dataTransfer.files));
    });

    function dataTransferCarriesFiles(dataTransfer) {
        if (!dataTransfer || !dataTransfer.types) {
            return false;
        }
        for (var i = 0; i < dataTransfer.types.length; i++) {
            if (dataTransfer.types[i] === 'Files') {
                return true;
            }
        }
        return false;
    }

    /**
     * Upload `files` to the Livewire component owning `host`, into the
     * public property named by its data-dispatch-paste/-dropzone attribute.
     */
    function uploadFilesFor(host, files) {
        var property = host.getAttribute('data-dispatch-paste') || host.getAttribute('data-dispatch-dropzone');
        if (!property) {
            return;
        }

        var wire = findWire(host);
        if (!wire || typeof wire.uploadMultiple !== 'function') {
            return;
        }

        wire.uploadMultiple(
            property,
            files,
            function () {
                // Upload finished — Livewire's own re-render reflects the
                // new file in the bound array property.
            },
            function () {
                // Errored — validation/size errors surface via the normal
                // Livewire error bag on the component (`newAttachments.*`,
                // `screenshots.*`, etc.), nothing extra to do here.
            },
            function () {
                // Progress callback — intentionally unused; components show
                // a generic `wire:loading wire:target="..."` indicator.
            }
        );
    }

    // =========================================================================
    // 4) Attachment lightbox
    // =========================================================================
    // A single reusable full-screen overlay, lazily created on first use.

    var lightboxOverlay = null;

    function ensureLightbox() {
        if (lightboxOverlay) {
            return lightboxOverlay;
        }

        lightboxOverlay = document.createElement('div');
        lightboxOverlay.setAttribute('data-dispatch-lightbox-overlay', '');
        lightboxOverlay.style.cssText = [
            'position:fixed', 'inset:0', 'z-index:10000',
            'background:rgba(15,23,42,0.85)', 'display:none',
            'align-items:center', 'justify-content:center', 'padding:2rem',
            'cursor:zoom-out',
        ].join(';');

        var img = document.createElement('img');
        img.style.cssText = 'max-width:100%;max-height:100%;border-radius:0.5rem;box-shadow:0 10px 40px rgba(0,0,0,0.5);';
        img.setAttribute('alt', '');
        lightboxOverlay.appendChild(img);

        lightboxOverlay.addEventListener('click', function () {
            lightboxOverlay.style.display = 'none';
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && lightboxOverlay.style.display !== 'none') {
                lightboxOverlay.style.display = 'none';
            }
        });

        document.body.appendChild(lightboxOverlay);
        return lightboxOverlay;
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-dispatch-lightbox]');
        if (!trigger) {
            return;
        }

        var src = trigger.getAttribute('data-dispatch-lightbox-src');
        if (!src) {
            return;
        }

        e.preventDefault();
        var overlay = ensureLightbox();
        overlay.querySelector('img').src = src;
        overlay.style.display = 'flex';
    });
})();
