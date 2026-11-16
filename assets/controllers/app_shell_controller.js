import { Controller } from '@hotwired/stimulus';

const RAIL_CLASS = 'app-nav-rail';
const COOKIE = 'feb_nav';

/*
 * Application frame (templates/layout/_app_nav.html.twig): collapses the sidebar to a rail of
 * icons and back. The state is kept in a cookie rather than in localStorage, so base.html.twig
 * can render it and the page does not jump after loading.
 */
export default class extends Controller {
    toggleRail() {
        this.setRail(!document.documentElement.classList.contains(RAIL_CLASS));
    }

    /** A group cannot unfold inside the rail: open the full sidebar first. */
    expandForGroup() {
        if (document.documentElement.classList.contains(RAIL_CLASS)) {
            this.setRail(false);
        }
    }

    setRail(enabled) {
        document.documentElement.classList.toggle(RAIL_CLASS, enabled);
        const path = window.config?.basePath || '/';
        document.cookie = `${COOKIE}=${enabled ? 'rail' : 'full'}; path=${path}; max-age=31536000; samesite=lax`;
        // Views that size themselves to the window (reservation table) measure again
        window.dispatchEvent(new Event('resize'));
    }
}
