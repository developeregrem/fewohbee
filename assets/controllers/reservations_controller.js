import { Controller } from '@hotwired/stimulus';
import {
    request as httpRequest,
    serializeForm as httpSerializeForm,
    serializeSelectors as httpSerializeSelectors,
} from './http_controller.js';
import {
    setLocalStorageItemIfNotExists,
    getLocalStorageItem,
    iniStartOrEndDate,
    setModalTitle,
} from './utils_controller.js';

export default class extends Controller {
    static values = {
        canSelect: { type: Boolean, default: false },
        translations: Object,
        startUrl: String,
    };

    connect() {
        const isPreview = document.documentElement.hasAttribute('data-turbo-preview');
        const spinnerPreview = document.getElementById('table-filter')?.querySelector('[data-reservations-table-spinner]');
        const tablePreview = document.getElementById('table-ajax');
        // Skip heavy init when Turbo preview is rendered
        if (isPreview) {
            if (spinnerPreview) {
                spinnerPreview.classList.add('fa-spin');
            }
            if (tablePreview) {
                tablePreview.innerHTML = '';
            }
            return;
        }
        this.modalContent = document.getElementById('modal-content-ajax');
        this.tableContainer = tablePreview;
        this.tableFilter = document.getElementById('table-filter');
        this.modalSettingsContainer = document.getElementById('modal-content-settings');
        this.tableUrl = this.tableFilter?.dataset.reservationsTableUrl || null;
        this.tableSettingsUrl = this.tableFilter?.dataset.reservationsTableSettingsUrl || null;
        this.addAppartmentSelectableUrl = this.tableContainer?.dataset.reservationsAddAppartmentSelectableUrl || null;
        const inheritedStartUrl = document.querySelector('[data-reservations-start-url]')?.dataset.reservationsStartUrl;
        this.startUrl = this.hasStartUrlValue ? this.startUrlValue : (this.element.dataset.reservationsStartUrl || inheritedStartUrl || '/');
        this.translations = this.translationsValue && Object.keys(this.translationsValue).length > 0
            ? this.translationsValue
            : this.readTranslationsFromDom();

        this.modalContent = document.getElementById('modal-content-ajax');
        const invoicesBootstrapped = this.modalContent.hasAttribute('data-reservations-bootstrapped');
        if (invoicesBootstrapped) {
            return;
        }
        this.modalContent.dataset.reservationsBootstrapped = 'true';
        window.lastClickedReservationId = window.lastClickedReservationId || 0;
        window.lastClickedReservationUrl = window.lastClickedReservationUrl || null;

        this.setupTableFilterListeners();
        this.applyStoredTableSettings();
        this.observeModalContent();
        this.boundResize = this.handleResize.bind(this);
        window.addEventListener('load', this.boundResize);
        window.addEventListener('resize', this.boundResize);
        window.addEventListener('orientationchange', this.boundResize);
        this.afterModalContentChange();
    }

    // ----- bootstrap helpers -----
    disconnect() {
        if (this.boundResize) {
            window.removeEventListener('load', this.boundResize);
            window.removeEventListener('resize', this.boundResize);
            window.removeEventListener('orientationchange', this.boundResize);
        }

        if (typeof tinymce !== 'undefined' && tinymce.get('editor1')) {
            tinymce.get('editor1').destroy();
        }
    }

    setupTableFilterListeners() {
        if (!this.tableFilter) {
            return;
        }

        setLocalStorageItemIfNotExists('reservation-settings-show-month', 'true');
        setLocalStorageItemIfNotExists('reservation-settings-show-week', 'true');
        setLocalStorageItemIfNotExists('reservation-settings-show-weekday', 'false');

        this.tableFilter.addEventListener('submit', (event) => {
            event.preventDefault();
            this.getNewTable();
        });

        this.tableFilter.addEventListener('change', (event) => {
            const target = event.target;
            if (!target) {
                return;
            }
            const selectors = ['#start', '#year', 'input[name="interval"]', 'select[name="apartment"]', '#objects', '#holidayCountry', '#holidaySubdivision'];
            if (selectors.some((s) => target.matches(s))) {
                this.getNewTable();
            }
        });

        this.tableFilter.addEventListener('click', (event) => {
            const trigger = event.target.closest('.js-shift-date');
            if (!trigger) {
                return;
            }
            event.preventDefault();
            this.shiftStartDate(trigger.dataset.direction);
        });
        if (this.modalSettingsContainer) {
            this.modalSettingsContainer.addEventListener('change', (event) => {
                if (event.target && event.target.matches('#holidayCountry')) {
                    this.loadTableSettings(this.tableSettingsUrl);
                }
            });
        }
    }

    applyStoredTableSettings() {
        if (!this.tableFilter || !this.tableSettingsUrl) {
            return;
        }
        this.getLocalTableSetting('interval', 'reservations-intervall', 'int');
        this.getLocalTableSetting('holidayCountry', 'reservations-table-holidaycountry');
        this.getLocalTableSetting('apartment', 'reservations-apartment', 'int');
        this.loadTableSettings(this.tableSettingsUrl, true);
    }

    observeModalContent() {
        if (!this.modalContent) {
            return;
        }

        const observer = new MutationObserver(() => {
            this.afterModalContentChange();
        });
        observer.observe(this.modalContent, { childList: true, subtree: true });
        this.modalObserver = observer;
    }

    afterModalContentChange() {
        if (this.isHandlingModalChange) {
            return;
        }
        this.isHandlingModalChange = true;
        this.enablePriceOptionsMisc();
        this.initTinyMceEditor();
        this.attachCustomerSearchInputs();
        this.attachPaginationLinks();
        window.setTimeout(() => {
            this.isHandlingModalChange = false;
        }, 0);
    }

    // Stimulus actions
    getNewTableAction(event) {
        if (event) {
            event.preventDefault();
        }
        const url = event?.currentTarget?.dataset.url || event?.currentTarget?.dataset.reservationsTableUrl || this.tableUrl;
        this.getNewTable(url);
    }

    selectAppartmentAction(event) {
        if (event) {
            event.preventDefault();
        }
        const createNew = event?.currentTarget?.dataset.reservationsCreateNew === 'true';
        const url = event?.currentTarget?.dataset.url;
        this.selectAppartment(createNew, url);
    }

    reservationPreviewAction(event) {
        event.preventDefault();
        const customerId = event.currentTarget.dataset.customerId || null;
        const tab = event.currentTarget.dataset.tab || null;
        this.getReservationPreview(customerId, tab);
    }

    editCustomerChangeAction(event) {
        event.preventDefault();
        const customerId = event.currentTarget.dataset.customerId;
        const tab = event.currentTarget.dataset.tab;
        const appartmentId = event.currentTarget.dataset.appartmentId;
        this.editReservationCustomerChange(customerId, tab, appartmentId);
    }

