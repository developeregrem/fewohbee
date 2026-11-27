import { Controller } from '@hotwired/stimulus';
import { Dropdown } from 'bootstrap';
import { request as httpRequest } from '../js/http.js';
import { setSaveState } from '../js/save_state.js';

/* stimulusFetch: 'lazy' */

/**
 * The payment status of an invoice in a menu that saves as soon as a status is picked
 * (<twig:InvoiceStatusPicker>: the payment means icon in the colour of the status, with the
 * status name in the invoice dialog). Every pick is a deliberate click, so there is no delay - a status change can start an
 * automation, e.g. a mail once an invoice is paid.
 *
 * A saved change is announced as `invoice:status-changed` on the document (detail: id, status).
 * Other pickers of the same invoice follow it, e.g. the list row behind the dialog, and the
 * occupancy plan reloads (reservations_controller.js).
 */
export default class extends Controller {
    static targets = ['option', 'toggle', 'state'];
    static values = { id: Number, status: Number, url: String, token: String };

    connect() {
        this.boundFollow = (event) => this.follow(event);
        document.addEventListener('invoice:status-changed', this.boundFollow);
        const toggle = this.element.querySelector('[data-bs-toggle="dropdown"]');
        if (toggle) {
            // Fixed, so a list that scrolls sideways does not cut the menu off
            this.dropdown = Dropdown.getOrCreateInstance(toggle, {
                popperConfig: (config) => ({ ...config, strategy: 'fixed' }),
            });
        }
    }

    disconnect() {
        document.removeEventListener('invoice:status-changed', this.boundFollow);
        this.dropdown?.dispose();
    }

    pick({ params: { status } }) {
        if (this.saving || status === this.statusValue) {
            return;
        }
        this.saving = true;
        this.setState('saving');
        httpRequest({
            url: this.urlValue,
            method: 'POST',
            data: { 'invoice-status': status, _token: this.tokenValue },
            loader: false,
            onSuccess: () => {
                this.show(status);
                this.setState('saved');
                document.dispatchEvent(new CustomEvent('invoice:status-changed', {
                    detail: { id: this.idValue, status },
                }));
            },
            // The shown status stays what is stored
            onError: () => this.setState('failed'),
            onComplete: () => {
                this.saving = false;
            },
        });
    }

    follow({ detail }) {
        if (detail?.id === this.idValue && detail.status !== this.statusValue) {
            this.show(detail.status);
        }
    }

    show(status) {
        this.statusValue = status;
        let current = null;
        this.optionTargets.forEach((option) => {
            const isCurrent = Number(option.dataset.invoiceStatusStatusParam) === status;
            option.classList.toggle('active', isCurrent);
            option.setAttribute('aria-current', String(isCurrent));
            if (isCurrent) {
                current = option;
            }
        });
        // The menu items carry icon and name of their status; the toggle takes them over
        if (current && this.hasToggleTarget) {
            const icon = this.toggleTarget.querySelector('[data-invoice-status-icon]');
            const label = this.toggleTarget.querySelector('[data-invoice-status-label]');
            if (icon) {
                icon.replaceWith(current.querySelector('[data-invoice-status-icon]').cloneNode(true));
            }
            if (label) {
                label.textContent = current.querySelector('[data-invoice-status-label]').textContent;
            }
        }
    }

    setState(state) {
        if (this.hasStateTarget) {
            setSaveState(this.stateTarget, state);
        }
    }
}
