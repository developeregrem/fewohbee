import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        urls: Object,
        canSelect: { type: Boolean, default: false },
        translations: Object
    };

    connect() {
        this.urls = this.urlsValue || {};
        this.modalContent = document.getElementById('modal-content-ajax');
        this.tableContainer = document.getElementById('table-ajax');
        this.tableFilter = document.getElementById('table-filter');
        this.modalSettingsContainer = document.getElementById('modal-content-settings');
        window.lastClickedReservationId = window.lastClickedReservationId || 0;

        this.registerGlobalFunctions();
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
    }

    registerGlobalFunctions() {
        const bindings = {
            getNewTable: this.getNewTable.bind(this),
            selectAppartment: this.selectAppartment.bind(this),
            getAvailableAppartmentsForPeriod: this.getAvailableAppartmentsForPeriod.bind(this),
            addAppartmentToSelection: this.addAppartmentToSelection.bind(this),
            selectableAddAppartmentToSelection: this.selectableAddAppartmentToSelection.bind(this),
            deleteAppartmentFromSelection: this.deleteAppartmentFromSelection.bind(this),
            saveAppartmentOptions: this.saveAppartmentOptions.bind(this),
            selectCustomer: this.selectCustomer.bind(this),
            getCustomers: this.getCustomers.bind(this),
            getFormForNewCustomer: this.getFormForNewCustomer.bind(this),
            createNewCustomer: this.createNewCustomer.bind(this),
            getReservationPreview: this.getReservationPreview.bind(this),
            toggleAppartmentOptions: this.toggleAppartmentOptions.bind(this),
            createNewReservations: this.createNewReservations.bind(this),
            getReservation: this.getReservation.bind(this),
            editReservation: this.editReservation.bind(this),
            changeReservationCustomer: this.changeReservationCustomer.bind(this),
            editReservationNewCustomer: this.editReservationNewCustomer.bind(this),
            editReservationCustomerChange: this.editReservationCustomerChange.bind(this),
            toggleMoreInfo: this.toggleMoreInfo.bind(this),
            doDeleteReservation: this.doDeleteReservation.bind(this),
            showInvoice: this.showInvoice.bind(this),
            deleteReservationCustomer: this.deleteReservationCustomer.bind(this),
            editReservationCustomerEdit: this.editReservationCustomerEdit.bind(this),
            saveEditCustomer: this.saveEditCustomer.bind(this),
            selectTemplateForReservations: this.selectTemplateForReservations.bind(this),
            previewTemplateForReservation: this.previewTemplateForReservation.bind(this),
            sendEmail: this.sendEmail.bind(this),
            saveTemplateFile: this.saveTemplateFile.bind(this),
            exportPDFCorrespondence: this.exportPDFCorrespondence.bind(this),
            showMailCorrespondence: this.showMailCorrespondence.bind(this),
            doDeleteInvoice: this.doDeleteInvoice.bind(this),
            toggleReservationDelete: this.toggleReservationDelete.bind(this),
            deleteCorrespondence: this.deleteCorrespondence.bind(this),
            toggleReservationEditAppartments: this.toggleReservationEditAppartments.bind(this),
            editUpdateReservation: this.editUpdateReservation.bind(this),
            selectReservationForTemplate: this.selectReservationForTemplate.bind(this),
            selectReservatioForInvoice: this.selectReservatioForInvoice.bind(this),
            selectReservation: this.selectReservation.bind(this),
            addAsAttachment: this.addAsAttachment.bind(this),
            deleteAttachment: this.deleteAttachment.bind(this),
            loadTableSettings: this.loadTableSettings.bind(this),
            shiftStartDate: this.shiftStartDate.bind(this)
        };

        Object.assign(window, bindings);
    }

    setupTableFilterListeners() {
        if (!this.tableFilter) {
            return;
        }

        this.ensureLocalStorage('reservation-settings-show-month', 'true');
        this.ensureLocalStorage('reservation-settings-show-week', 'true');

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
                    this.loadTableSettings(this.urls.tableSettings);
                }
            });
        }
    }

    applyStoredTableSettings() {
        if (!this.tableFilter || !this.urls.tableSettings) {
            return;
        }
        this.getLocalTableSetting('interval', 'reservations-intervall', 'int');
        this.getLocalTableSetting('holidayCountry', 'reservations-table-holidaycountry');
        this.getLocalTableSetting('apartment', 'reservations-apartment', 'int');
        this.loadTableSettings(this.urls.tableSettings, true);
    }

    observeModalContent() {
        if (!this.modalContent) {
            return;
        }

        const observer = new MutationObserver(() => {
            this.afterModalContentChange();
        });
        observer.observe(this.modalContent, { childList: true, subtree: true });
    }

    afterModalContentChange() {
        this.enablePriceOptionsMisc();
        this.initTinyMceEditor();
        this.attachCustomerSearchInputs();
        this.attachPaginationLinks();
    }

    // ----- table helpers -----

    getNewTable() {
        const url = this.urls.reservationTable;
        if (!url || !this.tableFilter) {
            return false;
        }
        if (!window.jQuery) {
            window.setTimeout(() => this.getNewTable(), 50);
            return false;
        }

        this.setLocalTableSetting('interval', 'reservations-intervall', 'int');
        this.setLocalTableSetting('holidayCountry', 'reservations-table-holidaycountry');
        this.setLocalTableSetting('holidaySubdivision', 'reservations-table-holidaysubdivision');
        this.setLocalTableSetting('apartment', 'reservations-apartment', 'int');

        if (this.tableContainer) {
            this.tableContainer.innerHTML = window.modalLoader || '';
        }

        this.request({
            url,
            method: 'GET',
            data: this.serializeForm(this.tableFilter),
            target: this.tableContainer,
            onSuccess: (data) => {
                if (this.tableContainer) {
                    this.tableContainer.innerHTML = data;
                }
                this.toggleDisplayTableRows();
                this.initStickyTables();
                this.initFit();
                this.initTableInteractions();
            }
        });

        return false;
    }

    loadTableSettings(url, initial = false) {
        if (!url) {
            return;
        }
        if (typeof _doPost === 'undefined') {
            window.setTimeout(() => this.loadTableSettings(url, initial), 50);
            return;
        }
        _doPost('#table-filter', url, '', 'POST', (data) => {
            const container = document.getElementById('modal-content-settings');
            if (container) {
                container.innerHTML = data;
            }
            if (initial) {
                this.getLocalTableSetting('holidaySubdivision', 'reservations-table-holidaysubdivision');
                this.getNewTable();
            }
            this.updateDisplaySettingsOnChange();
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
    }

    updateDisplaySettings(name) {
        const target = document.getElementById(name);
        if (!target) {
            return;
        }
        const checked = this.getLocalStorageItem('reservation-settings-' + name);
        if (checked !== null) {
            target.checked = checked === 'true';
        }
        target.addEventListener('click', () => {
            const value = target.checked;
            this.setLocalStorageItemIfNotExists('reservation-settings-' + name, value, true);
            this.toggleDisplayTableRows();
        });
    }

    toggleDisplayTableRows() {
        this.toggleRow('reservation-table-header-month', this.getLocalStorageItem('reservation-settings-show-month'));
        this.toggleRow('reservation-table-header-week', this.getLocalStorageItem('reservation-settings-show-week'));
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

    selectReservatioForInvoice(id) {
        const url = this.urls.selectInvoice;
        this.request({
            url,
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

    selectReservationForTemplate(id) {
        const url = this.urls.selectTemplateReservation;
        window.lastClickedReservationId = id;
        $('.modal-header .modal-title').text(this.translate('templates.select.reservations'));
        this.request({
            url,
            method: 'POST',
            data: { reservationid: id, createNew: 'true' },
            target: this.modalContent
        });
        return false;
    }

    selectAppartment(createNewReservation) {
        const url = this.urls.selectAppartment;
        $('#modalCenter .modal-title').text(this.translate('nav.reservation.add'));
        const data = this.serializeSelectors(['#objects']) + '&createNewReservation=' + createNewReservation;
        this.request({
            url,
            method: 'GET',
            data,
            target: this.modalContent
        });
        return false;
    }

    getAvailableAppartmentsForPeriod(mode) {
        $('#available-appartments').html('');
        iniStartOrEndDate('from', 'end', 1);
        if ($('#from').val() !== '' && $('#end').val() !== '') {
            const url = mode === 'edit' ? this.urls.getEditAvailableAppartments : this.urls.getAvailableAppartments;
            this.request({
                url,
                method: 'POST',
                data: this.serializeForm('#reservation-period'),
                target: document.getElementById('available-appartments')
            });
        }
        return false;
    }

    addAppartmentToSelection(id) {
        const url = this.urls.addAppartment;
        const content = window.modalLoader || '';
        let data;
        if (id) {
            data = 'appartmentid=' + id + '&' + this.serializeForm('#reservation-period') + '&' + this.serializeForm('#appartment-options-' + id);
        } else {
            data = this.serializeForm('#reservation-period');
        }
        data = data + '&createNewReservation=false';
        this.request({
            url,
            method: 'POST',
            data,
            target: this.modalContent
        });
        return false;
    }

    selectableAddAppartmentToSelection(id, start, end) {
        const url = this.urls.addAppartmentSelectable;
        const content = window.modalLoader || '';
        const data = 'appartmentid=' + id + '&from=' + start + '&end=' + end + '&createNewReservation=true';
        this.ajax({
            url,
            type: 'post',
            data,
            beforeSend: () => $('#modal-content-ajax').html(content),
            error: (xhr) => alert(xhr.status),
            success: (data) => $('#modal-content-ajax').html(data)
        });
        return false;
    }

    deleteAppartmentFromSelection(id) {
        const url = this.urls.removeAppartment;
        this.request({
            url,
            method: 'POST',
            data: this.serializeSelectors(['#from', '#end']) + '&appartmentid=' + id + '&createNewReservation=false',
            target: this.modalContent
        });
        return false;
    }

    saveAppartmentOptions(id) {
        const url = this.urls.modifyAppartmentOptions;
        this.request({
            url,
            method: 'POST',
            data: (this.serializeSelectors(['#from', '#end']) + '&' + this.serializeForm('#appartment-options-' + id) + '&appartmentid=' + id + '&createNewReservation=false'),
            target: this.modalContent
        });
        return false;
    }

    selectCustomer() {
        const url = this.urls.selectCustomer;
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
            this.request({
                url,
                method: 'POST',
                data: this.serializeForm('#reservation-period'),
                target: this.modalContent
            });
        }
        return false;
    }

    getCustomers(page, mode, tab, appartmentId) {
        const url = mode === 'edit' ? this.urls.getCustomersEdit : this.urls.getCustomers;
        const content = window.modalLoader || '';
        const safeTab = tab || '';
        $('#customer-selection .btn-primary').addClass('d-none');
        this.request({
            url,
            method: 'POST',
            data: this.serializeForm('#lastname') + '&tab=' + safeTab + '&page=' + page + '&appartmentId=' + appartmentId,
            target: document.getElementById('customers'),
            loader: true
        });
        return false;
    }

    getFormForNewCustomer() {
        const url = this.urls.getCustomerNewForm;
        this.request({
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

    createNewCustomer() {
        const url = this.urls.createCustomer;
        this.request({
            url,
            method: 'POST',
            data: this.serializeForm('#customer-selection'),
            target: this.modalContent
        });
        return false;
    }

    getReservationPreview(id, tab, displayWait = true) {
        const url = this.urls.createPreview;
        if (displayWait && this.modalContent) {
            this.modalContent.innerHTML = window.modalLoader || '';
        }
        this.request({
            url,
            method: 'POST',
            data: { customerid: id, tab },
            target: this.modalContent,
            onSuccess: (data) => {
                if (this.modalContent) {
                    this.modalContent.innerHTML = data;
                }
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
        const url = this.urls.createReservations;
        this.request({
            url,
            method: 'POST',
            data: this.serializeSelectors(['#_csrf_token', '#reservation-remark', '#reservation-origin', '#reservation-arrivalTime', '#reservation-departureTime']),
            target: this.modalContent,
            onSuccess: (data) => {
                if (data.length > 0) {
                    $('#flash-message-overlay').empty().append(data);
                } else {
                    window.location.href = this.urls.startUrl || '/';
                }
            }
        });
        return false;
    }

    getReservation(id, tab, displayWait = true) {
        if (id !== 'new') {
            let url = this.urls.getReservation;
            url = url ? url.replace('placeholder', id) : null;
            if (!url) {
                return false;
            }
            if (tab != null) {
                url += '?tab=' + tab;
            }
            if (displayWait && this.modalContent) {
                this.modalContent.innerHTML = window.modalLoader || '';
            }
            $('#modalCenter .modal-title').text(this.translate('reservation.details'));
            $('#modalCenter').modal('show');
            this.request({
                url,
                method: 'GET',
                target: this.modalContent,
                onSuccess: (data) => {
                    if (data.length > 0) {
                        this.modalContent.innerHTML = data;
                    } else {
                        window.location.href = this.urls.startUrl || '/';
                    }
                }
            });
        } else {
            this.getReservationPreview(null, tab, displayWait);
        }
        return false;
    }

    editReservation(id) {
        let url = this.urls.editReservation;
        url = url ? url.replace('placeholder', id) : null;
        $('#modalCenter .modal-title').text(this.translate('nav.reservation.edit'));
        this.request({
            url,
            method: 'GET',
            data: this.serializeSelectors(['#objects']),
            target: this.modalContent
        });
        return false;
    }

    changeReservationCustomer(id, tab, appartmentId) {
        let url = this.urls.changeReservationCustomer;
        url = url ? url.replace('placeholder', id) : null;
        this.request({
            url,
            method: 'GET',
            data: { tab, appartmentId },
            target: this.modalContent
        });
        return false;
    }

    editReservationNewCustomer(id, tab) {
        let url = this.urls.editReservationNewCustomer;
        url = url ? url.replace('placeholder', id) : null;
        this.request({
            url,
            method: 'POST',
            data: this.serializeForm('#customer-selection'),
            target: this.modalContent,
            onSuccess: (data) => {
                if (data.length > 0) {
                    $('#flash-message-overlay').empty().append(data);
                } else {
                    this.getReservation(id, tab);
                    if (id !== 'new' && tab === 'booker') {
                        this.getNewTable();
                    }
                }
            }
        });
        return false;
    }

    editReservationCustomerChange(id, tab, appartmentId) {
        let url = this.urls.editReservationCustomerChange;
        url = url ? url.replace('placeholder', $('#reservation-id').val()) : null;
        this.request({
            url,
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
        const url = this.urls.deleteReservation;
        this.request({
            url,
            method: 'POST',
            data: this.serializeForm(form),
            success: () => location.reload()
        });
        return false;
    }

    showInvoice(id) {
        let url = this.urls.getInvoice;
        url = url ? url.replace('placeholder', id) : null;
        this.loadIntoModal(url);
        return false;
    }

    deleteReservationCustomer(elm, customerId) {
        const form = document.getElementById('actions-customer-' + customerId);
        const url = this.urls.deleteReservationCustomer;
        this.request({
            url,
            method: 'POST',
            data: this.serializeForm(form),
            target: this.modalContent
        });
        return false;
    }

    editReservationCustomerEdit(customerId, form) {
        const url = this.urls.editReservationCustomerEdit;
        this.request({
            url,
            method: 'POST',
            data: this.serializeForm(form),
            target: this.modalContent
        });
        return false;
    }

    saveEditCustomer(id, form) {
        const url = this.urls.saveEditCustomer;
        this.request({
            url,
            method: 'POST',
            data: this.serializeForm(form),
            target: this.modalContent
        });
        return false;
    }

    selectTemplateForReservations(templateId, inProcess = false) {
        const url = this.urls.selectTemplate;
        const formData = inProcess ? this.serializeForm('#template-form') : null;
        this.request({
            url,
            method: 'POST',
            data: { templateId, inProcess, formData },
            target: this.modalContent,
            onSuccess: (data) => {
                $('#modalCenter .modal-title').text(this.translate('templates.select.template'));
                $('#modal-content-ajax').html(data);
            }
        });
        return false;
    }

    previewTemplateForReservation(id, inProcess = false) {
        let url = this.urls.previewTemplate;
        url = url ? url.replace('placeholder', id) : null;
        this.request({
            url,
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
        const url = this.urls.sendEmail;
        const formEl = typeof form === 'string' ? document.querySelector(form) : form;
        const editor = formEl ? formEl.querySelector('#editor1') : null;
        if (editor && typeof tinymce !== 'undefined' && tinymce.get('editor1')) {
            editor.value = tinymce.get('editor1').getContent();
        }
        this.request({
            url,
            method: 'POST',
            data: this.serializeForm(formEl),
            target: this.modalContent,
            onSuccess: (data) => {
                if (data.length > 0) {
                    $('#flash-message-overlay').empty().append(data);
                } else {
                    this.getReservation(window.lastClickedReservationId, 'correspondence');
                }
            }
        });
        return false;
    }

    saveTemplateFile(form) {
        const url = this.urls.saveTemplateFile;
        const formEl = typeof form === 'string' ? document.querySelector(form) : form;
        const editor = formEl ? formEl.querySelector('#editor1') : null;
        if (editor && typeof tinymce !== 'undefined' && tinymce.get('editor1')) {
            editor.value = tinymce.get('editor1').getContent();
        }
        this.request({
            url,
            method: 'POST',
            data: this.serializeForm(formEl),
            target: this.modalContent,
            onSuccess: (data) => {
                if (data.length > 0) {
                    $('#flash-message-overlay').empty().append(data);
                    if (window.isTemplateAttachment) {
                        this.previewTemplateForReservation(0, 'false');
                    }
                } else {
                    this.getReservation(window.lastClickedReservationId, 'correspondence');
                }
            }
        });
        return false;
    }

    exportPDFCorrespondence(id) {
        let url = this.urls.exportCorrespondencePdf;
        url = url ? url.replace('placeholder', id) : null;
        if (url) {
            window.location.href = url;
        }
        return false;
    }

    showMailCorrespondence(id, reservationId) {
        let url = this.urls.showCorrespondence;
        url = url ? url.replace('placeholder', id) : null;
        const content = window.modalLoader || '';
        this.ajax({
            url,
            type: 'post',
            data: { reservationId },
            beforeSend: () => $('#modal-content-ajax').html(content),
            error: (xhr) => alert(xhr.status),
            success: (data) => {
                $('#modalCenter .modal-title').text(this.translate('templates.preview'));
                $('#modal-content-ajax').html(data);
            }
        });
        return false;
    }

    doDeleteInvoice() {
        const form = '#invoiceDeleteForm';
        const url = this.urls.deleteInvoice;
        if (!url) {
            return false;
        }
        this.request({
            url,
            method: 'POST',
            data: this.serializeForm(form),
            success: () => location.reload()
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

    deleteCorrespondence(id, reservationId) {
        const url = this.urls.deleteCorrespondence;
        this.request({
            url,
            method: 'POST',
            data: { id, _csrf_token: $('#_csrf_token').val() },
            target: this.modalContent,
            onSuccess: (data) => {
                if (data.length > 0) {
                    $('#flash-message-overlay').empty().append(data);
                } else {
                    this.getReservation(reservationId, 'correspondence');
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
        this.request({
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

    selectReservation(id) {
        const url = this.urls.selectReservationForTemplate;
        $('.modal-header .modal-title').text(this.translate('templates.select.reservations'));
        this.request({
            url,
            method: 'POST',
            data: { reservationid: id },
            target: this.modalContent
        });
        return false;
    }

    addAsAttachment(id, isInvoice) {
        const url = this.urls.addAttachment;
        this.request({
            url,
            method: 'POST',
            data: { id, isInvoice },
            target: this.modalContent,
            onSuccess: () => this.previewTemplateForReservation(0, 'false')
        });
        return false;
    }

    deleteAttachment(id) {
        const url = this.urls.deleteAttachment;
        this.request({
            url,
            method: 'POST',
            data: { id, _csrf_token: $('#_csrf_token').val() },
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
                const form = item.closest('form');
                this.saveMiscPriceForReservation(item.dataset.reservationid, form, form.action);
                item.disabled = true;
            });
        });
    }

    saveMiscPriceForReservation(reservationId, form, url) {
        const successFunc = () => this.getReservation(reservationId, 'prices', false);
        _doPost('#' + form.id, url, '', null, successFunc);
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
        if (tinymce.get('editor1') !== null) {
            tinymce.get('editor1').remove();
        }
        tinymce.init({
            selector: '#editor1',
            language: document.documentElement.lang || 'de',
            branding: false,
            promotion: false,
            valid_children: '+body[style]',
            relative_urls: false,
            protect: [
                /<\/?\.?(html)?pageheader.*?>/g,
                /<\/?\.?(html)?pagefooter.*?>/g
            ]
        });
    }

    attachCustomerSearchInputs() {
        document.querySelectorAll('[data-reservations-customer-search]').forEach((input) => {
            if (input.dataset.enhanced) {
                return;
            }
            input.dataset.enhanced = 'true';
            if (typeof $(input).delayKeyup === 'function') {
                const mode = input.dataset.searchMode || '';
                const tab = input.dataset.searchTab || '';
                const appartment = input.dataset.searchAppartment || '';
                $(input).delayKeyup(() => {
                    this.getCustomers(1, mode, tab, appartment);
                }, 400);
            }
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

    request({ url, method = 'GET', data = null, target = null, loader = true, onSuccess = null, onError = null }) {
        const serialized = this.serializeData(data);
        let finalUrl = url;
        const fetchOptions = { method: method.toUpperCase(), headers: {} };

        if (fetchOptions.method === 'GET') {
            if (serialized) {
                finalUrl += (finalUrl.includes('?') ? '&' : '?') + serialized;
            }
        } else {
            fetchOptions.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
            fetchOptions.body = serialized;
        }

        const targetEl = typeof target === 'string' ? document.querySelector(target) : target;
        if (targetEl && loader) {
            targetEl.innerHTML = window.modalLoader || '';
        }

        fetch(finalUrl, fetchOptions)
            .then(async (response) => {
                const text = await response.text();
                if (!response.ok) {
                    const message = text || response.statusText || 'Unbekannter Fehler';
                    throw new Error(`${response.status} ${message}`.trim());
                }
                if (onSuccess) {
                    onSuccess(text);
                } else if (targetEl) {
                    targetEl.innerHTML = text;
                }
            })
            .catch((err) => {
                const message = err && err.message ? err.message : 'Request fehlgeschlagen';
                if (onError) {
                    onError(message);
                } else {
                    alert(message);
                }
            });
    }

    serializeData(data) {
        if (!data) {
            return '';
        }
        if (typeof data === 'string') {
            return data;
        }
        if (data instanceof FormData) {
            return new URLSearchParams([...data.entries()]).toString();
        }
        if (typeof data === 'object') {
            return new URLSearchParams(Object.entries(data)).toString();
        }
        return '';
    }

    serializeForm(selectorOrNode) {
        const node = typeof selectorOrNode === 'string' ? document.querySelector(selectorOrNode) : selectorOrNode;
        if (!node) {
            return '';
        }
        if (node instanceof HTMLFormElement) {
            return this.serializeData(new FormData(node));
        }
        const params = new URLSearchParams();
        if (node.name) {
            params.append(node.name, node.value ?? '');
        }
        return params.toString();
    }

    serializeSelectors(selectors = []) {
        const params = new URLSearchParams();
        selectors.forEach((selector) => {
            document.querySelectorAll(selector).forEach((node) => {
                if (node.name) {
                    params.append(node.name, node.value ?? '');
                }
            });
        });
        return params.toString();
    }

    loadIntoModal(url, callback) {
        this.request({
            url,
            method: 'GET',
            target: this.modalContent,
            onSuccess: (data) => {
                if (this.modalContent) {
                    this.modalContent.innerHTML = data;
                }
                if (callback) {
                    callback();
                }
            }
        });
    }

    ensureLocalStorage(key, value) {
        if (localStorage.getItem(key) === null) {
            localStorage.setItem(key, value);
        }
    }

    getLocalStorageItem(key) {
        return localStorage.getItem(key);
    }

    setLocalStorageItemIfNotExists(key, value) {
        if (localStorage.getItem(key) === null) {
            localStorage.setItem(key, value);
        } else {
            localStorage.setItem(key, value);
        }
    }

    getLocalTableSetting(targetFieldName, settingName, type = 'string') {
        const setting = localStorage.getItem(settingName);
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

    translate(key) {
        if (this.hasTranslationsValue && this.translationsValue[key]) {
            return this.translationsValue[key];
        }
        return key;
    }
}
