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
                this.syncBadge();
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

    markReadAction(event) {
        event.preventDefault();
        event.stopPropagation();
        this.dismiss(event.currentTarget);
    }

    markAllReadAction(event) {
        event.preventDefault();
        event.stopPropagation();
        this.dismiss(event.currentTarget);
    }

    /** Clears one entry or all of them, then refreshes the panel and the badge. */
    dismiss(trigger) {
        const url = trigger.dataset.url;
        if (!url) {
            return;
        }

        httpRequest({
            url,
            method: 'POST',
            data: { _token: trigger.dataset.token || '' },
            loader: false,
            // 204 No Content: reload the panel so the badge and the list agree,
            // instead of letting the default handler reload the whole page.
            onSuccess: () => this.loadPanelAction(),
            onError: (message) => console.error(message),
        });
    }

    /**
     * The badge is rendered with the page, so dismissing an entry would leave it
     * stale until the next navigation. The panel response carries the
     * recalculated state; take it from there.
     */
    syncBadge() {
        const source = this.panelTarget.querySelector('[data-notification-total]');
        const badge = this.element.querySelector('[data-notifications-badge]');
        if (!source || !badge) {
            return;
        }

        const total = parseInt(source.dataset.notificationTotal || '0', 10);
        if (Number.isNaN(total) || total < 1) {
            badge.classList.add('d-none');

            return;
        }

        badge.classList.remove('d-none');
        badge.className = badge.className.replace(/bg-\w+/, source.dataset.notificationBadgeClass || 'bg-secondary');
        badge.querySelector('[data-notifications-count]').textContent = String(total);
    }
}
