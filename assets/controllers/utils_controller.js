/**
 * Shared utilities extracted from legacy scripts.js for reuse in Stimulus controllers.
 */

export function setLocalStorageItemIfNotExists(key, value, forceUpdate = false) {
    if (localStorage.getItem(key) === null || forceUpdate) {
        localStorage.setItem(key, value);
    }
}

export function getLocalStorageItem(key) {
    return localStorage.getItem(key);
}

export function updatePDFExportLinks(templateId) {
    const linksToUpdate = document.querySelectorAll('.export-link');
    linksToUpdate.forEach((link) => {
        const src = link.getAttribute('href');
        const pos = src ? src.lastIndexOf('/') : -1;
        if (pos === -1) {
            return;
        }
        const next = src.substring(0, pos + 1) + templateId;
        link.setAttribute('href', next);
    });
}

/**
 * Creates a confirmation popover (yes/no) when clicking on an element which has the data-popover="delete" attribute assigned
 */
export function enableDeletePopover() {
    if (!window.bootstrap || !window.bootstrap.Tooltip || !window.bootstrap.Popover) {
        return;
    }
    const doRequestDelete = window.HttpHelper?.request || null;
    const myDefaultAllowList = window.bootstrap.Tooltip.Default.allowList;
    myDefaultAllowList.form = ['action'];
    myDefaultAllowList.input = ['type', 'name', 'value'];

    const popoverTriggerList = Array.from(document.querySelectorAll('[data-popover="delete"]'));
    popoverTriggerList.forEach((popoverTriggerEl) => {
        popoverTriggerEl.setAttribute('data-bs-toggle', 'popover');
        popoverTriggerEl.addEventListener('click', (e) => {
            e.preventDefault();
            // Hide any other open delete popovers before toggling the current one
            popoverTriggerList.forEach((otherEl) => {
                if (otherEl === popoverTriggerEl) {
                    return;
                }
                const otherInstance = window.bootstrap.Popover.getInstance(otherEl);
                if (otherInstance) {
                    otherInstance.hide();
                }
            });
        });
        const title = popoverTriggerEl.getAttribute('data-title') || popoverTriggerEl.getAttribute('title') || '';
        const popover = new window.bootstrap.Popover(popoverTriggerEl, { 
            html: true,
            title: title
        });
        popoverTriggerEl.addEventListener('shown.bs.popover', () => {
            document.querySelectorAll('.popover-delete').forEach((btn) => {
                btn.addEventListener('click', (e) => {
                    const form = e.target.closest('form');
                    const action = form ? form.action : null;
                    const instance = window.bootstrap.Popover.getInstance(popoverTriggerEl);
                    if (instance) {
                        instance.hide();
                    }
                    if (action && doRequestDelete) {
                        doRequestDelete({
                            url: action,
                            method: 'DELETE',
                            data: form ? new FormData(form) : null,
                        });
                    }
                });
            });
            document.querySelectorAll('.popover-cancel').forEach((btn) => {
                btn.addEventListener('click', (e) => {
                    const form = e.target.closest('form');
                    if (form) {
                        form.reset();
                    }
                    const instance = window.bootstrap.Popover.getInstance(popoverTriggerEl);
                    if (instance) {
                        instance.hide();
                    }
                });
            });
        });
    });
}

export function setModalTitle(title) {
        if (!title) return;
        const modalTitle = document.querySelector('#modalCenter .modal-title');
        if (modalTitle) {
            modalTitle.textContent = title;
        }
    }

/**
 * Inits two date input fields if one of the fields is empty. It will add e.g. in the other field + 1 day
 * @param {string} idStart
 * @param {string} idEnd
 * @param {number} addDays
 * @returns {void}
 */
export function iniStartOrEndDate(idStart, idEnd, addDays = 0) {
    const end = document.getElementById(idEnd);
    const start = document.getElementById(idStart);
    if (!start || !end) {
        return;
    }

    const formatDate = (date) => {
        const month = ('0' + (date.getMonth() + 1)).slice(-2);
        const day = ('0' + date.getDate()).slice(-2);
        return `${date.getFullYear()}-${month}-${day}`;
    };

    if (end.value === '' && start.value !== '') {
        const shifted = new Date(start.value);
        shifted.setDate(shifted.getDate() + addDays);
        end.value = formatDate(shifted);
    }

    if (start.value === '' && end.value !== '') {
        const shifted = new Date(end.value);
        shifted.setDate(shifted.getDate() - addDays);
        start.value = formatDate(shifted);
    }
}

// Expose for legacy callers
if (!window.UtilsHelper) {
    window.UtilsHelper = {
        setLocalStorageItemIfNotExists,
        getLocalStorageItem,
        updatePDFExportLinks,
        enableDeletePopover,
        iniStartOrEndDate,
    };
}
if (!window.iniStartOrEndDate) {
    window.iniStartOrEndDate = iniStartOrEndDate;
}

export default {
    setLocalStorageItemIfNotExists,
    getLocalStorageItem,
    updatePDFExportLinks,
    enableDeletePopover,
    iniStartOrEndDate,
    setModalTitle
};
