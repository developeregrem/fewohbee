import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/**
 * Toggles visibility between the per-night rates table and the percentage
 * configuration fields based on the selected TaxCalculationMode. Also strips
 * the `required` attribute from inputs/selects inside the hidden section so
 * the browser does not block form submission on non-focusable fields.
 */
export default class extends Controller {
    static targets = ['select', 'percentFields', 'ratesSection', 'adultOnlyWrapper'];
    static values = { flat: String };

    connect() {
        this.update();
    }

    update() {
        const isFlat = this.selectTarget.value === this.flatValue;

        this.toggle(this.percentFieldsTargets, !isFlat);
        this.toggle(this.ratesSectionTargets, isFlat);
        // appliesOnlyToAdult only makes sense for the per-person flat mode
        // (Swiss accommodation levy etc.). For percent-of-room it has no effect.
        this.toggle(this.adultOnlyWrapperTargets, isFlat);
    }

    toggle(elements, visible) {
        elements.forEach(el => {
            el.classList.toggle('d-none', !visible);
            el.querySelectorAll('input, select, textarea').forEach(field => {
                if (visible) {
                    if (field.dataset.requiredBackup === '1') {
                        field.required = true;
                        delete field.dataset.requiredBackup;
                    }
                } else if (field.required) {
                    field.dataset.requiredBackup = '1';
                    field.required = false;
                }
            });
        });
    }
}
