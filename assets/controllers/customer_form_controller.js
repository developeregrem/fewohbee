import { Controller } from '@hotwired/stimulus';
import { request as httpRequest } from './http_controller.js';

export default class extends Controller {
    static targets = [
        'accordion',
        'addressTemplate',
        'addressSearch',
        'flashOverlay',
        'deleteBox',
        'defaultBox',
    ];

    static values = {
        cityLookupUrl: String,
        addressLookupUrl: String,
        submitUrl: String,
        successUrl: String,
        mode: { type: String, default: 'edit' },
        deleteUrl: String,
    };

    connect() {
        this.disableTemplateInputs();
        this.initAddressLookup();
        this.initCityLookup();
        this.preselectLastname();
    }

    submitAction(event) {
        event.preventDefault();
        if (!this.hasSubmitUrlValue) {
            return;
        }
        const data = new FormData(this.element);
        httpRequest({
            url: this.submitUrlValue,
            method: 'POST',
            data,
            onSuccess: (response) => {
                if (this.modeValue === 'create') {
                    this.handleCreateSuccess(response);
                } else {
                    window.location.reload();
                }
            },
        });
    }

    deleteAction(event) {
        event.preventDefault();
        if (!this.hasDeleteUrlValue) {
            return;
        }
        const data = new FormData(this.element);
        httpRequest({
            url: this.deleteUrlValue,
            method: 'POST',
            data,
            onSuccess: () => {
                window.location.reload();
            },
        });
    }

    toggleDeleteAction(event) {
        event.preventDefault();
        if (!this.hasDeleteBoxTarget || !this.hasDefaultBoxTarget) {
            return;
        }
        const deleteVisible = !this.deleteBoxTarget.classList.contains('d-none');
        if (deleteVisible) {
            this.deleteBoxTarget.classList.add('d-none');
            this.defaultBoxTarget.classList.remove('d-none');
        } else {
            this.deleteBoxTarget.classList.remove('d-none');
            this.defaultBoxTarget.classList.add('d-none');
        }
    }

    addAddressFieldsAction(event) {
        event.preventDefault();
        this.addAddressPanel(true);
    }

    deleteAddressAction(event) {
        event.preventDefault();
        const panel = event.currentTarget.closest('.accordion-item');
        if (panel && panel.parentElement) {
            panel.parentElement.removeChild(panel);
        }
    }

    capitalizeNameAction(event) {
        const input = event.target;
        if (input && input.value && input.value.length === 1) {
            input.value = input.value.charAt(0).toUpperCase() + input.value.slice(1);
        }
    }

    handleTemplateChangeAction(event) {
        const value = event.target?.value || '';
        if (!value || value === '0') {
            return;
        }
        const parts = value.split('|');
        const mapper = [
            ['select[name^="salutation-"]', 0],
            ['input[name^="firstname-"]', 1],
            ['input[name^="lastname-"]', 2],
            ['input[name^="company-"]', 3],
            ['input[name^="address-"]', 4],
            ['input[name^="zip-"]', 5],
            ['input[name^="city-"]', 6],
            ['input[name^="birthday-"]', 7],
            ['select[name^="country-"]', 8],
            ['input[name^="phone-"]', 9],
            ['input[name^="fax-"]', 10],
            ['input[name^="mobilephone-"]', 11],
            ['input[name^="email-"]', 12],
        ];
        mapper.forEach(([selector, index]) => {
            const field = this.element.querySelector(selector);
            if (field && parts[index] !== undefined) {
                field.value = parts[index].trim();
            }
        });
    }

    // --- private helpers ---
    handleCreateSuccess(response) {
        const trimmed = (response || '').trim();
        if (trimmed.length > 0 && this.hasFlashOverlayTarget) {
            this.flashOverlayTarget.innerHTML = trimmed;
            return;
        }
        if (this.hasSuccessUrlValue) {
            window.location.href = this.successUrlValue;
            return;
        }
        window.location.reload();
    }

