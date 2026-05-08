import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/**
 * Maintains the per-category guest count steppers in a reservation form,
 * serializes them into a single JSON field (`guestCounts`) and shows a
 * live total (sum of categories flagged isCountedInOccupancy).
 *
 * Targets:
 *   - input: number inputs, each carrying data-category-id,
 *            data-counted-in-occupancy ("1" or "0") and data-is-adult
 *   - personsDisplay: element whose textContent reflects the live total
 *   - personsInput:   hidden field receiving the total (legacy `persons`)
 *   - hiddenJson:     hidden input receiving the JSON-encoded counts map
 *   - adultWarning:   warning shown when no adult guest is selected
 *   - overrideToggle: checkbox that suppresses the adult warning
 *   - overrideContainer: wrapper around warning + toggle (hidden when adult >= 1)
 *   - overWarning:    transient warning shown when occupancy max is hit
 *
 * Values:
 *   - max: hard cap for the sum of categories flagged isCountedInOccupancy
 *          (typically the apartment's bedsMax). 0 / unset = no cap.
 */
export default class extends Controller {
    static targets = [
        'input', 'personsDisplay', 'personsInput', 'hiddenJson',
        'adultWarning', 'overrideToggle', 'overrideContainer', 'overWarning',
    ];

    static values = { max: Number };

    connect() {
        this.recompute();
    }

    inputAction(event) {
        // Direct keyboard input: clamp the just-changed input so the occupancy
        // sum cannot exceed maxValue.
        const input = event ? event.target : null;
        if (input && input.dataset.countedInOccupancy === '1' && this._hasMax()) {
            const others = this._occupancySumExcluding(input);
            const allowed = Math.max(0, this.maxValue - others);
            const value = Math.max(0, parseInt(input.value, 10) || 0);
            if (value > allowed) {
                input.value = allowed;
                this._flashOverCapacity();
            }
        }
        this.recompute();
    }

    increment(event) {
        const catId = event.currentTarget.dataset.categoryId;
        const input = this.inputTargets.find((i) => i.dataset.categoryId === catId);
        if (!input) {
            return;
        }
        if (input.dataset.countedInOccupancy === '1' && this._hasMax()) {
            if (this._occupancySum() + 1 > this.maxValue) {
                this._flashOverCapacity();
                return;
            }
        }
        input.value = (parseInt(input.value, 10) || 0) + 1;
        this.recompute();
    }

    decrement(event) {
        const catId = event.currentTarget.dataset.categoryId;
        const input = this.inputTargets.find((i) => i.dataset.categoryId === catId);
        if (!input) {
            return;
        }
        input.value = Math.max(0, (parseInt(input.value, 10) || 0) - 1);
        this.recompute();
    }

    overrideAction() {
        this.recompute();
    }

    recompute() {
        const counts = {};
        let occupancySum = 0;
        let adultSum = 0;

        this.inputTargets.forEach((input) => {
            const value = Math.max(0, parseInt(input.value, 10) || 0);
            input.value = value;
            const catId = input.dataset.categoryId;
            if (value > 0 && catId) {
                counts[catId] = value;
            }
            if (input.dataset.countedInOccupancy === '1') {
                occupancySum += value;
            }
            if (input.dataset.isAdult === '1') {
                adultSum += value;
            }
        });

        if (this.hasHiddenJsonTarget) {
            this.hiddenJsonTarget.value = JSON.stringify(counts);
        }
        if (this.hasPersonsInputTarget) {
            this.personsInputTarget.value = occupancySum;
        }
        if (this.hasPersonsDisplayTarget) {
            this.personsDisplayTarget.textContent = occupancySum;
        }

        const overridden = this.hasOverrideToggleTarget && this.overrideToggleTarget.checked;
        if (this.hasAdultWarningTarget) {
            const showWarning = adultSum < 1 && !overridden;
            this.adultWarningTarget.classList.toggle('d-none', !showWarning);
        }

        if (this.hasOverrideContainerTarget) {
            const showContainer = adultSum < 1;
            this.overrideContainerTarget.classList.toggle('d-none', !showContainer);
            if (!showContainer && this.hasOverrideToggleTarget && this.overrideToggleTarget.checked) {
                this.overrideToggleTarget.checked = false;
            }
        }
    }

    _hasMax() {
        return this.hasMaxValue && this.maxValue > 0;
    }

    _occupancySum() {
        return this.inputTargets
            .filter((i) => i.dataset.countedInOccupancy === '1')
            .reduce((sum, i) => sum + (parseInt(i.value, 10) || 0), 0);
    }

    _occupancySumExcluding(skipInput) {
        return this.inputTargets
            .filter((i) => i !== skipInput && i.dataset.countedInOccupancy === '1')
            .reduce((sum, i) => sum + (parseInt(i.value, 10) || 0), 0);
    }

    _flashOverCapacity() {
        if (!this.hasOverWarningTarget) {
            return;
        }
        this.overWarningTarget.classList.remove('d-none');
        clearTimeout(this._overTimer);
        this._overTimer = setTimeout(() => {
            this.overWarningTarget.classList.add('d-none');
        }, 2500);
    }
}
