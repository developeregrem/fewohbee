import { Controller } from '@hotwired/stimulus';
import { enableDeletePopover } from '../js/utils.js';

/* stimulusFetch: 'lazy' */

export default class extends Controller {
    static targets = ['yearSelect', 'batchTable', 'offcanvas', 'offcanvasTitle', 'offcanvasBody'];
    static values = { batchesUrl: String };

    connect() {
        this._waitForBootstrapAndInit();
        this._loadBatches();
    }

    disconnect() {
        this._disposeTooltips();
    }

    _waitForBootstrapAndInit(attempt = 0) {
        if ((!window.bootstrap || !window.bootstrap.Popover) && attempt < 20) {
            setTimeout(() => this._waitForBootstrapAndInit(attempt + 1), 100);
            return;
        }
        enableDeletePopover();
        this._initTooltips();
    }

    yearChange() {
        this._loadBatches();
    }

    paginateBatchesAction(event) {
        event.preventDefault();
        const page = event.currentTarget.dataset.page || 1;
        this._loadBatches(page);
    }

    async _loadBatches(page = 1) {
        if (!this.hasBatchTableTarget || !this.hasBatchesUrlValue) return;

        const year = this.hasYearSelectTarget ? this.yearSelectTarget.value : '';
        const url = this.batchesUrlValue + '?year=' + encodeURIComponent(year) + '&page=' + encodeURIComponent(page);

        try {
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            this.batchTableTarget.innerHTML = await response.text();
        } catch {
            // silent
        }
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
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            this.offcanvasBodyTarget.innerHTML = await response.text();
        } catch {
            this.offcanvasBodyTarget.innerHTML = '<div class="alert alert-danger">Error loading form.</div>';
        }
    }

    _initTooltips() {
        this._tooltips = [...this.element.querySelectorAll('[data-bs-toggle="tooltip"]')]
            .map(el => new window.bootstrap.Tooltip(el));
    }

    _disposeTooltips() {
        (this._tooltips || []).forEach(t => t.dispose());
    }
}