    disableTemplateInputs() {
        if (!this.hasAddressTemplateTarget) {
            return;
        }
        this.addressTemplateTarget.querySelectorAll('input, select, textarea').forEach((node) => {
            node.disabled = true;
        });
    }

    initAddressLookup() {
        if (!this.hasAddressSearchTarget || !this.hasAddressLookupUrlValue) {
            return;
        }
        this.waitForAutocomplete(() => this.addAutocompleteAddressLookup(this.addressSearchTarget));
    }

    initCityLookup() {
        if (!this.hasAccordionTarget || !this.hasCityLookupUrlValue) {
            return;
        }
        this.waitForAutocomplete(() => {
            this.accordionTarget.querySelectorAll('input[name*="zip-"]').forEach((input) => {
                this.addAutocompleteCityLookup(input);
            });
        });
    }

    addAutocompleteCityLookup(input) {
        if (!input || input.disabled || input.dataset.customersCityLookupAttached === 'true') {
            return;
        }
        const cityUrlTemplate = this.cityLookupUrlValue;
        if (!cityUrlTemplate) {
            return;
        }
        input.dataset.customersCityLookupAttached = 'true';
        // eslint-disable-next-line no-new
        new window.autoComplete({
            selector: () => input,
            threshold: 3,
            cache: true,
            data: {
                src: async (query) => {
                    try {
                        const parentBody = input.closest('.accordion-body');
                        const countryElement = parentBody?.querySelector('select[name*="country-"]');
                        const country = countryElement?.value || '';
                        const url = cityUrlTemplate.replace('placeholder1', country).replace('placeholder2', query);
                        const result = await this.fetchJson(url);
                        return result;
                    } catch (error) {
                        return [];
                    }
                },
                keys: ['search'],
            },
            resultsList: {
                element: (list, data) => {
                    if (!data.results.length) {
                        const message = document.createElement('div');
                        message.setAttribute('class', 'no_result');
                        message.innerHTML = `<span>Found No Results for "${data.query}"</span>`;
                        list.prepend(message);
                    }
                },
                noResults: true,
                maxResults: 50,
            },
            resultItem: {
                highlight: true,
            },
            searchEngine(query, record) {
                return record;
            },
            events: {
                input: {
                    selection: (event) => {
                        const selection = event.detail.selection.value;
                        const parentBody = input.closest('.accordion-body');
                        const target = parentBody?.querySelector('input[name*="city-"]');
                        if (target) {
                            target.value = selection.placeName || '';
                        }
                        input.value = selection.postalCode || '';
                    },
                },
            },
        });
    }

    addAutocompleteAddressLookup(input) {
        if (!input || input.dataset.customersAddressLookupAttached === 'true' || !this.hasAddressLookupUrlValue) {
            return;
        }
        const addressUrlTemplate = this.addressLookupUrlValue;
        input.dataset.customersAddressLookupAttached = 'true';
        // eslint-disable-next-line no-new
        new window.autoComplete({
            selector: () => input,
            threshold: 2,
            cache: true,
            data: {
                src: async (query) => {
                    try {
                        const url = addressUrlTemplate.replace('placeholder', query);
                        const result = await this.fetchJson(url);
                        return result;
                    } catch (error) {
                        return [];
                    }
                },
            },
            resultsList: {
                maxResults: 5,
            },
            resultItem: {
                highlight: true,
                element: (item, data) => {
                    const company = data.value.company || '';
                    item.innerHTML = '';
                    const s1 = document.createElement('span');
                    const s2 = document.createElement('span');
                    if (company.length) {
                        s1.innerText = company;
                        item.appendChild(s1);
                        item.appendChild(document.createElement('br'));
                    }
                    s2.innerText = `${data.value.address}, ${data.value.zip} ${data.value.city}`;
                    item.appendChild(s2);
                },
            },
            searchEngine(query, record) {
                return record;
            },
            events: {
                input: {
                    selection: (event) => {
                        const selection = event.detail.selection.value;
                        this.fillAddressFields(selection);
                    },
                },
            },
        });
    }

