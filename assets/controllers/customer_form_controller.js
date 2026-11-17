import { Controller } from '@hotwired/stimulus';
import { Collapse } from 'bootstrap';
import { request as httpRequest } from '../js/http.js';

/* stimulusFetch: 'lazy' */

export default class extends Controller {
    static targets = [
        'accordion',
        'addressTemplate',
        'flashOverlay',
        'deleteBox',
        'defaultBox',
    ];

    static values = {
        submitUrl: String,
        successUrl: String,
        mode: { type: String, default: 'edit' },
        deleteUrl: String,
    };

    connect() {
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

    /* A place picked for a postal code (<twig:Autocomplete> on the zip field) */
    fillCityAction(event) {
        const { item } = event.detail;
        const input = event.target;
        const city = input.closest('.accordion-body')?.querySelector('input[name*="city-"]');
        if (city) {
            city.value = item.placeName || '';
        }
        input.value = item.postalCode || input.value;
    }

    /* An existing address picked in the address search: copied into a new address panel */
    fillAddressAction(event) {
        this.fillAddressFields(event.detail.item);
        event.target.value = '';
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
        const template = this.addressTemplateTarget.content.querySelector('.accordion-item');
        if (!template) {
            return null;
        }
        const clone = document.importNode(template, true);
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
        const firstPanel = this.accordionTarget.querySelector('#firstPanel');
        if (firstPanel && firstPanel.parentElement) {
            firstPanel.parentElement.insertBefore(clone, firstPanel.nextSibling);
        } else {
            this.accordionTarget.appendChild(clone);
        }
        if (collapse) {
            const bsCollapse = new Collapse(collapse, { toggle: false });
            if (showNew) {
                bsCollapse.show();
            }
        } else if (collapse && showNew) {
            collapse.classList.add('show');
        }
        return clone;
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
