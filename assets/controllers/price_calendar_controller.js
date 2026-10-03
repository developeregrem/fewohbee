import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

const PHONE_WIDTH = 768;

/**
 * Price calendar: reloads the page when the selection changes, opens a night in the offcanvas
 * (from the bottom on phones, from the right otherwise) and saves its day price. How a price
 * comes about is decided on the server.
 */
export default class extends Controller {
    static targets = ['offcanvas', 'offcanvasTitle', 'offcanvasBody'];

    filter(event) {
        event.currentTarget.requestSubmit();
    }

    async openNight(event) {
        const button = event.currentTarget;
        this.offcanvasTitleTarget.textContent = button.dataset.title || '';
        this.offcanvasBodyTarget.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-muted"></i></div>';

        const phone = window.innerWidth < PHONE_WIDTH;
        this.offcanvasTarget.classList.toggle('offcanvas-bottom', phone);
        this.offcanvasTarget.classList.toggle('offcanvas-end', !phone);
        window.bootstrap.Offcanvas.getOrCreateInstance(this.offcanvasTarget).show();

        try {
            const response = await fetch(button.dataset.url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            this.offcanvasBodyTarget.innerHTML = await response.text();
        } catch {
            this.offcanvasBodyTarget.innerHTML = `<div class="alert alert-danger mb-0">${this.element.dataset.priceCalendarLoadError || ''}</div>`;
        }
    }

    /** Saves or resets the day price; the page reloads so the calendar shows the result. */
    async save(event) {
        event.preventDefault();
        const form = event.currentTarget;
        // Collected before the buttons are disabled: a disabled submitter would not be sent,
        // and "back to automatic" would save instead.
        const body = new FormData(form, event.submitter);
        const buttons = form.querySelectorAll('button[type="submit"]');
        const error = form.querySelector('[data-price-calendar-error]');
        buttons.forEach((button) => { button.disabled = true; });

        try {
            const response = await fetch(form.getAttribute('action'), {
                method: 'POST',
                body,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (response.status === 204) {
                window.location.reload();
                return;
            }
            if (error) {
                error.textContent = await response.text();
                error.hidden = false;
            }
        } finally {
            buttons.forEach((button) => { button.disabled = false; });
        }
    }
}
