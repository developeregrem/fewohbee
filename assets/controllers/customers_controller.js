import { Controller } from '@hotwired/stimulus';
import { request as httpRequest } from '../js/http.js';
import { setModalTitle } from '../js/utils.js';

/* stimulusFetch: 'lazy' */

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
        if (title) setModalTitle(title);
        const target = document.getElementById('modal-content-ajax');
        
        httpRequest({
            url,
            method: 'GET',
            target,
            onSuccess: (html) => {
                target.innerHTML = html;
                // rAF stellt sicher, dass Bootstrap das neue DOM
                // registriert hat, bevor Listener angehängt werden
                requestAnimationFrame(() => initReservationHistoryPagination());
            }
        });
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

function initReservationHistoryPagination() {
    const perPage = 10;
    const tbody = document.querySelector('#reservationHistoryCollapse tbody');
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr'));

    // Alten Pager aus vorherigem Modal-Aufruf entfernen
    const oldPager = document.getElementById('res-history-pager');
    if (oldPager) oldPager.remove();

    if (rows.length <= perPage) return;

    let currentPage = 1;
    const pages = Math.ceil(rows.length / perPage);

    function showPage(page) {
        currentPage = page;
        rows.forEach((row, i) => {
            row.style.display = (i >= (page-1)*perPage && i < page*perPage) ? '' : 'none';
        });
        renderPager();
    }

    function renderPager() {
        let pager = document.getElementById('res-history-pager');
        if (!pager) {
            pager = document.createElement('div');
            pager.id = 'res-history-pager';
            pager.className = 'px-3 py-2';
            document.querySelector('#reservationHistoryCollapse .accordion-body').appendChild(pager);
        }
        pager.innerHTML = '';
        const ul = document.createElement('ul');
        ul.className = 'pagination pagination-sm mb-0';

        const prev = document.createElement('li');
        prev.className = 'page-item' + (currentPage === 1 ? ' disabled' : '');
        prev.innerHTML = '<a class="page-link" href="#">&laquo;</a>';
        if (currentPage > 1) prev.querySelector('a').addEventListener('click', e => { e.preventDefault(); showPage(currentPage-1); });
        ul.appendChild(prev);

        for (let i = 1; i <= pages; i++) {
            const li = document.createElement('li');
            li.className = 'page-item' + (i === currentPage ? ' active' : '');
            const p = i;
            li.innerHTML = '<a class="page-link" href="#">' + i + '</a>';
            li.querySelector('a').addEventListener('click', e => { e.preventDefault(); showPage(p); });
            ul.appendChild(li);
        }

        const next = document.createElement('li');
        next.className = 'page-item' + (currentPage === pages ? ' disabled' : '');
        next.innerHTML = '<a class="page-link" href="#">&raquo;</a>';
        if (currentPage < pages) next.querySelector('a').addEventListener('click', e => { e.preventDefault(); showPage(currentPage+1); });
        ul.appendChild(next);
        pager.appendChild(ul);
    }

    const collapse = document.getElementById('reservationHistoryCollapse');
    if (collapse) {
        collapse.addEventListener('shown.bs.collapse', () => showPage(1), { once: true });
        if (collapse.classList.contains('show')) showPage(1);
    }
}

