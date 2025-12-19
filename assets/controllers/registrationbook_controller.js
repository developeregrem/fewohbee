import { Controller } from '@hotwired/stimulus';
import {
    request as httpRequest,
    serializeForm as httpSerializeForm,
} from './http_controller.js';
import { iniStartOrEndDate, setModalTitle } from './utils_controller.js';

export default class extends Controller {
    static targets = ['searchForm', 'searchInput', 'table', 'page', 'bookEntry'];
    static values = {
        searchUrl: String,
    };

    connect() {
        this.modalContent = document.getElementById('modal-content-ajax');
        this.searchDebounce = null;
        this.customerSearchDebounce = null;
        const firstRow = this.element.querySelector('.js-registrationbook-reservation-row');
        if (firstRow) {
            this.toggleReservationCustomers(firstRow);
        }
    }

    // ---- Search & pagination ----
    searchAction(event) {
        if (event) {
            event.preventDefault();
        }
        if (this.hasPageTarget) {
            this.pageTarget.value = this.pageTarget.value || '1';
        }
        this.performSearch();
    }

    searchInputAction(event) {
        if (event) {
            event.preventDefault();
        }
        if (this.hasPageTarget) {
            this.pageTarget.value = '1';
        }
        clearTimeout(this.searchDebounce);
        this.searchDebounce = window.setTimeout(() => this.performSearch(), 400);
    }

    paginateAction(event) {
        if (event) {
            event.preventDefault();
        }
        const page = event?.currentTarget?.dataset.page || null;
        if (page && this.hasPageTarget) {
            this.pageTarget.value = page;
        }
        this.performSearch();
    }

    performSearch() {
        if (!this.hasSearchFormTarget || !this.hasTableTarget) {
            return;
        }
        const url = this.searchUrlValue || this.searchFormTarget.dataset.searchUrl;
        if (!url) {
            return;
        }
        const data = new FormData(this.searchFormTarget);
        if (this.hasPageTarget) {
            data.set('page', this.pageTarget.value || '1');
        }
        httpRequest({
            url,
            method: 'POST',
            data,
            target: this.tableTarget,
            onSuccess: (response) => {
                this.tableTarget.innerHTML = response;
            },
        });
    }

