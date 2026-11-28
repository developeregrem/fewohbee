import { Controller } from '@hotwired/stimulus';

/**
 * Filters a list in the browser while typing, for lists short enough to be on the page as a
 * whole (e.g. the automations). A row (target "item") stays when its text contains every word
 * typed - its text is that of the elements marked data-list-filter-field, else the whole row;
 * case and accents do not matter. A group of rows (target "group") with none left hides as
 * well; its count (target "count", data-texts: the wording for every number from none up,
 * rendered by the server) follows the rows shown. Target "empty" shows when nothing matches.
 */
export default class extends Controller {
    static targets = ['input', 'item', 'group', 'count', 'empty'];

    filter() {
        const words = normalize(this.inputTarget.value).split(/\s+/).filter(Boolean);
        this.itemTargets.forEach((item) => {
            item.hidden = !words.every((word) => textOf(item).includes(word));
        });

        let shownTotal = 0;
        this.groupTargets.forEach((group) => {
            const shown = this.itemTargets.filter((item) => group.contains(item) && !item.hidden).length;
            group.hidden = shown === 0;
            shownTotal += shown;
            this.countTargets.filter((count) => group.contains(count)).forEach((count) => {
                const texts = JSON.parse(count.dataset.texts || '[]');
                const label = count.querySelector('span') ?? count;
                if (texts[shown] !== undefined) label.textContent = texts[shown];
            });
        });
        if (!this.hasGroupTarget) {
            shownTotal = this.itemTargets.filter((item) => !item.hidden).length;
        }
        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = shownTotal > 0;
        }
    }

    /** Enter keeps the page: the list is filtered already */
    submit(event) {
        event.preventDefault();
    }
}

function normalize(text) {
    return text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase().trim();
}

/** The searchable text of a row, read once; text only for screen readers (labels such as
    "Trigger:") is left out, it would match every row */
function textOf(item) {
    if (item.listFilterText === undefined) {
        const fields = item.querySelectorAll('[data-list-filter-field]');
        const source = (fields.length > 0 ? [...fields] : [item]).map((field) => {
            const copy = field.cloneNode(true);
            copy.querySelectorAll('.visually-hidden').forEach((hidden) => hidden.remove());
            return copy.textContent;
        }).join(' ');
        item.listFilterText = normalize(source.replace(/\s+/g, ' '));
    }
    return item.listFilterText;
}
