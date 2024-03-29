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