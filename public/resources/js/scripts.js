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