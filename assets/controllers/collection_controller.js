import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/**
 * Generic Symfony-form CollectionType helper.
 * Adds new entries by cloning a prototype HTML fragment and removes entries
 * on click of [data-action="collection#remove"]. The prototype is expected
 * to already contain its own root element (e.g. a .row wrapper).
 */
export default class extends Controller {
    static targets = ['entries'];
    static values = { prototype: String, index: Number };

    add(event) {
        event.preventDefault();
        const html = this.prototypeValue.replace(/__name__/g, this.indexValue);
        const tpl = document.createElement('template');
        tpl.innerHTML = html.trim();
        const node = tpl.content.firstElementChild;
        if (node) this.entriesTarget.appendChild(node);
        this.indexValue += 1;
    }

    remove(event) {
        event.preventDefault();
        const row = event.currentTarget.closest('.collection-row');
        if (row) row.remove();
    }
}
