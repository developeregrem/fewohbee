import { Controller } from '@hotwired/stimulus';
import { enableDeletePopover } from '../js/utils.js';

const SCROLL_KEY = 'booking-rules:scroll-target';

/* stimulusFetch: 'lazy' */

/**
 * Booking rules on the online booking settings page.
 *
 * The controller is presentation only: it opens the offcanvas, shows the fields the chosen
 * rule type actually uses, keeps the day labels honest ("Mo" for an arrival, "Mo -> Di" for
 * a night), writes the live summary sentence and reloads the rule calendar. Every decision
 * about what a rule means - precedence, the calendar values, validation - is made on the server.
 */
export default class extends Controller {
    static values = {
        calendarUrl: String,
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
        'calendar',
        'calendarTip',
        'calendarLegend',
        'ruleRow',
        'ruleList',
        'ruleListEmpty',
        'toggleButton',
        'disabledBadge',
    ];

    connect() {
        this.highlightedRuleId = null;
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
            // Green while active, neutral while inactive — as the server renders it on load.
            button.classList.toggle('btn-success', !enabled);
            button.classList.toggle('btn-outline-secondary', enabled);
            const icon = button.querySelector('i');
            if (icon) {
                icon.classList.toggle('fa-toggle-on', !enabled);
                icon.classList.toggle('fa-toggle-off', enabled);
            }
        }

        // A disabled rule stops applying, so the calendar has to catch up.
        this.reloadCalendar();
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

    /** Switches the calendar to another room category; the week stays. */
    changeCalendarCategory(event) {
        this.reloadCalendar({ category: event.currentTarget.value }, event.currentTarget);
    }

    /** Moves the calendar by a week or back to the current one; the server rendered the target week. */
    navigateCalendar(event) {
        this.reloadCalendar({ week: event.currentTarget.dataset.week }, event.currentTarget);
    }

    /**
     * Re-renders the calendar card on the server. Category and week are mirrored into the page
     * URL, so the reload that follows saving a rule shows the same view again.
     */
    async reloadCalendar(changes = {}, trigger = null) {
        if (!this.hasCalendarTarget || !this.hasCalendarUrlValue) {
            return;
        }

        const current = this.calendarTarget.dataset;
        const params = new URLSearchParams({
            category: changes.category ?? current.category,
            week: changes.week ?? current.week,
        });
        const focusSelector = trigger ? this.focusSelectorFor(trigger) : null;

        const response = await fetch(`${this.calendarUrlValue}?${params}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) {
            return;
        }

        this.hideTip();
        this.calendarTarget.outerHTML = await response.text();

        const url = new URL(window.location.href);
        params.forEach((value, key) => url.searchParams.set(key, value));
        window.history.replaceState(null, '', url.toString());

        if (this.highlightedRuleId !== null) {
            this.applyHighlight(this.highlightedRuleId);
        }
        if (focusSelector) {
            this.calendarTarget.querySelector(focusSelector)?.focus({ preventScroll: true });
        }
    }

    /** Where keyboard focus returns once the calendar card has been replaced. */
    focusSelectorFor(trigger) {
        if (trigger.matches('select')) {
            return `#${CSS.escape(trigger.id)}`;
        }
        return trigger.dataset.nav ? `[data-nav="${CSS.escape(trigger.dataset.nav)}"]` : null;
    }

    /** Shows where a calendar value comes from. All wording arrives rendered from the server. */
    showTip(event) {
        const cell = event.target.closest('.brc-cell');
        if (!cell || !this.hasCalendarTipTarget) {
            if (event.type === 'focusin') this.hideTip();
            return;
        }

        const tip = this.calendarTipTarget;
        const rules = this.calendarRules();
        tip.replaceChildren();
        this.appendTipText(tip, 'brc-tip__title', cell.dataset.tipTitle);
        this.appendTipText(tip, 'brc-tip__value', cell.dataset.tipValue);

        const rule = rules[cell.dataset.rule];
        if (rule) {
            this.appendTipSection(tip, rule.source, [rule.text]);
        }
        const replaced = (cell.dataset.overridden || '').split(',').map((id) => rules[id]).filter(Boolean);
        if (replaced.length > 0) {
            this.appendTipSection(tip, this.calendarTarget.dataset.tipOverridden, replaced.map((r) => r.text));
        }
        // The top row explains only where the rules add up to more than the day's own rules.
        this.calendarReasons(cell).forEach((reason) => this.appendTipSection(tip, reason.label, [reason.text, reason.source]));

        tip.hidden = false;
        const box = tip.offsetParent.getBoundingClientRect();
        const rect = cell.getBoundingClientRect();
        const left = Math.max(8, Math.min(rect.left - box.left + rect.width / 2 - tip.offsetWidth / 2, box.width - tip.offsetWidth - 8));
        tip.style.left = `${left}px`;
        tip.style.top = `${rect.bottom - box.top + 8}px`;
    }

