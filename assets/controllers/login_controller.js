import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        startUrl: String,
    };

    static targets = ['error'];

    connect() {
        const hasNav = document.querySelector('body nav') !== null;
        if (hasNav && this.hasStartUrlValue && this.startUrlValue) {
            window.location.href = this.startUrlValue;
            return;
        }

        document.querySelectorAll('body div.d-none, body p.d-none').forEach((element) => {
            //element.classList.remove('d-none');
        });

        this.webauthnAuthFailed = false;
        this.handleAssertionFailure = this.handleAssertionFailure.bind(this);

        this.wrapFetchForWebauthn();
        document.addEventListener('webauthn:assertion:failure', this.handleAssertionFailure, true);
    }

    disconnect() {
        document.removeEventListener('webauthn:assertion:failure', this.handleAssertionFailure, true);
        this.restoreFetch();
    }

    wrapFetchForWebauthn() {
        if (this.fetchWrapped) {
            return;
        }

        this.originalFetch = window.fetch.bind(window);
        this.wrappedFetch = async (...args) => {
            const response = await this.originalFetch(...args);
            try {
                if (response?.status === 401) {
                    this.webauthnAuthFailed = true;
                    this.showWebauthnError();
                }
            } catch (e) {
                // ignore wrapper errors
            }
            return response;
        };

        window.fetch = this.wrappedFetch;
        this.fetchWrapped = true;
    }

    restoreFetch() {
        if (!this.fetchWrapped) {
            return;
        }

        if (window.fetch === this.wrappedFetch) {
            window.fetch = this.originalFetch;
        }

        this.fetchWrapped = false;
    }

    handleAssertionFailure() {
        if (this.webauthnAuthFailed) {
            this.showWebauthnError();
        }
    }

    showWebauthnError() {
        if (this.hasErrorTarget) {
            this.errorTarget.classList.remove('d-none');
        }
    }

}
