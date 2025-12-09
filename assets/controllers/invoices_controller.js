import { Controller } from '@hotwired/stimulus';
import {
    request as httpRequest,
    getContentForModal as httpGetContentForModal,
    serializeForm as httpSerializeForm,
} from './http_controller.js';
import {
    setLocalStorageItemIfNotExists,
    getLocalStorageItem,
    updatePDFExportLinks,
    enableDeletePopover,
    iniStartOrEndDate
} from './utils_controller.js';

const debounce = (fn, delay = 300) => {
    let t;
    return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...args), delay);
    };
};

export default class extends Controller {
    connect() {
        this.modalContent = document.getElementById('modal-content-ajax');
        const invoicesBootstrapped = this.modalContent.hasAttribute('data-invoices-bootstrapped');
        if (invoicesBootstrapped) {
            return;
        }
        this.modalContent.dataset.invoicesBootstrapped = 'true';
        this.invoiceTable = document.getElementById('invoice-table');
        this.searchForm = document.getElementById('invoices-search-form');
        this.searchInput = this.searchForm ? this.searchForm.querySelector('#search') : null;
        this.debouncedSearch = debounce(() => this.doSearch(), 400);
        this.bindStatusWatcher();
        const templateId = getLocalStorageItem('invoice-template-id');
        if (templateId) {
            updatePDFExportLinks(templateId);
        }
    }

