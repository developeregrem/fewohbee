import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        startUrl: String,
    };

    connect() {
        const hasNav = document.querySelector('body nav') !== null;
        if (hasNav && this.hasStartUrlValue && this.startUrlValue) {
            window.location.href = this.startUrlValue;
            return;
        }

        document.querySelectorAll('body div.d-none, body p.d-none').forEach((element) => {
            element.classList.remove('d-none');
        });
    }

}
