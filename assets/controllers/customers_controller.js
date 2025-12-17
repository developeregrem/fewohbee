import { Controller } from '@hotwired/stimulus';
import { request as httpRequest, getContentForModal } from './http_controller.js';
import { setModalTitle } from './utils_controller.js';

export default class extends Controller {
    static targets = ['searchForm', 'searchInput', 'table', 'page'];
    static values = {
        searchUrl: String,
    };

    connect() {
        this.debounceTimer = null;
        if (this.hasSearchInputTarget && this.searchInputTarget.value.trim() !== '') {
            this.searchAction();
        }
    }

    searchAction(event) {
        if (event) {
            event.preventDefault();
        }
        if (this.hasPageTarget) {
            this.pageTarget.value = this.pageTarget.value || '1';
        }
        this.performSearch();
    }

    searchInputAction(event) {
        if (event) {
            event.preventDefault();
        }
        if (this.hasPageTarget) {
            this.pageTarget.value = '1';
        }
        clearTimeout(this.debounceTimer);
        this.debounceTimer = window.setTimeout(() => this.performSearch(), 400);
    }

    paginateAction(event) {
        if (event) {
            event.preventDefault();
        }
        const page = event?.currentTarget?.dataset.page || null;
        if (page && this.hasPageTarget) {
            this.pageTarget.value = page;
        }
        this.performSearch();
    }

    openModalAction(event) {
        event.preventDefault();
        const url = event.currentTarget.dataset.url;
        const title = event.currentTarget.dataset.title || '';
        if (!url) {
            return;
        }
        setModalTitle(title);
        getContentForModal(url, title);
    }

    performSearch() {
        if (!this.hasSearchFormTarget || !this.hasTableTarget) {
            return;
        }
        const url = this.searchUrlValue || this.searchFormTarget.dataset.searchUrl || null;
        if (!url) {
            return;
        }
        const data = new FormData(this.searchFormTarget);
        if (this.hasPageTarget) {
            data.set('page', this.pageTarget.value || '1');
        }
        httpRequest({
            url,
            method: 'POST',
            data,
            target: this.tableTarget,
            onSuccess: (response) => {
                this.tableTarget.innerHTML = response;
            },
        });
    }
}
