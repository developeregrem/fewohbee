import { Controller } from '@hotwired/stimulus';
import { enableDeletePopover } from '../js/utils.js';

/**
 * Stimulus controller for managing room category images in the admin modal.
 * Handles file upload, deletion (via delete_popover pattern),
 * primary image toggle, and drag & drop reorder.
 *
 * Targets:
 *   - grid: Container div for the image thumbnail cards
 *   - fileInput: Hidden file input for selecting images
 *
 * Values:
 *   - uploadUrl: POST endpoint for uploading images (built via Twig path())
 *   - reorderUrl: POST endpoint for persisting image order (built via Twig path())
 *
 * Per-card data attributes (set in Twig via path()):
 *   - data-primary-url: POST endpoint for setting this image as primary
 *   - data-popover="delete" + data-bs-content: delete popover (via delete_popover.html.twig)
 */
export default class extends Controller {
    static targets = ['grid', 'fileInput'];
    static values = {
        uploadUrl: String,
        reorderUrl: String,
        labelDelete: String,
        labelCancel: String,
        labelDeleteTitle: String,
        labelSetPrimary: String,
    };

    /** Initializes delete popovers with a callback that removes the card from the grid */
    connect() {
        this._initDeletePopovers();
    }

    /** Opens the native file picker when the upload button is clicked */
    selectFiles() {
        this.fileInputTarget.click();
    }

    /** Handles file selection: uploads each file and appends the resulting thumbnail card */
    async handleFileSelect(event) {
        const files = event.target.files;
        if (!files.length) return;

        const formData = new FormData();
        for (const file of files) {
            formData.append('files[]', file);
        }

        try {
            const response = await fetch(this.uploadUrlValue, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                const err = await response.json().catch(() => ({}));
                alert(err.error || 'Upload fehlgeschlagen');
                return;
            }

            const images = await response.json();
            images.forEach(img => this.appendImageCard(img));
            this._initDeletePopovers();
        } catch (e) {
            alert('Upload fehlgeschlagen: ' + e.message);
        }

        // Reset file input so the same file can be re-selected
        event.target.value = '';
    }

    /** Sets an image as the primary (hero) image using the URL from data-primary-url */
    async setPrimary(event) {
        const card = event.target.closest('[data-image-id]');
        const url = card.dataset.primaryUrl;

        const response = await fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (response.ok) {
            // Update all star indicators
            this.gridTarget.querySelectorAll('.rc-img-primary').forEach(el => {
                el.classList.remove('text-warning');
                el.classList.add('text-secondary');
            });
            card.querySelector('.rc-img-primary').classList.remove('text-secondary');
            card.querySelector('.rc-img-primary').classList.add('text-warning');
        }
    }

    // === Drag & Drop reorder ===

    /** Marks the dragged card and stores its reference */
    dragStart(event) {
        this.draggedCard = event.target.closest('[data-image-id]');
        this.draggedCard.classList.add('opacity-50');
        event.dataTransfer.effectAllowed = 'move';
    }

    /** Allows drop by preventing the default browser behavior */
    dragOver(event) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
    }

    /** Inserts the dragged card before the drop target and persists the new order */
    async drop(event) {
        event.preventDefault();
        const target = event.target.closest('[data-image-id]');
        if (!target || target === this.draggedCard) return;

        // Insert before or after depending on position
        const rect = target.getBoundingClientRect();
        const midX = rect.left + rect.width / 2;
        if (event.clientX < midX) {
            this.gridTarget.insertBefore(this.draggedCard, target);
        } else {
            this.gridTarget.insertBefore(this.draggedCard, target.nextSibling);
        }

        this.draggedCard.classList.remove('opacity-50');
        await this.persistOrder();
    }

    /** Removes the drag visual indicator when dragging ends */
    dragEnd() {
        if (this.draggedCard) {
            this.draggedCard.classList.remove('opacity-50');
        }
    }

    /** Sends the current card order to the server using the reorder URL from Twig */
    async persistOrder() {
        const order = [...this.gridTarget.querySelectorAll('[data-image-id]')].map(
            el => parseInt(el.dataset.imageId)
        );

        await fetch(this.reorderUrlValue, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ order }),
        });
    }

    /**
     * Creates and appends a thumbnail card for a newly uploaded image.
     * The delete popover content mirrors the structure from delete_popover.html.twig.
     */
    appendImageCard(img) {
        const card = document.createElement('div');
        card.className = 'col-4 col-md-3 mb-3';
        card.dataset.imageId = img.id;
        card.dataset.primaryUrl = img.primaryUrl;
        card.draggable = true;
        card.dataset.action = [
            'dragstart->room-category-images#dragStart',
            'dragover->room-category-images#dragOver',
            'drop->room-category-images#drop',
            'dragend->room-category-images#dragEnd',
        ].join(' ');

        const popoverContent = `<form action="${img.deleteUrl}">`
            + `<div class="text-center">`
            + `<a class="btn btn-danger btn-sm popover-delete">${this.labelDeleteValue}</a> `
            + `<a class="btn btn-secondary btn-sm popover-cancel">${this.labelCancelValue}</a>`
            + `</div>`
            + `<input type="hidden" name="_token" value="${img.csrfToken}">`
            + `</form>`;

        card.innerHTML = `
            <div class="card h-100">
                <img src="${img.thumbnailUrl}" class="card-img-top" alt="" style="height:100px;object-fit:cover">
                <div class="card-body p-1 text-center">
                    <button type="button" class="btn btn-sm btn-link rc-img-primary ${img.isPrimary ? 'text-warning' : 'text-secondary'}"
                            data-action="click->room-category-images#setPrimary" title="${this.labelSetPrimaryValue}">
                        <i class="fas fa-star"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-link text-danger"
                            title="${this.labelDeleteTitleValue}"
                            data-popover="delete"
                            data-bs-content='${popoverContent.replace(/'/g, '&#39;')}'>
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>
            </div>
        `;

        this.gridTarget.appendChild(card);
    }

    /** Shows or hides the "no images" message based on whether images exist */
    updateEmptyState() {
        const emptyMsg = this.element.querySelector('.rc-img-empty');
        if (emptyMsg) {
            emptyMsg.style.display = this.gridTarget.children.length ? 'none' : 'block';
        }
    }

    /** Initializes delete popovers, removing the card on successful deletion */
    _initDeletePopovers() {
        enableDeletePopover({
            root: this.element,
            onSuccess: (triggerEl) => {
                const card = triggerEl.closest('[data-image-id]');
                if (card) card.remove();
                this.updateEmptyState();
            },
        });
    }
}
