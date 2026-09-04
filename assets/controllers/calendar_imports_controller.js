import { Controller } from '@hotwired/stimulus';
import { disposeTooltips, enableDeletePopover, enableTooltips } from '../js/utils.js';

/* stimulusFetch: 'lazy' */

export default class extends Controller {
    static targets = ['list', 'edit', 'create', 'editPanel'];
    static values = {
        previewUrl: String,
        previewToken: String,
    };

    connect() {
        enableDeletePopover();
        this.element.querySelectorAll('[data-calendar-imports-filter]').forEach((section) => this.renderTerms(section));
        enableTooltips(this.element);
    }

    disconnect() {
        disposeTooltips(this.element);
    }

    showEdit(event) {
        event.preventDefault();
        const importId = event.currentTarget.dataset.importId;
        if (!importId) {
            return;
        }
        this.hideAllEditPanels();
        const panel = this.editPanelTargets.find((item) => item.dataset.importId === importId);
        if (panel) {
            panel.classList.remove('d-none');
        }
        this.listTarget.classList.add('d-none');
        if (this.hasCreateTarget) {
            this.createTarget.classList.add('d-none');
        }
        this.editTarget.classList.remove('d-none');
    }

    showList(event) {
        if (event) {
            event.preventDefault();
        }
        this.editTarget.classList.add('d-none');
        if (this.hasCreateTarget) {
            this.createTarget.classList.add('d-none');
        }
        this.listTarget.classList.remove('d-none');
    }

    toggleCreate(event) {
        event.preventDefault();
        if (!this.hasCreateTarget) {
            return;
        }
        if (this.createTarget.classList.contains('d-none')) {
            this.createTarget.classList.remove('d-none');
        } else {
            this.createTarget.classList.add('d-none');
        }
    }

    async preview(event) {
        event.preventDefault();
        const button = event.currentTarget;
        const section = button.closest('[data-calendar-imports-filter]');
        const form = button.closest('form');
        const urlInput = form?.querySelector('[data-calendar-imports-filter-url]');
        if (!section || !urlInput) {
            return;
        }

        this.setPreviewLoading(button, true);
        this.hidePreview(section);

        const body = new FormData();
        body.append('url', urlInput.value);
        body.append('_token', this.previewTokenValue);

        try {
            const response = await fetch(this.previewUrlValue, {
                method: 'POST',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                body,
            });
            const payload = await response.json();
            if (!response.ok || !payload.success) {
                throw new Error(payload.message || this.genericError(section));
            }

            this.reuseSharedFilters(section, form, payload.sharedFilters);
            this.renderPreview(section, payload.groups || []);
            this.showStatus(section, payload.message || '', 'success');
        } catch (error) {
            const message = error instanceof Error && error.message ? error.message : this.genericError(section);
            this.showStatus(section, message, 'danger');
        } finally {
            this.setPreviewLoading(button, false);
        }
    }

    urlChanged(event) {
        const section = event.currentTarget.closest('form')?.querySelector('[data-calendar-imports-filter]');
        if (section) {
            this.hidePreview(section);
        }
    }

    toggleSummary(event) {
        const checkbox = event.currentTarget;
        const section = checkbox.closest('[data-calendar-imports-filter]');
        const fields = section?.querySelector('[data-calendar-imports-exact-fields]');
        const filterValue = checkbox.dataset.filterValue || '';
        if (!section || !fields || !filterValue) {
            return;
        }

        const values = this.fieldValues(fields).filter((value) => this.normalize(value) !== this.normalize(filterValue));
        if (!checkbox.checked) {
            values.push(filterValue);
        }
        this.syncFieldValues(fields, values);
        section.dataset.filtersTouched = 'true';
    }

    addTerm(event) {
        event.preventDefault();
        const section = event.currentTarget.closest('[data-calendar-imports-filter]');
        const input = section?.querySelector('[data-calendar-imports-term-input]');
        const fields = section?.querySelector('[data-calendar-imports-term-fields]');
        const exactFields = section?.querySelector('[data-calendar-imports-exact-fields]');
        const value = input?.value.trim() || '';
        if (!section || !input || !fields || !exactFields || !value) {
            return;
        }

        const terms = this.fieldValues(fields);
        if (!terms.some((term) => this.normalize(term) === this.normalize(value)) && terms.length < 50) {
            terms.push(value.slice(0, 255));
            this.syncFieldValues(fields, terms);

            // A broader term supersedes exact exclusions it already covers.
            const normalizedTerm = this.normalize(value);
            const exactValues = this.fieldValues(exactFields).filter(
                (exact) => !this.normalize(exact).includes(normalizedTerm),
            );
            this.syncFieldValues(exactFields, exactValues);
        }

        input.value = '';
        section.dataset.filtersTouched = 'true';
        this.renderTerms(section);
        this.applyPreviewFilters(section);
    }

    removeTerm(event) {
        const section = event.currentTarget.closest('[data-calendar-imports-filter]');
        const fields = section?.querySelector('[data-calendar-imports-term-fields]');
        const value = event.currentTarget.dataset.termValue || '';
        if (!section || !fields) {
            return;
        }

        this.syncFieldValues(
            fields,
            this.fieldValues(fields).filter((term) => this.normalize(term) !== this.normalize(value)),
        );
        section.dataset.filtersTouched = 'true';
        this.renderTerms(section);
        this.applyPreviewFilters(section);
    }

