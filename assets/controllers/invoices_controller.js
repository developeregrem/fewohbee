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
    enableTooltips,
    disposeTooltips,
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
        // Every form loaded into the modal connects anew, while the bootstrapping
        // below runs once per page - so tooltips are set up before that guard.
        this.initTooltips();

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

    async initTooltips() {
        await enableTooltips(this.element);
    }

    disconnect() {
        disposeTooltips(this.element);
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
                this.syncApartmentDescriptions();
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
                this.syncApartmentDescriptions();
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
        // The request leaves for the overview whatever the server answers, so a field the
        // server would reject has to be caught here, e.g. a payment period without days.
        if (!form.reportValidity()) return;
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

    // Auto-saves invoice number, date and due date to the session while typing (debounced).
    updateInvoiceMetaAction() {
        if (!this.debouncedInvoiceMeta) {
            this.debouncedInvoiceMeta = debounce(() => this.saveInvoiceMeta(), 400);
        }
        this.debouncedInvoiceMeta();
    }

    saveInvoiceMeta() {
        const wrapper = document.getElementById('invoice-meta');
        if (!wrapper) return;
        const url = wrapper.dataset.invoicesMetaUrl;
        if (!url) return;
        const number = document.getElementById('invoiceidInput');
        const date = document.getElementById('invoiceDate');
        const dueDate = document.getElementById('paymentDueDate');
        let data = `invoiceid=${encodeURIComponent(number ? number.value : '')}&invoiceDate=${encodeURIComponent(date ? date.value : '')}`;
        if (dueDate) data += `&paymentDueDate=${encodeURIComponent(dueDate.value)}`;
        // onSuccess no-op keeps the request silent; without it the shared helper would reload the page.
        httpRequest({ url, method: 'POST', data, loader: false, onSuccess: () => {} });
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
        // Carried over from the price like the switches above; a package passes
        // its answer on to the components it is broken into.
        const brokered = document.getElementById('invoice_misc_position_brokered');
        if (brokered && selected) brokered.checked = selected.dataset.brokered !== '0';
        if (isFlatPrice && !isPackage) {
            this.applyFlatPriceState(isFlatPrice, isPerRoom);
        }
        // flat prices (packages included) lock the quantity to 1, any other price leaves it to the user
        const amount = document.getElementById('invoice_misc_position_amount');
        if (amount && !isFlatPrice?.checked) amount.value = '';
        this.applyFlatPriceAmountState(isFlatPrice);
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
        this.applyFlatPriceAmountState(flatPriceCheckbox);
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

    // A flat price is billed exactly once, so its quantity is fixed to 1 and a hint below the field says so.
    applyFlatPriceAmountState(flatPriceCheckbox) {
        const amountSelector = flatPriceCheckbox?.dataset.amountSelector;
        if (!amountSelector) return;
        const amount = document.querySelector(amountSelector);
        if (!amount) return;

        if (flatPriceCheckbox.checked) {
            amount.value = '1';
        }
        amount.readOnly = flatPriceCheckbox.checked;
        const help = document.getElementById(`${amount.id}_help`);
        if (help) help.classList.toggle('d-none', !flatPriceCheckbox.checked);
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
            this.applyFlatPriceAmountState(flat);
        });
    }

    // The `change` handler doesn't fire when only one candidate exists and is auto-selected.
    syncApartmentDescriptions() {
        const selects = document.querySelectorAll('select[data-action*="fillApartmentDescriptionAction"]');
        selects.forEach((select) => {
            select.dispatchEvent(new Event('change'));
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

    // Payment due date menu: a period from the invoice date or the last departure just
    // fills in the date field, the only one that is submitted. It works within the
    // nearest [data-payment-due-scope], where an editable invoice date field takes
    // precedence over the date the menu was rendered with. Filling in the date fires its
    // change event, so an auto-save on it sees the new value too. Dates are handled as
    // UTC days so a daylight saving change cannot shift them.
    paymentDuePeriodAction(event) {
        event.preventDefault();
        this._applyPaymentDueDays(event.currentTarget, event.currentTarget.dataset.days);
        this._closePaymentDueMenu(event.currentTarget);
    }

    // Own period typed into the menu: the date follows with every keystroke.
    paymentDueDaysInputAction(event) {
        this._applyPaymentDueDays(event.currentTarget, event.currentTarget.value);
    }

    // Enter only closes the menu; inside the number and date dialog it would submit it.
    paymentDueDaysEnterAction(event) {
        event.preventDefault();
        this._closePaymentDueMenu(event.currentTarget);
    }

    paymentDueToDepartureAction(event) {
        event.preventDefault();
        const scope = event.currentTarget.closest('[data-payment-due-scope]');
        const departure = event.currentTarget.closest('[data-payment-due-departure]')?.dataset.paymentDueDeparture;
        if (!departure) return;
        this._setPaymentDueDate(scope, departure);
        this._closePaymentDueMenu(event.currentTarget);
    }

    // The invoice date moved: the due date keeps its distance to it, and the departure is
    // only offered while it does not lie before the invoice date.
    paymentInvoiceDateChangedAction(event) {
        const input = event.currentTarget;
        const scope = input.closest('[data-payment-due-scope]');
        const invoiceDate = input.value;
        const previous = input.dataset.paymentPrevious || input.defaultValue;
        input.dataset.paymentPrevious = invoiceDate;
        if (!scope || !invoiceDate) return;
        const dueDate = scope.querySelector('[data-payment-due-date]')?.value;
        const days = this._isoDayDiff(previous, dueDate);
        if (days !== null && days >= 0) {
            this._setPaymentDueDate(scope, this._shiftIsoDate(invoiceDate, days));
        }
        scope.querySelectorAll('[data-payment-due-departure]').forEach((item) => {
            item.classList.toggle('d-none', item.dataset.paymentDueDeparture < invoiceDate);
        });
    }

    _applyPaymentDueDays(element, value) {
        const scope = element.closest('[data-payment-due-scope]');
        const days = Number.parseInt(value, 10);
        const invoiceDate = this._paymentInvoiceDate(scope);
        if (!invoiceDate || Number.isNaN(days) || days < 0) return;
        this._setPaymentDueDate(scope, this._shiftIsoDate(invoiceDate, days));
    }

    _closePaymentDueMenu(element) {
        const toggle = element.closest('.input-group')?.querySelector('[data-bs-toggle="dropdown"]');
        if (toggle && window.bootstrap) window.bootstrap.Dropdown.getInstance(toggle)?.hide();
    }

    _setPaymentDueDate(scope, isoDate) {
        const dateInput = scope?.querySelector('[data-payment-due-date]');
        if (!dateInput) return;
        dateInput.value = isoDate;
        dateInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    _paymentInvoiceDate(scope) {
        return scope?.querySelector('[data-payment-invoice-date]')?.value || null;
    }

    _shiftIsoDate(isoDate, days) {
        const [y, m, d] = isoDate.split('-').map(Number);
        return new Date(Date.UTC(y, m - 1, d + days)).toISOString().slice(0, 10);
    }

    _isoDayDiff(fromIso, toIso) {
        if (!fromIso || !toIso) return null;
        const toUtc = (iso) => { const [y, m, d] = iso.split('-').map(Number); return Date.UTC(y, m - 1, d); };
        return Math.round((toUtc(toIso) - toUtc(fromIso)) / 86400000);
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
