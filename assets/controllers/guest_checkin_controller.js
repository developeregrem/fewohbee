import { Controller } from '@hotwired/stimulus';
import { request } from '../js/http.js';
import { enableDeletePopover } from '../js/utils.js';

/**
 * "Online check-in" tab of the reservation dialog.
 *
 * The link is fetched on demand instead of being rendered into the page, so it only reaches
 * users allowed to hand it out. Taking over or discarding a submission reloads the dialog with
 * the server's answer.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['linkBox', 'url', 'qrBox', 'qr', 'qrDownload', 'error', 'regenerateBox'];
    static values = {
        linkUrl: String,
        regenerateUrl: String,
        token: String,
    };

    connect() {
        enableDeletePopover({ root: this.element });
    }

    showLink(event) {
        event.preventDefault();
        this.fetchLink(this.linkUrlValue, () => this.linkBoxTarget.classList.remove('d-none'));
    }

    showQr(event) {
        event.preventDefault();
        this.fetchLink(this.linkUrlValue, () => this.qrBoxTarget.classList.remove('d-none'));
    }

    regenerate(event) {
        event.preventDefault();
        this.fetchLink(this.regenerateUrlValue, () => {
            this.linkBoxTarget.classList.remove('d-none');
            if (this.hasRegenerateBoxTarget) {
                window.bootstrap?.Collapse.getOrCreateInstance(this.regenerateBoxTarget).hide();
            }
        });
    }

    apply(event) {
        event.preventDefault();
        const form = event.target.closest('form');
        request({
            url: form.action,
            method: 'POST',
            data: new FormData(form),
            target: document.getElementById('modal-content-ajax'),
        });
    }

    fetchLink(url, onShown) {
        this.errorTarget.textContent = '';
        request({
            url,
            method: 'POST',
            data: { _token: this.tokenValue },
            loader: false,
            onSuccess: (text) => {
                const data = JSON.parse(text);
                this.urlTarget.value = data.url;
                this.qrTarget.src = data.qr;
                this.qrDownloadTarget.href = data.qr;
                onShown();
            },
            onError: (message) => {
                let error = message;
                try {
                    error = JSON.parse(message).error || message;
                } catch (e) {
                    // not JSON, show as is
                }
                this.errorTarget.textContent = error;
            },
        });
    }
}
