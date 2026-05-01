import { Controller } from '@hotwired/stimulus';
import { whenBootstrapAndIconsReady } from '../js/utils.js';

/**
 * Generic confirm-before-submit guard.
 *
 * Attach to a <form> and set data-confirm-submit-message-value="...".
 * Optional values:
 * - data-confirm-submit-confirm-label-value
 * - data-confirm-submit-cancel-label-value
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        message: String,
        confirmLabel: String,
        cancelLabel: String,
    };

    connect() {
        this.ready = false;
        this.popover = null;
        this.submitter = null;
        this.element.addEventListener('submit', this._guard);
        this.readyPromise = whenBootstrapAndIconsReady().then((ready) => {
            this.ready = ready;
            return ready;
        });
    }

    disconnect() {
        this.element.removeEventListener('submit', this._guard);
        this._hidePopover();
    }

    _guard = (event) => {
        if (this.element.dataset.confirmed === '1') {
            return;
        }

        if (!this.hasMessageValue || this.messageValue.trim() === '') {
            return;
        }

        event.preventDefault();
        this.submitter = event.submitter || this.element.querySelector('[type="submit"]') || this.element;

        if (!this.ready) {
            this.readyPromise.then((ready) => {
                if (ready) {
                    this._showPopover(this.submitter);
                }
            });
            return;
        }

        this._showPopover(this.submitter);
    };

    _showPopover(anchor) {
        this._hidePopover();

        this.popover = new window.bootstrap.Popover(anchor, {
            content: () => this._buildContent(),
            html: true,
            placement: 'top',
            sanitize: false,
            trigger: 'manual',
        });
        this.popover.show();
    }

    _buildContent() {
        const wrapper = document.createElement('div');
        wrapper.className = 'text-center';

        const message = document.createElement('p');
        message.className = 'mb-2 small';
        message.textContent = this.messageValue;
        wrapper.appendChild(message);

        const actions = document.createElement('div');
        actions.className = 'd-flex gap-2 justify-content-center';
        wrapper.appendChild(actions);

        const confirm = document.createElement('button');
        confirm.type = 'button';
        confirm.className = 'btn btn-primary btn-sm';
        confirm.textContent = this.confirmLabelValue || 'OK';
        confirm.addEventListener('click', () => this._confirm());
        actions.appendChild(confirm);

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn btn-outline-secondary btn-sm';
        cancel.textContent = this.cancelLabelValue || 'Cancel';
        cancel.addEventListener('click', () => this._hidePopover());
        actions.appendChild(cancel);

        return wrapper;
    }

    _confirm() {
        this.element.dataset.confirmed = '1';
        this._hidePopover();

        if (this.submitter instanceof HTMLElement && typeof this.element.requestSubmit === 'function') {
            this.element.requestSubmit(this.submitter);
            return;
        }

        this.element.submit();
    }

    _hidePopover() {
        if (!this.popover) {
            return;
        }
        this.popover.hide();
        this.popover.dispose();
        this.popover = null;
    }
}
