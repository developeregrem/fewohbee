function saveMiscPriceForReservation(reservationId, form, url) {    
    let successFunc = function() { getReservation(reservationId, "prices", false) };
    _doPost("#"+form.id, url, "", null, successFunc);

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