    // Actions
    showCreateInvoiceFormAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const createNewInvoice = event.currentTarget.dataset.createNew === 'true';
        if (!url) return;
        this.setModalTitle(event.currentTarget.dataset.title);
        httpGetContentForModal(`${url}?createNewInvoice=${createNewInvoice}`, event.currentTarget.dataset.title || '');
    }

    openModalAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        if (!url) return;
        this.setModalTitle(event.currentTarget.dataset.title);
        httpGetContentForModal(url, event.currentTarget.dataset.title || '', () => {
            enableDeletePopover();
        });
    }

    showInvoiceAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const edit = event.currentTarget.dataset.edit === 'true';
        if (!url) return;
        httpGetContentForModal(url, event.currentTarget.dataset.title || '', () => {
            if (edit) {
                this.toggleInvoiceEditFields();
            }
        });
    }

    doSearchAction(event) {
        if (event) {
            event.preventDefault();
        }
        this.doSearch();
    }

    searchInputAction() {
        this.debouncedSearch();
    }

    deleteInvoiceAction(event) {
        event.preventDefault();
        const form = event.target.closest('form');
        const url = event.currentTarget.dataset.url;
        if (!form) return;
        httpRequest({
            url,
            method: 'DELETE',
            data: httpSerializeForm(form),
            onSuccess: () => {
                location.reload();
            },
        }); 
    }

    submitFormAction(event) {
        event.preventDefault();
        const form = event.target.closest('form');
        if (!form) return;
        httpRequest({
            url: form.action,
            method: form.method || 'POST',
            data: httpSerializeForm(form),
            target: this.modalContent,
            onComplete: () => {
                enableDeletePopover();
            }
        });
    }

    removeApartmentPositionAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const index = event.currentTarget.dataset.index;
        if (!url) return;
        httpRequest({ url, method: 'POST', data: { appartmentInvoicePositionIndex: index }, target: this.modalContent });
    }

    removeMiscPositionAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const index = event.currentTarget.dataset.index;
        if (!url) return;
        httpRequest({ url, method: 'POST', data: { miscellaneousInvoicePositionIndex: index }, target: this.modalContent });
    }

    showNewInvoicePreviewAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        if (!url) return;
        httpGetContentForModal(url, '');
    }

    createInvoiceAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const successUrl = event.currentTarget.dataset.successUrl;
        const form = event.target.closest('form');
        if (!url || !successUrl) return;
        httpRequest({ 
            url, 
            method: 'POST', 
            data: httpSerializeForm(form),
            onSuccess: () => { 
                location.href = successUrl;
            }
         });
    }

    selectReservationAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const reservationId = event.currentTarget.dataset.reservationId || null;
        if (url) {
            this.selectReservation(reservationId, url);
        }
    }

    fillCustomerRecommendationAction(event) {
        const elm = event.currentTarget;
        let values = (elm.value || '').split('|');
        if (values.length === 1) return;
        const ids = ['invoice_customer_salutation', 'invoice_customer_firstname', 'invoice_customer_lastname', 'invoice_customer_company', 'invoice_customer_address', 'invoice_customer_zip', 'invoice_customer_city', 'invoice_customer_country', 'invoice_customer_phone', 'invoice_customer_email'];
        ids.forEach((id, idx) => {
            const node = document.getElementById(id);
            if (node) {
                node.value = values[idx] || '';
            }
        });
        return false;
    }

    fillFieldsFromPriceCategoryAction(event) {
        const values = (event.currentTarget.value || '').split('|');
        if (values.length === 2) return;
        const map = [
            ['invoice_misc_position_vat', 0],
            ['invoice_misc_position_price', 1],
            ['invoice_misc_position_description', 2],
        ];
        map.forEach(([id, idx]) => {
            const node = document.getElementById(id);
            if (node) node.value = values[idx] || '';
        });
        const includesVat = document.getElementById('invoice_misc_position_includesVat');
        const isFlatPrice = document.getElementById('invoice_misc_position_isFlatPrice');
        if (includesVat) includesVat.checked = values[3] === '1';
        if (isFlatPrice) isFlatPrice.checked = values[4] === '1';
        const amount = document.getElementById('invoice_misc_position_amount');
        if (amount) amount.value = values[4] || '';
        return false;
    }

    fillApartmentFieldsFromPriceCategoryAction(event) {
        const values = (event.currentTarget.value || '').split('|');
        if (values.length === 2) return;
        const vat = document.getElementById('invoice_apartment_position_vat');
        const price = document.getElementById('invoice_apartment_position_price');
        if (vat) vat.value = values[0] || '';
        if (price) price.value = values[1] || '';
        const includesVat = document.getElementById('invoice_apartment_position_includesVat');
        const isFlat = document.getElementById('invoice_apartment_position_isFlatPrice');
        if (includesVat) includesVat.checked = values[2] === '1';
        if (isFlat) isFlat.checked = values[3] === '1';
        return false;
    }

    fillApartmentDescriptionAction(event) {
        const select = event.currentTarget;
        const choicesId = select.dataset.choicesId || 'invoice_apartment_position_description_choices';
        const srcNode = document.getElementById(choicesId);
        if (!srcNode) return;
        const values = (srcNode.value || '').split('|');
        const target = document.getElementById('invoice_apartment_position_description');
        if (target) {
            target.value = values[select.selectedIndex] || '';
        }
    }

    persistTemplateSelectionAction(event) {
        const templateId = event.currentTarget.value;
        setLocalStorageItemIfNotExists('invoice-template-id', templateId, true);
        updatePDFExportLinks(templateId);
    }

    // helpers
    invoiceStatusChangeAction(event) {
        const saveBtn = document.getElementById('save-status');
        if (saveBtn) {
            saveBtn.classList.remove('d-none');
            saveBtn.disabled = false;
        }
    }

    updateInvoiceStatusAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const form = document.getElementById('invoice-form-status');
        const saveBtn = document.getElementById('save-status');
        if (!url || !form) return;
        httpRequest({
            url,
            method: 'POST',
            data: httpSerializeForm(form),
            onSuccess: () => {
                if (saveBtn) {
                    saveBtn.classList.add('d-none');
                    saveBtn.disabled = false;
                }
            },
        });
    }

    toggleInvoiceEditFieldsAction(event) {
        event.preventDefault();
        this.toggleInvoiceEditFields();
    }

    toggleInvoiceDeleteAction(event) {
        event.preventDefault();
        const boxDelete = document.getElementById('boxDelete');
        const boxDefault = document.getElementById('boxDefault');
        if (!boxDelete || !boxDefault) return;
        if (boxDelete.classList.contains('d-none')) {
            boxDelete.classList.remove('d-none');
            boxDefault.classList.add('d-none');
        } else {
            boxDelete.classList.add('d-none');
            boxDefault.classList.remove('d-none');
        }
    }

    removeApartmentPositionEditAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const id = event.currentTarget.dataset.index;
        if (!url) return;
        httpRequest({ url, method: 'POST', data: { appartmentInvoicePositionEditId: id }, target: this.modalContent });
    }

    removeMiscPositionEditAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const id = event.currentTarget.dataset.index;
        if (!url) return;
        httpRequest({ url, method: 'POST', data: { miscellaneousInvoicePositionEditId: id }, target: this.modalContent });
    }

    changeInvoiceRemarkAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        if (!url) return;
        httpGetContentForModal(url, '');
    }

    getReservationsInPeriodAction(event) {
        event.preventDefault();
        iniStartOrEndDate('from', 'end', 1);
        const url = event.currentTarget.dataset.url;
        const form = document.getElementById('invoice-filter-reservations-period');
        if (!url || !form) return;
        httpRequest({
            url,
            method: 'POST',
            data: httpSerializeForm(form),
            target: document.getElementById('container-invoice-filter-reservations-result'),
        });
    }

    getReservationsByCustomerNameAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const form = document.getElementById('invoice-filter-reservations-customer-name');
        if (!url || !form) return;
        httpRequest({
            url,
            method: 'POST',
            data: httpSerializeForm(form),
            target: document.getElementById('container-invoice-filter-reservations-result'),
        });
    }

    showTimeFilterAction(event) {
        event.preventDefault();
        const btnTime = document.getElementById('button-filter-time');
        const btnCustomer = document.getElementById('button-filter-customer');
        const boxTime = document.getElementById('container-invoice-filter-reservations-period');
        const boxCustomer = document.getElementById('container-invoice-filter-reservations-customer');
        if (btnTime) {
            btnTime.classList.add('btn-primary');
            btnTime.classList.remove('btn-secondary');
        }
        if (btnCustomer) {
            btnCustomer.classList.add('btn-secondary');
            btnCustomer.classList.remove('btn-primary');
        }
        if (boxTime) boxTime.classList.remove('d-none');
        if (boxCustomer) boxCustomer.classList.add('d-none');
    }

    showCustomerFilterAction(event) {
        event.preventDefault();
        const btnTime = document.getElementById('button-filter-time');
        const btnCustomer = document.getElementById('button-filter-customer');
        const boxTime = document.getElementById('container-invoice-filter-reservations-period');
        const boxCustomer = document.getElementById('container-invoice-filter-reservations-customer');
        if (btnCustomer) {
            btnCustomer.classList.add('btn-primary');
            btnCustomer.classList.remove('btn-secondary');
        }
        if (btnTime) {
            btnTime.classList.add('btn-secondary');
            btnTime.classList.remove('btn-primary');
        }
        if (boxTime) boxTime.classList.add('d-none');
        if (boxCustomer) boxCustomer.classList.remove('d-none');
    }

    showCreateInvoicePositionsAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const createFlag = event.currentTarget.dataset.createPositions;
        const baseForm = document.getElementById('new-invoice-id');
        if (!url) return;
        const data = `${baseForm ? httpSerializeForm(baseForm) + '&' : ''}createInvoicePositions=${createFlag}`;
        httpRequest({ 
            url, 
            method: 'POST', 
            data, 
            target: this.modalContent 
        });
    }

    deleteReservationFromSelectionAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const key = event.currentTarget.dataset.reservationKey;
        if (!url) return;
        httpRequest({ url, method: 'POST', data: { reservationkey: key }, target: this.modalContent });
    }

    showCreateInvoiceFormAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const createNewInvoice = event.currentTarget.dataset.createNew === 'true';
        if (!url) return;
        this.setModalTitle(event.currentTarget.dataset.title);
        httpGetContentForModal(`${url}?createNewInvoice=${createNewInvoice}`, event.currentTarget.dataset.title || '');
    }

    toggleInvoiceEditFields() {
        const fields = document.querySelectorAll('.invoice-edit-field');
        const editButton = document.getElementById('invoiceEditButton');
        const hidden = fields.length ? fields[0].classList.contains('d-none') : false;
        fields.forEach((f) => f.classList.toggle('d-none', !hidden ? true : false));
        if (editButton && hidden) {
            editButton.classList.add('d-none');
        }
    }

    setModalTitle(title) {
        if (!title) return;
        const modalTitle = document.querySelector('#modalCenter .modal-title');
        if (modalTitle) {
            modalTitle.textContent = title;
        }
    }

    selectReservation(id, url) {
        httpRequest({
            url,
            method: 'POST',
            data: id ? { reservationid: id } : {},
            target: this.modalContent
        });
        return false;
    }

    bindStatusWatcher() {
        const statusSelect = document.getElementById('invoce-status');
        if (statusSelect) {
            statusSelect.addEventListener('change', () => this.invoiceStatusChangeAction(new Event('change')));
        }
    }

    doSearch() {
        if (!this.searchForm) return;
        const url = this.searchForm.dataset.searchUrl;
        if (!url) return;
        httpRequest({
            url,
            method: 'POST',
            data: `${httpSerializeForm(this.searchForm)}&${httpSerializeForm('#page')}`,
            target: this.invoiceTable,
        });
    }
}
