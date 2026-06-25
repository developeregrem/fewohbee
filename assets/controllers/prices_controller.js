import { Controller } from '@hotwired/stimulus';
import {
    disposeTooltips,
    enableTooltips,
    iniStartOrEndDate,
    whenBootstrapAndIconsReady,
} from '../js/utils.js';

/* stimulusFetch: 'lazy' */

export default class extends Controller {
    static values = {
        priceId: String,
        startSelector: String,
        endSelector: String,
        periodsSelector: String,
    };

    connect() {
        const modalDialog = document.querySelector('#modalCenter .modal-dialog');

        if (modalDialog && !modalDialog.classList.contains('modal-lg')) {
            modalDialog.classList.add('modal-lg');
        }
        this.initState();
        this.observePeriodList();
        this.initPopovers();
        this.initTooltips();
    }

    async initPopovers() {
        const ready = await whenBootstrapAndIconsReady();
        if (!ready) return;
        this.element.querySelectorAll('[data-bs-toggle="popover"]').forEach((el) => {
            window.bootstrap.Popover.getOrCreateInstance(el);
        });
    }

    async initTooltips() {
        await enableTooltips(this.element);
    }

    disconnect() {
        if (this.periodObserver) {
            this.periodObserver.disconnect();
        }
        disposeTooltips(this.element);
    }

    beforeSubmitAction(event) {
        const form = event.target.closest('form');
        if (!form) return;
        this.addPeriodFromSelectors(form);
    }

    toggleTypeAction(event) {
        const select = event.currentTarget;
        const priceId = select.dataset.priceId || this.getPriceId();
        this.applyTypeState(select.value, priceId);
    }

    toggleStartEndAction(event) {
        const checkbox = event.currentTarget;
        const priceId = checkbox.dataset.priceId || this.getPriceId();
        this.applyStartEndState(checkbox.checked, priceId);
    }

    syncPeriodDatesAction(event) {
        const startId = event.currentTarget.dataset.startSelector;
        const endId = event.currentTarget.dataset.endSelector;
        if (startId && endId) {
            const addDays = parseInt(event.currentTarget.dataset.addDays || '0', 10);
            iniStartOrEndDate(startId.replace('#', ''), endId.replace('#', ''), addDays);
        }
    }

    bookableOnlineChangeAction(event) {
        const checkbox = event.currentTarget;
        const priceId = checkbox.dataset.priceId || this.getPriceId();
        this.applyBookableOnlineState(checkbox.checked, priceId);
    }

    mandatoryOnlineChangeAction(event) {
        const checkbox = event.currentTarget;
        const priceId = checkbox.dataset.priceId || this.getPriceId();
        this.applyMandatoryOnlineState(checkbox.checked, priceId);
    }

    dayCheckboxChangeAction(event) {
        const checkbox = event.currentTarget;
        const priceId = checkbox.dataset.priceId || this.getPriceId();
        if (checkbox.name.startsWith('alldays-')) {
            this.setAllDaysState(checkbox.checked, priceId);
        } else {
            if (!checkbox.checked) {
                const allDays = this.getAllDaysCheckbox(priceId);
                if (allDays) {
                    allDays.checked = false;
                }
            } else if (this.areAllDaysChecked(priceId)) {
                const allDays = this.getAllDaysCheckbox(priceId);
                if (allDays) {
                    allDays.checked = true;
                }
            }
        }
    }

    addPeriodAction(event) {
        event.preventDefault();
        const trigger = event.currentTarget;
        this.addPeriod(
            trigger.dataset.startSelector,
            trigger.dataset.endSelector,
            trigger.dataset.targetSelector,
        );
    }

    removePeriodAction(event) {
        const btn = event.target.closest('.btn-close');
        if (!btn) return;
        const row = btn.closest('.price-period');
        if (row) {
            row.remove();
        }
    }

    initState() {
        const priceId = this.getPriceId();
        const allDays = this.getAllDaysCheckbox(priceId);
        if (allDays && priceId === 'new') {
            allDays.checked = true;
            this.setAllDaysState(true, priceId);
        }

        const typeSelect = this.element.querySelector(`#type-${priceId}`);
        if (typeSelect) {
            this.applyTypeState(typeSelect.value, priceId);
        }

        const allPeriods = this.element.querySelector(`#allperiods-${priceId}`);
        if (allPeriods) {
            this.applyStartEndState(allPeriods.checked, priceId);
        }

        const mandatoryOnline = this.element.querySelector(`#isMandatoryOnline-${priceId}`);
        if (mandatoryOnline) {
            this.applyMandatoryOnlineState(mandatoryOnline.checked, priceId);
        }
    }

