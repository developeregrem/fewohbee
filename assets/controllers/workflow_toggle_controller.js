import { Controller } from '@hotwired/stimulus';

/**
 * Handles the enable/disable toggle for workflows via AJAX.
 */
export default class extends Controller {
    static values = {
        url: String,
        token: String,
    };

    toggle() {
        fetch(this.urlValue, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: new URLSearchParams({ _token: this.tokenValue }),
        })
        .then(r => r.json())
        .catch(() => {
            // Revert on error
            this.element.checked = !this.element.checked;
        });
    }
}
