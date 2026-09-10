import { Controller } from '@hotwired/stimulus';
import { enableDeletePopover } from '../js/utils.js';

const SCROLL_KEY = 'booking-rules:scroll-target';

/* stimulusFetch: 'lazy' */

/**
 * Booking rules on the online booking settings page.
 *
 * The controller is presentation only: it opens the offcanvas, shows the fields the chosen
 * rule type actually uses, keeps the day labels honest ("Mo" for an arrival, "Mo -> Di" for
 * a night) and writes the live summary sentence. Every decision about what a rule means -
 * precedence, the effect table, validation - is made on the server.
 */
export default class extends Controller {
    static values = {
        matrixUrl: String,
    };

    static targets = [
        'offcanvas',
        'offcanvasTitle',
        'offcanvasBody',
        'form',
        'typeInput',
        'weekdayLabel',
        'dayLabel',
        'minNightsRow',
        'categoryList',
        'summary',
        'matrix',
        'matrixCategory',
        'matrixWeek',
        'ruleRow',
        'ruleList',
        'ruleListEmpty',
        'toggleButton',
        'disabledBadge',
    ];

    connect() {
        this.initDeletePopovers();
        this.restoreScrollTarget();
    }

    /** Loads the rule form for a new or existing rule into the offcanvas. */
    async openOffcanvas(event) {
        event.preventDefault();

        const button = event.currentTarget;
        this.offcanvasTitleTarget.textContent = button.dataset.offcanvasTitle || '';
        this.offcanvasBodyTarget.innerHTML =
            '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-muted"></i></div>';

        window.bootstrap.Offcanvas.getOrCreateInstance(this.offcanvasTarget).show();

        try {
            const response = await fetch(button.dataset.url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            this.offcanvasBodyTarget.innerHTML = await response.text();
            this.refresh();
        } catch {
            this.offcanvasBodyTarget.innerHTML =
                `<div class="alert alert-danger mb-0">${this.element.dataset.bookingRulesLoadError || ''}</div>`;
        }
    }

    /**
     * Submits the rule form over ajax so validation errors land back in the offcanvas
     * instead of replacing the page with a bare form.
     */
    async submitRule(event) {
        event.preventDefault();

        const form = event.currentTarget;
        const submit = form.querySelector('button[type="submit"]');
        if (submit) submit.disabled = true;

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (response.status === 204) {
                this.rememberScrollTarget(form, response.headers.get('X-Booking-Rule-Id'));
                window.location.reload();
                return;
            }

            this.offcanvasBodyTarget.innerHTML = await response.text();
            this.refresh();
        } finally {
            if (submit) submit.disabled = false;
        }
    }

    /**
     * Enables or disables a rule without reloading the page — a reload would throw the
     * operator back to the top of a long settings page.
     */
    async toggleRule(event) {
        event.preventDefault();

        const form = event.currentTarget;
        const response = await fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!response.ok) {
            return;
        }

        const row = form.closest('li');
        const enabled = !row.classList.contains('opacity-50');
        row.classList.toggle('opacity-50', enabled);

        const badge = row.querySelector('[data-booking-rules-target="disabledBadge"]');
        if (badge) badge.hidden = !enabled;

        const button = form.querySelector('[data-booking-rules-target="toggleButton"]');
        if (button) {
            button.title = enabled ? button.dataset.enableLabel : button.dataset.disableLabel;
            const icon = button.querySelector('i');
            if (icon) {
                icon.classList.toggle('fa-toggle-on', !enabled);
                icon.classList.toggle('fa-toggle-off', enabled);
            }
        }

