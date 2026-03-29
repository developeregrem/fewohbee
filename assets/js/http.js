/**
 * Shared HTTP helpers for Stimulus controllers and legacy scripts.
 */
import { generateCsrfHeaders, generateCsrfToken } from './csrf_protection.js';

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
        // Only trigger stateless CSRF rotation for fields managed by Symfony's csrf-protection controller.
        // Legacy forms using the custom CSRFProtectionService rely on a stable `_csrf_token` value.
        if (node.querySelector('input[data-controller="csrf-protection"]')) {
            generateCsrfToken(node);
        }
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

export function request({ url, method = 'GET', data = null, target = null, loader = true, onSuccess = null, onError = null, onComplete = null, csrfForm = null }) {
    const serialized = serializeData(data);
    let finalUrl = url;
    const fetchOptions = { method: method.toUpperCase(), headers: {} };

    // Mark as AJAX request for Symfony's isXmlHttpRequest()
    fetchOptions.headers['X-Requested-With'] = 'XMLHttpRequest';

    if (fetchOptions.method === 'GET') {
        if (serialized) {
            finalUrl += (finalUrl.includes('?') ? '&' : '?') + serialized;
        }
    } else {
        fetchOptions.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
        fetchOptions.body = serialized;

        const form = typeof csrfForm === 'string' ? document.querySelector(csrfForm) : csrfForm;
        if (form instanceof HTMLFormElement && form.querySelector('input[data-controller="csrf-protection"]')) {
            generateCsrfToken(form);
            const csrfHeaders = generateCsrfHeaders(form);
            Object.keys(csrfHeaders).forEach((key) => {
                fetchOptions.headers[key] = csrfHeaders[key];
            });
        }
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
            } else {
                location.reload();
            }
        })
        .catch((err) => {
            const message = err && err.message ? err.message : 'Request fehlgeschlagen';
            if (onError) {
                onError(message);
            } else if (targetEl) {
                targetEl.innerHTML = message;
            } else {
                console.error(message);
            }
        })
        .finally(() => {
            if (onComplete) {
                onComplete();
            }
        });
}

// Expose globally for legacy scripts
if (!window.HttpHelper) {
    window.HttpHelper = {
        request,
        serializeForm,
        serializeSelectors,
        serializeData,
    };
}