    applyTypeState(value, priceId) {
        const fieldset = this.element.querySelector(`#price-form-fieldset-type-appartment-${priceId}`);
        const collapse = this.element.querySelector(`#collapseThree-${priceId}`);
        const headingButton = this.element.querySelector(`#headingThree-${priceId} button`);
        const numberOfPersons = this.element.querySelector(`#price-form-fieldset-type-appartment-${priceId} #number-of-persons`);
        const numberOfBeds = this.element.querySelector(`#price-form-fieldset-type-appartment-${priceId} #number-of-beds`);
        const minStay = this.element.querySelector(`#price-form-fieldset-type-appartment-${priceId} #min-stay`);
        const isAppartment = parseInt(value, 10) === 2;
        const defaultActiveWrapper = this.element.querySelector(`#default-active-in-reservation-creation-wrap-${priceId}`);
        const defaultActiveCheckbox = this.element.querySelector(`#isDefaultActiveInReservationCreation-${priceId}`);
        const perRoomRadio = this.element.querySelector(`#calc-per-room-${priceId}`);
        const isMisc = !isAppartment;

        if (fieldset) {
            fieldset.disabled = !isAppartment;
        }
        if (collapse) {
            collapse.classList.toggle('collapse', !isAppartment);
            collapse.classList.toggle('show', isAppartment);
        }
        if (headingButton) {
            headingButton.classList.toggle('text-secondary', !isAppartment);
            headingButton.classList.toggle('collapsed', !isAppartment);
            headingButton.disabled = !isAppartment;
        }
        if (numberOfPersons) {
            numberOfPersons.required = isAppartment;
        }
        if (numberOfBeds) {
            numberOfBeds.required = isAppartment;
        }
        if (minStay) {
            minStay.required = isAppartment;
        }
        if (defaultActiveWrapper) {
            defaultActiveWrapper.classList.toggle('d-none', !isMisc);
        }
        if (defaultActiveCheckbox) {
            defaultActiveCheckbox.disabled = !isMisc;
        }
        const bookableOnlineWrapper = this.element.querySelector(`#bookable-online-wrap-${priceId}`);
        const bookableOnlineCheckbox = this.element.querySelector(`#isBookableOnline-${priceId}`);
        if (bookableOnlineWrapper) {
            bookableOnlineWrapper.classList.toggle('d-none', !isMisc);
        }
        if (bookableOnlineCheckbox) {
            bookableOnlineCheckbox.disabled = !isMisc;
        }
        const mandatoryOnlineWrapper = this.element.querySelector(`#mandatory-online-wrap-${priceId}`);
        const mandatoryOnlineCheckbox = this.element.querySelector(`#isMandatoryOnline-${priceId}`);
        if (mandatoryOnlineWrapper) {
            mandatoryOnlineWrapper.classList.toggle('d-none', !isMisc);
        }
        if (mandatoryOnlineCheckbox) {
            if (!isMisc) {
                mandatoryOnlineCheckbox.checked = false;
                mandatoryOnlineCheckbox.disabled = true;
            } else {
                this.applyMandatoryOnlineState(mandatoryOnlineCheckbox.checked, priceId);
            }
        }
        // Single shared category field: required + no "all categories" option for apartment prices,
        // optional + "all categories" default for misc prices.
        const categorySelect = this.element.querySelector(`#category-${priceId}`);
        const categoryAllOption = this.element.querySelector(`#category-all-${priceId}`);
        const categoryHint = this.element.querySelector(`#category-hint-${priceId}`);
        if (categorySelect) {
            categorySelect.required = isAppartment;
        }
        if (categoryAllOption) {
            categoryAllOption.disabled = isAppartment;
            categoryAllOption.hidden = isAppartment;
            if (isAppartment && categorySelect && categorySelect.value === '') {
                const firstReal = categorySelect.querySelector('option[value]:not([value=""])');
                if (firstReal) categorySelect.value = firstReal.value;
            }
        }
        if (categoryHint) {
            categoryHint.classList.toggle('d-none', isAppartment);
        }
        const packageWrapper = this.element.querySelector(`#package-wrap-${priceId}`);
        if (packageWrapper) {
            packageWrapper.style.display = isMisc ? '' : 'none';
            if (!isMisc) {
                const packageCheckbox = this.element.querySelector(`#is-package-${priceId}`);
                if (packageCheckbox) {
                    packageCheckbox.checked = false;
                }
                const packageBody = this.element.querySelector(`#package-body-${priceId}`);
                if (packageBody) {
                    packageBody.classList.add('d-none');
                }
            }
        }
        if (priceId === 'new' && isAppartment && perRoomRadio && !perRoomRadio.disabled) {
            perRoomRadio.checked = true;
        }
    }

