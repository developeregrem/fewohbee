import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/**
 * Reflects the currently active Bootstrap tab in the URL as `?tab=<pane-id>`,
 * so that page reloads (e.g. after form save with redirect) restore the user's
 * last tab. The initial active tab is decided server-side via the same query
 * parameter; this controller only keeps the URL in sync while the user clicks
 * around.
 */
export default class extends Controller {
    connect() {
        this._listeners = [];
        this.element.querySelectorAll('[data-bs-toggle="tab"]').forEach((trigger) => {
            const handler = (event) => this._onShown(event);
            trigger.addEventListener('shown.bs.tab', handler);
            this._listeners.push({ trigger, handler });
        });
    }

    disconnect() {
        for (const { trigger, handler } of this._listeners) {
            trigger.removeEventListener('shown.bs.tab', handler);
        }
        this._listeners = [];
    }

    _onShown(event) {
        const target = event.target.dataset.bsTarget || '';
        const id = target.replace(/^#/, '');
        if (!id) return;

        const url = new URL(window.location.href);
        url.searchParams.set('tab', id);
        window.history.replaceState(null, '', url.toString());
    }
}
