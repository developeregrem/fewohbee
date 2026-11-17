import { Controller } from '@hotwired/stimulus';
import { Collapse } from 'bootstrap';

const RAIL_CLASS = 'app-nav-rail';
const COOKIE = 'feb_nav';
// The rail only exists where the frame is a sidebar (shell.css)
const SIDEBAR = window.matchMedia('(min-width: 992px)');

/*
 * Application frame (templates/layout/_app_nav.html.twig): collapses the sidebar to a rail of
 * icons and back, and unfolds the groups of the navigation. The state is kept in a cookie rather
 * than in localStorage, so base.html.twig can render it and the page does not jump after loading.
 */
export default class extends Controller {
    connect() {
        this.onGroupChange = (event) => this.syncGroupButton(event);
        this.element.addEventListener('show.bs.collapse', this.onGroupChange);
        this.element.addEventListener('hide.bs.collapse', this.onGroupChange);
    }

    disconnect() {
        this.element.removeEventListener('show.bs.collapse', this.onGroupChange);
        this.element.removeEventListener('hide.bs.collapse', this.onGroupChange);
    }

    toggleRail() {
        this.setRail(!this.isRail());
    }

    /**
     * A group of the navigation. Inside the rail its entries are hidden, whatever state the
     * group is in: a click there opens the full sidebar with the group unfolded, instead of
     * toggling it (which would fold an open group away).
     */
    toggleGroup(event) {
        const group = document.getElementById(event.currentTarget.getAttribute('aria-controls'));
        if (!group) {
            return;
        }
        const collapse = Collapse.getOrCreateInstance(group, { toggle: false });
        if (this.isRail()) {
            this.setRail(false);
            collapse.show();
        } else {
            collapse.toggle();
        }
    }

    isRail() {
        return SIDEBAR.matches && document.documentElement.classList.contains(RAIL_CLASS);
    }

    setRail(enabled) {
        document.documentElement.classList.toggle(RAIL_CLASS, enabled);
        const path = document.documentElement.dataset.basePath || '/';
        document.cookie = `${COOKIE}=${enabled ? 'rail' : 'full'}; path=${path}; max-age=31536000; samesite=lax`;
        // Views that size themselves to the window (reservation table) measure again
        window.dispatchEvent(new Event('resize'));
    }

    /* The group's button tells whether the group is open (caret, screen readers) */
    syncGroupButton(event) {
        if (!event.target.classList.contains('app-nav-sub')) {
            return;
        }
        this.element.querySelector(`[aria-controls="${event.target.id}"]`)
            ?.setAttribute('aria-expanded', event.type.startsWith('show') ? 'true' : 'false');
    }
}