    renderPreview(section, groups) {
        const list = section.querySelector('[data-calendar-imports-preview-list]');
        const results = section.querySelector('[data-calendar-imports-preview-results]');
        const template = section.querySelector('[data-calendar-imports-preview-row-template]');
        if (!list || !results || !(template instanceof HTMLTemplateElement)) {
            return;
        }

        list.replaceChildren();
        groups.forEach((group) => {
            const fragment = template.content.cloneNode(true);
            const checkbox = fragment.querySelector('[data-calendar-imports-preview-toggle]');
            const summary = fragment.querySelector('[data-calendar-imports-preview-summary]');
            const dates = fragment.querySelector('[data-calendar-imports-preview-dates]');
            const count = fragment.querySelector('[data-calendar-imports-preview-count]');

            checkbox.dataset.filterValue = group.filterValue;
            summary.textContent = group.summary || section.querySelector('[data-calendar-imports-empty-summary]')?.textContent || '';
            dates.textContent = this.formatPeriod(group.exampleStart, group.exampleEnd);
            count.textContent = String(group.count);
            list.append(fragment);
        });

        this.applyPreviewFilters(section);
        results.classList.toggle('d-none', groups.length === 0);
        section.querySelector('[data-calendar-imports-configured-note]')?.classList.add('d-none');
    }

    reuseSharedFilters(section, form, sharedFilters) {
        const sharing = form?.querySelector('[data-calendar-imports-filter-sharing]');
        const exactFields = section.querySelector('[data-calendar-imports-exact-fields]');
        const termFields = section.querySelector('[data-calendar-imports-term-fields]');
        if (
            !sharedFilters
            || !sharing?.checked
            || section.dataset.filtersTouched === 'true'
            || !exactFields
            || !termFields
            || this.fieldValues(exactFields).length > 0
            || this.fieldValues(termFields).length > 0
        ) {
            return;
        }

        const exact = Array.isArray(sharedFilters.exact) ? sharedFilters.exact : [];
        const terms = Array.isArray(sharedFilters.terms) ? sharedFilters.terms : [];
        this.syncFieldValues(exactFields, exact);
        this.syncFieldValues(termFields, terms);
        this.renderTerms(section);
    }

    applyPreviewFilters(section) {
        const exactFields = section.querySelector('[data-calendar-imports-exact-fields]');
        const termFields = section.querySelector('[data-calendar-imports-term-fields]');
        if (!exactFields || !termFields) {
            return;
        }

        const exactValues = this.fieldValues(exactFields).map((value) => this.normalize(value));
        const terms = this.fieldValues(termFields).map((term) => this.normalize(term)).filter(Boolean);
        section.querySelectorAll('[data-calendar-imports-preview-toggle]').forEach((checkbox) => {
            const value = this.normalize(checkbox.dataset.filterValue || '');
            const excludedByTerm = terms.some((term) => value.includes(term));
            checkbox.checked = !excludedByTerm && !exactValues.includes(value);
            checkbox.disabled = excludedByTerm;
            checkbox.closest('.list-group-item')?.classList.toggle('text-body-secondary', excludedByTerm);
        });
    }

    renderTerms(section) {
        const fields = section.querySelector('[data-calendar-imports-term-fields]');
        const list = section.querySelector('[data-calendar-imports-term-list]');
        const template = section.querySelector('[data-calendar-imports-term-template]');
        if (!fields || !list || !(template instanceof HTMLTemplateElement)) {
            return;
        }

        const terms = this.fieldValues(fields);
        const details = list.closest('details');
        if (details && terms.length > 0) {
            details.open = true;
        }
        list.replaceChildren();
        terms.forEach((term) => {
            const fragment = template.content.cloneNode(true);
            fragment.querySelector('[data-calendar-imports-term-label]').textContent = term;
            fragment.querySelector('[data-action*="removeTerm"]').dataset.termValue = term;
            list.append(fragment);
        });
    }

    fieldValues(container) {
        return Array.from(container.querySelectorAll('input[type="hidden"]'))
            .map((input) => input.value.trim())
            .filter(Boolean);
    }

    syncFieldValues(container, values) {
        const fieldName = container.dataset.fieldName;
        if (!fieldName) {
            return;
        }

        container.replaceChildren(...values.map((value, index) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `${fieldName}[${index}]`;
            input.value = value;

            return input;
        }));
    }

    formatPeriod(start, end) {
        const formatter = new Intl.DateTimeFormat(document.documentElement.lang || undefined, {dateStyle: 'medium'});
        const startLabel = formatter.format(this.localDate(start));
        if (!end || end === start) {
            return startLabel;
        }

        return `${startLabel} – ${formatter.format(this.localDate(end))}`;
    }

    localDate(value) {
        const [year, month, day] = value.split('-').map(Number);

        return new Date(year, month - 1, day);
    }

    normalize(value) {
        return value.trim().replace(/\s+/gu, ' ').toLowerCase().slice(0, 255);
    }

    setPreviewLoading(button, loading) {
        button.disabled = loading;
        button.querySelector('[data-calendar-imports-preview-button-label]')?.classList.toggle('d-none', loading);
        button.querySelector('[data-calendar-imports-preview-loading]')?.classList.toggle('d-none', !loading);
    }

    hidePreview(section) {
        section.querySelector('[data-calendar-imports-preview-results]')?.classList.add('d-none');
        section.querySelector('[data-calendar-imports-preview-status]')?.classList.add('d-none');
    }

    showStatus(section, message, type) {
        const status = section.querySelector('[data-calendar-imports-preview-status]');
        if (!status) {
            return;
        }

        status.className = `alert alert-${type} py-2 mb-0 mt-3`;
        status.textContent = message;
    }

    genericError(section) {
        return section.querySelector('[data-calendar-imports-generic-error]')?.textContent || '';
    }

    hideAllEditPanels() {
        this.editPanelTargets.forEach((panel) => panel.classList.add('d-none'));
    }
}