    // ---- Modal actions ----
    openAddReservationsAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const title = event.currentTarget.dataset.title || '';
        this.openAddReservations(url, title);
    }

    addReservationToBookAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const reservationId = event.currentTarget.dataset.reservationId;
        if (!url || !reservationId) {
            return;
        }
        const token = document.getElementById('_csrf_token')?.value || '';
        const data = new FormData();
        data.append('id', reservationId);
        if (token) {
            data.append('_csrf_token', token);
        }
        httpRequest({
            url,
            method: 'POST',
            data,
            target: this.modalContent,
            onSuccess: (response) => {
                if (this.modalContent) {
                    this.modalContent.innerHTML = response;
                }
                this.performSearch();
            },
        });
    }

    deleteReservationCustomerAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const formSelector = event.currentTarget.dataset.formSelector;
        const form = formSelector ? document.querySelector(formSelector) : null;
        if (!url || !form) {
            return;
        }
        httpRequest({
            url,
            method: 'POST',
            data: new FormData(form),
            target: this.modalContent,
        });
    }

    addReservationCustomerAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const reservationId = event.currentTarget.dataset.reservationId;
        if (!url || !reservationId) {
            return;
        }
        const data = new FormData();
        data.append('id', reservationId);
        httpRequest({
            url,
            method: 'POST',
            data,
            target: this.modalContent,
        });
    }

    selectCustomerFromListAction(event) {
        event.preventDefault();
        const dataset = event.currentTarget.dataset;
        const url = dataset.url || this.element.dataset.registrationbookEditCustomerUrl;
        const customerId = dataset.customerId;
        const tab = dataset.tab || 'guest';
        const appartmentId = dataset.appartmentId || 0;
        if (!url || !customerId) {
            return;
        }
        httpRequest({
            url,
            method: 'POST',
            data: { customerId, tab, appartmentId },
            loader: true,
            onSuccess: () => {
                this.openAddReservations(
                    this.element.dataset.registrationbookAddReservationsUrl,
                    this.element.dataset.registrationbookAddReservationsTitle
                );
            },
        });
    }

    editReservationCustomerAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const customerId = event.currentTarget.dataset.customerId;
        if (!url || !customerId) {
            return;
        }
        const data = new FormData();
        data.append('id', customerId);
        httpRequest({
            url,
            method: 'POST',
            data,
            target: this.modalContent,
        });
    }

    editReservationNewCustomerAction(event) {
        event.preventDefault();
        const form = event.target.closest('form');
        if (!form) {
            return;
        }
        const tab = form.querySelector('#tab')?.value;
        const url = form.dataset.url;
        this.editReservationNewCustomer(url, tab);
    }

    openAddReservations(url, title) {
        if (!url) {
            return false;
        }
        setModalTitle(title || '');
        httpRequest({
            url,
            method: 'GET',
            target: this.modalContent,
        });
        return false;
    }

    editReservationNewCustomer(url, tab) {
        if (!url) {
            return false;
        }
        httpRequest({
            url: url,
            method: 'POST',
            data: httpSerializeForm('#customer-selection'),
            target: this.modalContent,
            onSuccess: (data) => {
                this.openAddReservations(
                    this.element.dataset.registrationbookAddReservationsUrl,
                    this.element.dataset.registrationbookAddReservationsTitle
                );
            }
        });
        return false;
    }

    // ---- Reservation customer selection (reuse reservations controller) ----
    customersSearchAction(event) {
        event.preventDefault();
        this.triggerReservationsGetCustomers(event.currentTarget, false);
    }

    customersSearchInputAction(event) {
        event.preventDefault();
        clearTimeout(this.customerSearchDebounce);
        this.customerSearchDebounce = window.setTimeout(() => {
            this.triggerReservationsGetCustomers(event.target, true);
        }, 400);
    }

    triggerReservationsGetCustomers(target, resetPage = false) {
        const reservationsCtrl = this.getReservationsController();
        if (!reservationsCtrl || !target) {
            return;
        }
        const mode = target.dataset.mode || 'edit';
        const tab = target.dataset.tab || document.getElementById('tab')?.value || '';
        const appartmentId = target.dataset.appartmentId;
        const page = resetPage ? 1 : (target.dataset.page || 1);
        reservationsCtrl.getCustomers(page, mode, tab, appartmentId);
    }

    getReservationsController() {
        return this.application.getControllerForElementAndIdentifier(this.element, 'reservations');
    }

    // ---- Date filtering ----
    getAvailableBookEntriesAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url || this.element.dataset.registrationbookShowaddUrl;
        if (!url) {
            return;
        }
        iniStartOrEndDate('start', 'end', 1);
        const form = document.getElementById('registration-period');
        httpRequest({
            url,
            method: 'GET',
            data: form ? new FormData(form) : null,
            target: this.modalContent,
        });
    }

    // ---- UI helpers ----
    toggleReservationCustomersAction(event) {
        event.preventDefault();
        const row = event.currentTarget.closest('tr');
        this.toggleReservationCustomers(row);
    }

    toggleReservationCustomers(row) {
        if (!row) return;
        row.parentElement.querySelectorAll('tr').forEach((tr) => tr.classList.remove('cell-selected'));
        row.classList.add('cell-selected');
        const details = row.querySelector('.registrationbook-reservation-details');
        if (details && this.hasBookEntryTarget) {
            this.bookEntryTarget.innerHTML = details.innerHTML;
        }
    }

    deleteEntryAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const entryId = event.currentTarget.dataset.entryId;
        if (!url || !entryId) {
            return;
        }
        const row = document.getElementById(`entry-${entryId}`);
        const cell = document.getElementById(`entry-cell-${entryId}`);
        if (!row || !cell) {
            return;
        }
        const wasHidden = row.classList.contains('d-none');
        this.toggleEntryRow(entryId);
        if (!wasHidden) {
            return;
        }
        cell.innerHTML = window.loader || '';
        httpRequest({
            url,
            method: 'GET',
            target: cell,
            onSuccess: () => {
                this.hydrateDeleteForm(entryId);
            },
        });
    }

    toggleEntryRow(entryId) {
        const row = document.getElementById(`entry-${entryId}`);
        if (!row) {
            return;
        }
        row.classList.toggle('d-none');
    }

    hydrateDeleteForm(entryId) {
        const cell = document.getElementById(`entry-cell-${entryId}`);
        if (!cell) {
            return;
        }
        const form = cell.querySelector('form');
        if (form) {
            form.removeAttribute('onsubmit');
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                this.doDeleteEntry(entryId, form.action || form.dataset.url, form);
            });
        }
        const cancelBtn = cell.querySelector('button.btn-secondary');
        if (cancelBtn) {
            cancelBtn.removeAttribute('onclick');
            cancelBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.toggleEntryRow(entryId);
            });
        }
    }

    doDeleteEntry(entryId, url, form) {
        if (!url) {
            url = form && form.action;
        }
        if (!url) {
            return;
        }
        httpRequest({
            url,
            method: 'POST',
            data: form ? new FormData(form) : null,
            onSuccess: () => {
                this.performSearch();
            },
        });
    }
}