        // A disabled rule stops applying, so the effect table has to catch up.
        this.reloadMatrix();
    }

    /**
     * Remembers where to land after the reload that follows a save: the saved rule itself,
     * or — should the id be missing — the list it belongs to. A reload alone would drop the
     * operator at the top of a long settings page.
     */
    rememberScrollTarget(form, ruleId) {
        const isPeriod = form.querySelector('input[name$="[startDate]"]') !== null;
        const target = ruleId ? `booking-rule-${ruleId}` : (isPeriod ? 'booking-rule-periods' : 'booking-rules');

        try {
            window.sessionStorage.setItem(SCROLL_KEY, target);
        } catch {
            // Storage can be unavailable (private mode); the page then simply opens at the top.
        }
    }

    /** Scrolls to the element remembered before the last save and forgets it again. */
    restoreScrollTarget() {
        let id = null;
        try {
            id = window.sessionStorage.getItem(SCROLL_KEY);
            window.sessionStorage.removeItem(SCROLL_KEY);
        } catch {
            return;
        }

        const target = id ? document.getElementById(id) : null;
        if (!target) {
            return;
        }

        // Centred, so the rule is seen together with its neighbours and the list heading.
        requestAnimationFrame(() => target.scrollIntoView({ behavior: 'auto', block: 'center' }));
    }

    /** Shows only the fields the selected type uses and rewrites labels and summary. */
    refresh() {
        if (!this.hasFormTarget) {
            return;
        }

        const type = this.selectedType();
        const isNight = type === 'min_stay_through';
        const isMinStay = type === 'min_stay_arrival' || isNight;

        if (this.hasMinNightsRowTarget) {
            this.minNightsRowTarget.hidden = !isMinStay;
        }

        this.dayLabelTargets.forEach((label) => {
            label.textContent = isNight ? label.dataset.night : label.dataset.day;
        });

        if (this.hasWeekdayLabelTarget) {
            const label = this.weekdayLabelTarget;
            label.textContent = isNight
                ? label.dataset.night
                : (type === 'closed_to_departure' ? label.dataset.departure : label.dataset.arrival);
        }

        if (this.hasCategoryListTarget) {
            const all = this.formTarget.querySelector('input[type="checkbox"][name$="[allCategories]"]');
            this.categoryListTarget.hidden = Boolean(all && all.checked);
        }

        this.renderSummary(type, isNight);
    }

    /** Reloads the effect table for the chosen category and week. */
    async reloadMatrix() {
        if (!this.hasMatrixTarget || !this.hasMatrixCategoryTarget) {
            return;
        }

        const params = new URLSearchParams({
            category: this.matrixCategoryTarget.value,
            week: this.hasMatrixWeekTarget ? this.matrixWeekTarget.value : '',
        });

        const response = await fetch(`${this.matrixUrlValue}?${params}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        this.matrixTarget.innerHTML = await response.text();
    }

    initDeletePopovers() {
        enableDeletePopover({
            root: this.element,
            onSuccess: (triggerEl) => {
                const row = triggerEl.closest('li');
                if (row) row.remove();
                this.updateEmptyStates();
                this.reloadMatrix();
            },
        });
    }

    /** Each list shows either its rules or its "nothing here yet" line. */
    updateEmptyStates() {
        this.ruleListTargets.forEach((list) => {
            const hasRows = list.querySelector('li') !== null;
            list.hidden = !hasRows;

            // Paired through the surrounding card, so the two lists cannot get mixed up.
            const empty = list.closest('.card-body')?.querySelector('[data-booking-rules-target="ruleListEmpty"]');
            if (empty) empty.hidden = hasRows;
        });
    }

    selectedType() {
        const checked = this.typeInputTargets.find((input) => input.checked);
        return checked ? checked.value : '';
    }

    /**
     * "eine Nacht" or "4 Nächte" — the singular is a real setting, and a bare number would
     * read wrong in German. Both variants come pre-translated from the server.
     */
    nightPhrase(count) {
        let phrases = {};
        try {
            phrases = JSON.parse(this.formTarget.dataset.nights || '{}');
        } catch {
            phrases = {};
        }

        return count === 1
            ? (phrases.one || '')
            : (phrases.many || '').replace('__COUNT__', String(count));
    }

    /**
     * Builds the sentence from the server-rendered templates, so the wording and its
     * translation stay in the translation files rather than in this file.
     */
    renderSummary(type, isNight) {
        if (!this.hasSummaryTarget) {
            return;
        }

        let templates = {};
        try {
            templates = JSON.parse(this.formTarget.dataset.summaries || '{}');
        } catch {
            templates = {};
        }

        const days = this.dayLabelTargets
            .filter((label) => {
                const input = this.formTarget.querySelector(`#${CSS.escape(label.htmlFor)}`);
                return input && input.checked;
            })
            .map((label) => (isNight ? label.dataset.night : label.dataset.day));

        const input = this.formTarget.querySelector('input[type="number"][name$="[minNights]"]');
        const count = input && input.value ? Number(input.value) : 1;

        this.summaryTarget.textContent = days.length === 0
            ? ''
            : (templates[type] || '')
                .replace('__DAYS__', days.join(', '))
                .replace('__NIGHTS__', this.nightPhrase(count));
    }
}
