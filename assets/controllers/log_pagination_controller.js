import { Controller } from '@hotwired/stimulus';

/**
 * Pages through a server-rendered log table in place: paginator links inside
 * the element reload the fragment from the log route instead of navigating.
 * Wire it with data-action="click->log-pagination#paginate".
 */
export default class extends Controller {
    static values = {
        url: String,
    };

    paginate(event) {
        const link = event.target.closest('a[data-page]');
        if (!link) return;
        event.preventDefault();
        const page = Number.parseInt(link.dataset.page, 10);
        if (!Number.isInteger(page) || page < 1) return;
        const separator = this.urlValue.includes('?') ? '&' : '?';
        const query = new URLSearchParams({ page: String(page) });
        this._load(`${this.urlValue}${separator}${query}`);
    }

    _load(url) {
        // `url` is the server-rendered log route plus a validated numeric page,
        // never a URL derived from document.location. The returned fragment is
        // Twig-rendered with autoescaping enabled.
        fetch(url)
            .then(r => (r.ok ? r.text() : Promise.reject(r)))
            .then(html => { this.element.innerHTML = html; })
            .catch(() => {});
    }
}
