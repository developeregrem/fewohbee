import { Controller } from '@hotwired/stimulus';
import { parseDecimal, createTableCollator } from '../js/table_helpers.js';

/*
 * Generic client-side column sort for a static HTML table.
 *
 * Deliberately decoupled from any one screen so several tables can share it:
 * each sortable <th> is a "header" target carrying a real <button> wired to
 * `sort` and a data-sort-type (text | number | date). A cell may expose a
 * data-sort-value to sort by something other than its visible text (e.g. an ISO
 * date behind a localized one). Header state is reflected through aria-sort and
 * a Font Awesome sort icon; text is compared with Intl.Collator so sorting
 * follows the user's language. Rows flagged data-empty-row keep their place.
 */
export default class extends Controller {
    static targets = ['tbody', 'header'];
    static values = { locale: String };

    initialize() {
        this._collator = createTableCollator(this.localeValue);
    }

    sort(event) {
        const header = event.currentTarget.closest('th');
        if (!header || !this.hasTbodyTarget) return;

        const index = header.cellIndex;
        const type = header.dataset.sortType || 'text';
        // Toggle: first click ascends, a second click on the same header descends.
        const direction = header.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
        const modifier = direction === 'ascending' ? 1 : -1;

        const rows = Array.from(this.tbodyTarget.querySelectorAll(':scope > tr'))
            .filter((row) => row.dataset.emptyRow === undefined);

        // Array.prototype.sort is stable, so equal rows keep their current order.
        rows.sort((a, b) => this._compare(a.cells[index], b.cells[index], type) * modifier);

        rows.forEach((row) => this.tbodyTarget.appendChild(row));
        this._reflectHeaders(header, direction);
    }

    _compare(cellA, cellB, type) {
        if (type === 'number') {
            return (parseDecimal(this._raw(cellA)) ?? 0) - (parseDecimal(this._raw(cellB)) ?? 0);
        }
        if (type === 'date') {
            // Date cells carry an ISO Y-m-d sort value that orders correctly as text.
            return String(this._raw(cellA)).localeCompare(String(this._raw(cellB)));
        }
        return this._collator.compare(this._raw(cellA), this._raw(cellB));
    }

    _raw(cell) {
        if (!cell) return '';
        return (cell.dataset.sortValue ?? cell.textContent ?? '').trim();
    }

    _reflectHeaders(activeHeader, direction) {
        this.headerTargets.forEach((header) => {
            const isActive = header === activeHeader;
            header.setAttribute('aria-sort', isActive ? direction : 'none');

            const icon = header.querySelector('.sort-icon');
            if (!icon) return;
            const cls = isActive ? (direction === 'ascending' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort';
            icon.innerHTML = `<i class="fas ${cls}" aria-hidden="true"></i>`;
            window.FontAwesome?.dom?.i2svg?.({ node: icon });
        });
    }
}
