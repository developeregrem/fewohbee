import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/**
 * Suggestions below a text field, loaded from a JSON endpoint while typing. Rendered by the Twig
 * component <twig:Autocomplete>; picking a suggestion dispatches "autocomplete:select" with the
 * chosen item, the page decides what to fill in:
 *
 *   <twig:Autocomplete :url="path('customers.search.address', {address: '__query__'})"
 *                      label="{company}" detail="{address}, {zip} {city}"
 *                      data-action="autocomplete:select->customer-form#fillAddressAction"/>
 *
 * The url holds "__query__" for the typed text and "__<name>__" for each entry of params, a field
 * looked up by selector within the closest scope element (default: the form). label and detail
 * are templates over the item's keys; an empty label gives way to the detail.
 *
 * The field follows the ARIA combobox pattern: arrow keys move through the list, Enter picks,
 * Escape closes the list without closing a surrounding dialog.
 */
export default class extends Controller {
    static values = {
        url: String,
        params: Object,
        scope: String,
        minLength: { type: Number, default: 2 },
        maxResults: { type: Number, default: 8 },
        label: String,
        detail: String,
        empty: String,
    };

    static nextId = 0;

    connect() {
        const id = `autocomplete-${this.constructor.nextId++}`;
        this.items = [];
        this.active = -1;
        this.cache = new Map();

        this.menu = document.createElement('div');
        this.menu.id = id;
        this.menu.className = 'autocomplete-menu';
        this.menu.setAttribute('role', 'listbox');
        this.menu.hidden = true;
        this.menu.addEventListener('mousedown', (event) => event.preventDefault());
        this.menu.addEventListener('click', (event) => this.pick(event));

        this.spinner = document.createElement('span');
        this.spinner.className = 'autocomplete-spinner spinner-border spinner-border-sm';
        this.spinner.setAttribute('aria-hidden', 'true');
        this.spinner.hidden = true;

        // The suggestions are placed relative to the field's parent; adding siblings keeps the
        // field itself where it is (moving it would reconnect this controller).
        this.element.parentElement.classList.add('autocomplete');
        this.element.after(this.menu, this.spinner);
        this.element.setAttribute('role', 'combobox');
        this.element.setAttribute('aria-autocomplete', 'list');
        this.element.setAttribute('aria-expanded', 'false');
        this.element.setAttribute('aria-controls', id);

        this.onInput = () => this.schedule();
        this.onKeydown = (event) => this.keydown(event);
        this.onBlur = () => this.close();
        this.element.addEventListener('input', this.onInput);
        this.element.addEventListener('keydown', this.onKeydown);
        this.element.addEventListener('blur', this.onBlur);
    }

    disconnect() {
        clearTimeout(this.timer);
        this.request?.abort();
        this.element.removeEventListener('input', this.onInput);
        this.element.removeEventListener('keydown', this.onKeydown);
        this.element.removeEventListener('blur', this.onBlur);
        this.element.parentElement?.classList.remove('autocomplete');
        this.menu.remove();
        this.spinner.remove();
    }

    schedule() {
        clearTimeout(this.timer);
        const query = this.element.value.trim();
        if (query.length < this.minLengthValue) {
            this.request?.abort();
            this.close();
            return;
        }
        this.timer = setTimeout(() => this.load(query), 200);
    }

