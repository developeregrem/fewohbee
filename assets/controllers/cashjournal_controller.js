import { Controller } from '@hotwired/stimulus';
import { 
    request as httpRequest, 
    serializeForm as httpSerializeForm 
} from './http_controller.js';
import { 
    setLocalStorageItemIfNotExists, 
    getLocalStorageItem, 
    updatePDFExportLinks, 
    setModalTitle,
    enableDeletePopover
} from './utils_controller.js';

export default class extends Controller {
    static targets = ['table', 'year', 'template', 'defaultBox'];
    static values = {
        tableUrl: String,
        entryTableUrl: String,
    };

    connect() {
        const isPreview = document.documentElement.hasAttribute('data-turbo-preview');
         if(isPreview) {
             return;
         }
        this.modalContent = document.getElementById('modal-content-ajax');
        if (this.hasTemplateTarget) {
            const stored = getLocalStorageItem('cashjournal-template-id');
            if (stored !== null) {
                this.templateTarget.value = stored;
            } else if (this.templateTarget.value) {
                setLocalStorageItemIfNotExists('cashjournal-template-id', this.templateTarget.value);
            }
        }
        const modalDialog = document.querySelector('#modalCenter .modal-dialog');
        if (modalDialog) {
            modalDialog.classList.remove('modal-lg');
        }
        if (this.hasTableTarget && this.hasYearTarget && this.tableUrlValue) {
            this.loadJournalTable();
        }
    }

    // List handling
    loadJournalTable(page = 1) {
        const year = this.hasYearTarget ? this.yearTarget.value : '';
        const url = this.tableUrlValue;
        httpRequest({
            url,
            method: 'GET',
            data: { page, search: year },
            loader: false,
            target: this.tableTarget,
            onComplete: () => {
                const templateId = this.hasTemplateTarget ? this.templateTarget.value : getLocalStorageItem('cashjournal-template-id');
                if (templateId) {
                    updatePDFExportLinks(templateId);
                }
            },
        });
    }

    loadEntryTable(page = 1) {
        if (!this.entryTableUrlValue || !this.hasTableTarget) {
            return;
        }
        httpRequest({
            url: this.entryTableUrlValue,
            method: 'GET',
            data: { page },
            target: this.tableTarget,
        });
    }

    // Actions
    yearChangeAction() {
        this.loadJournalTable(1);
    }

    templateChangeAction() {
        if (!this.hasTemplateTarget) {
            return;
        }
        setLocalStorageItemIfNotExists('cashjournal-template-id', this.templateTarget.value, true);
        updatePDFExportLinks(this.templateTarget.value);
    }

    paginateAction(event) {
        event.preventDefault();
        const page = event.currentTarget.dataset.page || 1;
        this.loadJournalTable(page);
    }

    paginateEntriesAction(event) {
        event.preventDefault();
        const page = event.currentTarget.dataset.page || 1;
        this.loadEntryTable(page);
    }

    openModalAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const title = event.currentTarget.dataset.title || '';
        if (!url) return;
        setModalTitle(title);
        httpRequest({
            url,
            method: 'GET',
            target: this.modalContent,
            onComplete: () => {
                enableDeletePopover();
            }
        });
    }

    submitFormAction(event) {
        event.preventDefault();
        const form = event.target.closest('form');
        if (!form) return;
        const url = form.dataset.url || form.action;
        const successUrl = form.dataset.successUrl;
        httpRequest({
            url,
            method: form.method || 'POST',
            data: httpSerializeForm(form),
            target: this.modalContent,
            onComplete: () => {
                enableDeletePopover();
            },
            onSuccess: (data) => {
                if (data && data.length > 0 && form.querySelector('#flash-message-overlay')) {
                    const flash = form.querySelector('#flash-message-overlay');
                    flash.innerHTML = data;
                    return;
                }
                if (successUrl) {
                    window.location.href = successUrl;
                } else {
                    window.location.reload();
                }
            },
        });
    }

    editStatusAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        if (!url) return;
        httpRequest({
            url,
            method: 'POST',
            data: { status: event.currentTarget.dataset.status },
            onSuccess: () => this.loadJournalTable(),
        });
    }
}