    fillAddressFields(item) {
        const panel = this.addAddressPanel(true);
        if (!panel) {
            return;
        }
        panel.querySelectorAll('select[name^="addresstype-"]').forEach((node) => {
            node.value = item.type || '';
        });
        panel.querySelectorAll('input[name^="company-"]').forEach((node) => {
            node.value = item.company || '';
        });
        panel.querySelectorAll('input[name^="address-"]').forEach((node) => {
            node.value = item.address || '';
        });
        panel.querySelectorAll('input[name^="zip-"]').forEach((node) => {
            node.value = item.zip || '';
        });
        panel.querySelectorAll('input[name^="city-"]').forEach((node) => {
            node.value = item.city || '';
        });
        panel.querySelectorAll('select[name^="country-"]').forEach((node) => {
            node.value = item.country || node.value;
        });
        panel.querySelectorAll('input[name^="phone-"]').forEach((node) => {
            node.value = item.phone || '';
        });
        panel.querySelectorAll('input[name^="fax-"]').forEach((node) => {
            node.value = item.fax || '';
        });
        panel.querySelectorAll('input[name^="mobilephone-"]').forEach((node) => {
            node.value = item.mobile_phone || '';
        });
        panel.querySelectorAll('input[name^="email-"]').forEach((node) => {
            node.value = item.email || '';
        });
    }

    addAddressPanel(showNew = false) {
        if (!this.hasAddressTemplateTarget || !this.hasAccordionTarget) {
            return null;
        }
        const template = this.addressTemplateTarget.querySelector('.accordion-item');
        if (!template) {
            return null;
        }
        const clone = template.cloneNode(true);
        const newId = this.accordionTarget.querySelectorAll('.addressfields').length + 1;
        const collapse = clone.querySelector('.accordion-collapse');
        const heading = clone.querySelector('.accordion-header');
        const button = clone.querySelector('button');
        if (collapse) {
            collapse.id = `address${newId}`;
            collapse.setAttribute('aria-labelledby', `address-heading-${newId}`);
            collapse.setAttribute('data-bs-parent', '#accordion');
        }
        if (heading) {
            heading.id = `address-heading-${newId}`;
        }
        if (button) {
            button.setAttribute('data-bs-target', `#address${newId}`);
            button.setAttribute('aria-controls', `address${newId}`);
        }
        clone.querySelectorAll('input, select, textarea').forEach((node) => {
            node.disabled = false;
        });
        const firstPanel = this.accordionTarget.querySelector('#firstPanel');
        if (firstPanel && firstPanel.parentElement) {
            firstPanel.parentElement.insertBefore(clone, firstPanel.nextSibling);
        } else {
            this.accordionTarget.appendChild(clone);
        }
        if (collapse && window.bootstrap) {
            const bsCollapse = new window.bootstrap.Collapse(collapse, { toggle: false });
            if (showNew) {
                bsCollapse.show();
            }
        } else if (collapse && showNew) {
            collapse.classList.add('show');
        }
        if (collapse) {
            this.addAutocompleteCityLookup(collapse.querySelector('input[name*="zip-"]'));
        }
        return clone;
    }

    waitForAutocomplete(callback, attempt = 0) {
        if (window.autoComplete) {
            callback();
            return;
        }
        if (attempt > 10) {
            return;
        }
        window.setTimeout(() => this.waitForAutocomplete(callback, attempt + 1), 200);
    }

    async fetchJson(url) {
        return new Promise((resolve) => {
            httpRequest({
                url,
                method: 'GET',
                loader: false,
                onSuccess: (data) => {
                    try {
                        resolve(JSON.parse(data));
                    } catch (e) {
                        resolve([]);
                    }
                },
                onError: () => resolve([]),
            });
        });
    }

    preselectLastname() {
        if (this.modeValue !== 'create') {
            return;
        }
        const lastnameInput = this.element.querySelector('input[name^="lastname-"]');
        const searchInput = document.getElementById('lastname') || document.getElementById('search');
        // preselect lastname from search input field
        if (lastnameInput && searchInput && !lastnameInput.value) {
            lastnameInput.value = (searchInput.value || '').trim();
        }
    }
}
