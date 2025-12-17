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
