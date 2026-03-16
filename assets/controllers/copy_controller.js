import { Controller } from '@hotwired/stimulus';

/**
 * Handles copy-to-clipboard buttons that may be loaded via Turbo/AJAX.
 */
/* stimulusFetch: 'lazy' */

export default class extends Controller {
    static targets = ['input'];
    static values = {
        hint: String,
    };

    copy(event) {
        event.preventDefault();
        const button = event.currentTarget;

        const input = this.hasInputTarget
            ? this.inputTarget
            : button.closest('.input-group, .ob-codebox')?.querySelector('input[type="text"], textarea');
        if (!input) {
            return;
        }

        input.focus();
        if (typeof input.select === 'function') {
            input.select();
        }
        if (typeof input.setSelectionRange === 'function') {
            input.setSelectionRange(0, input.value.length);
        }

        if (!navigator.clipboard?.writeText) {
            console.warn('Clipboard API is not available in this browser context.');

            return;
        }

        navigator.clipboard.writeText(input.value)
            .then(() => this.showHint(input, button))
            .catch((error) => console.warn('Copy to clipboard failed', error));
    }

    showHint(input, button) {
        const hint = this.hintValue || button.dataset.hint;
        const Popover = window.bootstrap?.Popover;

        if (!Popover || !hint) {
            return;
        }

        const popover = new Popover(input, {
            content: hint,
            placement: 'top',
        });
        popover.show();
        setTimeout(() => popover.dispose(), 1500);
    }
}
