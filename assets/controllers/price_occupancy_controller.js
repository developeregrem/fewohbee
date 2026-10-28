import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/**
 * The surcharge for every further person of a room price: it can only be set for a price per
 * night and room, and the form explains it with an example computed from the amounts entered.
 *
 * Event spaces are charged per day: as soon as an event-space category is selected, every text
 * naming the unit (unitText targets with data-night / data-day) switches to days.
 */
export default class extends Controller {
    static targets = ['calcType', 'category', 'price', 'persons', 'surcharge', 'disabledHint', 'exactHint', 'example', 'unitText'];

    static values = {
        example: String,
        exampleDay: String,
    };

    connect() {
        this.update();
    }

    update() {
        const perDay = this.hasCategoryTarget
            && Array.from(this.categoryTarget.selectedOptions).some((option) => '1' === option.dataset.eventSpace);
        this.unitTextTargets.forEach((text) => {
            text.textContent = perDay ? text.dataset.day : text.dataset.night;
        });

        if (!this.hasSurchargeTarget) {
            return;
        }

        const perRoom = this.calcTypeTargets.some((radio) => radio.checked && 'per_room' === radio.value);
        this.surchargeTarget.disabled = !perRoom;
        this.disabledHintTarget.classList.toggle('d-none', perRoom);

        const surcharge = this.parse(this.surchargeTarget.value);
        const base = this.hasPriceTarget ? this.parse(this.priceTarget.value) : null;
        const persons = this.hasPersonsTarget ? parseInt(this.personsTarget.value, 10) : NaN;
        const showExample = perRoom && null !== surcharge && null !== base && persons > 0;

        this.exactHintTarget.classList.toggle('d-none', !perRoom || null !== surcharge);
        this.exampleTarget.classList.toggle('d-none', !showExample);
        if (showExample) {
            // Two more guests than the price includes: the smallest example that shows the multiplication.
            this.exampleTarget.textContent = (perDay ? this.exampleDayValue : this.exampleValue)
                .replace('__PERSONS__', String(persons + 2))
                .replace('__BASE__', this.format(base))
                .replace('__SURCHARGE__', this.format(surcharge))
                .replace('__TOTAL__', this.format(base + 2 * surcharge));
        }
    }

    /** Reads "1.234,50" as well as "1234.50"; null for an empty or unreadable field. */
    parse(raw) {
        let value = String(raw || '').trim();
        if ('' === value) {
            return null;
        }
        if (value.includes(',')) {
            value = value.replace(/\./g, '').replace(',', '.');
        }
        const amount = Number(value);

        return Number.isFinite(amount) && amount >= 0 ? amount : null;
    }

    format(amount) {
        return new Intl.NumberFormat(document.documentElement.lang || undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(amount);
    }
}
