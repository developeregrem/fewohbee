import { Controller } from '@hotwired/stimulus';
import { createSimpleHtmlEditor } from '../js/simple-html-editor.js';

/* stimulusFetch: 'lazy' */

export default class extends Controller {
    static targets = ['subsidiariesWrap', 'roomsWrap', 'subsidiariesCount', 'roomsCount'];

    connect() {
        this.htmlEditors = [];
        this.refresh();
        this.initHtmlEditors();
        this.element.addEventListener('change', this.onChange);
    }

    disconnect() {
        this.element.removeEventListener('change', this.onChange);
        this.destroyHtmlEditors();
    }

    onChange = () => {
        this.refresh();
    };

    refresh() {
        this.toggleVisibility();
        this.updateCounts();
    }

    /**
     * Replace configured settings textareas with the shared lightweight HTML editor.
     */
    initHtmlEditors() {
        this.element.querySelectorAll('textarea[data-online-booking-settings-editor]').forEach((textarea) => {
            if (textarea.dataset.editorInitialized === 'true') {
                return;
            }

            textarea.dataset.editorInitialized = 'true';
            textarea.style.display = 'none';

            const editorWrapper = document.createElement('div');
            editorWrapper.className = 'mt-2 mb-3';

            const editorContainer = document.createElement('div');
            editorContainer.className = 'simple-html-editor-content border rounded p-2';
            editorContainer.style.minHeight = '220px';
            editorContainer.style.cursor = 'text';

            textarea.insertAdjacentElement('afterend', editorWrapper);
            editorWrapper.appendChild(editorContainer);

            const editorHandle = createSimpleHtmlEditor(editorContainer, textarea.value, {
                onUpdate: (html) => {
                    textarea.value = html;
                },
            });

            this.htmlEditors.push({ textarea, editorWrapper, editorContainer, editorHandle });
        });
    }

    /**
     * Tear down editor instances when the page fragment is replaced.
     */
    destroyHtmlEditors() {
        this.htmlEditors.forEach(({ textarea, editorWrapper, editorHandle }) => {
            editorHandle?.destroy?.();
            editorWrapper?.remove?.();
            if (textarea) {
                textarea.style.display = '';
                delete textarea.dataset.editorInitialized;
            }
        });

        this.htmlEditors = [];
    }

    toggleVisibility() {
        const subsidiariesSelected = this.element.querySelector('input[name$="[subsidiariesMode]"]:checked')?.value === 'SELECTED';
        const roomsSelected = this.element.querySelector('input[name$="[roomsMode]"]:checked')?.value === 'SELECTED';

        if (this.hasSubsidiariesWrapTarget) {
            this.subsidiariesWrapTarget.classList.toggle('d-none', !subsidiariesSelected);
        }
        if (this.hasRoomsWrapTarget) {
            this.roomsWrapTarget.classList.toggle('d-none', !roomsSelected);
        }
    }

    updateCounts() {
        if (this.hasSubsidiariesCountTarget) {
            const total = this.countCheckboxes('selectedSubsidiaryIds');
            const selected = this.countCheckedCheckboxes('selectedSubsidiaryIds');
            this.subsidiariesCountTarget.textContent = `${selected} / ${total}`;
        }

        if (this.hasRoomsCountTarget) {
            const total = this.countCheckboxes('selectedRoomIds');
            const selected = this.countCheckedCheckboxes('selectedRoomIds');
            this.roomsCountTarget.textContent = `${selected} / ${total}`;
        }
    }

    countCheckboxes(fieldSuffix) {
        return this.element.querySelectorAll(`input[name*="[${fieldSuffix}]"]`).length;
    }

    countCheckedCheckboxes(fieldSuffix) {
        return this.element.querySelectorAll(`input[name*="[${fieldSuffix}]"]:checked`).length;
    }
}
