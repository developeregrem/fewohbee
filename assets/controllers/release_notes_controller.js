/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { request as httpRequest } from '../js/http.js';

/**
 * Records that the user has seen the release notes for the running version.
 *
 * Mounted on the body of the release notes modal, which the user opens from the
 * notification bell. Closing that modal — by any means, not just the confirm
 * button — clears the entry from the bell.
 *
 * There is deliberately no auto-opening popup: the bell is the single place
 * where a new version announces itself, so the information cannot be dismissed
 * by reflex before it has been read.
 */
export default class extends Controller {
    connect() {
        const modalElement = document.getElementById('modalCenter');
        if (!modalElement) {
            return;
        }

        // once: true — #modalCenter is shared with every other modal in the app,
        // so this listener must not outlive the release notes.
        modalElement.addEventListener('hidden.bs.modal', () => this.markSeen(), { once: true });
    }

    markSeen() {
        const url = this.element.dataset.releaseNotesSeenUrl;
        if (!url) {
            return;
        }

        httpRequest({
            url,
            method: 'POST',
            data: { _token: this.element.dataset.releaseNotesSeenToken || '' },
            loader: false,
            // 204 No Content: nothing to render, and the default handler would
            // reload the page right after the modal closed.
            onSuccess: () => {},
            onError: (message) => console.error(message),
        });
    }
}