    async load(query) {
        const url = this.buildUrl(query);
        let items = this.cache.get(url);
        if (!items) {
            this.request?.abort();
            this.request = new AbortController();
            this.setBusy(true);
            try {
                const response = await fetch(url, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    signal: this.request.signal,
                });
                items = response.ok ? await response.json() : [];
            } catch (error) {
                if (error.name === 'AbortError') {
                    return;
                }
                items = [];
            } finally {
                this.setBusy(false);
            }
            items = Array.isArray(items) ? items : Object.values(items ?? {});
            this.cache.set(url, items);
        }
        // Typing went on while the answer was on its way
        if (this.element.value.trim() !== query || document.activeElement !== this.element) {
            return;
        }
        this.show(items.slice(0, this.maxResultsValue), query);
    }

    buildUrl(query) {
        const scope = (this.scopeValue && this.element.closest(this.scopeValue)) || this.element.form || document;
        let url = this.urlValue.replace('__query__', encodeURIComponent(query));
        Object.entries(this.paramsValue).forEach(([name, selector]) => {
            const value = scope.querySelector(selector)?.value ?? '';
            url = url.replace(`__${name}__`, encodeURIComponent(value));
        });
        return url;
    }

    show(results, query) {
        // A result without any text to show cannot be told apart from the others
        const rows = results
            .map((item) => {
                const label = this.format(this.labelValue, item);
                const detail = this.format(this.detailValue, item);
                return label ? { item, label, detail } : { item, label: detail, detail: '' };
            })
            .filter((row) => row.label);
        const items = rows.map((row) => row.item);
        this.items = items;
        this.active = -1;
        this.menu.replaceChildren();
        rows.forEach(({ label, detail }, index) => {
            const option = document.createElement('div');
            option.id = `${this.menu.id}-${index}`;
            option.className = 'autocomplete-option';
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.dataset.index = String(index);
            option.append(this.highlight(label, query, 'autocomplete-label'));
            if (detail) {
                option.append(this.highlight(detail, query, 'autocomplete-detail'));
            }
            this.menu.append(option);
        });
        if (!items.length) {
            if (!this.emptyValue) {
                this.close();
                return;
            }
            const empty = document.createElement('div');
            empty.className = 'autocomplete-empty';
            empty.textContent = this.emptyValue;
            this.menu.append(empty);
        }
        this.place();
        this.menu.hidden = false;
        this.element.setAttribute('aria-expanded', 'true');
    }

    close() {
        clearTimeout(this.timer);
        this.menu.hidden = true;
        this.active = -1;
        this.element.setAttribute('aria-expanded', 'false');
        this.element.removeAttribute('aria-activedescendant');
    }

    keydown(event) {
        const open = !this.menu.hidden && this.items.length > 0;
        if (event.key === 'Escape' && !this.menu.hidden) {
            // Close only the list, not the dialog around the field
            event.preventDefault();
            event.stopPropagation();
            this.close();
        } else if (open && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            this.activate((this.active + step + this.items.length) % this.items.length);
        } else if (open && event.key === 'Enter' && this.active >= 0) {
            event.preventDefault();
            this.select(this.active);
        } else if (event.key === 'Tab') {
            this.close();
        }
    }

    activate(index) {
        this.menu.querySelectorAll('.autocomplete-option').forEach((option, i) => {
            option.setAttribute('aria-selected', i === index ? 'true' : 'false');
            option.classList.toggle('is-active', i === index);
        });
        this.active = index;
        const option = this.menu.children[index];
        this.element.setAttribute('aria-activedescendant', option.id);
        option.scrollIntoView({ block: 'nearest' });
    }

    pick(event) {
        const option = event.target.closest('.autocomplete-option');
        if (option) {
            this.select(Number(option.dataset.index));
        }
    }

    select(index) {
        const item = this.items[index];
        this.close();
        if (item !== undefined) {
            this.dispatch('select', { detail: { item } });
        }
    }

    /* "{address}, {zip} {city}" → the item's values; separators of empty values fall away */
    format(template, item) {
        if (!template) {
            return typeof item === 'object' && item !== null ? String(item.label ?? item.name ?? '') : String(item ?? '');
        }
        return template
            .replace(/\{(\w+)\}/g, (match, key) => (item?.[key] ?? '').toString())
            .replace(/^[\s,·-]+|[\s,·-]+$/g, '')
            .replace(/,(\s*,)+/g, ',');
    }

    /* The text as text nodes, the typed part marked */
    highlight(text, query, className) {
        const span = document.createElement('span');
        span.className = className;
        const at = text.toLowerCase().indexOf(query.toLowerCase());
        if (at < 0 || !query) {
            span.textContent = text;
            return span;
        }
        const mark = document.createElement('mark');
        mark.textContent = text.slice(at, at + query.length);
        span.append(text.slice(0, at), mark, text.slice(at + query.length));
        return span;
    }

    /* Directly below the field, as wide as the field, whatever else its parent holds */
    place() {
        const field = this.element;
        this.menu.style.top = `${field.offsetTop + field.offsetHeight + 4}px`;
        this.menu.style.left = `${field.offsetLeft}px`;
        this.menu.style.width = `${field.offsetWidth}px`;
    }

    setBusy(busy) {
        if (!busy) {
            this.element.removeAttribute('aria-busy');
        } else {
            this.element.setAttribute('aria-busy', 'true');
            const field = this.element;
            this.spinner.style.top = `${field.offsetTop + field.offsetHeight / 2}px`;
            this.spinner.style.left = `${field.offsetLeft + field.offsetWidth}px`;
        }
        this.spinner.hidden = !busy;
    }
}
