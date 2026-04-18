import { Controller } from '@hotwired/stimulus';
import {
    browserSupportsWebAuthn,
    browserSupportsWebAuthnAutofill,
    startRegistration,
    startAuthentication,
    WebAuthnAbortService,
} from '@simplewebauthn/browser';

export default class extends Controller {
    static values = {
        optionsUrl: String,
        resultUrl: String,
        successRedirectUri: String,
        conditionalUi: { type: Boolean, default: false },
    };

    static targets = ['username', 'message'];

    async connect() {
        if (!this.conditionalUiValue) {
            return;
        }

        const supportsAutofill = await browserSupportsWebAuthnAutofill();
        if (supportsAutofill) {
            await this._authenticate({ useBrowserAutofill: true });
        }
    }

    disconnect() {
        WebAuthnAbortService.cancelCeremony();
    }

    async register(event) {
        event.preventDefault();

        if (!browserSupportsWebAuthn()) {
            this._dispatchEvent('webauthn:unsupported', {});
            return;
        }

        try {
            const options = await this._fetchOptions();
            if (!options) return;

            const credential = await startRegistration({ optionsJSON: options });

            const response = await fetch(this.resultUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(credential),
            });

            if (!response.ok) {
                this._dispatchEvent('webauthn:registration:verify:error', { response });
                return;
            }

            if (this.hasSuccessRedirectUriValue) {
                window.location.replace(this.successRedirectUriValue);
            }
        } catch (error) {
            this._dispatchEvent('webauthn:registration:error', {
                error,
                code: error.code,
                name: error.name,
            });
        }
    }

    async authenticate(event) {
        event.preventDefault();

        if (!browserSupportsWebAuthn()) {
            this._dispatchEvent('webauthn:unsupported', {});
            return;
        }

        await this._authenticate({});
    }

    async _authenticate(startOptions) {
        try {
            const body = {};
            if (this.hasUsernameTarget && this.usernameTarget.value) {
                body.username = this.usernameTarget.value;
            }

            const options = await this._fetchOptions(body);
            if (!options) return;

            const credential = await startAuthentication({
                optionsJSON: options,
                ...startOptions,
            });

            const response = await fetch(this.resultUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(credential),
            });

            if (!response.ok) {
                this._dispatchEvent('webauthn:authentication:error', { response });
                return;
            }

            if (this.hasSuccessRedirectUriValue) {
                window.location.replace(this.successRedirectUriValue);
            }
        } catch (error) {
            this._dispatchEvent('webauthn:authentication:error', { error });
        }
    }

    async _fetchOptions(body = {}) {
        try {
            const response = await fetch(this.optionsUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(body),
            });

            if (!response.ok) return false;

            return await response.json();
        } catch (error) {
            return false;
        }
    }

    _dispatchEvent(name, payload) {
        this.element.dispatchEvent(new CustomEvent(name, { detail: payload, bubbles: true }));
    }
}
