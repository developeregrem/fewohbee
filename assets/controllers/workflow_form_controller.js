import { Controller } from '@hotwired/stimulus';
import { request as httpRequest } from '../js/http.js';

/**
 * Manages the dynamic workflow create/edit form.
 *
 * When the trigger type changes, fetches compatible conditions and actions
 * from the server and repopulates the dropdowns. Supports multiple conditions
 * (AND logic) via repeatable condition rows.
 */
export default class extends Controller {
    static targets = [
        'triggerType', 'triggerConfig', 'triggerConfigJson',
        'conditionsContainer', 'conditionsJson',
        'actionType', 'actionConfig', 'actionConfigJson',
        'previewCard', 'previewResults', 'previewHead', 'previewBody',
        'logContainer',
    ];
    static values = {
        compatibleUrl: String,
        previewUrl: String,
        logUrl: String,
        translations: Object,
    };

    connect() {
        this._conditionCounter = 0;

        // If editing an existing workflow, load compatible options for current trigger
        const currentTrigger = this.triggerTypeTarget.value;
        if (currentTrigger) {
            this._loadCompatibleOptions(currentTrigger, true);
            this._updatePreviewVisibility();
        }

        // Load execution log if the container is present
        if (this.hasLogContainerTarget && this.logUrlValue) {
            this._loadLogs(this.logUrlValue);
            this.logContainerTarget.addEventListener('click', (event) => {
                const link = event.target.closest('a[data-page]');
                if (!link) return;
                event.preventDefault();
                const page = link.dataset.page;
                const url = new URL(this.logUrlValue, window.location.href);
                url.searchParams.set('page', page);
                this._loadLogs(url.toString());
            });
        }
    }

    onTriggerChange() {
        const triggerType = this.triggerTypeTarget.value;
        this.triggerConfigTarget.innerHTML = '';
        this.conditionsContainerTarget.innerHTML = '';
        this._clearSection(this.actionTypeTarget, this.actionConfigTarget);
        this._hidePreview();
        this._updatePreviewVisibility();

        if (triggerType) {
            this._loadCompatibleOptions(triggerType, false);
        }
    }

    // --- Condition management ---

    addCondition() {
        if (!this._currentConditions || this._currentConditions.length === 0) return;
        this._addConditionRow();
    }

    removeCondition(event) {
        const row = event.currentTarget.closest('[data-condition-row]');
        if (row) row.remove();
    }

    onConditionTypeChange(event) {
        const row = event.currentTarget.closest('[data-condition-row]');
        const configContainer = row.querySelector('[data-condition-config]');
        configContainer.innerHTML = '';
        const conditionType = event.currentTarget.value;
        if (conditionType && this._currentConditions) {
            const cond = this._currentConditions.find(c => c.type === conditionType);
            if (cond && cond.configSchema && cond.configSchema.length > 0) {
                this._renderConfigFields(configContainer, cond.configSchema, `condition-${row.dataset.conditionIndex}`);
            }
        }
    }

    runPreview() {
        const triggerType = this.triggerTypeTarget.value;
        if (!triggerType) return;

        this.collectConfigJson();

        const data = {
            triggerType: triggerType,
            triggerConfig: this.triggerConfigJsonTarget.value || '{}',
            conditions: this.conditionsJsonTarget.value || '[]',
        };

        this.previewResultsTarget.classList.remove('d-none');
        this.previewBodyTarget.innerHTML = `<tr><td colspan="10" class="text-center py-3 text-body-secondary"><i class="fas fa-spinner fa-spin me-1"></i>${this.translationsValue.preview_loading || 'Loading...'}</td></tr>`;

        httpRequest({
            url: this.previewUrlValue,
            method: 'POST',
            data: data,
            onSuccess: (text) => {
                const result = JSON.parse(text);
                this._renderPreview(result);
            },
            onError: (msg) => {
                this.previewBodyTarget.innerHTML = `<tr><td colspan="10" class="text-center py-3 text-danger">${msg}</td></tr>`;
            },
        });
    }

    // Called before form submit to collect config JSON (also used as Stimulus action)
    collectConfigJson() {
        this.triggerConfigJsonTarget.value = this._gatherConfig(this.triggerConfigTarget);
        this.actionConfigJsonTarget.value = this._gatherConfig(this.actionConfigTarget);
        this.conditionsJsonTarget.value = this._gatherConditions();
    }

    _gatherConfig(container) {
        const inputs = container.querySelectorAll('[data-config-key]');
        if (inputs.length === 0) return '{}';
        const config = {};
        inputs.forEach(input => {
            const key = input.dataset.configKey;
            const val = input.type === 'number' ? Number(input.value) : input.value;
            config[key] = val;
        });
        return JSON.stringify(config);
    }

