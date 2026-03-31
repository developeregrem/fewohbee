import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['message'];
    static values = {
        alreadyRegistered: String,
        registrationFailed: String,
        saveFailed: String,
        unauthorized: String,
        noRegistrationInProgress: String,
        invalidResponseType: String,
    };

    handleRegistrationError(event) {
        const { code, error } = event.detail ?? {};

        if (code === 'ERROR_AUTHENTICATOR_PREVIOUSLY_REGISTERED') {
            this.showMessage(this.alreadyRegisteredValue, 'warning');
            return;
        }

        const message = this.translateMessage(error?.message) || this.registrationFailedValue;
        this.showMessage(message, 'danger');
    }

    async handleVerifyError(event) {
        const response = event.detail?.response;
        let message = this.saveFailedValue;

        if (response) {
            try {
                const payload = await response.clone().json();
                message = this.translateMessage(payload.message || payload.error) || message;
            } catch (_error) {
                try {
                    const text = await response.clone().text();
                    if (text.trim() !== '') {
                        message = this.translateMessage(text) || message;
                    }
                } catch (_ignored) {
                    // Ignore unreadable response bodies and keep the generic message.
                }
            }
        }

        this.showMessage(message, 'danger');
    }

    showMessage(message, level) {
        if (!this.hasMessageTarget) {
            return;
        }

        this.messageTarget.textContent = message;
        this.messageTarget.classList.remove('d-none', 'alert-danger', 'alert-warning', 'alert-success');
        this.messageTarget.classList.add(`alert-${level}`);
    }

    translateMessage(message) {
        if (!message) {
            return null;
        }

        const normalized = message.trim();

        const translations = {
            Unauthorized: this.unauthorizedValue,
            'No registration in progress': this.noRegistrationInProgressValue,
            'Invalid response type': this.invalidResponseTypeValue,
            'Invalid response': this.invalidResponseTypeValue,
            'The credentials already exists': this.alreadyRegisteredValue,
            'The authenticator was previously registered': this.alreadyRegisteredValue,
        };

        return translations[normalized] || normalized;
    }
}
