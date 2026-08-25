/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { request as httpRequest } from '../js/http.js';
import { setModalTitle } from '../js/utils.js';

/**
 * The navbar notification bell.
 *
 * The badge is rendered server-side on every page, so it is always current after
 * a Turbo navigation without any polling. Only the list is fetched, and only
 * when the dropdown is actually opened.
 */
export default class extends Controller {
    static targets = ['panel'];
    static values = { url: String };

    loadPanelAction() {
        if (!this.hasPanelTarget || !this.hasUrlValue) {
            return;
        }
        // Re-fetch on every open: conflicts and reminders are live state, and a
        // stale list is worse than a short spinner.
        httpRequest({
            url: this.urlValue,
            loader: false,
            onSuccess: (html) => {
                // An expired session answers with the login page. Showing a login
                // form inside the bell dropdown would be nonsense, so say nothing
                // and let the next real navigation take the user to the login.
                if (!html.includes('data-notification-panel')) {
                    return;
                }
                this.panelTarget.innerHTML = html;
            },
            onError: (message) => console.error(message),
        });
    }

    openItemAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        if (!url) {
            return;
        }

        const modalContent = document.getElementById('modal-content-ajax');
        const modalElement = document.getElementById('modalCenter');
        if (!modalContent || !modalElement) {
            return;
        }

        setModalTitle(event.currentTarget.dataset.modalTitle || '');
        httpRequest({ url, target: modalContent });
        window.bootstrap?.Modal?.getOrCreateInstance(modalElement)?.show();
    }
}
