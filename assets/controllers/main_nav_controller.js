import { Controller } from '@hotwired/stimulus';

/*
 * Main navigation (offcanvas in base.html.twig): a side menu on touch screens below lg,
 * the full bar with a mouse. Closes the menu before Turbo caches the page, so going back
 * does not show a snapshot with the menu still open.
 *
 * Offcanvas.hide() is no use here: Bootstrap releases the body's scroll lock only after
 * the closing animation, and Turbo caches the page before that - after "back" the page
 * could not scroll, and gestures and pull-to-refresh stopped working. So everything the
 * offcanvas changed is undone synchronously instead.
 */
export default class extends Controller {
    connect() {
        // The side menu is for touch screens; with a mouse the bar is always expanded,
        // however narrow the window (same test as isDesktopWeekMode() in the reservations)
        const navbar = this.element.closest('.navbar');
        if (navbar && window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
            navbar.classList.replace('navbar-expand-lg', 'navbar-expand');
            navbar.classList.add('main-nav-desktop');
        }

        this.boundBeforeCache = () => this.resetForCache();
        document.addEventListener('turbo:before-cache', this.boundBeforeCache);
    }

    disconnect() {
        document.removeEventListener('turbo:before-cache', this.boundBeforeCache);
    }

    resetForCache() {
        window.bootstrap?.Offcanvas?.getInstance(this.element)?.dispose();
        this.element.classList.remove('show', 'showing', 'hiding');
        this.element.removeAttribute('aria-modal');
        this.element.removeAttribute('role');
        document.querySelectorAll('.offcanvas-backdrop').forEach((backdrop) => backdrop.remove());

        // Bootstrap's scrollbar helper: it keeps a previous inline value in data-bs-<property>
        // and removes the property otherwise; do the same for the body and the fixed elements
        // it adjusts
        const restore = (element, property) => {
            const attribute = `data-bs-${property}`;
            if (element.hasAttribute(attribute)) {
                element.style.setProperty(property, element.getAttribute(attribute));
                element.removeAttribute(attribute);
            } else {
                element.style.removeProperty(property);
            }
        };
        restore(document.body, 'overflow');
        restore(document.body, 'padding-right');
        document.querySelectorAll('.fixed-top, .fixed-bottom, .is-fixed, .sticky-top').forEach((element) => {
            restore(element, 'padding-right');
            restore(element, 'margin-right');
        });
    }
}
