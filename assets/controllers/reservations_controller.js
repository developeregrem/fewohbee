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
    enableDeletePopover,
} from './utils_controller.js';
import { createSimpleHtmlEditor } from '../js/simple-html-editor.js';

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

        if (this.simpleEditor) {
            this.simpleEditor.destroy();
            this.simpleEditor = null;
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
        this.initHtmlEditor();
        this.attachCustomerSearchInputs();
        this.attachPaginationLinks();
        this.updateConflictBadgeFromModal();
        enableDeletePopover();
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

    openConflictsAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        if (!url) return;
        this.conflictsUrl = url;
        if (this.modalContent) {
            this.modalContent.dataset.conflictsUrl = url;
        }
        setModalTitle(this.translate('reservation.conflict.title'));
        this.loadConflictsModal();
    }

    resolveConflictAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        if (!url) return;
        httpRequest({
            url,
            method: 'POST',
            target: this.modalContent,
            loader: false,
            onSuccess: (data) => {
                if (this.showFeedback(data)) {
                    return;
                }
                this.loadConflictsModal();
                this.getNewTable();
            }
        });
    }

    paginateImportReviewAction(event) {
        event.preventDefault();
        const url = event.currentTarget.getAttribute('href');
        if (!url) return;
        this.conflictsUrl = url;
        if (this.modalContent) {
            this.modalContent.dataset.conflictsUrl = url;
        }
        this.loadConflictsModal();
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
        const einvoiceCheckbox = event.currentTarget.querySelector('[data-einvoice-checkbox]');
        const isEInvoice = !!(einvoiceCheckbox && einvoiceCheckbox.checked);
        const url = event.currentTarget.dataset.url;
        if (url && this.modalContent) {
            this.modalContent.dataset.addAttachmentUrl = url;
        }
        this.addAsAttachment(id, isInvoice, isEInvoice);
    }

    stopAttachmentRowClickAction(event) {
        event.stopPropagation();
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
        this.initCellSelection();
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

    initCellSelection() {
        if (!this.canSelectValue) {
            return;
        }

        const table = document.querySelector('.table-reservation');
        const calendar = document.querySelector('[data-reservations-yearly="true"]');
        if (!table && !calendar) {
            return;
        }

        const container = table || calendar;
        const isYearly = !!calendar;
        const cellSelector = isYearly ? '.reservation-yearly-parent' : '.td-empty';
        const isFinePointer = window.matchMedia('(pointer: fine)').matches;

        let startCell = null;
        let endCell = null;
        let dragging = false;
        let selectableCells = [];

        const isBlocked = (cell) => {
            if (isYearly) {
                const res = cell.querySelector('.reservation-yearly');
                return res && !res.classList.contains('month-reservationstartend');
            }
            return false;
        };

        // Get all <tr> rows that contain selectable cells
        const getAllRows = () => Array.from(container.querySelectorAll('tr')).filter(
            (tr) => tr.querySelector(cellSelector)
        );

        const buildCellList = (cell, singleRowOnly = false) => {
            if (!isYearly && !singleRowOnly) {
                // Multi-row: collect cells from all rows
                selectableCells = Array.from(container.querySelectorAll('tbody ' + cellSelector));
            } else if (!isYearly && singleRowOnly) {
                // Single row only (touch mode)
                const tr = cell.closest('tr');
                selectableCells = Array.from(tr.querySelectorAll(cellSelector));
            } else {
                selectableCells = Array.from(container.querySelectorAll(cellSelector));
            }
        };

        // Get valid date range within a single row, respecting blocked/reserved cells
        const getValidRangeForRow = (tr, fromDate, toDate) => {
            const cells = Array.from(tr.querySelectorAll(cellSelector));
            const startDate = fromDate <= toDate ? fromDate : toDate;
            const endDate = fromDate <= toDate ? toDate : fromDate;
            const inRange = cells.filter((c) => c.dataset.day >= startDate && c.dataset.day <= endDate);

            if (inRange.length === 0) return [];

            // Check for gaps: if the number of half-day cells doesn't match the expected
            // continuous range, there's a reservation/blocked cell in between.
            // Walk from the start cell's position in the full row toward the end,
            // stopping if any cell in between is not a td-empty cell.
            const allTds = Array.from(tr.querySelectorAll('td'));
            const firstEmpty = inRange[0];
            const lastEmpty = inRange[inRange.length - 1];
            const firstIdx = allTds.indexOf(firstEmpty);
            const lastIdx = allTds.indexOf(lastEmpty);
            if (firstIdx === -1 || lastIdx === -1) return inRange;

            // Check that all <td> elements between first and last are td-empty
            for (let i = firstIdx; i <= lastIdx; i++) {
                if (!allTds[i].matches(cellSelector)) {
                    // There's a non-empty cell (reservation/blocked) in between – not valid
                    return [];
                }
            }

            // Walk from start to end, stop at blocked (for yearly view compatibility)
            const valid = [];
            for (const cell of inRange) {
                if (isBlocked(cell)) break;
                valid.push(cell);
            }
            return valid;
        };

        // 2D rectangular selection: rows × date range
        const getValidRect = (from, to) => {
            if (!from) return [];
            const target = to || from;

            if (isYearly) {
                // Yearly view: keep original 1D behavior
                const fromIdx = selectableCells.indexOf(from);
                const toIdx = selectableCells.indexOf(target);
                if (fromIdx === -1 || toIdx === -1) return [];
                const start = Math.min(fromIdx, toIdx);
                const end = Math.max(fromIdx, toIdx);
                const cells = selectableCells.slice(start, end + 1);
                const forward = toIdx >= fromIdx;
                const valid = [];
                for (let i = 0; i < cells.length; i++) {
                    const cell = forward ? cells[i] : cells[cells.length - 1 - i];
                    if (isBlocked(cell)) break;
                    valid.push(cell);
                }
                return forward ? valid : valid.reverse();
            }

            // Multi-row rectangle selection
            const fromRow = from.closest('tr');
            const toRow = target.closest('tr');
            const allRows = getAllRows();
            const fromRowIdx = allRows.indexOf(fromRow);
            const toRowIdx = allRows.indexOf(toRow);
            if (fromRowIdx === -1 || toRowIdx === -1) return [];

            const startRowIdx = Math.min(fromRowIdx, toRowIdx);
            const endRowIdx = Math.max(fromRowIdx, toRowIdx);
            const fromDate = from.dataset.day;
            const toDate = target.dataset.day;

            const allValid = [];
            for (let i = startRowIdx; i <= endRowIdx; i++) {
                const rowValid = getValidRangeForRow(allRows[i], fromDate, toDate);
                allValid.push(...rowValid);
            }
            return allValid;
        };

        const clearHighlights = () => {
            container.querySelectorAll('.ui-selecting').forEach(
                (el) => el.classList.remove('ui-selecting')
            );
        };

        const highlightRange = (from, to) => {
            clearHighlights();
            const valid = getValidRect(from, to);
            valid.forEach((cell) => cell.classList.add('ui-selecting'));
            return valid;
        };

        const getCellFromPoint = (x, y) => {
            const el = document.elementFromPoint(x, y);
            return el ? el.closest(cellSelector) : null;
        };

        const resetSelection = () => {
            startCell = null;
            endCell = null;
            dragging = false;
            clearHighlights();
            if (table) {
                table.classList.add('table-hover');
            }
        };

        const finishSelection = () => {
            const valid = getValidRect(startCell, endCell || startCell);
            resetSelection();

            if (valid.length === 0) return;

            if (isYearly) {
                const firstDay = valid[0].dataset.day;
                const lastDay = valid[valid.length - 1].dataset.day;
                const apartmentId = calendar.dataset.reservationsApartmentId;
                if (apartmentId && firstDay) {
                    this.selectableAddAppartmentToSelection(apartmentId, firstDay, lastDay || firstDay);
                    $('#modalCenter').modal('toggle');
                }
                return;
            }

            // Multi-row: group valid cells by apartment
            const byApartment = new Map();
            for (const cell of valid) {
                const aptId = cell.dataset.appartment;
                if (!byApartment.has(aptId)) {
                    byApartment.set(aptId, []);
                }
                byApartment.get(aptId).push(cell);
            }

            // Collect apartment entries: [{id, from, end}]
            const apartments = [];
            for (const [aptId, cells] of byApartment) {
                const days = cells.map((c) => c.dataset.day).sort();
                apartments.push({
                    id: aptId,
                    from: days[0],
                    end: days[days.length - 1]
                });
            }

            if (apartments.length > 0) {
                this.selectableAddMultipleAppartmentsToSelection(apartments);
                $('#modalCenter').modal('toggle');
            }
        };

        // --- Touch: two-tap mode (tap start, tap end) – single row only ---
        if (!isFinePointer) {
            container.addEventListener('click', (e) => {
                const cell = getCellFromPoint(e.clientX, e.clientY);
                if (!cell || isBlocked(cell)) return;

                if (!startCell) {
                    // First tap: set start
                    buildCellList(cell, true);
                    startCell = cell;
                    cell.classList.add('ui-selecting');
                    if (table) {
                        table.classList.remove('table-hover');
                    }
                } else {
                    // Second tap: set end and finish
                    if (selectableCells.includes(cell)) {
                        endCell = cell;
                        highlightRange(startCell, endCell);
                        finishSelection();
                    } else {
                        // Tapped outside valid range → restart with this cell
                        resetSelection();
                        buildCellList(cell, true);
                        startCell = cell;
                        cell.classList.add('ui-selecting');
                        if (table) {
                            table.classList.remove('table-hover');
                        }
                    }
                }
            });
            return;
        }

        // --- Desktop: click-and-drag mode ---
        const onPointerDown = (e) => {
            const cell = getCellFromPoint(e.clientX, e.clientY);
            if (!cell || isBlocked(cell)) return;

            buildCellList(cell);
            dragging = true;
            startCell = cell;
            endCell = cell;
            cell.classList.add('ui-selecting');

            if (table) {
                table.classList.remove('table-hover');
            }

            container.setPointerCapture(e.pointerId);
            e.preventDefault();
        };

        const onPointerMove = (e) => {
            if (!dragging) return;
            const cell = getCellFromPoint(e.clientX, e.clientY);
            if (!cell || !cell.matches(cellSelector)) return;
            endCell = cell;
            highlightRange(startCell, endCell);
        };

        const onPointerUp = (e) => {
            if (!dragging) return;
            try { container.releasePointerCapture(e.pointerId); } catch (_) { /* */ }
            finishSelection();
        };

        const onPointerCancel = (e) => {
            if (!dragging) return;
            try { container.releasePointerCapture(e.pointerId); } catch (_) { /* */ }
            resetSelection();
        };

        container.addEventListener('pointerdown', onPointerDown);
        container.addEventListener('pointermove', onPointerMove);
        container.addEventListener('pointerup', onPointerUp);
        container.addEventListener('pointercancel', onPointerCancel);
        container.style.touchAction = 'none';
        container.addEventListener('selectstart', (e) => { if (dragging) e.preventDefault(); });
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

    // ----- conflict helpers -----

    loadConflictsModal() {
        const targetUrl = this.conflictsUrl || this.modalContent?.dataset?.conflictsUrl;
        if (!targetUrl) {
            return;
        }
        httpRequest({
            url: targetUrl,
            method: 'GET',
            target: this.modalContent,
            onSuccess: (data) => {
                if (this.modalContent) {
                    this.modalContent.innerHTML = data;
                }
                this.updateConflictBadgeFromModal();
                enableDeletePopover();
            }
        });
    }

    updateConflictBadgeFromModal() {
        const badge = document.querySelector('[data-conflict-count-badge]');
        const button = document.querySelector('[data-conflict-button]');
        const countSource = this.modalContent?.querySelector('[data-conflict-count]');
        if (!badge || !button) {
            return;
        }
        if (!countSource || !countSource.dataset || typeof countSource.dataset.conflictCount === 'undefined') {
            return;
        }
        const value = parseInt(countSource.dataset.conflictCount || '0', 10);
        badge.textContent = Number.isNaN(value) ? '0' : String(value);
        if (value > 0) {
            button.classList.remove('d-none');
        } else {
            button.classList.add('d-none');
        }
    }

    updateReservationStatusAction(event) {
        const select = event.currentTarget;
        const url = select.dataset.url;
        const token = select.dataset.token;
        if (!url || !token) {
            return;
        }
        select.disabled = true;
        httpRequest({
            url,
            method: 'POST',
            loader: false,
            data: { status: select.value, _token: token },
            onComplete: () => {
                select.disabled = false;
            }
        });
    }

    showFeedback(data, target = null) {
        if (!data || typeof data !== 'string' || data.trim().length === 0) {
            return false;
        }
        const base = target || this.modalContent || document;
        const overlay = base?.querySelector ? base.querySelector('#flash-message-overlay') : null;
        if (!overlay) {
            return false;
        }
        overlay.innerHTML = data;
        return true;
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

    selectableAddMultipleAppartmentsToSelection(apartments, url = null) {
        const targetUrl = url || this.addAppartmentSelectableUrl || document.getElementById('reservation-period')?.dataset.addAppartmentSelectableUrl;
        if (!targetUrl) {
            return false;
        }
        const params = new URLSearchParams();
        params.append('createNewReservation', 'true');
        apartments.forEach((apt, idx) => {
            params.append('apartments[' + idx + '][id]', apt.id);
            params.append('apartments[' + idx + '][from]', apt.from);
            params.append('apartments[' + idx + '][end]', apt.end);
        });
        httpRequest({
            url: targetUrl,
            method: 'POST',
            data: params.toString(),
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
            this.showFeedback(message);
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
                if (this.showFeedback(data)) {
                    return;
                }
                window.location.href = this.startUrl || '/';
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
                if (this.showFeedback(data)) {
                    return;
                }
                const resUrl = isNew ? 'new' : this.getContextValue('reservationUrl') || null;
                this.getReservation(resUrl, tab);
                if (url !== 'new' && tab === 'booker') {
                    this.getNewTable();
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
        if (editor && this.simpleEditor) {
            editor.value = this.simpleEditor.getHTML();
        }
        httpRequest({
            url,
            method: 'POST',
            data: httpSerializeForm(formEl),
            target: this.modalContent,
            onSuccess: (data) => {
                if (this.showFeedback(data)) {
                    return;
                }
                this.getReservation(refreshUrl || window.lastClickedReservationUrl, 'correspondence');
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
        if (editor && this.simpleEditor) {
            editor.value = this.simpleEditor.getHTML();
        }
        httpRequest({
            url,
            method: 'POST',
            data: httpSerializeForm(formEl),
            loader: false,
            onSuccess: (data) => {
                const doc = new DOMParser().parseFromString(data, 'text/html');
                const isAttachment = !!doc.querySelector('[data-is-attachment]');
                if (this.showFeedback(data)) {
                    if (isAttachment) {
                        this.previewTemplateForReservation(0, 'false');
                    }
                    return;
                }
                this.getReservation(refreshUrl || window.lastClickedReservationUrl, 'correspondence');
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
                if (this.showFeedback(data)) {
                    return;
                }
                this.getReservation(window.lastClickedReservationUrl, 'correspondence');
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

    

    addAsAttachment(id, isInvoice, isEInvoice = false) {
        const url = this.getContextValue('addAttachmentUrl');
        if (!url) {
            return false;
        }
        httpRequest({
            url,
            method: 'POST',
            data: { id, isInvoice, isEInvoice },
            loader: false,
            target: this.modalContent,
            onSuccess: (data) => {
                if (this.showFeedback(data)) {
                    return;
                }
                this.previewTemplateForReservation(0, 'false');
            }
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
                if (this.showFeedback(data)) {
                    return;
                }
                const row = document.getElementById('aid-' + id);
                if (row) {
                    row.remove();
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

    initHtmlEditor() {
        const textarea = document.getElementById('editor1');
        if (!textarea || textarea.dataset.editorInitialized === 'true') {
            return;
        }

        // Destroy previous instance if modal content was replaced
        if (this.simpleEditor) {
            this.simpleEditor.destroy();
            this.simpleEditor = null;
        }

        textarea.dataset.editorInitialized = 'true';
        textarea.style.display = 'none';

        const editorContainer = document.createElement('div');
        editorContainer.className = 'simple-html-editor-content border rounded p-2';
        editorContainer.style.minHeight = '200px';
        textarea.parentNode.insertBefore(editorContainer, textarea.nextSibling);

        this.simpleEditor = createSimpleHtmlEditor(editorContainer, textarea.value, {
            onUpdate: (html) => { textarea.value = html; },
            labels: {
                fontFamily: this.translate('templates.editor.toolbar.font_family'),
                fontSize: this.translate('templates.editor.toolbar.font_size'),
            },
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
