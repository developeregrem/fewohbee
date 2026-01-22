import { Controller } from '@hotwired/stimulus';
import { request as httpRequest, serializeForm as httpSerializeForm } from './http_controller.js';

export default class extends Controller {
    static targets = ['form', 'spinner'];

    submitFilters(event) {
        this.spin();
        if (event) {
            event.preventDefault();
        }

        if (this.formTarget && typeof this.formTarget.requestSubmit === 'function') {
            this.formTarget.requestSubmit();
        } else if (this.formTarget) {
            this.formTarget.submit();
        }
    }

    spin() {
        if (this.hasSpinnerTarget) {
            this.spinnerTarget.classList.add('fa-spin');
        }
    }

    async saveRow(event) {
        event.preventDefault();
        const form = event.target;
        const submitter = event.submitter || form.querySelector('button[type="submit"]');

        if (submitter) {
            submitter.disabled = true;
        }

        httpRequest({
            url: form.action,
            method: form.method || 'POST',
            data: httpSerializeForm(form),
            loader: false,
            onSuccess: () => {},
            onComplete: () => {
                if (submitter) {
                    submitter.disabled = false;
                }
            },
            onError: (message) => {
                console.warn('[housekeeping] save failed', message);
            },
        });
    }
}
