import { Controller } from '@hotwired/stimulus';

/**
 * Keeps the "new calendar entry" form short. The end time only appears once
 * a start time or an end date is set - on a range it belongs to the last day
 * and needs no start time. The date fields stay behind a link, because the
 * clicked day is almost always the right one.
 */
export default class extends Controller {
    static targets = ['time', 'dateTo', 'endTimeGroup', 'dateSummary', 'dateFields'];

    connect() {
        this.updateEndTime();
    }

    updateEndTime() {
        const needsEndTime = this.timeTarget.value !== '' || this.dateToTarget.value !== '';
        this.endTimeGroupTarget.classList.toggle('d-none', !needsEndTime);
        if (!needsEndTime) {
            // A hidden end time must not be submitted behind the user's back.
            this.endTimeGroupTarget.querySelectorAll('input').forEach((input) => {
                input.value = '';
            });
        }
    }

    showDateFields(event) {
        event.preventDefault();
        this.dateSummaryTarget.classList.add('d-none');
        this.dateFieldsTarget.classList.remove('d-none');
        this.dateFieldsTarget.querySelector('input')?.focus();
    }
}
