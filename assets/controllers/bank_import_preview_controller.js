import { Controller } from '@hotwired/stimulus';
import { enableDeletePopover, enableTooltips, disposeTooltips } from '../js/utils.js';

/**
 * Inline editor for a bank-statement preview.
 *
 * Each line is represented by one row. The user can pick debit/credit
 * accounts, type a remark, toggle "ignore" or check a box for bulk actions.
 * Every change is persisted to the session via JSON endpoints; status badges
 * and filter counts update locally to keep the UI snappy.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'row',
        'rowCheckbox',
        'selectAll',
        'filterChip',
        'bulkBar',
        'bulkCount',
        'bulkAccountSelect',
        'sortHeader',
        'statusCell',
        'ignoreButton',
        'commitForm',
        'commitButton',
        'commitReadyCount',
        'commitPendingCount',
        'commitIgnoredCount',
        'commitDuplicateCount',
        // Split modal
        'splitModal',
        'splitTotal',
        'splitPurpose',
        'splitAssigned',
        'splitDelta',
        'splitRows',
        'splitRowTemplate',
        // Rule modal
        'ruleModal',
        'ruleName',
        'ruleCondCounterparty',
        'ruleCondCounterpartyValue',
        'ruleCondIban',
        'ruleCondIbanValue',
        'ruleCondDirection',
        'ruleCondDirectionValue',
        'ruleCondPurpose',
        'ruleCondPurposeValue',
        'ruleDebit',
        'ruleCredit',
        'ruleTaxRate',
        'ruleRemark',
        'ruleAssignSection',
        'ruleSplitSection',
        'ruleSplitRows',
        'ruleSplitRowTemplate',
        'ruleInvoiceExtractionMode',
        'ruleInvoiceExtractionMarkerGroup',
        'ruleInvoiceExtractionMarker',
        'ruleInvoiceExtractionRegexGroup',
        'ruleInvoiceExtractionRegex',
        'ruleInvoiceExtractionPreview',
        'rulePriority',
        'ruleScope',
        'linesData',
    ];
    static values = {
        lineUrlPrefix: String,
        bulkUrl: String,
        discardRedirectUrl: String,
        csrf: String,
        locale: String,
        currencySymbol: String,
        bulkSelectedLabel: String,
        statusPendingTitle: String,
        statusDuplicateTitle: String,
        statusIgnoredTitle: String,
        statusReadyTitle: String,
        updateFailedMessage: String,
        saveFailedMessage: String,
        errorPrefix: String,
        commitConfirmTemplate: String,
        splitRemainderLabel: String,
        splitTooMuchLabel: String,
        splitOpenLabel: String,
        ruleDefaultName: String,
        ruleConditionRequiredMessage: String,
        ruleNameRequiredMessage: String,
        ruleSplitFoundLabel: String,
        ruleSplitMissingLabel: String,
        ruleSplitRemainderLabel: String,
        ruleInvoiceFoundLabel: String,
        ruleInvoiceMissingLabel: String,
        ruleSplitMarkerRequiredMessage: String,
        ruleSplitRegexRequiredMessage: String,
        ruleSplitInvalidLabel: String,
    };

    connect() {
        enableDeletePopover({
            root: this.element,
            onSuccess: () => {
                window.location.href = this.discardRedirectUrlValue;
            },
        });
        enableTooltips(this.element);
        this.activeFilter = 'all';
        this.activeIdx = null;
        this.activeSortKey = null;
        this.activeSortDirection = 'asc';
        this._collator = new Intl.Collator(
            this.localeValue || document.documentElement.lang || undefined,
            { numeric: true, sensitivity: 'base' },
        );
        this._lines = this._loadLineSnapshot();
        this._refreshBulkBar();
    }

    _loadLineSnapshot() {
        if (!this.hasLinesDataTarget) return [];
        try {
            return JSON.parse(this.linesDataTarget.textContent || '[]');
        } catch {
            return [];
        }
    }

    _lineByIdx(idx) {
        const i = parseInt(idx, 10);
        return this._lines.find((line) => line.idx === i) ?? null;
    }

    disconnect() {
        disposeTooltips(this.element);
    }

    // ── Per-line edits ────────────────────────────────────────────────

    fieldChange(event) {
        const input = event.currentTarget;
        const row = input.closest('tr');
        const idx = row?.dataset.idx;
        const field = input.dataset.field;
        if (idx === undefined || !field) return;

        // Text inputs fire on both change + blur; debounce identical values.
        if ((field === 'remark' || field === 'invoiceNumber') && input.dataset.lastSent === input.value) return;
        if (field === 'remark' || field === 'invoiceNumber') input.dataset.lastSent = input.value;

        this._sendUpdate(row, idx, field, input.value);
    }

    toggleIgnore(event) {
        const row = event.currentTarget.closest('tr');
        const idx = row?.dataset.idx;
        if (idx === undefined) return;
        const currentlyIgnored = row.classList.contains('table-secondary')
            && !row.dataset.duplicate; // duplicates also have that class — guard
        // We use the data-status attr as authoritative state.
        const willIgnore = row.dataset.status !== 'ignored';
        this._sendUpdate(row, idx, 'isIgnored', willIgnore ? '1' : '0');
    }

    // ── Filtering ─────────────────────────────────────────────────────

    setFilter(event) {
        const filter = event.currentTarget.dataset.filter || 'all';
        this.activeFilter = filter;

        this.filterChipTargets.forEach((chip) => {
            chip.classList.toggle('active', chip.dataset.filter === filter);
        });

        this._applyFilter();
    }

    _applyFilter() {
        const f = this.activeFilter;
        this.rowTargets.forEach((row) => {
            const status = row.dataset.status;
            const hasRule = row.dataset.rule === '1';
            const hasInvoice = row.dataset.invoice === '1';
            let visible = true;

            switch (f) {
                case 'pending':   visible = status === 'pending'; break;
                case 'ready':     visible = status === 'ready'; break;
                case 'duplicate': visible = status === 'duplicate'; break;
                case 'ignored':   visible = status === 'ignored'; break;
                case 'rule':      visible = hasRule; break;
                case 'invoice':   visible = hasInvoice; break;
                case 'all':
                default:          visible = true;
            }

            row.hidden = !visible;
        });
    }

    // ── Sorting ───────────────────────────────────────────────────────

    sortTable(event) {
        const header = event.currentTarget.closest('th[data-sort-key]');
        const tbody = this.rowTargets[0]?.parentElement;
        if (!header || !tbody) return;

        const key = header.dataset.sortKey;
        const type = header.dataset.sortType || 'text';
        const direction = this.activeSortKey === key && this.activeSortDirection === 'asc' ? 'desc' : 'asc';
        const modifier = direction === 'asc' ? 1 : -1;

        const rows = [...this.rowTargets].sort((a, b) => {
            const valueA = this._sortValue(a, key, type);
            const valueB = this._sortValue(b, key, type);
            const compared = this._compareSortValues(valueA, valueB, type);
            if (compared !== 0) return compared * modifier;

            return this._originalRowIndex(a) - this._originalRowIndex(b);
        });

        rows.forEach((row) => tbody.appendChild(row));
        this.activeSortKey = key;
        this.activeSortDirection = direction;
        this._refreshSortHeaders(header, direction);
    }

    _sortValue(row, key, type) {
        const line = this._lineByIdx(row.dataset.idx);
        switch (key) {
            case 'status':
                return row.dataset.status || line?.status || '';
            case 'date':
                return line?.bookDate || '';
            case 'counterparty':
                return [line?.counterpartyName, line?.counterpartyIban].filter(Boolean).join(' ');
            case 'purpose':
                return line?.purpose || '';
            case 'amount':
                return parseFloat(line?.amount ?? '0') || 0;
            case 'invoice':
                return this._fieldSortText(row, 'invoiceNumber') || line?.matchedInvoiceNumber || '';
            case 'debit':
                return this._fieldSortText(row, 'debitAccountId') || this._cellSortText(row, 7);
            case 'credit':
                return this._fieldSortText(row, 'creditAccountId') || this._cellSortText(row, 8);
            case 'tax':
                return this._fieldSortText(row, 'taxRateId') || this._cellSortText(row, 9);
            case 'remark':
                return this._fieldSortText(row, 'remark');
            default:
                return type === 'number' ? 0 : '';
        }
    }

    _compareSortValues(valueA, valueB, type) {
        if (type === 'number') {
            return valueA - valueB;
        }

        if (type === 'date') {
            return String(valueA).localeCompare(String(valueB));
        }

        if (type === 'status') {
            const order = { pending: 0, ready: 1, duplicate: 2, ignored: 3 };
            return (order[valueA] ?? 99) - (order[valueB] ?? 99);
        }

        return this._collator.compare(String(valueA || '').trim(), String(valueB || '').trim());
    }

    _fieldSortText(row, field) {
        const fieldEl = row.querySelector(`[data-field="${field}"]`);
        if (!fieldEl) return '';

        if (fieldEl instanceof HTMLSelectElement) {
            return fieldEl.selectedOptions[0]?.textContent || '';
        }

        return fieldEl.value ?? fieldEl.textContent ?? '';
    }

    _cellSortText(row, index) {
        return row.cells[index]?.textContent || '';
    }

    _originalRowIndex(row) {
        const idx = parseInt(row.dataset.idx, 10);
        return Number.isFinite(idx) ? idx : 0;
    }

    _refreshSortHeaders(activeHeader, direction) {
        this.sortHeaderTargets.forEach((header) => {
            const isActive = header === activeHeader;
            header.setAttribute('aria-sort', isActive ? (direction === 'asc' ? 'ascending' : 'descending') : 'none');

            const icon = header.querySelector('.sort-icon');
            if (!icon) return;
            icon.innerHTML = `<i class="fas ${isActive ? (direction === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort'}" aria-hidden="true"></i>`;
            window.FontAwesome?.dom?.i2svg?.({ node: icon });
        });
    }

    // ── Bulk actions ──────────────────────────────────────────────────

    toggleAll(event) {
        const checked = event.currentTarget.checked;
        this.rowCheckboxTargets.forEach((cb) => {
            if (cb.disabled) return;
            const row = cb.closest('tr');
            if (row && row.hidden) return; // only act on currently visible rows
            cb.checked = checked;
        });
        this._refreshBulkBar();
    }

    rowSelectChange() {
        this._refreshBulkBar();
    }

    _selectedIndices() {
        return this.rowCheckboxTargets
            .filter((cb) => cb.checked && !cb.disabled)
            .map((cb) => cb.closest('tr')?.dataset.idx)
            .filter((v) => v !== undefined);
    }

    _refreshBulkBar() {
        if (!this.hasBulkBarTarget) return;
        const count = this._selectedIndices().length;
        this.bulkBarTarget.hidden = count === 0;
        if (this.hasBulkCountTarget) {
            this.bulkCountTarget.textContent = count > 0
                ? this._formatText(this.bulkSelectedLabelValue, { '%count%': count })
                : '';
        }
    }

    bulkIgnore()    { this._sendBulk('ignore'); }
    bulkUnignore()  { this._sendBulk('unignore'); }

    bulkAssignDebit() {
        const accountId = this.bulkAccountSelectTarget.value;
        if (!accountId) return;
        this._sendBulk('assign_debit', { debitAccountId: accountId });
    }
    bulkAssignCredit() {
        const accountId = this.bulkAccountSelectTarget.value;
        if (!accountId) return;
        this._sendBulk('assign_credit', { creditAccountId: accountId });
    }

    // ── Network helpers ───────────────────────────────────────────────

    async _sendUpdate(row, idx, field, value) {
        row.classList.add('is-saving');
        const body = new URLSearchParams();
        body.set('_token', this.csrfValue);
        body.set('field', field);
        body.set('value', value);

        try {
            const res = await fetch(this._updateUrl(idx), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body,
            });
            if (!res.ok) throw new Error('http ' + res.status);
            const json = await res.json();
            const reloadAfterSave = field === 'invoiceNumber' && this._invoiceNumberChangeNeedsReload(idx);
            this._updateLineSnapshot(idx, field, value);
            this._applyServerState(row, idx, json);
            if (reloadAfterSave) {
                window.location.reload();
                return;
            }
            this._flashSaved(row);
        } catch (e) {
            row.classList.add('table-danger');
            setTimeout(() => row.classList.remove('table-danger'), 1500);
        } finally {
            row.classList.remove('is-saving');
        }
    }

    async _sendBulk(action, extra = {}) {
        const indices = this._selectedIndices();
        if (indices.length === 0) return;

        const body = new URLSearchParams();
        body.set('_token', this.csrfValue);
        body.set('action', action);
        indices.forEach((idx) => body.append('indices[]', idx));
        Object.entries(extra).forEach(([k, v]) => body.set(k, v));

        try {
            const res = await fetch(this.bulkUrlValue, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body,
            });
            if (!res.ok) throw new Error('http ' + res.status);
            // For bulk we just reload — many rows changed at once.
            window.location.reload();
        } catch (e) {
            // eslint-disable-next-line no-alert
            alert(this.updateFailedMessageValue);
        }
    }

    _updateUrl(idx) {
        return this.lineUrlPrefixValue + encodeURIComponent(idx);
    }

    _invoiceNumberChangeNeedsReload(idx) {
        const line = this._lineByIdx(idx);
        return Boolean(
            line?.matchedInvoiceId
            && line.matchedInvoiceAmountMatches
            && (!Array.isArray(line.splits) || line.splits.length === 0),
        );
    }

    _applyServerState(row, idx, payload) {
        if (payload?.status) {
            row.dataset.status = payload.status;
            const line = this._lineByIdx(idx);
            if (line) {
                row.dataset.invoice = (line.matchedInvoiceId || line.userInvoiceNumber) ? '1' : '0';
            }
            row.classList.toggle('table-secondary', payload.status === 'ignored' || payload.status === 'duplicate');
            row.classList.toggle('text-muted', payload.status === 'ignored' || payload.status === 'duplicate');
            this._refreshStatusBadge(row, payload.status);
            this._refreshIgnoreButton(row, payload.status === 'ignored');
        }
        if (payload?.counts) {
            this._refreshFilterCounts(payload.counts);
            this._refreshCommitBar(payload.counts);
        }
        // Re-evaluate visibility under the active filter (e.g. row that just
        // moved to "ready" should disappear from the "pending" filter).
        this._applyFilter();
    }

    _updateLineSnapshot(idx, field, value) {
        const line = this._lineByIdx(idx);
        if (!line) return;

        const idOrNull = (raw) => {
            const parsed = parseInt(raw, 10);
            return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
        };

        switch (field) {
            case 'debitAccountId':
                line.userDebitAccountId = idOrNull(value);
                break;
            case 'creditAccountId':
                line.userCreditAccountId = idOrNull(value);
                break;
            case 'taxRateId':
                line.userTaxRateId = idOrNull(value);
                break;
            case 'remark':
                line.userRemark = String(value || '').trim() || null;
                break;
            case 'invoiceNumber':
                line.userInvoiceNumber = String(value || '').trim().slice(0, 50) || null;
                break;
            case 'isIgnored':
                line.isIgnored = String(value) === '1';
                break;
            default:
                break;
        }
    }

    _refreshStatusBadge(row, status) {
        const cell = row.querySelector('[data-bank-import-preview-target="statusCell"]');
        if (!cell) return;
        // Replace only the leading badge (first child element).
        const first = cell.firstElementChild;
        if (!first) return;

        let cls = 'badge bg-warning text-dark';
        let icon = 'fa-circle-notch';
        let title = this.statusPendingTitleValue;
        if (status === 'duplicate') { cls = 'badge bg-info text-dark'; icon = 'fa-clone'; title = this.statusDuplicateTitleValue; }
        else if (status === 'ignored') { cls = 'badge bg-light text-dark border'; icon = 'fa-eye-slash'; title = this.statusIgnoredTitleValue; }
        else if (status === 'ready') { cls = 'badge bg-success'; icon = 'fa-check'; title = this.statusReadyTitleValue; }

        first.className = cls;
        first.setAttribute('title', title);
        first.innerHTML = `<i class="fas ${icon}"></i>`;
        window.FontAwesome?.dom?.i2svg?.({ node: first });
        // Refresh tooltip.
        const tooltip = window.bootstrap?.Tooltip?.getInstance(first);
        if (tooltip) tooltip.setContent({ '.tooltip-inner': title });
    }

    _refreshIgnoreButton(row, isIgnored) {
        const btn = row.querySelector('[data-bank-import-preview-target="ignoreButton"]');
        if (!btn) return;
        btn.classList.toggle('btn-secondary', isIgnored);
        btn.classList.toggle('btn-outline-secondary', !isIgnored);
        const icon = btn.querySelector('i');
        if (icon) icon.className = 'fas ' + (isIgnored ? 'fa-eye-slash' : 'fa-eye');
    }

    _refreshFilterCounts(counts) {
        this.filterChipTargets.forEach((chip) => {
            const filter = chip.dataset.filter;
            if (counts[filter] === undefined) return;
            const badge = chip.querySelector('.badge');
            if (badge) badge.textContent = counts[filter];
        });
    }

    _refreshCommitBar(counts) {
        if (this.hasCommitReadyCountTarget) this.commitReadyCountTarget.textContent = counts.ready ?? 0;
        if (this.hasCommitPendingCountTarget) this.commitPendingCountTarget.textContent = counts.pending ?? 0;
        if (this.hasCommitIgnoredCountTarget) this.commitIgnoredCountTarget.textContent = counts.ignored ?? 0;
        if (this.hasCommitDuplicateCountTarget) this.commitDuplicateCountTarget.textContent = counts.duplicate ?? 0;
        if (this.hasCommitButtonTarget) this.commitButtonTarget.disabled = (counts.ready ?? 0) === 0;
        if (this.hasCommitFormTarget && this.hasCommitConfirmTemplateValue) {
            this.commitFormTarget.dataset.confirmSubmitMessageValue = this._formatText(this.commitConfirmTemplateValue, {
                '%count%': counts.ready ?? 0,
            });
        }
    }

    _flashSaved(row) {
        row.classList.add('is-saved');
        setTimeout(() => row.classList.remove('is-saved'), 800);
    }

    // ── Split modal ───────────────────────────────────────────────────

    openSplit(event) {
        const row = event.currentTarget.closest('tr');
        const idx = row?.dataset.idx;
        if (idx === undefined) return;

        const line = this._lineByIdx(idx);
        if (!line) return;

        this.activeIdx = idx;
        this.splitTotalTarget.textContent = this._formatAmount(line.amount);
        this.splitPurposeTarget.textContent = line.purpose || '—';
        this.splitRowsTarget.innerHTML = '';

        if (Array.isArray(line.splits) && line.splits.length > 0) {
            line.splits.forEach((split) => this._addSplitRow(split));
        } else {
            // Seed with two empty rows so the user has something to fill in.
            this._addSplitRow();
            this._addSplitRow();
        }

        this._splitRecalc();
        this._showModal(this.splitModalTarget);
    }

    splitAddRow() {
        this._addSplitRow();
        this._splitRecalc();
    }

    splitRemoveRow(event) {
        event.currentTarget.closest('.split-row')?.remove();
        this._splitRecalc();
    }

    splitRecalc() {
        this._splitRecalc();
    }

    async splitSubmit() {
        if (this.activeIdx === null) return;

        // Resolve percent/remainder modes to absolute amounts using the line's
        // total — the per-line endpoint expects fixed amounts only.
        const total = Math.abs(parseFloat((this._lineByIdx(this.activeIdx) || {}).amount || '0'));
        const rows = Array.from(this.splitRowsTarget.querySelectorAll('.split-row'));
        const raw = rows.map((rowEl) => ({
            mode: rowEl.querySelector('.split-mode')?.value || 'amount',
            value: parseFloat(rowEl.querySelector('.split-value')?.value || '0') || 0,
            debitAccountId: rowEl.querySelector('.split-debit')?.value || '',
            creditAccountId: rowEl.querySelector('.split-credit')?.value || '',
            taxRateId: rowEl.querySelector('.split-tax-rate')?.value || '',
            remark: rowEl.querySelector('.split-remark')?.value || '',
        }));

        let assigned = 0;
        const resolved = raw.map((r) => {
            if (r.mode === 'percent') {
                const a = total * (r.value / 100);
                assigned += a;
                return { ...r, amount: a };
            }
            if (r.mode === 'amount') {
                assigned += r.value;
                return { ...r, amount: r.value };
            }
            return { ...r, amount: 0 }; // remainder placeholder
        });
        const remainder = Math.max(0, total - assigned);
        const splits = resolved
            .map((r) => (r.mode === 'remainder' ? { ...r, amount: remainder } : r))
            .filter((s) => s.amount > 0);

        const body = new URLSearchParams();
        body.set('_token', this.csrfValue);
        splits.forEach((s, i) => {
            body.append(`splits[${i}][amount]`, s.amount.toFixed(2));
            body.append(`splits[${i}][debitAccountId]`, s.debitAccountId);
            body.append(`splits[${i}][creditAccountId]`, s.creditAccountId);
            body.append(`splits[${i}][taxRateId]`, s.taxRateId);
            body.append(`splits[${i}][remark]`, s.remark);
        });

        try {
            const res = await fetch(this._lineSubResourceUrl(this.activeIdx, 'split'), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body,
            });
            if (!res.ok) throw new Error('http ' + res.status);
            // The whole row visualisation needs refreshing — easiest via reload.
            window.location.reload();
        } catch {
            // eslint-disable-next-line no-alert
            alert(this.saveFailedMessageValue);
        }
    }

    _addSplitRow(prefill = null) {
        const tpl = this.splitRowTemplateTarget.content.firstElementChild.cloneNode(true);
        if (prefill) {
            const modeSel = tpl.querySelector('.split-mode');
            const valueInput = tpl.querySelector('.split-value');
            const mode = prefill.mode
                || (prefill.remainder ? 'remainder' : (prefill.percent !== undefined ? 'percent' : 'amount'));
            if (modeSel) modeSel.value = mode;
            if (valueInput) {
                if (mode === 'percent') valueInput.value = prefill.percent ?? '';
                else if (mode === 'amount') valueInput.value = Math.abs(parseFloat(prefill.amount ?? 0)).toFixed(2);
                else valueInput.value = '';
            }
            const debitSel = tpl.querySelector('.split-debit');
            if (debitSel && prefill.debitAccountId) debitSel.value = String(prefill.debitAccountId);
            const creditSel = tpl.querySelector('.split-credit');
            if (creditSel && prefill.creditAccountId) creditSel.value = String(prefill.creditAccountId);
            const taxRateSel = tpl.querySelector('.split-tax-rate');
            if (taxRateSel && prefill.taxRateId) taxRateSel.value = String(prefill.taxRateId);
            const remarkInput = tpl.querySelector('.split-remark');
            if (remarkInput) remarkInput.value = prefill.remark ?? prefill.remarkTemplate ?? '';
        }
        this.splitRowsTarget.appendChild(tpl);
        this._applyModeUI(tpl);
    }

    splitModeChange(event) {
        this._applyModeUI(event.currentTarget.closest('.split-row'));
        this._splitRecalc();
    }

    _applyModeUI(rowEl) {
        if (!rowEl) return;
        const mode = rowEl.querySelector('.split-mode')?.value || 'amount';
        const input = rowEl.querySelector('.split-value');
        const unit = rowEl.querySelector('.split-unit');
        if (input) {
            input.disabled = mode === 'remainder';
            if (mode === 'remainder') input.value = '';
        }
        if (unit) unit.textContent = mode === 'percent' ? '%' : (mode === 'remainder' ? '↻' : this.currencySymbolValue);
    }

    _splitRecalc() {
        const total = Math.abs(parseFloat((this._lineByIdx(this.activeIdx) || {}).amount || '0'));
        let assigned = 0;
        let hasRemainder = false;

        this.splitRowsTarget.querySelectorAll('.split-row').forEach((rowEl) => {
            const mode = rowEl.querySelector('.split-mode')?.value || 'amount';
            const v = parseFloat(rowEl.querySelector('.split-value')?.value || '0') || 0;
            if (mode === 'amount') assigned += v;
            else if (mode === 'percent') assigned += total * (v / 100);
            else if (mode === 'remainder') hasRemainder = true;
        });

        this.splitAssignedTarget.textContent = this._formatAmountValue(assigned);
        const delta = total - assigned;
        if (hasRemainder) {
            this.splitDeltaTarget.textContent = delta >= 0
                ? this._formatText(this.splitRemainderLabelValue, { '%amount%': this._formatAmountValue(Math.max(0, delta)) })
                : this._formatText(this.splitTooMuchLabelValue, { '%amount%': this._formatAmountValue(delta) });
            this.splitDeltaTarget.className = delta >= 0 ? 'small text-muted' : 'small text-danger';
        } else if (Math.abs(delta) < 0.005) {
            this.splitDeltaTarget.textContent = '✓';
            this.splitDeltaTarget.className = 'small text-success';
        } else if (delta > 0) {
            this.splitDeltaTarget.textContent = this._formatText(this.splitOpenLabelValue, { '%amount%': this._formatAmountValue(delta) });
            this.splitDeltaTarget.className = 'small text-warning';
        } else {
            this.splitDeltaTarget.textContent = this._formatText(this.splitTooMuchLabelValue, { '%amount%': this._formatAmountValue(delta) });
            this.splitDeltaTarget.className = 'small text-danger';
        }
    }

    _formatAmount(rawSigned) {
        const n = parseFloat(rawSigned);
        const sign = n < 0 ? '-' : '';
        return sign + this._formatAmountValue(Math.abs(n));
    }

    _formatAmountValue(value) {
        return this._formatNumber(value) + ' ' + this.currencySymbolValue;
    }

    _formatNumber(n) {
        const locale = this.localeValue || document.documentElement.lang || 'de-DE';
        return n.toLocaleString(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    _formatText(template, replacements = {}) {
        return Object.entries(replacements).reduce(
            (text, [search, replace]) => text.split(search).join(String(replace)),
            template || '',
        );
    }

    // ── Rule modal ────────────────────────────────────────────────────

    openRule(event) {
        const row = event.currentTarget.closest('tr');
        const idx = row?.dataset.idx;
        if (idx === undefined) return;
        const line = this._lineByIdx(idx);
        if (!line) return;

        this.activeIdx = idx;

        // Suggest a sensible default name.
        this.ruleNameTarget.value = (line.counterpartyName || this.ruleDefaultNameValue).slice(0, 80);

        const name = (line.counterpartyName || '').trim();
        this.ruleCondCounterpartyValueTarget.textContent = name || '—';
        this.ruleCondCounterpartyTarget.checked = name !== '';
        this.ruleCondCounterpartyTarget.disabled = name === '';

        const iban = (line.counterpartyIban || '').trim();
        this.ruleCondIbanValueTarget.textContent = iban || '—';
        this.ruleCondIbanTarget.checked = false;
        this.ruleCondIbanTarget.disabled = iban === '';

        const direction = parseFloat(line.amount) >= 0 ? 'in' : 'out';
        this.ruleCondDirectionValueTarget.textContent = direction === 'in'
            ? this.ruleCondDirectionValueTarget.dataset.in || direction
            : this.ruleCondDirectionValueTarget.dataset.out || direction;
        this.ruleCondDirectionTarget.checked = false;

        this.ruleCondPurposeTarget.checked = false;
        this.ruleCondPurposeValueTarget.value = '';

        // Pre-fill the action with the line's current edit state.
        this.ruleDebitTarget.value = line.userDebitAccountId ? String(line.userDebitAccountId) : '';
        this.ruleCreditTarget.value = line.userCreditAccountId ? String(line.userCreditAccountId) : '';
        this.ruleTaxRateTarget.value = line.userTaxRateId ? String(line.userTaxRateId) : '';
        this.ruleRemarkTarget.value = line.userRemark || '';
        this.rulePriorityTarget.value = '50';
        this.ruleScopeTarget.checked = true;
        this._populateRuleInvoiceExtraction(line);
        this._populateRuleSplitAction(line);

        this._showModal(this.ruleModalTarget);
    }

    async ruleSubmit() {
        if (this.activeIdx === null) return;

        const conditionFields = [];
        if (this.ruleCondCounterpartyTarget.checked) conditionFields.push('counterpartyName');
        if (this.ruleCondIbanTarget.checked) conditionFields.push('counterpartyIban');
        if (this.ruleCondDirectionTarget.checked) conditionFields.push('direction');
        if (this.ruleCondPurposeTarget.checked && this.ruleCondPurposeValueTarget.value.trim() !== '') {
            conditionFields.push('purpose');
        }

        if (conditionFields.length === 0) {
            // eslint-disable-next-line no-alert
            alert(this.ruleConditionRequiredMessageValue);
            return;
        }

        const name = this.ruleNameTarget.value.trim();
        if (name === '') {
            // eslint-disable-next-line no-alert
            alert(this.ruleNameRequiredMessageValue);
            return;
        }

        const body = new URLSearchParams();
        body.set('_token', this.csrfValue);
        body.set('name', name);
        conditionFields.forEach((f) => body.append('conditionFields[]', f));
        body.set('purposeContains', this.ruleCondPurposeValueTarget.value.trim());
        // If the line has splits, persist dynamic marker/remainder split rules.
        const line = this._lineByIdx(this.activeIdx);
        const lineSplits = Array.isArray(line?.splits) ? line.splits : [];
        if (lineSplits.length > 0) {
            body.set('actionMode', 'split');
            const splitRows = Array.from(this.ruleSplitRowsTarget.querySelectorAll('.rule-split-row'));
            for (const [i, rowEl] of splitRows.entries()) {
                const source = rowEl.querySelector('.rule-split-source')?.value || 'purpose_marker';
                const pattern = (rowEl.querySelector('.rule-split-marker')?.value || '').trim();
                if (source === 'purpose_marker' && pattern === '') {
                    // eslint-disable-next-line no-alert
                    alert(this.ruleSplitMarkerRequiredMessageValue);
                    return;
                }
                if (source === 'purpose_regex' && pattern === '') {
                    // eslint-disable-next-line no-alert
                    alert(this.ruleSplitRegexRequiredMessageValue);
                    return;
                }

                if (source === 'remainder') {
                    body.append(`splits[${i}][remainder]`, '1');
                } else if (source === 'purpose_regex') {
                    body.append(`splits[${i}][amountSource]`, 'purpose_regex');
                    body.append(`splits[${i}][pattern]`, pattern);
                } else {
                    body.append(`splits[${i}][amountSource]`, 'purpose_marker');
                    body.append(`splits[${i}][marker]`, pattern);
                }
                body.append(`splits[${i}][debitAccountId]`, rowEl.querySelector('.rule-split-debit')?.value || '');
                body.append(`splits[${i}][creditAccountId]`, rowEl.querySelector('.rule-split-credit')?.value || '');
                body.append(`splits[${i}][taxRateId]`, rowEl.querySelector('.rule-split-tax-rate')?.value || '');
                body.append(`splits[${i}][remark]`, rowEl.querySelector('.rule-split-remark')?.value || '');
            }
        } else {
            body.set('actionMode', 'assign');
            body.set('debitAccountId', this.ruleDebitTarget.value);
            body.set('creditAccountId', this.ruleCreditTarget.value);
            body.set('taxRateId', this.ruleTaxRateTarget.value);
            body.set('remarkTemplate', this.ruleRemarkTarget.value);
        }
        this._appendInvoiceExtractionToRuleBody(body);
        body.set('priority', this.rulePriorityTarget.value || '50');
        body.set('scopeToBankAccount', this.ruleScopeTarget.checked ? '1' : '0');

        try {
            const res = await fetch(this._lineSubResourceUrl(this.activeIdx, 'rule'), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body,
            });
            const json = await res.json();
            if (!res.ok) {
                // eslint-disable-next-line no-alert
                alert(this._formatText(this.errorPrefixValue, { '%message%': json?.error || res.status }));
                return;
            }
            // The new rule may have applied to other lines too — reload.
            window.location.reload();
        } catch {
            // eslint-disable-next-line no-alert
            alert(this.saveFailedMessageValue);
        }
    }

    _populateRuleInvoiceExtraction(line) {
        if (!this.hasRuleInvoiceExtractionModeTarget) return;

        const invoiceNumber = String(line?.userInvoiceNumber || '').trim();
        const marker = invoiceNumber ? this._guessMarkerForText(line?.purpose || '', invoiceNumber) : '';
        this.ruleInvoiceExtractionModeTarget.value = invoiceNumber ? 'marker' : 'none';
        this.ruleInvoiceExtractionMarkerTarget.value = marker;
        this.ruleInvoiceExtractionRegexTarget.value = '';
        this._refreshRuleInvoiceExtraction();
    }

    ruleInvoiceExtractionModeChange() {
        this._refreshRuleInvoiceExtraction();
    }

    ruleInvoiceExtractionInput() {
        this._refreshRuleInvoiceExtraction();
    }

    _refreshRuleInvoiceExtraction() {
        if (!this.hasRuleInvoiceExtractionModeTarget) return;

        const mode = this.ruleInvoiceExtractionModeTarget.value || 'none';
        this.ruleInvoiceExtractionMarkerGroupTarget.hidden = mode !== 'marker';
        this.ruleInvoiceExtractionRegexGroupTarget.hidden = mode !== 'regex';

        const preview = this.ruleInvoiceExtractionPreviewTarget;
        if (mode === 'none') {
            preview.className = 'small text-muted';
            preview.textContent = '';
            return;
        }

        const purpose = (this._lineByIdx(this.activeIdx) || {}).purpose || '';
        const found = mode === 'marker'
            ? this._extractInvoiceNumberAfterMarker(purpose, this.ruleInvoiceExtractionMarkerTarget.value.trim())
            : this._extractInvoiceNumberByRegex(purpose, this.ruleInvoiceExtractionRegexTarget.value.trim());

        if (!found) {
            preview.className = 'small text-warning';
            preview.textContent = this.ruleInvoiceMissingLabelValue;
            return;
        }

        preview.className = 'small text-success';
        preview.textContent = this._formatText(this.ruleInvoiceFoundLabelValue, { '%number%': found });
    }

    _appendInvoiceExtractionToRuleBody(body) {
        if (!this.hasRuleInvoiceExtractionModeTarget) return;

        const mode = this.ruleInvoiceExtractionModeTarget.value || 'none';
        body.set('invoiceExtractionMode', mode);
        if (mode === 'marker') {
            body.set('invoiceExtractionMarker', this.ruleInvoiceExtractionMarkerTarget.value.trim());
        } else if (mode === 'regex') {
            body.set('invoiceExtractionRegex', this.ruleInvoiceExtractionRegexTarget.value.trim());
        }
    }

    _populateRuleSplitAction(line) {
        const splits = Array.isArray(line?.splits) ? line.splits : [];
        if (splits.length === 0) {
            if (this.hasRuleAssignSectionTarget) this.ruleAssignSectionTarget.hidden = false;
            if (this.hasRuleSplitSectionTarget) this.ruleSplitSectionTarget.hidden = true;
            if (this.hasRuleSplitRowsTarget) this.ruleSplitRowsTarget.innerHTML = '';
            return;
        }

        if (this.hasRuleAssignSectionTarget) this.ruleAssignSectionTarget.hidden = true;
        if (this.hasRuleSplitSectionTarget) this.ruleSplitSectionTarget.hidden = false;
        this.ruleSplitRowsTarget.innerHTML = '';
        splits.forEach((split, idx) => this._addRuleSplitRow(split, line, idx));
    }

    _addRuleSplitRow(split, line, idx) {
        const tpl = this.ruleSplitRowTemplateTarget.content.firstElementChild.cloneNode(true);
        const amount = Math.abs(parseFloat(split.amount || '0')) || 0;
        const currentAmount = tpl.querySelector('.rule-split-current-amount');
        if (currentAmount) currentAmount.textContent = this._formatAmountValue(amount);

        const marker = this._guessMarkerForAmount(line.purpose || '', amount);
        const sourceSel = tpl.querySelector('.rule-split-source');
        const markerInput = tpl.querySelector('.rule-split-marker');
        const isLast = idx === (Array.isArray(line.splits) ? line.splits.length - 1 : idx);
        if (sourceSel) sourceSel.value = marker === '' && isLast ? 'remainder' : 'purpose_marker';
        if (markerInput) markerInput.value = marker;

        const debitSel = tpl.querySelector('.rule-split-debit');
        if (debitSel && split.debitAccountId) debitSel.value = String(split.debitAccountId);
        const creditSel = tpl.querySelector('.rule-split-credit');
        if (creditSel && split.creditAccountId) creditSel.value = String(split.creditAccountId);
        const taxRateSel = tpl.querySelector('.rule-split-tax-rate');
        if (taxRateSel && split.taxRateId) taxRateSel.value = String(split.taxRateId);
        const remarkInput = tpl.querySelector('.rule-split-remark');
        if (remarkInput) remarkInput.value = split.remark ?? '';

        this.ruleSplitRowsTarget.appendChild(tpl);
        this._refreshRuleSplitRow(tpl);
    }

    ruleSplitSourceChange(event) {
        this._refreshRuleSplitRow(event.currentTarget.closest('.rule-split-row'));
    }

    ruleSplitMarkerInput(event) {
        this._refreshRuleSplitRow(event.currentTarget.closest('.rule-split-row'));
    }

    _refreshRuleSplitRow(rowEl) {
        if (!rowEl) return;
        const source = rowEl.querySelector('.rule-split-source')?.value || 'purpose_marker';
        const patternInput = rowEl.querySelector('.rule-split-marker');
        const preview = rowEl.querySelector('.rule-split-preview');
        if (source === 'remainder') {
            if (patternInput) patternInput.disabled = true;
            if (preview) {
                preview.className = 'small rule-split-preview text-muted';
                preview.textContent = this.ruleSplitRemainderLabelValue;
            }
            return;
        }

        if (patternInput) patternInput.disabled = false;
        const pattern = (patternInput?.value || '').trim();
        const purpose = (this._lineByIdx(this.activeIdx) || {}).purpose || '';
        const found = source === 'purpose_regex'
            ? this._extractAmountByRegex(purpose, pattern)
            : this._extractAmountAfterMarker(purpose, pattern);
        if (!preview) return;
        if (found === false) {
            preview.className = 'small rule-split-preview text-warning';
            preview.textContent = this.ruleSplitInvalidLabelValue;
            return;
        }
        if (found === null) {
            preview.className = 'small rule-split-preview text-warning';
            preview.textContent = this.ruleSplitMissingLabelValue;
            return;
        }

        preview.className = 'small rule-split-preview text-success';
        preview.textContent = this._formatText(this.ruleSplitFoundLabelValue, {
            '%amount%': this._formatAmountValue(found),
        });
    }

    _guessMarkerForAmount(purpose, amount) {
        if (!purpose || amount <= 0) return '';
        const matches = this._amountMatches(purpose);
        const match = matches.find((candidate) => Math.abs(candidate.amount - amount) < 0.005);
        if (!match) return '';

        const before = purpose.slice(0, match.index).replace(/\s+/g, ' ').trim();
        if (before === '') return '';
        const afterPreviousAmount = before.replace(/^.*(?:\d{1,3}(?:[.,]\d{3})+[.,]\d{2}|\d+[.,]\d{2}|\d{1,3}(?:[.,]\d{3})+|\d+)\s*-?\s*/u, '').trim();
        const words = (afterPreviousAmount || before).split(/\s+/).filter(Boolean);
        return words.slice(-4).join(' ').replace(/[:\-–]+$/u, '').trim();
    }

    _guessMarkerForText(purpose, needle) {
        if (!purpose || !needle) return '';
        const idx = purpose.toLocaleLowerCase().indexOf(needle.toLocaleLowerCase());
        if (idx < 0) return '';

        const before = purpose.slice(0, idx).replace(/\s+/g, ' ').trim();
        if (before === '') return '';
        const words = before.split(/\s+/).filter(Boolean);

        return words.slice(-4).join(' ').replace(/[:\-–#]+$/u, '').trim();
    }

    _extractInvoiceNumberAfterMarker(purpose, marker) {
        if (!purpose || !marker) return null;
        const idx = purpose.toLocaleLowerCase().indexOf(marker.toLocaleLowerCase());
        if (idx < 0) return null;
        const tail = purpose.slice(idx + marker.length);
        const match = tail.match(/^\s*(?:(?:nr\.?|nummer|no\.?|#)\s*)?[:\-#\s]*([\p{L}\p{N}][\p{L}\p{N}.\/_-]{1,49})/u);
        return match ? this._cleanInvoiceNumber(match[1]) : null;
    }

    _extractInvoiceNumberByRegex(purpose, pattern) {
        if (!purpose || !pattern) return null;
        let regex;
        try {
            const delimited = pattern.match(/^\/(.+)\/([gimsuy]*)$/u);
            if (delimited) {
                regex = new RegExp(delimited[1], delimited[2].replace('g', ''));
            } else {
                regex = new RegExp(pattern, 'iu');
            }
        } catch {
            return null;
        }

        const match = purpose.match(regex);
        return match ? this._cleanInvoiceNumber(match[1] || match[0]) : null;
    }

    _cleanInvoiceNumber(value) {
        const cleaned = String(value || '').trim().replace(/^[.,;:]+|[.,;:]+$/gu, '');
        return cleaned ? cleaned.slice(0, 50) : null;
    }

    _extractAmountAfterMarker(purpose, marker) {
        if (!purpose || !marker) return null;
        const idx = purpose.toLocaleLowerCase().indexOf(marker.toLocaleLowerCase());
        if (idx < 0) return null;
        const tail = purpose.slice(idx + marker.length);
        const matches = this._amountMatches(tail);
        return matches.length > 0 ? matches[0].amount : null;
    }

    _extractAmountByRegex(purpose, pattern) {
        if (!purpose || !pattern) return null;
        let regex;
        try {
            const delimited = pattern.match(/^\/(.+)\/([gimsuy]*)$/u);
            if (delimited) {
                regex = new RegExp(delimited[1], delimited[2].replace('g', ''));
            } else {
                regex = new RegExp(pattern, 'iu');
            }
        } catch {
            return false;
        }

        const match = purpose.match(regex);
        if (!match) return null;

        for (const capture of match.slice(1)) {
            const parsed = this._parseLooseAmount(capture);
            if (parsed !== null) return parsed;
        }

        return this._parseLooseAmount(match[0]);
    }

    _amountMatches(text) {
        const regex = /(^|[^\d.,])(?:([+-]?(?:\d{1,3}(?:[.,]\d{3})+[.,]\d{2}|\d+[.,]\d{2}))\s*-?(?![.,]\d)|([+-]?(?:\d{1,3}(?:[.,]\d{3})+|\d+))\s*-?(?=\s*(?:€|EUR\b|Euro\b|,|;|$))(?![.,]\d))/giu;
        const matches = [];
        for (const match of text.matchAll(regex)) {
            const amount = this._parseLooseAmount(match[2] || match[3]);
            if (amount !== null) {
                matches.push({ index: (match.index || 0) + (match[1]?.length || 0), amount });
            }
        }
        return matches;
    }

    _parseLooseAmount(raw) {
        let value = String(raw || '').replace(/[^\d,.\-+]/g, '');
        if (value === '' || value === '-' || value === '+') return null;
        const lastComma = value.lastIndexOf(',');
        const lastDot = value.lastIndexOf('.');
        if (lastComma >= 0 || lastDot >= 0) {
            const separator = lastComma >= 0 && lastDot >= 0
                ? (lastComma > lastDot ? ',' : '.')
                : (lastComma >= 0 ? ',' : '.');
            const separatorPos = value.lastIndexOf(separator);
            const digitsAfterSeparator = separatorPos < 0 ? 0 : value.length - separatorPos - 1;
            const isThousandsOnly = digitsAfterSeparator === 3
                && value.split(separator).length === 2
                && /^[+-]?\d{1,3}[.,]\d{3}$/u.test(value);

            if (isThousandsOnly) {
                value = value.split(separator).join('');
            } else {
                const decimal = lastComma >= 0 && lastDot >= 0
                    ? (lastComma > lastDot ? ',' : '.')
                    : separator;
                const thousands = decimal === ',' ? '.' : ',';
                value = value.split(thousands).join('').replace(decimal, '.');
            }
        }
        const parsed = parseFloat(value);
        return Number.isNaN(parsed) ? null : Math.abs(parsed);
    }

    // ── Modal helpers ─────────────────────────────────────────────────

    _showModal(element) {
        const modal = window.bootstrap?.Modal?.getOrCreateInstance(element);
        modal?.show();
    }

    _lineSubResourceUrl(idx, suffix) {
        return this.lineUrlPrefixValue + encodeURIComponent(idx) + '/' + suffix;
    }
}
