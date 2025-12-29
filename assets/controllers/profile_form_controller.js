import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['submit'];
    static values = {
        loadingText: String,
    };
    defaultText = null;

    connect() {
        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = false;
            this.defaultText = this.submitTarget.innerHTML;
        }
        const firstField = this.element.querySelector('input, select, textarea');
        if (firstField instanceof HTMLElement) {
            firstField.focus();
        }
    }

    submit() {
        if (!this.hasSubmitTarget) {
            return;
        }
        // Disable the submit button and change its text to indicate loading
        this.submitTarget.disabled = true;
        const text = this.loadingTextValue || this.submitTarget.innerHTML;
        this.submitTarget.innerHTML = text;
    }

    restore() {
        if (!this.hasSubmitTarget) {
            return;
        }
        // Re-enable the submit button and restore its original text
        this.submitTarget.disabled = false;
        if (this.defaultText !== null) {
            this.submitTarget.innerHTML = this.defaultText;
        }
    }
}
