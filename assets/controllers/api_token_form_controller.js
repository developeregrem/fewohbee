import { Controller } from '@hotwired/stimulus';
import { enableTooltips, disposeTooltips } from '../js/utils.js';

/* stimulusFetch: 'lazy' */

/**
 * Token dialog: shows only the permissions that apply to the chosen kind of access (REST or AI
 * assistant), takes away the "unlimited" validity for AI tokens (they must expire; the server
 * enforces it as well) and sums up in plain language what the access will be allowed to do.
 */
export default class extends Controller {
    static targets = ['mcpOnly', 'restOnly', 'group', 'scopeItem', 'expiry', 'unlimited', 'summary'];

    static values = {
        // Translated sentences of the summary, see Profile/_api_token_summary.html.twig.
        texts: Object,
    };

    connect() {
        this.update();
        enableTooltips(this.element);
    }

    disconnect() {
        disposeTooltips(this.element);
    }

    update() {
        const checked = this.element.querySelector('input[type="radio"][name$="[kind]"]:checked');
        const isMcp = checked !== null && checked.value === 'mcp';
        const kind = isMcp ? 'mcp' : 'api';

        this.mcpOnlyTargets.forEach((element) => element.classList.toggle('d-none', !isMcp));
        this.restOnlyTargets.forEach((element) => element.classList.toggle('d-none', isMcp));

        // A permission the other kind of access evaluates is hidden and not submitted.
        this.scopeItemTargets.forEach((item) => {
            const applies = item.dataset.kinds.split(' ').includes(kind);
            item.classList.toggle('d-none', !applies);
            item.querySelectorAll('input:not([data-unavailable])').forEach((input) => {
                input.disabled = !applies;
            });
        });
        this.groupTargets.forEach((group) => {
            const visible = this.scopeItemTargets.some((item) => group.contains(item) && !item.classList.contains('d-none'));
            group.classList.toggle('d-none', !visible);
        });

        this.unlimitedTargets.forEach((option) => {
            option.disabled = isMcp;
        });
        if (isMcp && this.hasExpiryTarget && this.expiryTarget.value === '') {
            // Preselect a sensible validity instead of leaving an invalid choice selected.
            this.expiryTarget.value = '+90 days';
        }

        this.renderSummary(isMcp);
    }

    renderSummary(isMcp) {
        if (!this.hasSummaryTarget) return;

        const texts = this.textsValue;
        // Scopes the chosen kind does not evaluate are not stored, even when still ticked.
        const kind = isMcp ? 'mcp' : 'api';
        const chosen = this.scopeItemTargets.filter((item) => item.querySelector('input:checked:not(:disabled)')
            && item.dataset.kinds.split(' ').includes(kind));
        const labels = (group) => chosen.filter((item) => item.dataset.group === group).map((item) => item.dataset.summary);
        const list = (items) => (items.length > 1
            ? `${items.slice(0, -1).join(', ')} ${texts.and} ${items[items.length - 1]}`
            : items.join(''));

        const see = labels('see');
        const change = labels('change');
        const sentences = [];
        if (isMcp) {
            const guests = labels('personal_data').length > 0;
            const bank = chosen.some((item) => item.dataset.sharesBankData === '1');

            sentences.push(see.length > 0 ? texts.seeMcp.replace('%list%', list(see)) : texts.seeMcpNone);
            sentences.push(guests && bank ? texts.personalBoth : guests ? texts.personalGuests : bank ? texts.personalBank : texts.personalNone);
            sentences.push(change.length > 0 ? texts.changeSome.replace('%list%', list(change)) : texts.changeNone);
        } else {
            if (see.length > 0) sentences.push(texts.seeRest.replace('%list%', list(see)));
            if (change.length > 0) sentences.push(texts.changeRest.replace('%list%', list(change)));
        }

        this.summaryTarget.textContent = sentences.join(' ');
    }
}
