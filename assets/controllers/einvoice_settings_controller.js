import { Controller } from '@hotwired/stimulus';

/**
 * Toggles profile-dependent required fields and hints in the invoice settings form.
 *
 * Attach to the settings <form>. Rows carrying the class .js-xrechnung-required
 * become required only when the XRechnung profile is selected. The label's
 * `required` class is toggled too so the shared CSS star marker stays consistent.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['profileSelect', 'hintEn16931', 'hintXrechnung', 'contactHint'];

    connect() {
        this.profileChanged();
    }

    profileChanged() {
        const isXRechnung = this.hasProfileSelectTarget && this.profileSelectTarget.value === 'xrechnung';

        this.element.querySelectorAll('.js-xrechnung-required').forEach((row) => {
            row.querySelectorAll('input').forEach((input) => {
                input.required = isXRechnung;
            });
            row.querySelectorAll('label').forEach((label) => {
                label.classList.toggle('required', isXRechnung);
            });
        });

        if (this.hasContactHintTarget) {
            this.contactHintTarget.classList.toggle('d-none', !isXRechnung);
        }
        if (this.hasHintEn16931Target) {
            this.hintEn16931Target.classList.toggle('d-none', isXRechnung);
        }
        if (this.hasHintXrechnungTarget) {
            this.hintXrechnungTarget.classList.toggle('d-none', !isXRechnung);
        }
    }
}
