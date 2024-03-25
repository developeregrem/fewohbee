function createMiscellaneousInvoicePosition(elm) {
    return _doPost('#'+elm.id, elm.action);
}

function editMiscellaneousInvoicePosition(elm) {
    return _doPost('#'+elm.id, elm.action);
}

function createApartmentInvoicePosition(elm) {
    return _doPost('#'+elm.id, elm.action);
}

function editApartmentInvoicePosition(elm) {
    return _doPost('#'+elm.id, elm.action);
}

function changeInvoiceCustomerSave(elm)  {
    return _doPost('#'+elm.id, elm.action);
}

function fillCustomerRecommendation(elm) {
    let values = elm.value.split("|");

    // ignore first empty line
    if(values.length === 1) {
        return false;
    }
    document.getElementById("invoice_customer_salutation").value = values[0];
    document.getElementById("invoice_customer_firstname").value = values[1];
    document.getElementById("invoice_customer_lastname").value = values[2];
    document.getElementById("invoice_customer_company").value = values[3];
    document.getElementById("invoice_customer_address").value = values[4];
    document.getElementById("invoice_customer_zip").value = values[5];
    document.getElementById("invoice_customer_city").value = values[6];

    return false;
}

function fillFieldsFromPriceCategory(elm) {    
    let values = elm.value.split("|");
    // first empty option is selected
     if(values.length === 2) {
         return false;
     }
    document.getElementById("invoice_misc_position_vat").value = values[0];
    document.getElementById("invoice_misc_position_price").value = values[1];
    document.getElementById("invoice_misc_position_description").value = values[2];

    document.getElementById("invoice_misc_position_includesVat").checked = (values[3] == 1 ? true : false);
    document.getElementById("invoice_misc_position_isFlatPrice").checked = (values[4] == 1 ? true : false);
    document.getElementById("invoice_misc_position_amount").value = values[4];

    return false;
}

function fillApartmentFieldsFromPriceCategory(elm) {    
    let values = elm.value.split("|");
    // first empty option is selected
    if(values.length === 2) {
        return false;
    }
    document.getElementById("invoice_apartment_position_vat").value = values[0];
    document.getElementById("invoice_apartment_position_price").value = values[1];

    document.getElementById("invoice_apartment_position_includesVat").checked = (values[2] == 1 ? true : false);
    document.getElementById("invoice_apartment_position_isFlatPrice").checked = (values[3] == 1 ? true : false);

    return false;
}

function addChangeEvtForApartmentDescription() {
    let priceSelect = document.getElementById("invoice_apartment_position_number");
    priceSelect.addEventListener('change', event => {
        fillApartmentDescription(priceSelect);
    });
    // fill on load
    fillApartmentDescription(priceSelect);
}
function fillApartmentDescription(elm) {    
    let src = document.getElementById("invoice_apartment_position_description_choices").value;
    let values = src.split("|");
    document.getElementById("invoice_apartment_position_description").value = values[elm.selectedIndex];

    return false;
}
