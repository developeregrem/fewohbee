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
