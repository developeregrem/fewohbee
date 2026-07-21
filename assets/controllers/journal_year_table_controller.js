import { Controller } from '@hotwired/stimulus';

/*
 * Client-side sort and filter for the read-only yearly journal table. The whole
 * year is rendered at once, so sorting and filtering happen in the browser
 * without a round-trip - the point is to have everything on one screen.
 */
export default class extends Controller {
    static targets = ['tbody', 'columnFilter', 'filterRow'];

    // Show or hide the per-column filter row. Hiding clears the filters so no
    // rows stay hidden behind a filter row that is no longer visible.
    toggleFilters(event) {
        const willShow = this.filterRowTarget.classList.contains('d-none');
        this.filterRowTarget.classList.toggle('d-none', !willShow);

        const btn = event.currentTarget;
        btn.classList.toggle('active', willShow);
        btn.setAttribute('aria-pressed', willShow ? 'true' : 'false');

        if (willShow) {
            this.columnFilterTargets[0]?.focus();
        } else {
            this.columnFilterTargets.forEach((input) => { input.value = ''; });
            this.filter();
        }
    }

    sort(event) {
        const th = event.currentTarget;
        const headers = Array.from(th.parentNode.children);
        const index = headers.indexOf(th);
        const type = th.dataset.sortType || 'text';
        const dir = th.dataset.sortDir === 'asc' ? 'desc' : 'asc';

        // reset every header's direction and indicator, then mark this one
        headers.forEach((h) => {
            delete h.dataset.sortDir;
            const ind = h.querySelector('.sort-indicator');
            if (ind) ind.textContent = '';
        });
        th.dataset.sortDir = dir;
        const indicator = th.querySelector('.sort-indicator');
        if (indicator) indicator.textContent = dir === 'asc' ? ' ▲' : ' ▼';

        const rows = Array.from(this.tbodyTarget.querySelectorAll('tr'))
            .filter((r) => r.dataset.emptyRow === undefined);

        rows.sort((a, b) => {
            const av = this._cellValue(a, index, type);
            const bv = this._cellValue(b, index, type);
            if (av < bv) return dir === 'asc' ? -1 : 1;
            if (av > bv) return dir === 'asc' ? 1 : -1;
            return 0;
        });

        rows.forEach((r) => this.tbodyTarget.appendChild(r));
    }

    // Each column has its own filter; a row is shown only when it matches every
    // active column filter (AND), so columns can be narrowed independently.
    // Number and date filters understand the comparisons >, >=, <, <=, =, !=,
    // and several of them at once (space-separated, all must hold) so a closed
    // range like ">=01.06.2026 <=31.08.2026" works; everything else is a
    // case-insensitive substring match.
    filter() {
        const active = this.columnFilterTargets
            .map((input) => ({
                index: parseInt(input.dataset.columnIndex, 10),
                type: input.dataset.columnType || 'text',
                q: input.value.trim(),
            }))
            .filter((f) => f.q !== '');

        this.tbodyTarget.querySelectorAll('tr').forEach((row) => {
            if (row.dataset.emptyRow !== undefined) return;
            const match = active.every((f) => this._cellMatches(row.children[f.index], f.type, f.q));
            row.classList.toggle('d-none', !match);
        });
    }

    _cellMatches(cell, type, q) {
        if (!cell) return false;

        // On number/date columns, pull out every comparison clause (operator +
        // operand, operands carry no spaces) and require all of them to hold.
        if (type === 'number' || type === 'date') {
            const clauses = [...q.matchAll(/(<=|>=|!=|<>|<|>|=)\s*([^\s<>=!]+)/g)];
            if (clauses.length > 0) {
                const a = this._comparable(cell.dataset.sortValue ?? cell.textContent, type);
                if (a === null) return false;

                return clauses.every(([, op, operand]) => {
                    const b = this._comparable(operand, type);
                    if (b === null) return false;
                    switch (op) {
                        case '>': return a > b;
                        case '>=': return a >= b;
                        case '<': return a < b;
                        case '<=': return a <= b;
                        case '=': return a === b;
                        case '!=':
                        case '<>': return a !== b;
                        default: return false;
                    }
                });
            }
        }

        return cell.textContent.toLowerCase().includes(q.toLowerCase());
    }

    /** Turn a cell value or a typed operand into something comparable, or null. */
    _comparable(raw, type) {
        const s = String(raw).trim();
        if (type === 'number') {
            const n = parseFloat(s.replace(/\s/g, '').replace(',', '.'));
            return Number.isNaN(n) ? null : n;
        }
        // date: accept ISO (2097-03-15) or German (15.03.2097), compare as ISO strings
        if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
        const de = s.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/);
        if (de) return `${de[3]}-${de[2].padStart(2, '0')}-${de[1].padStart(2, '0')}`;
        return null;
    }

    _cellValue(row, index, type) {
        const cell = row.children[index];
        if (!cell) return type === 'number' ? 0 : '';
        const raw = cell.dataset.sortValue ?? cell.textContent.trim();
        if (type === 'number') return parseFloat(String(raw).replace(',', '.')) || 0;
        // dates carry an ISO Y-m-d sort value and sort correctly as strings
        return String(raw).toLowerCase();
    }
}
