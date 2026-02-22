import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['importNotice'];

    connect() {
        this.hideDismissedImportNotice();
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
