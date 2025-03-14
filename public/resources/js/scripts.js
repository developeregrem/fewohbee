jQuery.fn.delayKeyup = function (cb, delay) {
    if (delay === null) {
        delay = 400;
    }
    var timer = 0;
    return $(this).on('keyup', function () {
        clearTimeout(timer);
        timer = setTimeout(cb, delay);
    });
};

Date.prototype.addDays = function (days) {
    var date = new Date(this.valueOf());
    date.setDate(date.getDate() + days);
    return date;
}

Date.prototype.minusDays = function (days) {
    var date = new Date(this.valueOf());
    date.setDate(date.getDate() - days);
    return date;
}
/**
 * Inits  two date input fields if one of the fields is empty. It will add e.g. in the other field + 1 day
 * @param string idStart
 * @param string idEnd
 * @param int addDays
 * @returns void
 */
function iniStartOrEndDate(idStart, idEnd, addDays) {
    let end = document.getElementById(idEnd);
    let start = document.getElementById(idStart);
    if (end.value === '' && start.value !== '') {
        let dEnd = new Date(start.value).addDays(addDays);
        let sDate = dEnd.getFullYear() + '-' + ('0' + (dEnd.getMonth() + 1)).slice(-2) + '-' + ('0' + dEnd.getDate()).slice(-2);
        end.value = sDate;
    }

    if (start.value === '' && end.value !== '') {
        let dStart = new Date(end.value).minusDays(addDays);
        let sDate = dStart.getFullYear() + '-' + ('0' + (dStart.getMonth() + 1)).slice(-2) + '-' + ('0' + dStart.getDate()).slice(-2);
        start.value = sDate;
    }
}

/**
 * Adds an event listener to dynamically loaded content
 * @param element element a static element e.g. document
 * @param string type the event e.g. onclick
 * @param string selector the dynamically loaded content
 * @param function handler a function that shall be executed once the event is fired
 * @returns void
 */
const delegate = (element, type, selector, handler) => {
    element.addEventListener(type, (event) => {
        if (event.target.closest(selector)) {
            handler(event);
        }
    });
};

/**
 * Copy the text of a near textfield to clipboard
 * @param {Element} elm
 * @returns {void}
 */
function copyToClipboard(elm) {
    if (elm) {
        let target = elm.closest('div').querySelector('input[type=text]');
        if (target) {
            target.select();
            target.setSelectionRange(0, target.value.lengt);
            let suceed;
            try {
                suceed = navigator.clipboard.writeText(target.value);
            } catch (e) {
                console.warn(e);
                suceed = false;
            }

            if (suceed) {
                var popover = new bootstrap.Popover(target, {
                    'content': elm.dataset.hint,
                    'placement': 'top',
                });
                popover.show();
                setTimeout(function () {
                    popover.dispose();
                }, 1500);
            }
        }
    }
}

/**
 * Sets a value in localStorage if it doesn't already exist
 * @param {string} key
 * @param {string} value
 * @param {boolean} forceUpdate
 * @returns {void}
 */
function setLocalStorageItemIfNotExists(key, value, forceUpdate) {
    forceUpdate = forceUpdate || false;
    if (localStorage.getItem(key) === null || forceUpdate) {
        localStorage.setItem(key, value);
    }
}

/**
 * Gets a value from localStorage
 * @param {string} key
 * @returns {string|null}
 */
function getLocalStorageItem(key) {
    return localStorage.getItem(key);
}

/**
 * Update all export links with a new template id
 * @param {*} templateId 
 */
function updatePDFExportLinks(templateId) {
    let linksToUpdate = document.querySelectorAll('.export-link');
    linksToUpdate.forEach(function(link) {
        let src = link.getAttribute("href");
        let pos = src.lastIndexOf('/');
        // replace old template id with new one
        var str = src.substring(0, pos + 1) + templateId;
        // set new href
        link.setAttribute("href", str);
    });
}

/**
 * Creates a confirmation popover (yes/no) when clicking on an element which has the data-popover="delete" attribute assigned
 * @returns {void}
 */
function enableDeletePopover() {
    const myDefaultAllowList = bootstrap.Tooltip.Default.allowList;
    myDefaultAllowList.form = ['action'];
    myDefaultAllowList.input = ['type', 'name', 'value'];

    var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-popover="delete"]'));
    var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
        popoverTriggerEl.setAttribute('data-bs-toggle', 'popover');
        popoverTriggerEl.addEventListener('shown.bs.popover', () => {
            
            // add event listener to all delete/confirm buttons
            let deleteBut = document.getElementsByClassName('popover-delete');
            for (let i = 0; i < deleteBut.length; i++) {
                deleteBut[i].addEventListener('click', function (e) {
                    let form = e.target.closest('form');
                    let action = form.action;
                    let popover = bootstrap.Popover.getInstance(popoverTriggerEl);
                    popover.hide();
                    _doDelete(form, action);
                });
            }

            // add event listener to all cancel buttons
            let cancelBut = document.getElementsByClassName('popover-cancel');
            for (let i = 0; i < cancelBut.length; i++) {
                cancelBut[i].addEventListener('click', function (e) {

                    let popover = bootstrap.Popover.getInstance(popoverTriggerEl);
                    popover.hide();
                });
            }
          })

        let config = {
            'placement': 'top',
            'html': true,
            'trigger': 'focus',
        };
        return new bootstrap.Popover(popoverTriggerEl, config);
    });
}
