function saveMiscPriceForReservation(reservationId, form, url) {
    let successFunc = function () {
        getReservation(reservationId, "prices", false)
    };
    _doPost("#" + form.id, url, "", null, successFunc);

    return false;
}

function enablePriceOptionsMisc() {
    document.querySelectorAll('#reservation-price-misc-options input[type="checkbox"]').forEach(item => {
        item.addEventListener('click', event => {
            let form = item.closest('form');
            saveMiscPriceForReservation(item.dataset.reservationid, form, form.action);
            item.disabled = true;
        });
    });
}

/**
 * Load subdivision list based on selected country
 * @param {bool} initial
 */
function loadTableSettings(url, initial) {
    // whether this function is called on initial page load
    initial = initial || false;
    _doPost('#table-filter', url, "", "POST", (data) => {
        document.getElementById("modal-content-settings").innerHTML = data;
        if (initial) {
            getLocalTableSetting('holidaySubdivision', 'reservations-table-holidaysubdivision');
            getNewTable();
        }
    });
}

/**
 * Load table settings based on local storage and set the values
 * @param {string} targetField
 * @param {string} settingName
 * @param {string} type
 * @returns {void}
 */
function getLocalTableSetting(targetFieldName, settingName, type) {
    type = type || 'string';
    let setting = localStorage.getItem(settingName);
    if (setting !== null && setting.length > 0) {
        let value = setting;
        if (type === 'int') {
            value = parseInt(setting);
            if (isNaN(value))
                return;
        }
        let targetField = document.querySelector("#table-filter select[name='" + targetFieldName + "']");
        if (targetField !== null) {
            targetField.value = value;
        }
    }
}

/**
 * Save table settings to local storage
 * @param {string} targetFieldName
 * @param {string} settingName
 * @param {string} type
 * @returns {void}
 */
function setLocalTableSetting(targetFieldName, settingName, type) {
    type = type || 'string';

    let targetField = document.querySelector("#table-filter select[name='" + targetFieldName + "']");
    if (targetField === null) {
        return;
    }

    let value = targetField.value;
    if (type === 'int') {
        value = parseInt(value);
        if (isNaN(value))
            return;
    }
    localStorage.setItem(settingName, value);
}

/**
 * Display the modal to edit table settings
 * @returns {Boolean}
 */
function showTableSettings() {
    let container = document.getElementById("table-settings");
    $(".modal-header .modal-title").text("Settings");
    $("#modal-content-ajax").html(container.innerHTML);

    return false;
}

/**
 * Checks whether the current day is reservation
 * @param {array} item
 * @returns {Boolean}
 */
function isDayWithReservation(item) {
    let reservationItem = item.node.querySelector(".reservation-yearly");
    // only when a reservation fits the whole day; ignore days where a reservation starts or ends
    if (reservationItem && !reservationItem.classList.contains('month-reservationstartend')) {
        return true;
    } else {
        // reservation start or ends at this day
        return false;
    }
    return false;
}

/**
 * Mark a day as selected
 * @param {type} item
 * @param {type} c
 * @returns {void}
 */
function selectableSelect(item, c) {
    item.node.classList.add(c.selecting);
    item.selecting = true;
}

/**
 * Mark a day as deselected
 * @param {type} item
 * @param {type} c
 * @returns {void}
 */
function selectableDeselect(item, c) {
    item.selecting = false;
    item.node.classList.remove(c.selecting);
    item.node.classList.remove(c.selected);
}

var selectable, lastSelectedMonthDay, startSlectedDay, endSelectedDay;