jQuery.fn.delayKeyup = function( cb, delay ){
    if(delay === null){
      delay = 400;
    }
    var timer = 0;
    return $(this).on('keyup',function(){
      clearTimeout(timer);
      timer = setTimeout( cb , delay );
    });
};

Date.prototype.addDays = function(days) {
    var date = new Date(this.valueOf());
    date.setDate(date.getDate() + days);
    return date;
}

Date.prototype.minusDays = function(days) {
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
    if(end.value === '' && start.value !== '') {
        let dEnd = new Date(start.value).addDays(addDays);
        let sDate = dEnd.getFullYear()+'-'+('0' + (dEnd.getMonth()+1)).slice(-2)+'-'+('0' + dEnd.getDate()).slice(-2);
        end.value = sDate;
    }

    if(start.value === '' && end.value !== '') {
       let dStart = new Date(end.value).minusDays(addDays);
        let sDate = dStart.getFullYear()+'-'+('0' + (dStart.getMonth()+1)).slice(-2)+'-'+('0' + dStart.getDate()).slice(-2);
        start.value = sDate;
    }
}