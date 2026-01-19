import { Controller } from '@hotwired/stimulus';
import { request as httpRequest, serializeForm as httpSerializeForm } from './http_controller.js';
import { setModalTitle, enableDeletePopover } from './utils_controller.js';

export default class extends Controller {
    connect() {
        this.modalContent = document.getElementById('modal-content-ajax');
        const isBootstrapped = this.modalContent.hasAttribute('data-settings-bootstrapped');
        if (isBootstrapped) {
            return;
        }
        this.modalContent.dataset.settingsBootstrapped = 'true';
        this.waitForIconsAndPopover();
    }

    waitForIconsAndPopover(attempt = 0) {
        // Ensure font-awesome icons are present and popover assets loaded
        const iconsReady = !!document.querySelector('svg.fa-trash-can');
        if (!iconsReady && attempt < 10) {
            setTimeout(() => this.waitForIconsAndPopover(attempt + 1), 100);
            return;
        }
        enableDeletePopover();
    }

    openModalAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const title = event.currentTarget.dataset.title || '';
        const enableEdit = event.currentTarget.dataset.enableEdit === 'true';
        const modalSize = event.currentTarget.dataset.modalSize || 'default';

        if (!url) return;
        this.setModalSize(modalSize);
        setModalTitle(title);
        httpRequest({
            url,
            method: 'GET',
            target: this.modalContent,
            onComplete: () => {
                if (enableEdit) {
                    this.enableEditFromModal();
                }
            },
        });
    }

    setModalSize(size) {
        const modalDialog = document.querySelector('#modalCenter .modal-dialog');
        if (!modalDialog) return;
        if (size === 'lg') {
            modalDialog.classList.add('modal-lg');
        } else {
            modalDialog.classList.remove('modal-lg');
        }
    }

    enableEditFromModal() {
        const editLink = this.modalContent.querySelector('[data-action*="settings#enableEditAction"]');
        if (editLink) {
            this.enableEditAction({ preventDefault() {}, currentTarget: editLink });
        }
    }

    enableEditAction(event) {
        event.preventDefault();
        const fieldsetSelector = event.currentTarget.dataset.fieldsetSelector;
        const saveSelector = event.currentTarget.dataset.saveSelector;
        const editAreaSelector = event.currentTarget.dataset.editAreaSelector;
        if (editAreaSelector) {
            const area = document.querySelector(editAreaSelector);
            if (area) area.classList.add('d-none');
        }
        if (saveSelector) {
            const saveBtn = document.querySelector(saveSelector);
            if (saveBtn) saveBtn.classList.remove('d-none');
        }
        if (fieldsetSelector) {
            const fieldset = document.querySelector(fieldsetSelector);
            if (fieldset) fieldset.removeAttribute('disabled');
        }
    }

    submitFormAction(event) {
        event.preventDefault();
        const form = event.target.closest('form');
        if (!form) return;
        const submitButton = event.submitter || form.querySelector('input[type="submit"]');
        let originalLabel = '';
        if (submitButton) {
            submitButton.disabled = true;
            originalLabel = submitButton.value;
            const nextLabel = originalLabel.endsWith('...') ? originalLabel : `${originalLabel}...`;
            submitButton.value = nextLabel;
        }
        const url = form.dataset.url || form.action;
        const method = form.dataset.method || form.method || 'POST';
        const successUrl = form.dataset.successUrl;
        httpRequest({
            url,
            method,
            data: httpSerializeForm(form),
            target: this.modalContent,
            loader: false,
            onSuccess: (data) => {
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.value = originalLabel;
                }
                // if server returns html containing a modal body, keep user in modal
                if (typeof data === 'string' && data.includes('modal-body')) {
                    if (this.modalContent) {
                        this.modalContent.innerHTML = data;
                        return;
                    }
                }
                // show inline flash if present
                const flash = form.querySelector('#flash-message-overlay');
                if (flash && typeof data === 'string' && data.length > 0) {
                    flash.innerHTML = data;
                    return;
                }
                if (successUrl) {
                    window.location.href = successUrl;
                } else {
                    window.location.reload();
                }
            },
        });
    }

    deleteAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const formSelector = event.currentTarget.dataset.formSelector;
        const confirmText = event.currentTarget.dataset.confirm;
        if (!url) return;
        if (confirmText && !window.confirm(confirmText)) {
            return;
        }
        const form = formSelector ? document.querySelector(formSelector) : null;
        httpRequest({
            url,
            method: 'DELETE',
            data: form ? new FormData(form) : null,
            onSuccess: () => window.location.reload(),
        });
    }
}