    _gatherConditions() {
        const rows = this.conditionsContainerTarget.querySelectorAll('[data-condition-row]');
        const conditions = [];
        rows.forEach(row => {
            const select = row.querySelector('[data-condition-type-select]');
            if (!select || !select.value) return;
            const configContainer = row.querySelector('[data-condition-config]');
            const config = {};
            if (configContainer) {
                configContainer.querySelectorAll('[data-config-key]').forEach(input => {
                    const key = input.dataset.configKey;
                    config[key] = input.type === 'number' ? Number(input.value) : input.value;
                });
            }
            conditions.push({ type: select.value, config });
        });
        return JSON.stringify(conditions);
    }

    _loadCompatibleOptions(triggerType, isRestore) {
        httpRequest({
            url: this.compatibleUrlValue,
            method: 'POST',
            data: { triggerType },
            onSuccess: (text) => {
                const result = JSON.parse(text);
                this._currentConditions = result.conditions;
                this._populateActions(result.actions, isRestore);

                if (result.triggerConfigSchema && result.triggerConfigSchema.length > 0) {
                    this._renderConfigFields(this.triggerConfigTarget, result.triggerConfigSchema, 'trigger');
                    if (isRestore) {
                        this._restoreConfig(this.triggerConfigTarget, this.triggerConfigJsonTarget.value);
                    }
                }

                // Restore conditions if editing
                if (isRestore) {
                    this._restoreConditions();
                }
            },
        });
    }

    _restoreConditions() {
        const savedConditions = (() => {
            try { return JSON.parse(this.conditionsJsonTarget.value); }
            catch { return []; }
        })();

        if (!Array.isArray(savedConditions) || savedConditions.length === 0) return;

        savedConditions.forEach(saved => {
            const row = this._addConditionRow(saved.type);
            if (row && saved.config) {
                const configContainer = row.querySelector('[data-condition-config]');
                if (configContainer) {
                    this._restoreConfig(configContainer, JSON.stringify(saved.config));
                }
            }
        });
    }

    _addConditionRow(preselectedType) {
        if (!this._currentConditions) return null;

        const index = this._conditionCounter++;
        const row = document.createElement('div');
        row.dataset.conditionRow = '';
        row.dataset.conditionIndex = index;
        row.className = 'mb-3 border rounded p-3 position-relative';

        const removeLabel = this.translationsValue.remove_condition || 'Remove';

        // Build condition type select
        const options = this._currentConditions
            .filter(c => c.type) // skip the empty "no condition" entry
            .map(c => `<option value="${c.type}"${c.type === preselectedType ? ' selected' : ''}>${c.label}</option>`)
            .join('');

        row.innerHTML = `
            <div class="d-flex align-items-center justify-content-between mb-2">
                <select class="form-select form-select-sm" data-condition-type-select
                        data-action="change->workflow-form#onConditionTypeChange">
                    <option value="">--</option>
                    ${options}
                </select>
                <button type="button" class="btn btn-sm btn-outline-danger ms-2 flex-shrink-0"
                        data-action="click->workflow-form#removeCondition">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div data-condition-config></div>
        `;

        this.conditionsContainerTarget.appendChild(row);

        // If preselected, render config fields
        if (preselectedType) {
            const cond = this._currentConditions.find(c => c.type === preselectedType);
            if (cond && cond.configSchema && cond.configSchema.length > 0) {
                const configContainer = row.querySelector('[data-condition-config]');
                this._renderConfigFields(configContainer, cond.configSchema, `condition-${index}`);
            }
        }

        return row;
    }

    onActionChange() {
        this.actionConfigTarget.innerHTML = '';
        const actionType = this.actionTypeTarget.value;
        if (actionType && this._currentActions) {
            const action = this._currentActions.find(a => a.type === actionType);
            if (action && action.configSchema && action.configSchema.length > 0) {
                this._renderConfigFields(this.actionConfigTarget, action.configSchema, 'action');
            }
        }
    }

    _populateActions(actions, isRestore) {
        const select = this.actionTypeTarget;
        const savedValue = this.actionTypeTarget.dataset.restoreValue || '';
        this._currentActions = actions;

        select.innerHTML = `<option value="">-- --</option>`;
        (actions || []).forEach(a => {
            const opt = document.createElement('option');
            opt.value = a.type;
            opt.textContent = a.label;
            if (isRestore && a.type === savedValue) opt.selected = true;
            select.appendChild(opt);
        });

        // Restore action config if editing
        if (isRestore && savedValue) {
            const action = actions.find(a => a.type === savedValue);
            if (action && action.configSchema && action.configSchema.length > 0) {
                this._renderConfigFields(this.actionConfigTarget, action.configSchema, 'action');
                this._restoreConfig(this.actionConfigTarget, this.actionConfigJsonTarget.value);
            }
        }
    }

