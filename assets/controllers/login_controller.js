// assets/controllers/login_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = [];

  connect() {
    // optional: beim Start alte Fehler ausblenden
    this.hideError();
  }

  onFailure(event) {
    // Payload vom UX-WebAuthn-Controller (enthält Status und evtl. message)
    const { detail } = event || {};
    const status = detail?.status ?? detail?.response?.status;
    const serverMsg =
      detail?.message ||
      detail?.response?.message ||
      detail?.error?.message;

    let msg = serverMsg || (status === 401
      ? 'Anmeldung fehlgeschlagen. Bitte prüfe Benutzername und Sicherheitsschlüssel.'
      : `Fehler beim Anmelden${status ? ` (HTTP ${status})` : ''}.`);

    this.showError(msg);
  }

  onSuccess(_event) {
    // Bei Erfolg ggf. Fehlermeldung ausblenden (Redirect erfolgt i. d. R. durch requestSuccessRedirectUri)
    this.hideError();
  }

  showError(message) {
    const el = this.element.querySelector('#login-error');
    if (!el) return;
    el.textContent = message;
    el.classList.remove('d-none');
    el.removeAttribute('hidden');
  }

  hideError() {
    const el = this.element.querySelector('#login-error');
    if (!el) return;
    el.textContent = '';
    el.classList.add('d-none');
    el.setAttribute('hidden', 'hidden');
  }
}
