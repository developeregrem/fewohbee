import { Controller } from '@hotwired/stimulus';

/*
 * Main navigation below xl (offcanvas in base.html.twig): closes the menu before Turbo
 * caches the page, so going back does not show a snapshot with the menu still open.
 */
export default class extends Controller {
    connect() {
        this.boundBeforeCache = () => {
            window.bootstrap?.Offcanvas?.getInstance(this.element)?.hide();
            this.element.classList.remove('show', 'showing');
            document.querySelectorAll('.offcanvas-backdrop').forEach((backdrop) => backdrop.remove());
        };
        document.addEventListener('turbo:before-cache', this.boundBeforeCache);
    }

    disconnect() {
        document.removeEventListener('turbo:before-cache', this.boundBeforeCache);
    }
}
