import { Controller } from '@hotwired/stimulus';

/**
 * Touch screens below lg (phones, tablets held upright): scrolling down slides the top and the
 * bottom bar away, so the page gets the whole screen; they come back once the page is at its top
 * again (shell.css, "Bars that scroll away"). Where the frame is a sidebar (lg and up) nothing
 * happens: collapsing it stays the user's choice with the sidebar's own button.
 *
 * Hiding is taken from the finger rather than from the scroll position: at the end of a page the
 * browser bounces back or slides its address bar in and out, which moves the scroll position up
 * without the user scrolling up. On a page that does not scroll, a finger moving down brings the
 * bars back.
 *
 * Sits on the main area of base.html.twig; the reservation overview hides the bars in steps of
 * its own (reservations_controller.js) and leaves it out. Each page starts with the bars shown.
 */
const HIDDEN_CLASS = 'app-bars-hidden';
const TOUCH = window.matchMedia('not all and (hover: hover) and (pointer: fine)');
const BARS = window.matchMedia('(max-width: 991.98px)');
// How far the finger moves in one direction before the bars follow, so a jitter does not flip
// them back and forth
const DISTANCE = 24;

export default class extends Controller {
    connect() {
        if (!TOUCH.matches) {
            return;
        }
        this.abort = new AbortController();
        const signal = this.abort.signal;
        this.start = null;
        document.addEventListener('touchstart', (event) => this.onTouchStart(event), { passive: true, signal });
        document.addEventListener('touchmove', (event) => this.onTouchMove(event), { passive: true, signal });
        window.addEventListener('scroll', () => this.onScroll(), { passive: true, signal });
        // Turned sideways into the sidebar layout: nothing to keep away there
        BARS.addEventListener('change', () => this.setHidden(false), { signal });
    }

    disconnect() {
        this.abort?.abort();
        this.setHidden(false);
    }

    onTouchStart(event) {
        const touch = event.touches.length === 1 ? event.touches[0] : null;
        // Not in a dialog, a menu or a field: they scroll or take the finger themselves
        this.start = touch && BARS.matches && !this.isBusy()
            && !event.target.closest?.('.modal, .offcanvas, .dropdown-menu, .popover, input, textarea, select')
            ? { x: touch.clientX, y: touch.clientY }
            : null;
    }

    onTouchMove(event) {
        if (!this.start || event.touches.length !== 1) {
            return;
        }
        const dx = event.touches[0].clientX - this.start.x;
        const dy = event.touches[0].clientY - this.start.y;
        // Sideways is paging (swipe-nav), not scrolling
        if (Math.abs(dy) < DISTANCE || Math.abs(dy) < 1.5 * Math.abs(dx)) {
            return;
        }
        this.start = { x: event.touches[0].clientX, y: event.touches[0].clientY };
        if (dy < 0) {
            this.setHidden(true);
        } else if (window.scrollY <= 0) {
            this.setHidden(false);
        }
    }

    /**
     * Back at the top of the page the bars return. Not on a page that no longer scrolls: without
     * the bottom bar it got shorter, and the browser moving it back to its top is no scrolling up.
     */
    onScroll() {
        const scrollable = document.documentElement.scrollHeight > window.innerHeight;
        if (window.scrollY <= 0 && scrollable) {
            this.setHidden(false);
        }
    }

    /** An open dialog, side menu or a menu of the top bar keeps the bars as they are */
    isBusy() {
        return document.querySelector('.modal.show, .offcanvas.show, .offcanvas.showing, .app-nav .dropdown-menu.show');
    }

    setHidden(hidden) {
        document.documentElement.classList.toggle(HIDDEN_CLASS, hidden);
    }
}
