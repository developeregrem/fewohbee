import { Controller } from '@hotwired/stimulus';
import { isCompactViewport } from '../js/utils.js';

/* stimulusFetch: 'lazy' */

/**
 * While a dialog covers the screen like a page (compact viewport, .modal-fullscreen-lg-down),
 * the back button or back swipe closes it instead of leaving the page underneath.
 *
 * Opening pushes a history entry for the same URL; closing the dialog any other way takes that
 * entry off again. Turbo must not take the step back onto the page's own entry for a restore
 * visit, which renders the whole page again and loses e.g. the table's scroll position. Which
 * popstate listener runs first differs between browsers, so instead of racing Turbo the page's
 * entry loses its Turbo state while the dialog is open - Turbo leaves state-less entries alone -
 * and gets it back afterwards.
 */
export default class extends Controller {
    connect() {
        this.entryPushed = false;
        this.awaitingBack = false;
        this.pageState = null;
        this.onShown = () => this.pushEntry();
        this.onHidden = () => this.dropEntry();
        this.onPopState = (event) => this.closeOnBack(event);
        this.onTurboVisit = () => this.leavePage();
        this.element.addEventListener('shown.bs.modal', this.onShown);
        this.element.addEventListener('hidden.bs.modal', this.onHidden);
        window.addEventListener('popstate', this.onPopState, true);
        document.addEventListener('turbo:visit', this.onTurboVisit);
    }

    disconnect() {
        this.element.removeEventListener('shown.bs.modal', this.onShown);
        this.element.removeEventListener('hidden.bs.modal', this.onHidden);
        window.removeEventListener('popstate', this.onPopState, true);
        document.removeEventListener('turbo:visit', this.onTurboVisit);
    }

    pushEntry() {
        const coversScreen = this.element.querySelector('.modal-dialog.modal-fullscreen-lg-down') && isCompactViewport();
        if (this.entryPushed || !coversScreen) {
            return;
        }
        this.pageState = window.history.state;
        const { turbo, ...rest } = this.pageState || {};
        window.history.replaceState({ ...rest, modalPage: true }, '');
        window.history.pushState({ modalOpen: true }, '');
        this.entryPushed = true;
    }

    /** Closed by its button, the backdrop or a script: the pushed entry has to go. */
    dropEntry() {
        if (!this.entryPushed) {
            return;
        }
        this.entryPushed = false;
        this.awaitingBack = true;
        window.history.back();
    }

    closeOnBack(event) {
        if (!event.state?.modalPage) {
            return;
        }
        // Back on the page's own entry: give it its Turbo state again
        window.history.replaceState(this.pageState, '');
        event.stopImmediatePropagation();
        if (this.awaitingBack) {
            this.awaitingBack = false;
            return;
        }
        if (this.entryPushed) {
            this.entryPushed = false;
            window.bootstrap.Modal.getOrCreateInstance(this.element).hide();
        }
    }

    /**
     * Leaving through a link inside the open dialog: the dialog's entry stands for this page from
     * now on, so it needs the page's Turbo state for back to restore the page.
     */
    leavePage() {
        if (this.entryPushed) {
            window.history.replaceState(this.pageState, '');
            this.entryPushed = false;
        }
    }
}
