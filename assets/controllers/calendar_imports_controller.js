import { Controller } from '@hotwired/stimulus';
import { enableDeletePopover } from './utils_controller.js';

export default class extends Controller {
    static targets = ['list', 'edit', 'create', 'editPanel'];

    connect() {
        enableDeletePopover();
    }

    showEdit(event) {
        event.preventDefault();
        const importId = event.currentTarget.dataset.importId;
        if (!importId) {
            return;
        }
        this.hideAllEditPanels();
        const panel = this.editPanelTargets.find((item) => item.dataset.importId === importId);
        if (panel) {
            panel.classList.remove('d-none');
        }
        this.listTarget.classList.add('d-none');
        if (this.hasCreateTarget) {
            this.createTarget.classList.add('d-none');
        }
        this.editTarget.classList.remove('d-none');
    }

    showList(event) {
        if (event) {
            event.preventDefault();
        }
        this.editTarget.classList.add('d-none');
        if (this.hasCreateTarget) {
            this.createTarget.classList.add('d-none');
        }
        this.listTarget.classList.remove('d-none');
    }

    toggleCreate(event) {
        event.preventDefault();
        if (!this.hasCreateTarget) {
            return;
        }
        if (this.createTarget.classList.contains('d-none')) {
            this.createTarget.classList.remove('d-none');
        } else {
            this.createTarget.classList.add('d-none');
        }
    }

    hideAllEditPanels() {
        this.editPanelTargets.forEach((panel) => panel.classList.add('d-none'));
    }
}
