import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/**
 * Switches a record on or off over fetch (the form of <twig:ActiveToggle fetch>), so a long list
 * keeps its scroll position. On success it flips the switch and marks the row - the closest tr or
 * li: inactiveClass while off, its [data-inactive-badge] shown while off. A refusal (e.g. a price
 * that conflicts with another active one) reloads the page, which shows the server's message.
 */
export default class extends Controller {
    static values = { inactiveClass: String };

    async toggle(event) {
        event.preventDefault();
        const form = this.element;
        let response;
        try {
            response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
        } catch {
            return;
        }
        if (!response.ok) {
            window.location.reload();
            return;
        }

        const button = form.querySelector('button');
        const active = button.getAttribute('aria-pressed') !== 'true';
        const label = active ? button.dataset.disableLabel : button.dataset.enableLabel;
        button.setAttribute('aria-pressed', String(active));
        button.classList.toggle('is-on', active);
        button.title = label;
        button.setAttribute('aria-label', label);
        const icon = button.querySelector('i');
        icon?.classList.toggle('fa-toggle-on', active);
        icon?.classList.toggle('fa-toggle-off', !active);

        const row = form.closest('tr, li');
        if (row && this.inactiveClassValue) {
            row.classList.toggle(this.inactiveClassValue, !active);
        }
        row?.querySelectorAll('[data-inactive-badge]').forEach((badge) => {
            badge.hidden = active;
        });

        // For a page that shows more of the record elsewhere (active-toggle:toggled)
        this.dispatch('toggled', { detail: { active } });
    }
}
