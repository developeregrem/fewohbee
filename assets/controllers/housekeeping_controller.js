import { Controller } from '@hotwired/stimulus';
import { request as httpRequest, serializeForm as httpSerializeForm } from '../js/http.js';

/* stimulusFetch: 'lazy' */

export default class extends Controller {
    static targets = ['form', 'spinner', 'content'];

    connect() {
        const isPreview = document.documentElement.hasAttribute('data-turbo-preview');
        // Skip heavy init when Turbo preview is rendered
        if (isPreview) {
            this.spin()
            return;
        } else {
            this.stopSpin();
        }
    }

    submitFilters(event) {
        this.spin();
        if (event) {
            event.preventDefault();
        }

        if (this.hasContentTarget) {
            // AJAX mode
            const url = new URL(this.formTarget.action || window.location.href, window.location.origin);
            const query = httpSerializeForm(this.formTarget);
            if (query) {
                url.search = query;
            }

            httpRequest({
                url: url.toString(),
                method: 'GET',
                target: this.contentTarget,
                loader: false,
                onComplete: () => {
                    this.stopSpin();
                },
                onError: (message) => {
                    console.warn('[housekeeping] filter failed', message);
                    this.stopSpin();
                },
            });
        } else {
            // Fallback: full page reload
            if (this.formTarget && typeof this.formTarget.requestSubmit === 'function') {
                this.formTarget.requestSubmit();
            } else if (this.formTarget) {
                this.formTarget.submit();
            }
        }
    }

    spin() {
        if (this.hasSpinnerTarget) {
            this.spinnerTarget.classList.add('fa-spin');
        }
    }

    stopSpin() {
        if (this.hasSpinnerTarget) {
            this.spinnerTarget.classList.remove('fa-spin');
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
