import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/**
 * Token dialog: shows the AI assistant options only for AI tokens and takes away the
 * "unlimited" validity for them (AI tokens must expire; the server enforces it as well).
 */
export default class extends Controller {
    static targets = ['mcpSection', 'expiry', 'unlimited'];

    connect() {
        this.update();
    }

    update() {
        const checked = this.element.querySelector('input[type="radio"][name$="[kind]"]:checked');
        const isMcp = checked !== null && checked.value === 'mcp';

        this.mcpSectionTargets.forEach((section) => section.classList.toggle('d-none', !isMcp));

        this.unlimitedTargets.forEach((option) => {
            option.disabled = isMcp;
        });
        if (isMcp && this.hasExpiryTarget && this.expiryTarget.value === '') {
            // Preselect a sensible validity instead of leaving an invalid choice selected.
            this.expiryTarget.value = '+90 days';
        }
    }
}
