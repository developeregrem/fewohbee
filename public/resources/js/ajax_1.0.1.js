if (window.$ && $.ajaxSetup) {
    $.ajaxSetup({ cache: false });
}

function serializeFormData(formId) {
    const form = typeof formId === 'string' ? document.querySelector(formId) : formId;
    if (!form) {
        return '';
    }
    if (form instanceof HTMLFormElement) {
        return new URLSearchParams(new FormData(form)).toString();
    }
    const params = new URLSearchParams();
    if (form.name) {
        params.append(form.name, form.value ?? '');
    }
    return params.toString();
}

/**
 * Loads content into the modal via get
 * @param {string} url
 * @param {string} title
 * @param {type} successFunc
 */
function getContentForModal(url, title, successFunc) {
    title = title || "";
    successFunc = successFunc || function(){};
    const loader = window.modalLoader || "";
    const modalTitle = document.querySelector("#modalCenter .modal-title");
    const modalBody = document.getElementById("modal-content-ajax");
    if (modalTitle) {
        modalTitle.textContent = title;
    }
    if (modalBody) {
        modalBody.innerHTML = loader;
    }
    fetch(url, { method: "GET" })
        .then((response) => response.text())
        .then((data) => {
            if (modalBody) {
                modalBody.innerHTML = data;
            }
            successFunc();
        })
        .catch((err) => alert(err.message || 'Fehler beim Laden'));
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
function _doPost(formId, url, successUrl, type, successFunc) {
    successUrl = successUrl || "";
    type = type || "POST";
    successFunc = successFunc || null;

    const body = serializeFormData(formId);

    fetch(url, {
        method: type,
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body
    }).then((response) => response.text())
        .then((data) => {
            if(successFunc !== null) {
                successFunc(data);
            } else {
                const modal = document.getElementById("modal-content-ajax");
                const flash = document.getElementById("flash-message-overlay");
                const parser = new DOMParser();
                const doc = parser.parseFromString(data, 'text/html');
                if (doc.querySelector('.modal-body')) {
                    if (modal) {
                        modal.innerHTML = data;
                    }
                } else if (data && data.length > 0) {
                    if (flash) {
                        flash.innerHTML = '';
                        flash.insertAdjacentHTML('beforeend', data);
                    }
                } else if(successUrl.length > 0 ) {
                    location.href = successUrl;
                } else {
                    location.reload();
                }
            }
        })
        .catch((err) => alert(err.status || err.message));
    return false;
}

function _doDelete(formId, url, successUrl, successFunc) {
    return _doPost(formId, url, successUrl, "DELETE", successFunc);
}

function _doPut(formId, url, successUrl, successFunc) {
    return _doPost(formId, url, successUrl, "PUT", successFunc);
}
