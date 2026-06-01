import { Controller } from '@hotwired/stimulus';
import { generateCsrfHeaders, generateCsrfToken, removeCsrfToken } from '../js/csrf_protection.js';

export default class extends Controller {
    static targets = ['button', 'result'];
    static values = {
        testUrl: String,
        genericError: String,
    };

    testConnection() {
        const form = this.element.closest('form');
        if (!form) {
            return;
        }

        this.buttonTarget.disabled = true;
        this.resultTarget.className = 'small text-body-secondary';
        this.resultTarget.textContent = '';

        generateCsrfToken(form);

        fetch(this.testUrlValue, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                ...generateCsrfHeaders(form),
            },
            body: new FormData(form),
        })
            .then((response) => response.json().then((payload) => ({ response, payload })))
            .then(({ response, payload }) => {
                this.resultTarget.className = response.ok ? 'small text-success' : 'small text-danger';
                this.resultTarget.textContent = payload.message || '';
            })
            .catch(() => {
                this.resultTarget.className = 'small text-danger';
                this.resultTarget.textContent = this.genericErrorValue;
            })
            .finally(() => {
                removeCsrfToken(form);
                this.buttonTarget.disabled = false;
            });
    }
}