    hideTip() {
        if (this.hasCalendarTipTarget) {
            this.calendarTipTarget.hidden = true;
        }
    }

    /** The rule sentences of the current calendar, parsed once per rendered card. */
    calendarRules() {
        const calendar = this.calendarTarget;
        if (this.rulesOwner !== calendar) {
            try {
                this.rules = JSON.parse(calendar.dataset.rules || '{}');
            } catch {
                this.rules = {};
            }
            this.rulesOwner = calendar;
        }

        return this.rules;
    }

    /** Why a top-row value is marked, as worded by the server. */
    calendarReasons(cell) {
        try {
            return JSON.parse(cell.dataset.tipReasons || '[]');
        } catch {
            return [];
        }
    }

    appendTipText(tip, className, text) {
        if (!text) return;
        const line = document.createElement('div');
        line.className = className;
        line.textContent = text;
        tip.append(line);
    }

    appendTipSection(tip, label, texts) {
        const section = document.createElement('div');
        const heading = document.createElement('span');
        heading.className = 'brc-tip__label';
        heading.textContent = label || '';
        section.append(heading);
        texts.forEach((text, index) => {
            if (index > 0) section.append(document.createElement('br'));
            section.append(document.createTextNode(text));
        });
        tip.append(section);
    }

    /**
     * Marks the calendar days whose value comes from the clicked rule. The marking stays while
     * scrolling or changing weeks, so a rule far down the list can still be read in the calendar.
     */
    toggleHighlight(event) {
        // The rule's own controls (switch, edit, delete) keep their meaning.
        if (event.target.closest('a, input, form, button:not([data-rule-highlight])')) {
            return;
        }
        const ruleId = event.currentTarget.dataset.ruleId;
        this.highlightedRuleId = this.highlightedRuleId === ruleId ? null : ruleId;
        this.applyHighlight(this.highlightedRuleId);
    }

    clearHighlight() {
        this.highlightedRuleId = null;
        this.applyHighlight(null);
    }

    applyHighlight(ruleId) {
        this.ruleRowTargets.forEach((row) => {
            const marked = row.dataset.ruleId === ruleId;
            row.classList.toggle('is-highlighted', marked);
            row.querySelector('[data-rule-highlight]')?.setAttribute('aria-pressed', String(marked));
        });

        if (!this.hasCalendarTarget) {
            return;
        }

        let found = 0;
        this.calendarTarget.querySelectorAll('.brc-cell').forEach((cell) => {
            const isSource = ruleId !== null && cell.dataset.rule === ruleId;
            const isReplaced = ruleId !== null && (cell.dataset.overridden || '').split(',').includes(ruleId);
            cell.classList.toggle('is-source', isSource);
            cell.classList.toggle('is-replaced', isReplaced);
            if (isSource || isReplaced) found++;
        });

        if (!this.hasCalendarLegendTarget) {
            return;
        }
        // Only visibility changes here: a shifting card would move the hovered rule away from the pointer.
        if (ruleId === null) {
            delete this.calendarLegendTarget.dataset.highlight;
        } else {
            this.calendarLegendTarget.dataset.highlight = found > 0 ? 'found' : 'none';
        }
    }

    initDeletePopovers() {
        enableDeletePopover({
            root: this.element,
            onSuccess: (triggerEl) => {
                const row = triggerEl.closest('li');
                if (row?.dataset.ruleId === this.highlightedRuleId) this.highlightedRuleId = null;
                if (row) row.remove();
                this.updateEmptyStates();
                this.reloadCalendar();
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
