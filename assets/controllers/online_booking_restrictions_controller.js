import { Controller } from '@hotwired/stimulus';
import { enableDeletePopover } from '../js/utils.js';

/* stimulusFetch: 'lazy' */

export default class extends Controller {
    static values = {
        restoreHash: { type: String, default: 'online-booking-overrides' },
    };

    static targets = [
        'overrideId',
        'startDate',
        'endDate',
        'minNights',
        'categoryAll',
        'categoryItem',
        'overridesTable',
        'overridesEmpty',
    ];

    connect() {
        this.initDeletePopovers();
        this.restoreScrollTarget();
    }

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

    initDeletePopovers() {
        enableDeletePopover({
            root: this.element,
            onSuccess: (triggerEl) => {
                const row = triggerEl.closest('tr');
                if (row) {
                    row.remove();
                }
                this.updateEmptyState();
            },
        });
    }

    updateEmptyState() {
        if (!this.hasOverridesTableTarget || !this.hasOverridesEmptyTarget) {
            return;
        }

        const hasRows = this.overridesTableTarget.querySelector('tbody tr') !== null;
        this.overridesTableTarget.classList.toggle('d-none', !hasRows);
        this.overridesEmptyTarget.classList.toggle('d-none', hasRows);
    }

    rememberScrollTarget() {
        window.sessionStorage.setItem(
            'online-booking-restrictions:return-hash',
            this.restoreHashValue
        );
    }

    restoreScrollTarget() {
        const hash = window.sessionStorage.getItem('online-booking-restrictions:return-hash');
        if (!hash) {
            return;
        }

        window.sessionStorage.removeItem('online-booking-restrictions:return-hash');

        const target = document.getElementById(hash);
        if (!target) {
            return;
        }

        if (window.location.hash !== `#${hash}`) {
            window.history.replaceState(null, '', `#${hash}`);
        }

        requestAnimationFrame(() => {
            target.scrollIntoView({ behavior: 'auto', block: 'start' });
        });
    }
}