    _renderConfigFields(container, schema, prefix) {
        container.innerHTML = '';
        schema.forEach(field => {
            const div = document.createElement('div');
            div.className = 'mb-3';
            const id = `wf-${prefix}-${field.key}`;

            // Store showIf metadata on the wrapper div for later evaluation
            if (field.showIf) {
                div.dataset.showIfKey = field.showIf.key;
                div.dataset.showIfValue = field.showIf.value;
                div.classList.add('d-none'); // hidden by default; evaluated after render
            }

            if (field.type === 'number') {
                div.innerHTML = `
                    <label for="${id}" class="form-label">${field.label || field.key}</label>
                    <input type="number" class="form-control" id="${id}"
                           data-config-key="${field.key}"
                           value="${field.default ?? ''}"
                           ${field.min !== undefined ? `min="${field.min}"` : ''}
                           ${field.max !== undefined ? `max="${field.max}"` : ''}>
                    ${field.help ? `<div class="form-text">${field.help}</div>` : ''}`;
            } else if (field.type === 'select' && field.options) {
                let options = field.options.map(o =>
                    `<option value="${o.value}"${o.value === (field.default ?? '') ? ' selected' : ''}>${o.label}</option>`
                ).join('');
                div.innerHTML = `
                    <label for="${id}" class="form-label">${field.label || field.key}</label>
                    <select class="form-select" id="${id}" data-config-key="${field.key}">${options}</select>
                    ${field.help ? `<div class="form-text">${field.help}</div>` : ''}`;
            } else if (field.type === 'email') {
                div.innerHTML = `
                    <label for="${id}" class="form-label">${field.label || field.key}</label>
                    <input type="email" class="form-control" id="${id}"
                           data-config-key="${field.key}"
                           value="${field.default ?? ''}">
                    ${field.help ? `<div class="form-text">${field.help}</div>` : ''}`;
            } else {
                div.innerHTML = `
                    <label for="${id}" class="form-label">${field.label || field.key}</label>
                    <input type="text" class="form-control" id="${id}"
                           data-config-key="${field.key}"
                           value="${field.default ?? ''}">
                    ${field.help ? `<div class="form-text">${field.help}</div>` : ''}`;
            }
            container.appendChild(div);
        });

        // Evaluate initial showIf visibility and bind change listeners
        this._updateShowIf(container);
        container.addEventListener('change', () => this._updateShowIf(container));
    }

    /** Show/hide fields with data-show-if-* based on current sibling select values. */
    _updateShowIf(container) {
        const conditionalFields = container.querySelectorAll('[data-show-if-key]');
        conditionalFields.forEach(div => {
            const key = div.dataset.showIfKey;
            const expectedValue = div.dataset.showIfValue;
            const control = container.querySelector(`[data-config-key="${key}"]`);
            const currentValue = control ? control.value : '';
            div.classList.toggle('d-none', currentValue !== expectedValue);
        });
    }

    _restoreConfig(container, jsonStr) {
        if (!jsonStr) return;
        try {
            const config = JSON.parse(jsonStr);
            Object.entries(config).forEach(([key, value]) => {
                const input = container.querySelector(`[data-config-key="${key}"]`);
                if (input) input.value = value;
            });
            // Re-evaluate showIf after values are restored
            this._updateShowIf(container);
        } catch (e) { /* ignore */ }
    }

    _clearSection(select, configContainer) {
        select.innerHTML = '<option value="">--</option>';
        configContainer.innerHTML = '';
    }

    _hidePreview() {
        this.previewResultsTarget.classList.add('d-none');
        this.previewBodyTarget.innerHTML = '';
        this.previewHeadTarget.innerHTML = '';
    }

    _updatePreviewVisibility() {
        const selectedOption = this.triggerTypeTarget.selectedOptions[0];
        const isEventDriven = selectedOption && selectedOption.dataset.eventDriven === '1';
        this.previewCardTarget.classList.toggle('d-none', isEventDriven);
    }

    _loadLogs(url) {
        fetch(url)
            .then(r => r.text())
            .then(html => { this.logContainerTarget.innerHTML = html; })
            .catch(() => { this.logContainerTarget.innerHTML = ''; });
    }

    _renderPreview(result) {
        const entities = result.entities || [];
        if (entities.length === 0) {
            this.previewBodyTarget.innerHTML = `<tr><td colspan="10" class="text-center py-3 text-body-secondary">${this.translationsValue.preview_empty || 'No entities found'}</td></tr>`;
            this.previewHeadTarget.innerHTML = '';
            return;
        }

        // Build columns from first entity's keys
        const columns = Object.keys(entities[0]);
        this.previewHeadTarget.innerHTML = '<tr>' + columns.map(c => `<th class="small">${c}</th>`).join('') + '</tr>';

        this.previewBodyTarget.innerHTML = entities.map(entity =>
            '<tr>' + columns.map(col => `<td class="small">${entity[col] ?? ''}</td>`).join('') + '</tr>'
        ).join('');

        // Show count (singular vs plural)
        const countTpl = entities.length === 1
            ? (this.translationsValue.preview_count_one || '%count% record found')
            : (this.translationsValue.preview_count || '%count% records found');
        const countText = countTpl.replace('%count%', entities.length);
        const countRow = `<tr><td colspan="${columns.length}" class="text-body-secondary small text-end py-1">${countText}</td></tr>`;
        this.previewBodyTarget.innerHTML += countRow;
    }
}
