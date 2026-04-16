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
 * Wait until the document has finished parsing (DOMContentLoaded).
 */
function _whenDomReady() {
    if (document.readyState !== 'loading') {
        return Promise.resolve();
    }
    return new Promise((resolve) => {
        document.addEventListener('DOMContentLoaded', () => resolve(), { once: true });
    });
}

/**
 * Polls for Bootstrap Popover/Tooltip availability. Resolves to true if available, false on timeout.
 */
function _whenBootstrapReady(maxAttempts = 30, intervalMs = 50) {
    return new Promise((resolve) => {
        let attempt = 0;
        const check = () => {
            if (window.bootstrap && window.bootstrap.Popover && window.bootstrap.Tooltip) {
                resolve(true);
                return;
            }
            if (++attempt >= maxAttempts) {
                resolve(false);
                return;
            }
            setTimeout(check, intervalMs);
        };
        check();
    });
}

/**
 * Forces Font Awesome (SVG/JS mode) to convert all pending <i> icons to <svg> now
 * and waits for completion. No-op if FA is absent or in webfont mode.
 */
async function _ensureFontAwesomeRendered() {
    const fa = window.FontAwesome;
    if (!fa || !fa.dom || typeof fa.dom.i2svg !== 'function') {
        return;
    }
    try {
        await fa.dom.i2svg();
    } catch {
        /* silent */
    }
}

/**
 * Resolves once the DOM is parsed, Bootstrap is available, and Font Awesome
 * has replaced all queued <i> icons with <svg>. Callers should await this
 * before initializing Bootstrap components that target FA icons.
 */
export async function whenBootstrapAndIconsReady() {
    await _whenDomReady();
    const bootstrapReady = await _whenBootstrapReady();
    if (!bootstrapReady) {
        return false;
    }
    await _ensureFontAwesomeRendered();
    return true;
}

/**
 * Extend the Bootstrap Tooltip/Popover sanitizer allow-list once so that
 * the delete popover form content survives sanitization.
 */
let _allowListPatched = false;
function _patchTooltipAllowList() {
    if (_allowListPatched || !window.bootstrap?.Tooltip?.Default?.allowList) {
        return;
    }
    const allowList = window.bootstrap.Tooltip.Default.allowList;
    allowList.form = ['action'];
    allowList.input = ['type', 'name', 'value'];
    _allowListPatched = true;
}

/**
 * Initialize Bootstrap tooltips on all `[data-bs-toggle="tooltip"]` elements
 * within the given root element. Waits for Bootstrap and Font Awesome.
 * Returns a Promise that resolves to the array of created Tooltip instances.
 */
export async function enableTooltips(root = document) {
    const ready = await whenBootstrapAndIconsReady();
    if (!ready) return [];
    const rootEl = root?.querySelectorAll ? root : document;
    return [...rootEl.querySelectorAll('[data-bs-toggle="tooltip"]')]
        .map((el) => window.bootstrap.Tooltip.getOrCreateInstance(el));
}

/**
 * Dispose all Bootstrap tooltips previously created within the given root.
 */
export function disposeTooltips(root = document) {
    const rootEl = root?.querySelectorAll ? root : document;
    rootEl.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
        const instance = window.bootstrap?.Tooltip?.getInstance?.(el);
        if (instance) instance.dispose();
    });
}

/**
 * Creates a confirmation popover (yes/no) when clicking on an element which has the
 * data-popover="delete" attribute assigned. Internally waits for Bootstrap and Font
 * Awesome to be ready, so callers do not need to worry about load timing.
 */
export function enableDeletePopover({ onSuccess, root = document } = {}) {
    whenBootstrapAndIconsReady().then((ready) => {
        if (ready) {
            _enableDeletePopoverNow({ onSuccess, root });
        }
    });
}

function _enableDeletePopoverNow({ onSuccess, root }) {
    _patchTooltipAllowList();
    const doRequestDelete = window.HttpHelper?.request || null;

    const rootEl = root?.querySelectorAll ? root : document;
    const popoverTriggerList = [];
    if (rootEl instanceof Element && rootEl.matches('[data-popover="delete"]')) {
        popoverTriggerList.push(rootEl);
    }
    popoverTriggerList.push(...Array.from(rootEl.querySelectorAll('[data-popover="delete"]')));

    popoverTriggerList.forEach((popoverTriggerEl) => {
        popoverTriggerEl._deletePopoverOnSuccess = onSuccess || null;
        popoverTriggerEl.setAttribute('data-bs-toggle', 'popover');
        if (popoverTriggerEl.dataset.deletePopoverInitialized === 'true') {
            return;
        }

        popoverTriggerEl.dataset.deletePopoverInitialized = 'true';

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
                    const targetSelector = popoverTriggerEl.getAttribute('data-delete-target');
                    const target = targetSelector ? document.querySelector(targetSelector) : null;
                    const instance = window.bootstrap.Popover.getInstance(popoverTriggerEl);
                    if (instance) {
                        instance.hide();
                    }
                    if (action && doRequestDelete) {
                        const successHandler = popoverTriggerEl._deletePopoverOnSuccess || null;
                        doRequestDelete({
                            url: action,
                            method: 'DELETE',
                            data: form ? new FormData(form) : null,
                            target: successHandler ? null : (target || null),
                            onSuccess: successHandler ? () => successHandler(popoverTriggerEl) : null,
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
