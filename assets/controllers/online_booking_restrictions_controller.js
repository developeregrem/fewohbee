import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

export default class extends Controller {
    static targets = ['overrideId', 'startDate', 'endDate', 'minNights', 'categoryAll', 'categoryItem'];

    editOverride(event) {
        const btn = event.currentTarget;
        this.overrideIdTarget.value = btn.dataset.overrideId || '';
        this.startDateTarget.value = btn.dataset.overrideStart || '';
        this.endDateTarget.value = btn.dataset.overrideEnd || '';
        this.minNightsTarget.value = btn.dataset.overrideNights || '1';

        const categoryId = btn.dataset.overrideCategory || '';

        // Reset all checkboxes
        this.categoryAllTarget.checked = false;
        this.categoryItemTargets.forEach(cb => { cb.checked = false; });

        if (categoryId === '') {
            // "All categories" was set
            this.categoryAllTarget.checked = true;
        } else {
            const item = this.categoryItemTargets.find(cb => cb.value === categoryId);
            if (item) item.checked = true;
        }
    }

    toggleAll() {
        if (this.categoryAllTarget.checked) {
            // Uncheck all individual items when "All" is selected
            this.categoryItemTargets.forEach(cb => { cb.checked = false; });
        }
    }

    toggleItem() {
        const anyChecked = this.categoryItemTargets.some(cb => cb.checked);
        if (anyChecked) {
            this.categoryAllTarget.checked = false;
        } else {
            // If nothing selected, re-check "All"
            this.categoryAllTarget.checked = true;
        }
    }
}
