import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

export default class extends Controller {
    static values = {
        priceId: String,
    };

    connect() {
        this.rowCounter = 0;
        this.applyRemainderStates();
        this.updateSumPreview();
    }

    togglePackageAction(event) {
        const priceId = event.currentTarget.dataset.priceId || this.priceIdValue;
        const body = this.element.querySelector(`#package-body-${priceId}`);
        if (!body) return;
        const enabled = event.currentTarget.checked;
        body.classList.toggle('d-none', !enabled);
        if (enabled && this.getRows(priceId).length === 0) {
            this.addRow(priceId);
            this.addRow(priceId);
        }
        this.updateSumPreview();
    }

    addRowAction(event) {
        event.preventDefault();
        const priceId = event.currentTarget.dataset.priceId || this.priceIdValue;
        this.addRow(priceId);
        this.updateSumPreview();
    }

    removeRowAction(event) {
        event.preventDefault();
        const row = event.currentTarget.closest('.package-row');
        if (row) {
            row.remove();
            this.updateSumPreview();
        }
    }

    updateSumPreviewAction() {
        this.updateSumPreview();
    }

    toggleRemainderAction() {
        this.applyRemainderStates();
        this.updateSumPreview();
    }

    applyRemainderStates() {
        const priceId = this.priceIdValue;
        this.getRows(priceId).forEach((row) => {
            const remRadio = row.querySelector('input[name^="component-remainder-"]');
            const typeSel = row.querySelector('select[name^="component-type-"]');
            const valInput = row.querySelector('input[name^="component-value-"]');
            const isRemainder = !!(remRadio && remRadio.checked);
            if (typeSel) typeSel.disabled = isRemainder;
            if (valInput) valInput.disabled = isRemainder;
        });
    }

    addRow(priceId) {
        const template = document.getElementById(`package-row-template-${priceId}`);
        const tbody = document.getElementById(`package-rows-${priceId}`);
        if (!template || !tbody) return;
        const key = `n${Date.now()}${this.rowCounter++}`;
        const html = template.innerHTML.replace(/__KEY__/g, key);
        const wrapper = document.createElement('tbody');
        wrapper.innerHTML = html.trim();
        const row = wrapper.querySelector('tr');
        if (row) {
            tbody.appendChild(row);
        }
    }

    getRows(priceId) {
        const tbody = document.getElementById(`package-rows-${priceId}`);
        return tbody ? tbody.querySelectorAll('.package-row') : [];
    }

    updateSumPreview() {
        const priceId = this.priceIdValue;
        const priceInput = document.querySelector(`input[name="price-${priceId}"]`);
        if (!priceInput) return;
        const total = parseFloat((priceInput.value || '0').replace(',', '.')) || 0;

        let percentSum = 0;
        let amountSum = 0;
        let hasRemainder = false;

        this.getRows(priceId).forEach((row) => {
            const typeSel = row.querySelector('select[name^="component-type-"]');
            const valInput = row.querySelector('input[name^="component-value-"]');
            const remRadio = row.querySelector('input[name^="component-remainder-"]');
            if (!typeSel || !valInput) return;
            if (remRadio && remRadio.checked) {
                hasRemainder = true;
                return;
            }
            const val = parseFloat((valInput.value || '0').replace(',', '.')) || 0;
            if (typeSel.value === 'percent') {
                percentSum += val;
            } else {
                amountSum += val;
            }
        });

        const preview = document.getElementById(`package-sum-preview-${priceId}`);
        if (!preview) return;
        const span = preview.querySelector('[data-sum-preview]');
        if (!span) return;

        const covered = total * (percentSum / 100) + amountSum;
        const fmt = (n) => n.toFixed(2).replace('.', ',');
        if (hasRemainder) {
            const remainder = total - covered;
            span.textContent = `${fmt(covered)} € + ${fmt(Math.max(0, remainder))} € (Rest) = ${fmt(Math.max(covered, total))} €`;
            preview.classList.remove('bg-warning-subtle', 'bg-success-subtle');
            preview.classList.add(Math.abs(remainder) < 0.01 || remainder > 0 ? 'bg-success-subtle' : 'bg-warning-subtle');
        } else {
            span.textContent = `${fmt(covered)} € / ${fmt(total)} €`;
            preview.classList.remove('bg-warning-subtle', 'bg-success-subtle');
            preview.classList.add(Math.abs(covered - total) < 0.01 ? 'bg-success-subtle' : 'bg-warning-subtle');
        }
    }
}
