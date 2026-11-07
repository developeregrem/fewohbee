import { Controller } from '@hotwired/stimulus';
import { onSwipe, horizontalSwipeDirection } from '../js/swipe.js';

/**
 * Paging arrows at the screen edges, and on touch screens the same paging by
 * a sideways swipe anywhere on the page. Swiping from right to left goes
 * forward, like the right arrow.
 *
 * - previous / next targets are the arrows (templates/BookingJournal/
 *   _edge_navigation.html.twig); a swipe clicks them. As links the page
 *   decides where they lead, e.g. the next page of a list and after its last
 *   page the next month.
 * - As buttons they step the select target, whose numeric options (years)
 *   may come in any order; its change event loads the period. Arrows without
 *   a neighbouring value are hidden.
 *
 * A table wider than the screen scrolls sideways first; only a swipe on past
 * its edge pages. Swipes in form fields, dialogs and side panels are left
 * alone.
 */
export default class extends Controller {
    static targets = ['select', 'previous', 'next'];

    connect() {
        this.updateButtons();
        this.abort = new AbortController();
        onSwipe(document, this.abort.signal, (dx, dy, target, edges) => {
            const direction = horizontalSwipeDirection(dx, dy);
            if (!direction || !edges || !(direction === 'forward' ? edges.right : edges.left)) {
                return;
            }
            const arrow = direction === 'forward'
                ? (this.hasNextTarget ? this.nextTarget : null)
                : (this.hasPreviousTarget ? this.previousTarget : null);
            if (arrow && !arrow.hidden) {
                arrow.click();
            }
        }, (event) => this.startState(event.target));
    }

    disconnect() {
        this.abort?.abort();
    }

    previous() {
        this.stepSelect(false);
    }

    next() {
        this.stepSelect(true);
    }

    /** Shows the arrow buttons only where the select has a neighbouring value. */
    updateButtons() {
        if (!this.hasSelectTarget) {
            return;
        }
        if (this.hasPreviousTarget && this.previousTarget.tagName === 'BUTTON') {
            this.previousTarget.hidden = this.neighbourOption(false) === null;
        }
        if (this.hasNextTarget && this.nextTarget.tagName === 'BUTTON') {
            this.nextTarget.hidden = this.neighbourOption(true) === null;
        }
    }

    /**
     * Where the touch started, if it may page: whether its sideways scrolling
     * area (if any) already sits at its left and right edge. Null for touches
     * that belong to something else.
     */
    startState(target) {
        if (!(target instanceof Element)
            || target.closest('input, textarea, select, [contenteditable], .modal, .offcanvas, .dropdown-menu, .popover')
            || document.querySelector('.modal.show, .offcanvas.show, .offcanvas.showing')) {
            return null;
        }
        for (let el = target; el && el !== document.body; el = el.parentElement) {
            const overflowX = getComputedStyle(el).overflowX;
            if ((overflowX === 'auto' || overflowX === 'scroll') && el.scrollWidth > el.clientWidth + 1) {
                return {
                    left: el.scrollLeft <= 1,
                    right: el.scrollLeft + el.clientWidth >= el.scrollWidth - 1,
                };
            }
        }
        return { left: true, right: true };
    }

    stepSelect(forward) {
        const option = this.hasSelectTarget ? this.neighbourOption(forward) : null;
        if (option) {
            this.selectTarget.value = option.value;
            this.selectTarget.dispatchEvent(new Event('change', { bubbles: true }));
            this.updateButtons();
        }
    }

    /** The next higher (forward) or lower numeric option, whatever the list order. */
    neighbourOption(forward) {
        const current = Number(this.selectTarget.value);
        let best = null;
        for (const option of this.selectTarget.options) {
            const value = Number(option.value);
            if ((forward ? value > current : value < current)
                && (best === null || (forward ? value < Number(best.value) : value > Number(best.value)))) {
                best = option;
            }
        }
        return best;
    }
}
