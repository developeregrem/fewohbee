import { Controller } from '@hotwired/stimulus';
import { enableDeletePopover, enableTooltips, disposeTooltips } from '../js/utils.js';

/* stimulusFetch: 'lazy' */

export default class extends Controller {
    static targets = ['offcanvas', 'offcanvasTitle', 'offcanvasBody'];
    static values = {
        formLoadFailed: String,
    };

    connect() {
        enableDeletePopover({ root: this.element });
        enableTooltips(this.element);
    }

    disconnect() {
        disposeTooltips(this.element);
    }

    async openOffcanvas(event) {
        event.preventDefault();

        const button = event.currentTarget;
        const url = button.dataset.url;
        const title = button.dataset.offcanvasTitle;

        this.offcanvasTitleTarget.textContent = title;
        this.offcanvasBodyTarget.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-muted"></i></div>';

        const offcanvas = window.bootstrap.Offcanvas.getOrCreateInstance(this.offcanvasTarget);
        offcanvas.show();

        try {
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            this.offcanvasBodyTarget.innerHTML = await response.text();
        } catch {
            const alert = document.createElement('div');
            alert.className = 'alert alert-danger';
            alert.textContent = this.formLoadFailedValue;
            this.offcanvasBodyTarget.replaceChildren(alert);
        }
    }
}