    getCustomersAction(event) {
        event.preventDefault();
        const page = event.currentTarget.dataset.page || 1;
        const mode = event.currentTarget.dataset.mode;
        const tab = event.currentTarget.dataset.tab;
        const appartmentId = event.currentTarget.dataset.appartmentId;
        this.getCustomers(page, mode, tab, appartmentId);
    }

    getFormForNewCustomerAction(event) {
        event.preventDefault();
        this.getFormForNewCustomer();
    }

    submitFormAction(event) {
        event.preventDefault();
        const form = event.target.closest('form');
        if (!form) {
            return;
        }
        const url = form.dataset.url || form.getAttribute('action');
        const targetSelector = form.dataset.target;
        const target = targetSelector ? document.querySelector(targetSelector) : this.modalContent;
        httpRequest({
            url,
            method: form.method || 'POST',
            data: httpSerializeForm(form),
            target
        });
    }

    openReservationAction(event) {
        event.preventDefault();
        const tab = event.currentTarget.dataset.tab || null;
        const url = event.currentTarget.dataset.url;
        const reservationId = event.currentTarget.dataset.reservationId || null;
        window.lastClickedReservationUrl = url;
        if (url) {
            this.getReservation(reservationId === 'new' ? reservationId : url, tab);
        }
    }

    selectCustomerAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        if (url && this.modalContent) {
            this.modalContent.dataset.selectCustomerUrl = url;
        }
        this.selectCustomer(url);
    }

    createReservationsAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        if (url && this.modalContent) {
            this.modalContent.dataset.createReservationsUrl = url;
        }
        this.createNewReservations();
    }

    toggleDeleteAction(event) {
        event.preventDefault();
        this.toggleReservationDelete();
    }

    showAddReservationToSelectionAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const createNew = event.currentTarget.dataset.createNew === 'true';
        const title = event.currentTarget.dataset.title || '';
        if (!url) return;
        setModalTitle(title);
        httpRequest({
            url: url,
            method: 'GET',
            target: this.modalContent,
            data: { createNew: createNew },    
        });
    }

    showTimeFilterAction(event) {
        event.preventDefault();
        const btnTime = document.getElementById('button-filter-time');
        const btnCustomer = document.getElementById('button-filter-customer');
        const boxTime = document.getElementById('container-filter-reservations-period');
        const boxCustomer = document.getElementById('container-filter-reservations-customer');
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
        const boxTime = document.getElementById('container-filter-reservations-period');
        const boxCustomer = document.getElementById('container-filter-reservations-customer');
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

    getReservationsInPeriodAction(event) {
        event.preventDefault();
        iniStartOrEndDate('from', 'end', 1);
        const url = event.currentTarget.dataset.url;
        const form = document.getElementById('filter-reservations-period');
        if (!url || !form) return;
        httpRequest({
            url,
            method: 'POST',
            data: httpSerializeForm(form),
            target: document.getElementById('container-filter-reservations-result'),
        });
    }

    getReservationsByCustomerNameAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const form = document.getElementById('filter-reservations-customer-name');
        if (!url || !form) return;
        httpRequest({
            url,
            method: 'POST',
            data: httpSerializeForm(form),
            target: document.getElementById('container-filter-reservations-result'),
        });
    }

    selectReservationAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url || event.target.closest('[data-select-url]')?.dataset.selectUrl;
        const reservationId = event.currentTarget.dataset.reservationId || null;
        if (url) {
            httpRequest({
                url,
                method: 'POST',
                data: { reservationid: reservationId },
                target: this.modalContent
            });
        }
    }

    deleteReservationFromSelectionAction(event) {
        event.preventDefault();
        const url = event.target.closest('[data-delete-url]')?.dataset.deleteUrl;
        const key = event.currentTarget.dataset.reservationKey;
        if (!url) return;
        httpRequest({ 
            url, method: 'POST', 
            data: { reservationkey: key }, 
            target: this.modalContent 
        });
    }

    openModalContentAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const title = event.currentTarget.dataset.title || '';
        if (!url) {
            return;
        }
        setModalTitle(title);
        httpRequest({
            url,
            method: 'GET',
            target: this.modalContent
        });
    }

    deleteReservationAction(event) {
        event.preventDefault();
        this.doDeleteReservation();
    }

    selectReservationForInvoiceAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const reservationId = event.currentTarget.dataset.reservationId;
        this.selectReservatioForInvoice(reservationId, url);
    }

    toggleAppartmentOptionsAction(event) {
        const appartmentId = event.currentTarget.dataset.appartmentId;
        event.preventDefault();
        this.toggleAppartmentOptions(appartmentId);
    }

    deleteAppartmentAction(event) {
        event.preventDefault();
        const id = event.currentTarget.dataset.appartmentId;
        this.deleteAppartmentFromSelection(id);
    }

    saveAppartmentOptionsAction(event) {
        event.preventDefault();
        const id = event.currentTarget.dataset.appartmentId;
        this.saveAppartmentOptions(id);
    }

    addAppartmentToSelectionAction(event) {
        event.preventDefault();
        const id = event.currentTarget.dataset.appartmentId;
        const url = event.currentTarget.dataset.url;
        if (url && this.modalContent) {
            this.modalContent.dataset.addAppartmentUrl = url;
        }
        this.addAppartmentToSelection(id, url);
    }

    getAvailableAppartmentsAction(event) {
        if (event) {
            event.preventDefault();
        }
        this.getAvailableAppartmentsForPeriod(event?.currentTarget?.dataset.mode);
    }

    toggleReservationEditAppartmentsAction(event) {
        event.preventDefault();
        const id = event.currentTarget.dataset.appartmentId;
        this.toggleReservationEditAppartments(id);
    }

    editUpdateReservationAction(event) {
        event.preventDefault();
        const id = event.currentTarget.dataset.appartmentId;
        this.editUpdateReservation(id);
    }

    sendEmailAction(event) {
        event.preventDefault();
        const form = event.target.closest('form');
        this.sendEmail(form);
    }

    saveTemplateFileAction(event) {
        event.preventDefault();
        const form = event.target.closest('form');
        this.saveTemplateFile(form);
    }

    exportPDFCorrespondenceAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const id = event.currentTarget.dataset.attachmentId || event.currentTarget.dataset.id;
        this.exportPDFCorrespondence(id, url);
    }

    deleteAttachmentAction(event) {
        event.preventDefault();
        const id = event.currentTarget.dataset.attachmentId;
        const url = event.currentTarget.dataset.url;
        const form = event.currentTarget.closest('form');
        this.deleteAttachment(id, url, form);
    }

    addAttachmentAction(event) {
        event.preventDefault();
        const id = event.currentTarget.dataset.attachmentId;
        const isInvoice = event.currentTarget.dataset.isInvoice === 'true';
        const url = event.currentTarget.dataset.url;
        if (url && this.modalContent) {
            this.modalContent.dataset.addAttachmentUrl = url;
        }
        this.addAsAttachment(id, isInvoice);
    }

    previewTemplateForReservationAction(event) {
        event.preventDefault();
        const id = event.currentTarget.dataset.templateId;
        const url = event.currentTarget.dataset.url;
        const inProcess = event.currentTarget.dataset.inprocess || false;
        this.previewTemplateForReservation(id, inProcess, url);
    }

    saveEditCustomerAction(event) {
        event.preventDefault();
        const form = event.target.closest('form');
        if (!form) {
            return;
        }
        this.saveEditCustomer(null, form);
    }

    editReservationNewCustomerAction(event) {
        event.preventDefault();
        const form = event.target.closest('form');
        const isNew = event.currentTarget.dataset.reservationId === 'new';
        if (!form) {
            return;
        }
        const tab = form.querySelector('#tab')?.value;
        const url = form.dataset.url;
        this.editReservationNewCustomer(url, tab, isNew);
    }

    changeReservationCustomerAction(event) {
        event.preventDefault();
        const reservationId = event.currentTarget.dataset.reservationId;
        const tab = event.currentTarget.dataset.tab;
        const appartmentId = event.currentTarget.dataset.appartmentId;
        const url = event.currentTarget.dataset.url;
        this.changeReservationCustomer(reservationId, tab, appartmentId, url);
    }

    editReservationCustomerEditAction(event) {
        event.preventDefault();
        const customerId = event.currentTarget.dataset.customerId;
        const formSelector = event.currentTarget.dataset.formSelector;
        const url = event.currentTarget.dataset.url;
        const form = formSelector ? document.querySelector(formSelector) : null;
        if (form && url) {
            form.dataset.editUrl = url;
        }
        this.editReservationCustomerEdit(customerId, form);
    }

    deleteReservationCustomerAction(event) {
        event.preventDefault();
        const customerId = event.currentTarget.dataset.customerId;
        const url = event.currentTarget.dataset.url;
        const form = document.getElementById('actions-customer-' + customerId);
        if (form && url) {
            form.dataset.deleteUrl = url;
        }
        this.deleteReservationCustomer(null, customerId);
    }

    editReservationAction(event) {
        event.preventDefault();
        const reservationId = event.currentTarget.dataset.reservationId;
        const url = event.currentTarget.dataset.url;
        this.editReservation(reservationId, url);
    }

    showMailCorrespondenceAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const reservationId = event.currentTarget.dataset.reservationId;
        const correspondenceId = event.currentTarget.dataset.correspondenceId || reservationId;
        this.showMailCorrespondence(correspondenceId, reservationId, url);
    }

    deleteCorrespondenceAction(event) {
        event.preventDefault();
        const id = event.currentTarget.dataset.correspondenceId;
        const url = event.currentTarget.dataset.url;
        if (url && this.modalContent) {
            this.modalContent.dataset.deleteCorrespondenceUrl = url;
        }
        this.deleteCorrespondence(id);
    }

    createNewCustomerAction(event) {
        event.preventDefault();
        const form = event.target.closest('form');
        this.createNewCustomer(null, form);
    }

    // ----- table helpers -----

    getNewTable(url = null) {
        const targetUrl = url || this.tableUrl;
        if (!targetUrl || !this.tableFilter) {
            return false;
        }

        this.setLocalTableSetting('interval', 'reservations-intervall', 'int');
        this.setLocalTableSetting('apartment', 'reservations-apartment', 'int');

        // set custom spinner here, so that the reservation table content is not replaced
        const spinner = this.tableFilter ? this.tableFilter.querySelector('[data-reservations-table-spinner]') : null;
        if (spinner) {
            spinner.classList.add('fa-spin');
        }

        httpRequest({
            url: targetUrl,
            method: 'GET',
            data: httpSerializeForm(this.tableFilter),
            target: this.tableContainer,
            loader: false,  // we handle the spinner ourselves
            onSuccess: (data) => {
                if (this.tableContainer) {
                    this.tableContainer.innerHTML = data;
                    this.addAppartmentSelectableUrl = this.tableContainer.dataset.reservationsAddAppartmentSelectableUrl || this.addAppartmentSelectableUrl;
                }
                this.toggleDisplayTableRows();
                this.initStickyTables();
                this.initFit();
                this.initTableInteractions();
            },
            onComplete: () => {
                // disable spinner
                const spinnerEl = this.tableFilter ? this.tableFilter.querySelector('[data-reservations-table-spinner]') : null;
                if (spinnerEl) {
                    spinnerEl.classList.remove('fa-spin');
                }
            }
        });

        return false;
    }

    loadTableSettings(url, initial = false) {
        if (!url) {
            return;
        }

        this.setLocalTableSetting('holidayCountry', 'reservations-table-holidaycountry');
        this.setLocalTableSetting('holidaySubdivision', 'reservations-table-holidaysubdivision');

        httpRequest({
            url,
            method: 'POST',
            data: httpSerializeForm(this.tableFilter),
            target: this.modalSettingsContainer,
            loader: false,
            onComplete: () => { 
                if (initial) {
                    this.getLocalTableSetting('holidaySubdivision', 'reservations-table-holidaysubdivision');
                    this.getNewTable();
                }
                this.updateDisplaySettingsOnChange();
            }
        });
    }

    shiftStartDate(direction) {
        const startInput = document.getElementById('start');
        const intervalInput = this.tableFilter ? this.tableFilter.querySelector('input[name="interval"]') : null;
        if (!startInput || !intervalInput) {
            return;
        }
        const intervalValue = parseInt(intervalInput.value, 10);
        const interval = !isNaN(intervalValue) && intervalValue > 0 ? intervalValue : 1;
        const currentDate = startInput.value ? new Date(startInput.value) : new Date();
        if (Number.isNaN(currentDate.getTime())) {
            return;
        }
        const offset = direction === 'forward' ? interval : -interval;
        currentDate.setDate(currentDate.getDate() + offset);
        startInput.value = this.formatDateInputValue(currentDate);
        startInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    formatDateInputValue(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    updateDisplaySettingsOnChange() {
        this.updateDisplaySettings('show-week');
        this.updateDisplaySettings('show-month');
        this.updateDisplaySettings('show-weekday');
    }

    updateDisplaySettings(name) {
        const target = document.getElementById(name);
        if (!target) {
            return;
        }
        const checked = getLocalStorageItem('reservation-settings-' + name);
        if (checked !== null) {
            target.checked = checked === 'true';
        }
        target.addEventListener('click', () => {
            const value = target.checked;
            setLocalStorageItemIfNotExists('reservation-settings-' + name, value, true);
            this.toggleDisplayTableRows();
        });
    }

    toggleDisplayTableRows() {
        this.toggleRow('reservation-table-header-month', getLocalStorageItem('reservation-settings-show-month'));
        this.toggleRow('reservation-table-header-week', getLocalStorageItem('reservation-settings-show-week'));
        this.toggleRow('reservation-table-header-weekday', getLocalStorageItem('reservation-settings-show-weekday'));
    }

    toggleRow(name, show) {
        const row = document.getElementById(name);
        if (!row) {
            return;
        }
        row.style.display = show == 'true' ? '' : 'none';
        this.initStickyTables();
    }

    setStickyHeaderOffsets(table) {
        const rows = Array.from(table.querySelectorAll('thead tr')).filter((row) => {
            return window.getComputedStyle(row).display !== 'none';
        });
        let offset = 0;
        const n = rows.length;
        rows.forEach((row, i) => {
            const h = row.getBoundingClientRect().height;
            row.querySelectorAll('th,td').forEach((th) => {
                th.style.top = offset + 'px';
                th.style.zIndex = (n - i + 2).toString();
            });
            offset += h;
        });
    }

    initStickyTables() {
        document.querySelectorAll('.table-sticky').forEach((table) => this.setStickyHeaderOffsets(table));
    }

    fitTableToViewport(el, extraBottom = 0) {
        const rect = el.getBoundingClientRect();
        const minHeight = parseInt(el.dataset.minHeight || 0, 10) || 200;
        const available = window.innerHeight - rect.top - extraBottom;
        const height = available > 0 ? Math.max(minHeight, available) : minHeight;
        el.style.maxHeight = height + 'px';
        el.style.minHeight = minHeight + 'px';
        el.style.overflow = 'auto';
    }

    initFit() {
        document.querySelectorAll('.js-fit-vh').forEach((el) => this.fitTableToViewport(el));
    }

    handleResize() {
        this.initStickyTables();
        this.initFit();
    }

    initTableInteractions() {
        this.initPopovers();
        this.initSelectable();
        this.initYearlySelectable();
    }

    initPopovers() {
        if (!window.jQuery) {
            return;
        }
        $('.reservation-inner').popover({ placement: 'top', html: true, trigger: 'hover' });
        $('.room-info').popover({ html: true });
        $('.holiday-info').popover();
        $('.reservation-popover').popover({ placement: 'top', html: true, trigger: 'hover' });
    }

    initSelectable() {
        if (!this.canSelectValue) {
            return;
        }
        const enableSelectable = window.matchMedia('(pointer: fine)').matches;
        if (!enableSelectable || !window.jQuery || !$.fn.selectable) {
            return;
        }
        const table = $('.table-reservation');
        if (!table.length) {
            return;
        }
        let tdStartDate = '';
        let tdEndDate = '';
        let tdStartAppartment = '';
        let lastTdNumber = '';

        table.selectable({
            filter: '.td-empty',
            cancel: '.reservation',
            selecting: (event, ui) => {
                if (tdStartDate === '') {
                    tdStartDate = $(ui.selecting).attr('data-day');
                    tdStartAppartment = $(ui.selecting).attr('data-appartment');
                    lastTdNumber = $(ui.selecting).attr('data-tdnumber');
                } else {
                    const curAppartment = $(ui.selecting).attr('data-appartment');
                    if (curAppartment !== tdStartAppartment) {
                        $(ui.selecting).removeClass('ui-selectee ui-selecting');
                    } else if ($(ui.selecting).attr('data-tdnumber') !== lastTdNumber) {
                        $(ui.selecting).removeClass('ui-selectee ui-selecting');
                    } else if ($(ui.selecting).attr('data-day') !== tdStartDate) {
                        tdEndDate = $(ui.selecting).attr('data-day');
                    }
                }
            },
            unselecting: (event, ui) => {
                if ($(ui.unselecting).attr('data-tdnumber') === lastTdNumber) {
                    const curDay = $(ui.unselecting).attr('data-day');
                    if (curDay > tdStartDate) {
                        tdEndDate = $(ui.unselecting).prev().attr('data-day');
                    } else if (curDay < tdStartDate) {
                        tdEndDate = $(ui.unselecting).next().attr('data-day');
                    }
                }
            },
            start: () => table.removeClass('table-hover'),
            stop: () => {
                if (tdStartDate !== '' && tdEndDate !== '' && tdStartAppartment !== '' && tdStartDate !== tdEndDate) {
                    this.selectableAddAppartmentToSelection(tdStartAppartment, tdStartDate, tdEndDate);
                    $('#modalCenter').modal('toggle');
                }
                tdStartDate = '';
                tdEndDate = '';
                tdStartAppartment = '';
                lastTdNumber = '';
                table.addClass('table-hover');
                $('.td-empty').removeClass('ui-selectee ui-selected');
            }
        });
    }

    initYearlySelectable() {
        const calendar = document.querySelector('[data-reservations-yearly=\"true\"]');
        if (!calendar || typeof Selectable === 'undefined') {
            return;
        }

        if (this.yearlySelectable) {
            this.yearlySelectable.destroy();
        }

        let startSlectedDay = null;
        let endSelectedDay = null;
        const apartmentId = calendar.dataset.reservationsApartmentId;

        this.yearlySelectable = new Selectable({
            filter: '.reservation-yearly-parent',
            ignore: '.reservation-yearly',
            lasso: {
                border: '2px dashed rgba(255, 255, 255, 0)',
                backgroundColor: 'rgba(255, 255, 255, 0)'
            }
        });

        this.yearlySelectable.on('start', (e, item) => {
            if (item) {
                startSlectedDay = item.node;
                endSelectedDay = null;
            }
        });

        this.yearlySelectable.on('drag', (e) => {
            let elm = document.elementFromPoint(e.pageX, e.pageY - window.pageYOffset);
            const c = this.yearlySelectable.config.classes;
            if (!elm) {
                return;
            }
            elm = elm.closest('.' + c.selectable);
            if (!elm) {
                return;
            }
            const elmIdx = this.yearlySelectable.nodes.indexOf(elm);
            const startIdx = this.yearlySelectable.nodes.indexOf(startSlectedDay);
            let start; let end;
            const cItems = this.yearlySelectable.items.length;
            if (elmIdx > startIdx) {
                start = startIdx;
                end = elmIdx;
            } else {
                start = elmIdx;
                end = startIdx;
            }
            let canSelected = true;
            let i = 0; let u = cItems - 1;
            while (i < cItems) {
                const idx = (elmIdx > startIdx ? i : u);
                const item = this.yearlySelectable.items[idx];
                if (idx >= start && idx <= end) {
                    if (this.isDayWithReservation(item)) {
                        canSelected = false;
                    }
                    if (canSelected) {
                        this.selectableSelect(item, c);
                        endSelectedDay = item.node;
                    } else {
                        this.selectableDeselect(item, c);
                    }
                } else if (item.selected || item.selecting) {
                    this.selectableDeselect(item, c);
                }
                i++;
                u--;
            }
        });

        this.yearlySelectable.on('end', () => {
            if (startSlectedDay && endSelectedDay) {
                this.selectableAddAppartmentToSelection(apartmentId, startSlectedDay.dataset.day, endSelectedDay.dataset.day);
                $('#modalCenter').modal('toggle');
                startSlectedDay = null;
                endSelectedDay = null;
            }
        });
    }

    isDayWithReservation(item) {
        const reservationItem = item.node.querySelector('.reservation-yearly');
        if (reservationItem && !reservationItem.classList.contains('month-reservationstartend')) {
            return true;
        }
        return false;
    }

    selectableSelect(item, c) {
        item.node.classList.add(c.selecting);
        item.selecting = true;
    }

    selectableDeselect(item, c) {
        item.selecting = false;
        item.node.classList.remove(c.selecting);
        item.node.classList.remove(c.selected);
    }

    // ----- modal helpers -----

    selectReservatioForInvoice(id, url = null) {
        const targetUrl = url || this.getContextValue('reservationsSelectInvoiceUrl');
        if (!targetUrl) {
            return false;
        }
        httpRequest({
            url: targetUrl,
            method: 'POST',
            data: { reservationid: id, createNewInvoice: 'true' },
            target: this.modalContent,
            onSuccess: (data) => {
                if (this.modalContent) {
                    this.modalContent.innerHTML = data;
                }
                this.toggleDisplayTableRows();
            }
        });
        return false;
    }

    selectReservationForTemplateAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const id = event.currentTarget.dataset.reservationId;
        const createNew = event.currentTarget.dataset.createNew === 'true';
        if (!url) return;
        window.lastClickedReservationId = id;
        //$('.modal-header .modal-title').text(this.translate('templates.select.reservations'));
        httpRequest({
            url,
            method: 'POST',
            data: id ? { reservationid: id, createNew: createNew } : { createNew: createNew },
            target: this.modalContent
        });
    }

    selectAppartment(createNewReservation, url = null) {
        const targetUrl = url || this.element.dataset.reservationsSelectAppartmentUrl || this.tableFilter?.dataset.reservationsSelectAppartmentUrl;
        if (!targetUrl) {
            return false;
        }
        $('#modalCenter .modal-title').text(this.translate('nav.reservation.add'));
        const data = httpSerializeSelectors(['#objects']) + '&createNewReservation=' + createNewReservation;
        httpRequest({
            url: targetUrl,
            method: 'GET',
            data,
            target: this.modalContent
        });
        return false;
    }

    getAvailableAppartmentsForPeriod(mode, url = null) {
        $('#available-appartments').html('');
        iniStartOrEndDate('from', 'end', 1);
        if ($('#from').val() !== '' && $('#end').val() !== '') {
            const form = document.getElementById('reservation-period');
            const targetUrl = url || (mode === 'edit'
                ? form?.dataset.availableEditUrl
                : form?.dataset.availableUrl);
            if (!targetUrl) {
                return false;
            }
            httpRequest({
                url: targetUrl,
                method: 'POST',
                data: httpSerializeForm('#reservation-period'),
                target: document.getElementById('available-appartments')
            });
        }
        return false;
    }

    addAppartmentToSelection(id, url = null) {
        const targetUrl = url || document.getElementById('reservation-period')?.dataset.addAppartmentUrl || this.getContextValue('addAppartmentUrl');
        const content = window.modalLoader || '';
        if (!targetUrl) {
            return false;
        }
        let data;
        if (id) {
            data = 'appartmentid=' + id + '&' + httpSerializeForm('#reservation-period') + '&' + httpSerializeForm('#appartment-options-' + id);
        } else {
            data = httpSerializeForm('#reservation-period');
        }
        data = data + '&createNewReservation=false';
        httpRequest({
            url: targetUrl,
            method: 'POST',
            data,
            target: this.modalContent
        });
        return false;
    }

    selectableAddAppartmentToSelection(id, start, end, url = null) {
        const targetUrl = url || this.addAppartmentSelectableUrl || document.getElementById('reservation-period')?.dataset.addAppartmentSelectableUrl;
        const data = 'appartmentid=' + id + '&from=' + start + '&end=' + end + '&createNewReservation=true';
        if (!targetUrl) {
            return false;
        }
        httpRequest({
            url: targetUrl,
            method: 'POST',
            data,
            target: this.modalContent
        });
        return false;
    }

    deleteAppartmentFromSelection(id, url = null) {
        const targetUrl = url || document.getElementById('reservation-period')?.dataset.removeAppartmentUrl || this.getContextValue('removeAppartmentUrl');
        if (!targetUrl) {
            return false;
        }
        httpRequest({
            url: targetUrl,
            method: 'POST',
            data: httpSerializeSelectors(['#from', '#end']) + '&appartmentid=' + id + '&createNewReservation=false',
            target: this.modalContent
        });
        return false;
    }

    saveAppartmentOptions(id, url = null) {
        const targetUrl = url || document.getElementById('reservation-period')?.dataset.modifyAppartmentOptionsUrl || this.getContextValue('modifyAppartmentOptionsUrl');
        if (!targetUrl) {
            return false;
        }
        httpRequest({
            url: targetUrl,
            method: 'POST',
            data: (httpSerializeSelectors(['#from', '#end']) + '&' + httpSerializeForm('#appartment-options-' + id) + '&appartmentid=' + id + '&createNewReservation=false'),
            target: this.modalContent
        });
        return false;
    }

    selectCustomer(url = null) {
        const targetUrl = url || document.getElementById('reservation-period')?.dataset.selectCustomerUrl || this.getContextValue('selectCustomerUrl');
        if (!targetUrl) {
            return false;
        }
        const content = window.modalLoader || '';
        const message = '<div class="col-md-10 col-md-offset-1">' +
            '<div class="alert alert-danger alert-dismissible fade show" role="alert">' +
            '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
            this.translate('reservation.no.selected.appartments') +
            '</div>' +
            '</div>';

        if ($('#selectedAppartments tr').length === 1) {
            $('#flash-message-overlay').empty().append(message);
        } else {
            $('#breadcrumb-appartments').wrap('<a href="#" />');
            $('#breadcrumb-customer').removeClass('d-none');
            httpRequest({
                url: targetUrl,
                method: 'POST',
                data: httpSerializeForm('#reservation-period'),
                target: this.modalContent
            });
        }
        return false;
    }

    getCustomers(page, mode, tab, appartmentId) {
        const customersContainer = document.getElementById('customers');
        const url = mode === 'edit'
            ? customersContainer?.dataset.customersEditUrl
            : customersContainer?.dataset.customersUrl;
        if (!url) {
            return false;
        }
        const content = window.modalLoader || '';
        const safeTab = tab || '';
        $('#customer-selection .btn-primary').addClass('d-none');
        const extraParams = customersContainer?.dataset.customersExtraParams || '';
        httpRequest({
            url,
            method: 'POST',
            data: httpSerializeForm('#lastname') + '&tab=' + safeTab + '&page=' + page + '&appartmentId=' + appartmentId + (extraParams ? '&' + extraParams : ''),
            target: document.getElementById('customers'),
            loader: true
        });
        return false;
    }

    getFormForNewCustomer() {
        const container = document.getElementById('customers');
        const url = container?.dataset.customerNewFormUrl;
        if (!url) {
            return false;
        }
        httpRequest({
            url,
            method: 'POST',
            target: document.getElementById('customers'),
            onSuccess: (data) => {
                $('#customers').html(data);
                $('#customer-selection .btn-primary').removeClass('d-none');
            }
        });
        return false;
    }

    createNewCustomer(url = null, form = null) {
        const formEl = form || document.getElementById('customer-selection');
        const targetUrl = url || formEl?.dataset.url || formEl?.action;
        if (!targetUrl) {
            return false;
        }
        httpRequest({
            url: targetUrl,
            method: 'POST',
            data: httpSerializeForm(formEl),
            target: this.modalContent
        });
        return false;
    }

    getReservationPreview(customerId, tab) {
        const url = this.getContextValue('previewUrl') || document.getElementById('reservation-period')?.dataset.previewUrl;
        if (!url) {
            return false;
        }
        const data = { customerid: customerId ? customerId : '' };
        if (tab !== null) {
            data.tab = tab;
        }
        httpRequest({
            url,
            method: 'POST',
            data: data,
            target: this.modalContent,
            onComplete: () => {
                this.enablePriceOptionsMisc();
            }
        });
        return false;
    }

    toggleAppartmentOptions(id) {
        const elm = $('#appartment-' + id);
        if (elm.hasClass('d-none')) {
            $('.appartment-options').addClass('d-none');
            elm.removeClass('d-none');
        } else {
            elm.addClass('d-none');
        }
        return false;
    }

    createNewReservations() {
        const url = this.getContextValue('createReservationsUrl');
        if (!url) {
            return false;
        }
        httpRequest({
            url,
            method: 'POST',
            data: httpSerializeSelectors(['#_csrf_token', '#reservation-remark', '#reservation-origin', '#reservation-arrivalTime', '#reservation-departureTime']),
            target: this.modalContent,
            onSuccess: (data) => {
                if (data.length > 0) {
                    $('#flash-message-overlay').empty().append(data);
                } else {
                    window.location.href = this.startUrl || '/';
                }
            }
        });
        return false;
    }

    getReservation(url, tab, loader=true) {
        const isNew = url === 'new';
        if (isNew) {
            this.currentReservationUrl = null;
            this.getReservationPreview(null, tab);
            return false;
        }
        
        const data = {}
        if(tab != null) {
            data.tab = tab;
        }
        
        setModalTitle(this.translate('reservation.details'));
        $('#modalCenter').modal('show');
        httpRequest({
            url,
            method: 'GET',
            data: data,
            loader: loader,
            target: this.modalContent,
        });
    }

    editReservation(id, url = null) {
        let targetUrl = url;
        if (!targetUrl) {
            const template = this.getContextValue('editReservationUrlTemplate') || this.getContextValue('reservationsEditReservationUrlTemplate');
            targetUrl = template && id ? template.replace('placeholder', id) : null;
        }
        if (!targetUrl) {
            return false;
        }
        $('#modalCenter .modal-title').text(this.translate('nav.reservation.edit'));
        httpRequest({
            url: targetUrl,
            method: 'GET',
            data: httpSerializeSelectors(['#objects']),
            target: this.modalContent
        });
        return false;
    }

    changeReservationCustomer(id, tab, appartmentId, url = null) {
        let targetUrl = url;
        if (!targetUrl) {
            const template = this.getContextValue('changeReservationCustomerUrlTemplate');
            targetUrl = template && id ? template.replace('placeholder', id) : null;
        }
        if (!targetUrl) {
            return false;
        }
        httpRequest({
            url: targetUrl,
            method: 'GET',
            data: { tab, appartmentId },
            target: this.modalContent
        });
        return false;
    }

    editReservationNewCustomer(url, tab, isNew) {
        if (!url) {
            return false;
        }
        httpRequest({
            url: url,
            method: 'POST',
            data: httpSerializeForm('#customer-selection'),
            target: this.modalContent,
            onSuccess: (data) => {
                if (data.length > 0) {
                    $('#flash-message-overlay').empty().append(data);
                } else {
                    const resUrl = isNew ? 'new' : this.getContextValue('reservationUrl') || null;
                    this.getReservation(resUrl, tab);
                    if (url !== 'new' && tab === 'booker') {
                        this.getNewTable();
                    }
                }
            }
        });
        return false;
    }

    editReservationCustomerChange(id, tab, appartmentId, url = null) {
        let targetUrl = url;
        if (!targetUrl) {
            const template = this.getContextValue('editReservationCustomerChangeUrlTemplate');
            const reservationId = $('#reservation-id').val();
            targetUrl = template && reservationId ? template.replace('placeholder', reservationId) : null;
        }
        if (!targetUrl) {
            return false;
        }
        httpRequest({
            url: targetUrl,
            method: 'POST',
            data: { customerId: id, tab, appartmentId },
            target: this.modalContent,
            onSuccess: (data) => {
                if (this.modalContent) {
                    this.modalContent.innerHTML = data;
                }
                if (tab === 'booker') {
                    this.getNewTable();
                }
            }
        });
        return false;
    }

    toggleMoreInfo(fieldId) {
        const elm = $('#' + fieldId);
        if (elm.is(':hidden')) {
            elm.fadeIn().removeClass('hide');
        } else {
            elm.hide();
        }
        return false;
    }

    doDeleteReservation() {
        const form = '#reservationShowForm';
        const formEl = document.querySelector(form);
        const url = formEl?.dataset.url || formEl?.action || this.getContextValue('deleteReservationUrl');
        if (!url) {
            return false;
        }
        httpRequest({
            url,
            method: 'POST',
            data: httpSerializeForm(form),
            onSuccess: () => location.reload()
        });
        return false;
    }

    deleteReservationCustomer(elm, customerId) {
        const form = document.getElementById('actions-customer-' + customerId);
        const url = form?.dataset.deleteUrl || form?.action || this.getContextValue('deleteReservationCustomerUrl');
        if (!form || !url) {
            return false;
        }
        httpRequest({
            url,
            method: 'POST',
            data: httpSerializeForm(form),
            target: this.modalContent
        });
        return false;
    }

    editReservationCustomerEdit(customerId, form) {
        const formEl = typeof form === 'string' ? document.querySelector(form) : form;
        const url = formEl?.dataset.editUrl || formEl?.action || this.getContextValue('editReservationCustomerEditUrl');
        if (!url) {
            return false;
        }
        httpRequest({
            url,
            method: 'POST',
            data: httpSerializeForm(formEl),
            target: this.modalContent
        });
        return false;
    }

    saveEditCustomer(id, form) {
        const formEl = typeof form === 'string' ? document.querySelector(form) : form;
        const url = formEl?.dataset.url || formEl?.action || this.getContextValue('saveEditCustomerUrl');
        if (!url) {
            return false;
        }
        httpRequest({
            url,
            method: 'POST',
            data: httpSerializeForm(formEl),
            target: this.modalContent
        });
        return false;
    }

    selectTemplateForReservationsAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const templateId = event.currentTarget.dataset.templateId || null;
        const inProcess = event.currentTarget.dataset.inprocess === 'true';
        const formData = inProcess ? httpSerializeForm('#template-form') : null;
        setModalTitle(event.currentTarget.dataset.title || '');
        httpRequest({
            url,
            method: 'POST',
            data: { templateId, inProcess, formData },
            target: this.modalContent
        });
        return false;
    }

    previewTemplateForReservation(id, inProcess = false, url = null) {
        let targetUrl = url;
        if (!targetUrl) {
            const template = this.getContextValue('previewTemplateUrl');
            targetUrl = template && id !== undefined ? template.replace('placeholder', id) : null;
        }
        if (!targetUrl) {
            return false;
        }
        httpRequest({
            url: targetUrl,
            method: 'POST',
            data: { inProcess },
            target: this.modalContent,
            onSuccess: (data) => {
                $('#modalCenter .modal-title').text(this.translate('templates.edit'));
                $('#modal-content-ajax').html(data);
            }
        });
        return false;
    }

    sendEmail(form) {
        const formEl = typeof form === 'string' ? document.querySelector(form) : form;
        const url = formEl?.dataset.url || formEl?.action || this.getContextValue('sendEmailUrl');
        const editor = formEl ? formEl.querySelector('#editor1') : null;
        const refreshUrl = formEl?.dataset.refreshUrl || this.currentReservationUrl;
        if (!formEl || !url) {
            return false;
        }
        if (editor && typeof tinymce !== 'undefined' && tinymce.get('editor1')) {
            editor.value = tinymce.get('editor1').getContent();
        }
        httpRequest({
            url,
            method: 'POST',
            data: httpSerializeForm(formEl),
            target: this.modalContent,
            onSuccess: (data) => {
                if (data.length > 0) {
                    $('#flash-message-overlay').empty().append(data);
                } else {
                    this.getReservation(refreshUrl || window.lastClickedReservationUrl, 'correspondence');
                }
            }
        });
        return false;
    }

    saveTemplateFile(form) {
        const formEl = typeof form === 'string' ? document.querySelector(form) : form;
        const url = formEl?.dataset.url || formEl?.action || this.getContextValue('saveTemplateFileUrl');
        const editor = formEl ? formEl.querySelector('#editor1') : null;
        const refreshUrl = formEl?.dataset.refreshUrl || this.currentReservationUrl;
        if (!formEl || !url) {
            return false;
        }
        if (editor && typeof tinymce !== 'undefined' && tinymce.get('editor1')) {
            editor.value = tinymce.get('editor1').getContent();
        }
        httpRequest({
            url,
            method: 'POST',
            data: httpSerializeForm(formEl),
            target: this.modalContent,
            onSuccess: (data) => {
                if (data.length > 0) {
                    $('#flash-message-overlay').empty().append(data);
                    if (window.isTemplateAttachment) {
                        this.previewTemplateForReservation(0, 'false');
                    }
                } else {
                    this.getReservation(refreshUrl || window.lastClickedReservationUrl, 'correspondence');
                }
            }
        });
        return false;
    }

    exportPDFCorrespondence(id, url = null) {
        let targetUrl = url;
        if (!targetUrl) {
            const template = this.getContextValue('exportCorrespondencePdfUrlTemplate');
            targetUrl = template && id ? template.replace('placeholder', id) : null;
        }
        if (targetUrl) {
            window.location.href = targetUrl;
        }
        return false;
    }

    showMailCorrespondence(id, reservationId, url = null) {
        let targetUrl = url;
        if (!targetUrl) {
            const template = this.getContextValue('showCorrespondenceUrlTemplate');
            targetUrl = template && id ? template.replace('placeholder', id) : null;
        }
        if (!targetUrl) {
            return false;
        }
        httpRequest({
            url: targetUrl,
            method: 'POST',
            data: { reservationId },
            target: this.modalContent,
            onSuccess: (data) => {
                $('#modalCenter .modal-title').text(this.translate('templates.preview'));
                const modalBody = document.getElementById('modal-content-ajax');
                if (modalBody) {
                    modalBody.innerHTML = data;
                }
            }
        });
        return false;
    }

    doDeleteInvoice() {
        const form = '#invoiceDeleteForm';
        const formEl = document.querySelector(form);
        const url = formEl?.dataset.url || formEl?.action || this.getContextValue('deleteInvoiceUrl');
        if (!url) {
            return false;
        }
        httpRequest({
            url,
            method: 'POST',
            data: httpSerializeForm(formEl),
            onSuccess: () => location.reload()
        });
        return false;
    }

    toggleReservationDelete() {
        const boxDelete = $('#boxDelete');
        const boxDefault = $('#boxDefault');
        if (boxDelete.is(':hidden')) {
            boxDelete.fadeIn().removeClass('d-none');
            boxDefault.hide();
        } else {
            boxDelete.addClass('d-none');
            boxDefault.fadeIn();
        }
        return false;
    }

    deleteCorrespondence(id) {
        const url = this.getContextValue('deleteCorrespondenceUrl');
        if (!url) {
            return false;
        }
        httpRequest({
            url,
            method: 'POST',
            data: { id, _csrf_token: $('#_csrf_token').val() },
            target: this.modalContent,
            onSuccess: (data) => {
                if (data.length > 0) {
                    $('#flash-message-overlay').empty().append(data);
                } else {
                    this.getReservation(window.lastClickedReservationUrl, 'correspondence');
                }
            }
        });
        return false;
    }

    toggleReservationEditAppartments(id) {
        const box = $('#box-available-appartments');
        if (box.is(':hidden')) {
            this.getAvailableAppartmentsForPeriod('edit');
            box.fadeIn().removeClass('d-none');
            $('#appartment-' + id).addClass('d-none');
            $('#reservation-edit-save').hide();
            $('#selectedAppartments').addClass('text-secondary');
        } else {
            box.addClass('d-none');
            $('#reservation-edit-save').show();
            $('#selectedAppartments').removeClass('text-secondary');
            $('#appartment-' + id).fadeIn();
        }
        return false;
    }

    editUpdateReservation(appartmentId) {
        const reservationId = $('#reservation-id').val() || appartmentId;
        const options = 'status=' + $('#appartment-' + appartmentId).find('#status :selected').val() + '&persons=' + $('#appartment-' + appartmentId).find('#persons :selected').val();
        const targetForm = document.getElementById('reservation-period');
        const url = targetForm ? targetForm.dataset.target : null;
        if (!url) {
            return false;
        }
        httpRequest({
            url,
            method: 'POST',
            data: (options + '&id=' + reservationId + '&aid=' + appartmentId + '&from=' + $('#from').val() + '&end=' + $('#end').val()),
            target: this.modalContent,
            onSuccess: (data) => {
                if (this.modalContent) {
                    this.modalContent.innerHTML = data;
                }
                this.getNewTable();
            }
        });
        return false;
    }

    

    addAsAttachment(id, isInvoice) {
        const url = this.getContextValue('addAttachmentUrl');
        if (!url) {
            return false;
        }
        httpRequest({
            url,
            method: 'POST',
            data: { id, isInvoice },
            target: this.modalContent,
            onSuccess: () => this.previewTemplateForReservation(0, 'false')
        });
        return false;
    }

    deleteAttachment(id, url = null, form = null) {
        const targetUrl = url || this.getContextValue('deleteAttachmentUrl');
        if (!targetUrl) {
            return false;
        }
        const token = form?.querySelector("input[name='_csrf_token']").value;
        httpRequest({
            url: targetUrl,
            method: 'POST',
            data: { id, _csrf_token: token },
            loader: false,
            target: this.modalContent,
            onSuccess: (data) => {
                if (data.length > 0) {
                    $('#flash-message-overlay').empty().append(data);
                } else {
                    const row = document.getElementById('aid-' + id);
                    if (row) {
                        row.remove();
                    }
                }
            }
        });
        return false;
    }

    // ----- utility helpers -----

    enablePriceOptionsMisc() {
        document.querySelectorAll('#reservation-price-misc-options input[type="checkbox"]').forEach((item) => {
            if (item.dataset.enhanced) {
                return;
            }
            item.dataset.enhanced = 'true';
            item.addEventListener('click', () => {
                const form = item.closest('form')
                const reservationId = item.dataset.reservationId;
                this.saveMiscPriceForReservation(form, form.action, reservationId);
                item.disabled = true;
            });
        });
    }

    saveMiscPriceForReservation(form, url, reservationId) {
        if (!url) {
            return false;
        }
        httpRequest({
            url,
            method: 'POST',
            loader: false,
            data: httpSerializeForm(form),
            onSuccess: 
                () => this.getReservation(reservationId == 'new' ? reservationId : window.lastClickedReservationUrl, 'prices', false)
        });
        return false;
    }

    initTinyMceEditor() {
        if (typeof tinymce === 'undefined') {
            return;
        }
        const editorNode = document.getElementById('editor1');
        if (!editorNode) {
            return;
        }
    
        if (editorNode.dataset.tinymceInitialized === 'true' || tinymce.get('editor1') !== null) {
            return;
        }

        editorNode.dataset.tinymceInitialized = 'true';
        tinymce.init({
            selector: '#editor1',
            language: document.documentElement.lang || 'de',
            branding: false,
            promotion: false,
            valid_children: '+body[style]',
            relative_urls: false,
            protect: [
                /<\/?\.?(set)?(html)?pageheader.*?>/g,
                /<\/?\.?(set)?(html)?pagefooter.*?>/g
            ]
        });
    }

    attachCustomerSearchInputs() {
        document.querySelectorAll('[data-reservations-customer-search]').forEach((input) => {
            if (input.dataset.enhanced) {
                return;
            }
            input.dataset.enhanced = 'true';
            const mode = input.dataset.searchMode || '';
            const tab = input.dataset.searchTab || '';
            const appartment = input.dataset.searchAppartment || '';
            let debounceTimer = null;
            input.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = window.setTimeout(() => {
                    this.getCustomers(1, mode, tab, appartment);
                }, 400);
            });
        });
    }

    attachPaginationLinks() {
        document.querySelectorAll('[data-reservations-pagination]').forEach((wrapper) => {
            if (wrapper.dataset.enhanced) {
                return;
            }
            wrapper.dataset.enhanced = 'true';
            wrapper.addEventListener('click', (event) => {
                const link = event.target.closest('a[data-page]');
                if (!link) {
                    return;
                }
                event.preventDefault();
                const page = link.dataset.page;
                const mode = wrapper.dataset.reservationsPaginationMode;
                const tab = wrapper.dataset.reservationsPaginationTab;
                const appartmentId = wrapper.dataset.reservationsPaginationAppartmentId;
                if (wrapper.dataset.reservationsPagination === 'customers') {
                    this.getCustomers(page, mode, tab, appartmentId);
                }
            });
        });
    }

    getLocalTableSetting(targetFieldName, settingName, type = 'string') {
        const setting = getLocalStorageItem(settingName);
        if (setting !== null && setting.length > 0) {
            let value = setting;
            if (type === 'int') {
                value = parseInt(setting);
                if (isNaN(value)) {
                    return;
                }
            }
            const targetField = document.querySelector("#table-filter select[name='" + targetFieldName + "']");
            if (targetField !== null) {
                targetField.value = value;
            }
        }
    }

    setLocalTableSetting(targetFieldName, settingName, type = 'string') {
        const targetField = document.querySelector("#table-filter select[name='" + targetFieldName + "']");
        if (targetField === null) {
            return;
        }
        let value = targetField.value;
        if (type === 'int') {
            value = parseInt(value);
            if (isNaN(value)) {
                return;
            }
        }
        localStorage.setItem(settingName, value);
    }

    appendTab(url, tab) {
        if (!tab) {
            return url;
        }
        const separator = url.includes('?') ? '&' : '?';
        return `${url}${separator}tab=${tab}`;
    }

    getContextValue(key) {
        if (this.modalContent && this.modalContent.dataset && this.modalContent.dataset[key]) {
            return this.modalContent.dataset[key];
        }
        const scoped = this.modalContent?.querySelector('[data-controller="reservations"]');
        if (scoped && scoped.dataset && scoped.dataset[key]) {
            return scoped.dataset[key];
        }
        if (this.element && this.element.dataset && this.element.dataset[key]) {
            return this.element.dataset[key];
        }
        return null;
    }

    translate(key) {
        const translations = this.translations || this.readTranslationsFromDom();
        if (translations && translations[key]) {
            return translations[key];
        }
        return key;
    }

    readTranslationsFromDom() {
        const source = document.querySelector('[data-reservations-translations-value]');
        if (!source) {
            return {};
        }
        const raw = source.dataset.reservationsTranslationsValue;
        if (!raw) {
            return {};
        }
        try {
            return JSON.parse(raw);
        } catch (e) {
            return {};
        }
    }
}
