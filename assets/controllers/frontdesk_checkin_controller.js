import { Controller } from '@hotwired/stimulus';
import { request } from '../js/http.js';

/**
 * Check-in cell of a frontdesk row: marks a check-in at the desk or takes it back, then swaps
 * the cell for the one the server renders with the new state.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        url: String,
        token: String,
    };

    send({ params: { intent } }) {
        const toggle = this.element.querySelector('[data-bs-toggle="dropdown"]');
        window.bootstrap?.Dropdown.getInstance(toggle)?.hide();
        request({
            url: this.urlValue,
            method: 'POST',
            data: { _token: this.tokenValue, intent },
            loader: false,
            onSuccess: (html) => {
                this.element.outerHTML = html;
            },
        });
    }
}
