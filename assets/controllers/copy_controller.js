import { Controller } from '@hotwired/stimulus';

/**
 * Handles copy-to-clipboard buttons that may be loaded via Turbo/AJAX.
 */
export default class extends Controller {
    static targets = ['input'];
    static values = {
        hint: String,
    };

    copy(event) {
        event.preventDefault();
        const button = event.currentTarget;

        const input = this.inputTarget || button.closest('.input-group')?.querySelector('input[type="text"]');
        if (!input) {
            return;
        }

        input.select();
        input.setSelectionRange(0, input.value.length);

        navigator.clipboard?.writeText(input.value)
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
