import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['importNotice'];

    connect() {
        this.modalContent = document.getElementById('modal-content-ajax');
        const modalDialog = document.querySelector('#modalCenter .modal-dialog');

        if (modalDialog && !modalDialog.classList.contains('modal-lg')) {
            modalDialog.classList.add('modal-lg');
        }
        this.hideDismissedImportNotice();
    }

    disconnect() {
        if (this.modalObserver) {
            this.modalObserver.disconnect();
        }
    }

    dismissImportNotice() {
        this.storeImportNoticeDismissed();
        if (this.hasImportNoticeTarget) {
            this.importNoticeTarget.remove();
        }
    }

    hideDismissedImportNotice() {
        if (this.isImportNoticeDismissed() && this.hasImportNoticeTarget) {
            this.importNoticeTarget.remove();
        }
    }

    isImportNoticeDismissed() {
        try {
            return window.localStorage.getItem('templates.operations.import.dismissed') === '1';
        } catch (error) {
            return false;
        }
    }

    storeImportNoticeDismissed() {
        try {
            window.localStorage.setItem('templates.operations.import.dismissed', '1');
        } catch (error) {
            // Ignore storage issues.
        }
    }
}
