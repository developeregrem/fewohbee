/**
 * Shared HTTP helpers for Stimulus controllers and legacy scripts.
 */

export function serializeData(data) {
    if (!data) {
        return '';
    }
    if (typeof data === 'string') {
        return data;
    }
    if (data instanceof FormData) {
        return new URLSearchParams([...data.entries()]).toString();
    }
    if (typeof data === 'object') {
        return new URLSearchParams(Object.entries(data)).toString();
    }
    return '';
}

export function serializeForm(selectorOrNode) {
    const node = typeof selectorOrNode === 'string' ? document.querySelector(selectorOrNode) : selectorOrNode;
    if (!node) {
        return '';
    }
    if (node instanceof HTMLFormElement) {
        return serializeData(new FormData(node));
    }
    const params = new URLSearchParams();
    if (node.name) {
        params.append(node.name, node.value ?? '');
    }
    return params.toString();
}

export function serializeSelectors(selectors = []) {
    const params = new URLSearchParams();
    selectors.forEach((selector) => {
        document.querySelectorAll(selector).forEach((node) => {
            if (node.name) {
                params.append(node.name, node.value ?? '');
            }
        });
    });
    return params.toString();
}

export function request({ url, method = 'GET', data = null, target = null, loader = true, onSuccess = null, onError = null, onComplete = null }) {
    const serialized = serializeData(data);
    let finalUrl = url;
    const fetchOptions = { method: method.toUpperCase(), headers: {} };

    if (fetchOptions.method === 'GET') {
        if (serialized) {
            finalUrl += (finalUrl.includes('?') ? '&' : '?') + serialized;
        }
    } else {
        fetchOptions.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
        fetchOptions.body = serialized;
    }

    const targetEl = typeof target === 'string' ? document.querySelector(target) : target;
    if (targetEl && loader) {
        targetEl.innerHTML = window.modalLoader || '';
    }

    fetch(finalUrl, fetchOptions)
        .then(async (response) => {
            const text = await response.text();
            if (!response.ok) {
                const message = text || response.statusText || 'Unbekannter Fehler';
                throw new Error(`${response.status} ${message}`.trim());
            }
            if (onSuccess) {
                onSuccess(text);
            } else if (targetEl) {
                targetEl.innerHTML = text;
            }
        })
        .catch((err) => {
            const message = err && err.message ? err.message : 'Request fehlgeschlagen';
            if (onError) {
                onError(message);
            } else {
                alert(message);
            }
        })
        .finally(() => {
            if (onComplete) {
                onComplete();
            }
        });
}

export function getContentForModal(url, title = '', successFunc = () => {}) {
    const modalTitle = document.querySelector('#modalCenter .modal-title');
    const modalBody = document.getElementById('modal-content-ajax');
    if (modalTitle) {
        modalTitle.textContent = title;
    }
    return request({
        url,
        method: 'GET',
        target: modalBody,
        onSuccess: (data) => {
            if (modalBody) {
                modalBody.innerHTML = data;
            }
            successFunc();
        },
    });
}

export function doPost(formId, url, successUrl = '', type = 'POST', successFunc = null) {
    const payload = serializeForm(formId);
    return request({
        url,
        method: type,
        data: payload,
        onSuccess: (data) => {
            if (successFunc) {
                successFunc(data);
                return;
            }
            const modal = document.getElementById('modal-content-ajax');
            const flash = document.getElementById('flash-message-overlay');
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
            } else if (successUrl.length > 0) {
                location.href = successUrl;
            } else {
                location.reload();
            }
        },
    });
}

export function doDelete(formId, url, successUrl = '', successFunc = null) {
    return doPost(formId, url, successUrl, 'DELETE', successFunc);
}

export function doPut(formId, url, successUrl = '', successFunc = null) {
    return doPost(formId, url, successUrl, 'PUT', successFunc);
}

// Expose globally for legacy scripts
if (!window.HttpHelper) {
    window.HttpHelper = {
        request,
        getContentForModal,
        doPost,
        doDelete,
        doPut,
        serializeForm,
        serializeSelectors,
        serializeData,
    };
}

// Default export to satisfy auto-registration tooling (even though this is a helper, not a Stimulus controller)
export default {
    request,
    getContentForModal,
    doPost,
    doDelete,
    doPut,
    serializeForm,
    serializeSelectors,
    serializeData,
};
