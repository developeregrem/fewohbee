 $.ajaxSetup({
    cache: false
});
/**
 * Loads content into the modal via get
 * @param {string} url
 * @param {string} title
 * @param {type} successFunc
 */
function getContentForModal(url, title, successFunc) {
    title = title || "";
    successFunc = successFunc || function(){};
    $("#modalCenter .modal-title").text(title);
    $("#modal-content-ajax").html(modalLoader);
    $("#modal-content-ajax").load(url, function (response, status, xhr) {
        successFunc();
    });
}

/**
 * Performs a post request with data from the given form id
 * if a validation error occurs the warning will be displayed in the modal itself
 * if success a realod is performed
 * @param {string} formId
 * @param {string} url
 * @param {string} successUrl
 * @returns {Boolean}
 */
function _doPost(formId, url, successUrl) {
    successUrl = successUrl || "";
    $.ajax({
        url: url,
        type: "POST",
        data: $(formId).serialize(),
        error: function (xhr, ajaxOptions, thrownError) {
            alert(xhr.status);
        },
        success: function (data) {
            // if the whole modal content is returned
            if($(data).filter('.modal-body').length > 0 || $(data).find('.modal-body').length > 0) {
                $("#modal-content-ajax").html(data);
            // if only flash messages are returned
            } else if (data && data.length > 0) {   
                $("#flash-message-overlay").empty();
                $("#flash-message-overlay").append(data);
            } else if(successUrl.length > 0 ) {
                location.href = successUrl;
            } else {
                location.reload();
            }
        }
    });
    return false;
}