    applyStartEndState(allPeriodsChecked, priceId) {
        const start = this.element.querySelector(`#periodstart-${priceId}`);
        const end = this.element.querySelector(`#periodend-${priceId}`);
        if (start) start.disabled = allPeriodsChecked;
        if (end) end.disabled = allPeriodsChecked;
    }

    applyBookableOnlineState(bookableChecked, priceId) {
        const mandatory = this.element.querySelector(`#isMandatoryOnline-${priceId}`);
        if (!mandatory) return;
        if (!bookableChecked) {
            mandatory.checked = false;
            mandatory.disabled = true;
        } else {
            mandatory.disabled = false;
        }
    }

    applyMandatoryOnlineState(mandatoryChecked, priceId) {
        const bookable = this.element.querySelector(`#isBookableOnline-${priceId}`);
        if (!bookable) return;
        if (mandatoryChecked) {
            // Pflicht impliziert online verfügbar — Switch erzwingen und sperren.
            bookable.checked = true;
            bookable.disabled = true;
        } else {
            bookable.disabled = false;
        }
    }

    setAllDaysState(checked, priceId) {
        this.element.querySelectorAll('.days-control input[type="checkbox"]').forEach((cb) => {
            if (!cb.name.startsWith('alldays-')) {
                cb.checked = checked;
            }
        });
    }

    areAllDaysChecked(priceId) {
        const checkboxes = this.element.querySelectorAll('.days-control input[type="checkbox"]:not([name^="alldays-"])');
        return Array.from(checkboxes).every((cb) => cb.checked);
    }

    getAllDaysCheckbox(priceId) {
        return this.element.querySelector(`#alldays-${priceId}`);
    }

    addPeriodFromSelectors(form) {
        const startSel = form.dataset.pricesStartSelector || (this.hasStartSelectorValue ? this.startSelectorValue : null);
        const endSel = form.dataset.pricesEndSelector || (this.hasEndSelectorValue ? this.endSelectorValue : null);
        const targetSel = form.dataset.pricesPeriodsSelector || (this.hasPeriodsSelectorValue ? this.periodsSelectorValue : null);
        this.addPeriod(startSel, endSel, targetSel);
    }

    addPeriod(startSelector, endSelector, targetSelector) {
        if (!startSelector || !endSelector || !targetSelector) return;
        const start = document.querySelector(startSelector);
        const end = document.querySelector(endSelector);
        const target = document.querySelector(targetSelector);
        const template = document.getElementById('pricePeriodTemplate');

        if (!start || !end || !target || !template) return;
        if (!start.value || !end.value) return;

        const clone = template.querySelector('.price-period').cloneNode(true);
        const dStart = new Date(start.value);
        const dEnd = new Date(end.value);
        const txtStart = `${('0' + dStart.getDate()).slice(-2)}.${('0' + (dStart.getMonth() + 1)).slice(-2)}.${dStart.getFullYear()}`;
        const txtEnd = `${('0' + dEnd.getDate()).slice(-2)}.${('0' + (dEnd.getMonth() + 1)).slice(-2)}.${dEnd.getFullYear()}`;
        const periodText = clone.querySelector('.period-text');
        if (periodText) {
            periodText.textContent = `${txtStart} - ${txtEnd}`;
        }

        clone.querySelectorAll('input[type=hidden]').forEach((input) => {
            input.disabled = false;
        });
        const startInput = clone.querySelector("input[name='periodstart-new[]']");
        const endInput = clone.querySelector("input[name='periodend-new[]']");
        if (startInput) startInput.value = start.value;
        if (endInput) endInput.value = end.value;

        target.prepend(clone);
        start.value = '';
        end.value = '';
    }

    observePeriodList() {
        const targetSel = this.element.dataset.pricesPeriodsSelector || (this.hasPeriodsSelectorValue ? this.periodsSelectorValue : null);
        if (!targetSel) return;
        const target = document.querySelector(targetSel);
        if (!target) return;
        target.addEventListener('click', (event) => this.removePeriodAction(event));
    }

    getPriceId() {
        if (this.hasPriceIdValue) {
            return this.priceIdValue;
        }
        return this.element.dataset.pricesPriceId || 'new';
    }
}
