import { Controller } from '@hotwired/stimulus';
import { enableDeletePopover } from '../js/utils.js';

/* stimulusFetch: 'lazy' */

const PHONE_WIDTH = 768;
const PREVIEW_DELAY = 400;

/**
 * Price rules settings page: opens the template picker, rule form and limits form in one
 * offcanvas (from the bottom on phones, from the right otherwise), shows only the fields the
 * chosen condition uses and keeps the preview in step with the form. Every decision about what
 * a rule does is made on the server.
 */
export default class extends Controller {
    static targets = ['offcanvas', 'offcanvasTitle', 'offcanvasBody', 'list', 'empty'];

    connect() {
        this.previewTimer = null;
        enableDeletePopover({
            root: this.element,
            onSuccess: (trigger) => {
                trigger.closest('li')?.remove();
                this.updateEmptyState();
            },
        });
    }

    disconnect() {
        clearTimeout(this.previewTimer);
    }

    async openOffcanvas(event) {
        event.preventDefault();
        const button = event.currentTarget;
        this.offcanvasTitleTarget.textContent = button.dataset.offcanvasTitle || '';
        this.offcanvasBodyTarget.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-muted"></i></div>';

        const phone = window.innerWidth < PHONE_WIDTH;
        this.offcanvasTarget.classList.toggle('offcanvas-bottom', phone);
        this.offcanvasTarget.classList.toggle('offcanvas-end', !phone);
        window.bootstrap.Offcanvas.getOrCreateInstance(this.offcanvasTarget).show();

        try {
            const response = await fetch(button.dataset.url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            this.offcanvasBodyTarget.innerHTML = await response.text();
            this.formLoaded();
        } catch {
            this.offcanvasBodyTarget.innerHTML = `<div class="alert alert-danger mb-0">${this.element.dataset.priceRulesLoadError || ''}</div>`;
        }
    }

    /** Submits over fetch so validation errors land back in the offcanvas; a save reloads the page. */
    async submitForm(event) {
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
                window.location.reload();
                return;
            }
            this.offcanvasBodyTarget.innerHTML = await response.text();
            this.formLoaded();
        } finally {
            if (submit) submit.disabled = false;
        }
    }

    /** Switches a rule on or off in place, keeping the scroll position of a long list. */
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
        const nowEnabled = row.classList.contains('opacity-50');
        row.classList.toggle('opacity-50', !nowEnabled);
        const badge = row.querySelector('[data-disabled-badge]');
        if (badge) badge.hidden = nowEnabled;

        const button = form.querySelector('button');
        button.title = nowEnabled ? button.dataset.disableLabel : button.dataset.enableLabel;
        button.classList.toggle('btn-success', nowEnabled);
        button.classList.toggle('btn-outline-secondary', !nowEnabled);
        const icon = button.querySelector('i');
        icon?.classList.toggle('fa-toggle-on', nowEnabled);
        icon?.classList.toggle('fa-toggle-off', !nowEnabled);
    }

    changed() {
        this.refreshFields();
        clearTimeout(this.previewTimer);
        this.previewTimer = setTimeout(() => this.loadPreview(), PREVIEW_DELAY);
    }

    formLoaded() {
        this.refreshFields();
        this.loadPreview();
    }

    /** Shows the fields and the explanation of the chosen condition; "all" greys out the selection. */
    refreshFields() {
        const form = this.offcanvasBodyTarget.querySelector('form');
        if (!form) {
            return;
        }

        const condition = form.querySelector('select[name$="[condition]"]')?.value;
        form.querySelectorAll('[data-conditions]').forEach((row) => {
            row.hidden = !row.dataset.conditions.split(' ').includes(condition);
        });
        form.querySelectorAll('[data-condition-help]').forEach((help) => {
            help.hidden = help.dataset.conditionHelp !== condition;
        });
        form.querySelectorAll('[data-scope]').forEach((scope) => {
            const all = scope.querySelector('[data-scope-all]')?.checked ?? false;
            scope.querySelectorAll('[data-scope-list] input').forEach((input) => { input.disabled = all; });
            scope.querySelector('[data-scope-list]')?.classList.toggle('opacity-50', all);
        });
    }

    async loadPreview() {
        const form = this.offcanvasBodyTarget.querySelector('form[data-preview-url]');
        const target = form?.querySelector('[data-price-rules-preview]');
        if (!target) {
            return;
        }

        const response = await fetch(form.dataset.previewUrl, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (response.ok) {
            target.innerHTML = await response.text();
        }
    }

    updateEmptyState() {
        const hasRules = this.listTarget.querySelector('li') !== null;
        this.listTarget.hidden = !hasRules;
        this.emptyTarget.hidden = hasRules;
    }
}
