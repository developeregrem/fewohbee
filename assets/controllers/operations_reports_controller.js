import { Controller } from '@hotwired/stimulus';
import { request as httpRequest, serializeForm as httpSerializeForm } from './http_controller.js';

export default class extends Controller {
    static targets = ['form', 'download', 'preview', 'spinner'];

    connect() {
        this.updateLinks();
        this.loadPreview();
    }

    preventSubmit(event) {
        if (event) {
            event.preventDefault();
        }
    }

    updateLinks() {
        if (!this.hasFormTarget) {
            return;
        }
        const query = httpSerializeForm(this.formTarget);
        if (this.hasDownloadTarget) {
            const baseUrl = this.downloadTarget.dataset.baseUrl || this.downloadTarget.href;
            this.downloadTarget.href = this.appendQuery(baseUrl, query);
        }
        if (this.hasPreviewTarget) {
            const basePreview = this.previewTarget.dataset.basePreviewUrl || '';
            if (basePreview) {
                this.previewTarget.dataset.previewUrl = this.appendQuery(basePreview, query);
            }
        }
    }

    loadPreview(event) {
        if (event) {
            event.preventDefault();
        }
        this.updateLinks();
        if (!this.hasPreviewTarget) {
            return;
        }
        const url = this.previewTarget.dataset.previewUrl;
        if (!url) {
            return;
        }
        if (this.hasSpinnerTarget) {
            this.spinnerTarget.classList.add('fa-spin');
        }
        httpRequest({
            url,
            method: 'GET',
            target: this.previewTarget,
            loader: false,
            onComplete: () => {
                if (this.hasSpinnerTarget) {
                    this.spinnerTarget.classList.remove('fa-spin');
                }
            },
        });
    }

    appendQuery(baseUrl, query) {
        if (!query) {
            return baseUrl;
        }
        return baseUrl + (baseUrl.includes('?') ? '&' : '?') + query;
    }
}
