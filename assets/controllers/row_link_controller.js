import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/**
 * A click anywhere on a row of a list opens what the row's name opens: it clicks the link marked
 * data-row-link-target="link" in that row. For lists whose own open or edit button gave way to
 * the name; not for tables with fields to fill in.
 *
 * Clicks on other controls (links, buttons, form fields, the actions cell) keep their own
 * meaning, and a click that ends a text selection does nothing, so a number can still be copied.
 * The name stays the real link for keyboards and screen readers.
 *
 *   <table data-controller="row-link" data-action="click->row-link#open">
 *       … <a href="…" class="data-table-link" data-row-link-target="link">Name</a> …
 */
export default class extends Controller {
    static targets = ['link'];

    open(event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        const row = event.target.closest('tr');
        if (!row || !this.element.contains(row)
            || event.target.closest('a, button, input, select, textarea, label, .cell-actions, [data-row-link-ignore]')) {
            return;
        }
        if (window.getSelection()?.toString()) {
            return;
        }
        this.linkTargets.find((link) => row.contains(link))?.click();
    }
}
