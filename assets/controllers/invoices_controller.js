import { Controller } from '@hotwired/stimulus';
import {
    request as httpRequest,
    serializeForm as httpSerializeForm,
} from '../js/http.js';
import {
    setLocalStorageItemIfNotExists,
    getLocalStorageItem,
    updatePDFExportLinks,
    enableDeletePopover,
    setModalTitle
} from '../js/utils.js';

/* stimulusFetch: 'lazy' */

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
    openModalAction(event) {
        event.preventDefault();
        let url = event.currentTarget.dataset.url;
        if (!url) return;
        const createNew = event.currentTarget.dataset.createNew;
        if (typeof createNew !== 'undefined') {
            const separator = url.includes('?') ? '&' : '?';
            url = `${url}${separator}createNew=${encodeURIComponent(createNew)}`;
        }
        const title = event.currentTarget.dataset.title || '';
        setModalTitle(title);
        const target = this.modalContent || document.getElementById('modal-content-ajax');
        
        httpRequest({
            url,
            method: 'GET',
            target,
            onComplete: () => {
                enableDeletePopover();
                this.syncFlatPricePerRoomStates();
                const templateSelect = target?.querySelector('#template');
                if (templateSelect) {
                    const storedTemplateId = getLocalStorageItem('invoice-template-id');
                    if (storedTemplateId) {
                        templateSelect.value = storedTemplateId;
                    }
                }
            },
        });
    }

    showInvoiceAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const edit = event.currentTarget.dataset.edit === 'true';
        if (!url) return;
        const target = this.modalContent || document.getElementById('modal-content-ajax');
        
        httpRequest({
            url,
            method: 'GET',
            target,
            onComplete: () => {
                this.syncFlatPricePerRoomStates();
                if (edit) {
                    this.toggleInvoiceEditFields();
                }
            },
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
        const target = this.modalContent || document.getElementById('modal-content-ajax');
        
        httpRequest({
            url,
            method: 'GET',
            target,
        });
    }

    createInvoiceAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const successUrl = event.currentTarget.dataset.successUrl;
        const form = event.target.closest('form');
        if (!url || !successUrl || !form) return;
        httpRequest({ 
            url, 
            method: 'POST', 
            data: httpSerializeForm(form),
            onSuccess: () => { 
                location.href = successUrl;
            }
         });
    }

    fillCustomerRecommendationAction(event) {
        const elm = event.currentTarget;
        let values = (elm.value || '').split('|');
        if (values.length === 1) return;
        const ids = ['invoice_customer_salutation', 'invoice_customer_firstname', 'invoice_customer_lastname', 'invoice_customer_company', 'invoice_customer_address', 'invoice_customer_zip', 'invoice_customer_city', 'invoice_customer_country', 'invoice_customer_phone', 'invoice_customer_email', 'invoice_customer_buyerVatId', 'invoice_customer_buyerReference', 'invoice_customer_customerIBAN'];
        ids.forEach((id, idx) => {
            const node = document.getElementById(id);
            if (node) {
                node.value = values[idx] || '';
            }
        });
        return false;
    }

    fillFieldsFromPriceCategoryAction(event) {
        const select = event.currentTarget;
        const selected = select.options[select.selectedIndex];
        const values = (select.value || '').split('|');
        const isPackage = !!(selected && selected.dataset.isPackage === '1');
        const priceId = selected ? selected.dataset.priceId || '' : '';

        const packageHidden = document.getElementById('packagePriceId');
        const packageInfo = document.getElementById('package-info');
        const description = document.getElementById('invoice_misc_position_description');
        const vat = document.getElementById('invoice_misc_position_vat');
        const price = document.getElementById('invoice_misc_position_price');
        const includesVat = document.getElementById('invoice_misc_position_includesVat');
        const isFlatPrice = document.getElementById('invoice_misc_position_isFlatPrice');
        const isPerRoom = document.getElementById('invoice_misc_position_isPerRoom');

        if (packageHidden) packageHidden.value = isPackage ? priceId : '';
        if (packageInfo) packageInfo.classList.toggle('d-none', !isPackage);
        [description, vat, price, includesVat, isFlatPrice, isPerRoom].forEach((node) => {
            if (node) node.disabled = isPackage;
        });

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
        if (includesVat) includesVat.checked = values[3] === '1';
        if (isFlatPrice) isFlatPrice.checked = values[4] === '1';
        if (isPerRoom) isPerRoom.checked = values[5] === '1';
        if (isFlatPrice && !isPackage) {
            this.applyFlatPriceState(isFlatPrice, isPerRoom);
        }
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
        const isPerRoom = document.getElementById('invoice_apartment_position_isPerRoom');
        if (includesVat) includesVat.checked = values[2] === '1';
        if (isFlat) isFlat.checked = values[3] === '1';
        if (isPerRoom) isPerRoom.checked = values[4] === '1';
        if (isFlat) {
            this.applyFlatPriceState(isFlat, isPerRoom);
        }
        return false;
    }

    flatPriceTogglePerRoomAction(event) {
        const flatPriceCheckbox = event.currentTarget;
        const perRoomSelector = flatPriceCheckbox.dataset.perRoomSelector;
        if (!perRoomSelector) return;
        const perRoomCheckbox = document.querySelector(perRoomSelector);
        this.applyFlatPriceState(flatPriceCheckbox, perRoomCheckbox);
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
    applyFlatPriceState(flatPriceCheckbox, perRoomCheckbox) {
        if (!flatPriceCheckbox || !perRoomCheckbox) return;

        if (flatPriceCheckbox.checked) {
            perRoomCheckbox.checked = false;
            perRoomCheckbox.disabled = true;
        } else {
            perRoomCheckbox.disabled = false;
        }
    }

    syncFlatPricePerRoomStates() {
        const pairs = [
            ['#invoice_apartment_position_isFlatPrice', '#invoice_apartment_position_isPerRoom'],
            ['#invoice_misc_position_isFlatPrice', '#invoice_misc_position_isPerRoom'],
        ];
        pairs.forEach(([flatSelector, perRoomSelector]) => {
            const flat = document.querySelector(flatSelector);
            const perRoom = document.querySelector(perRoomSelector);
            this.applyFlatPriceState(flat, perRoom);
        });
    }

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
        httpRequest({
            url,
            method: 'GET',
            target: this.modalContent,
        });
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

    toggleInvoiceEditFields() {
        const fields = document.querySelectorAll('.invoice-edit-field');
        const editButton = document.getElementById('invoiceEditButton');
        const hidden = fields.length ? fields[0].classList.contains('d-none') : false;
        fields.forEach((f) => f.classList.toggle('d-none', !hidden ? true : false));
        if (editButton && hidden) {
            editButton.classList.add('d-none');
        }
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
