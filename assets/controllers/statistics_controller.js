import { Controller } from '@hotwired/stimulus';
import { request as httpRequest } from './http_controller.js';

export default class extends Controller {
    static targets = [
        'objects',
        'monthlyStart',
        'monthlyEnd',
        'monthlyStartYear',
        'monthlyEndYear',
        'yearlyStartYear',
        'yearlyEndYear',
        'monthlyChart',
        'yearlyChart',
        'invoiceStatusForm',
        'snapshotMonth',
        'snapshotYear',
        'snapshotArrivalsTotal',
        'snapshotOvernightsTotal',
        'snapshotRoomsTotal',
        'snapshotBedsTotal',
        'snapshotUtilization',
        'snapshotWarnings',
        'snapshotArrivalsChart',
        'snapshotOvernightsChart',
        'snapshotByCountryBody',
    ];

    static values = {
        monthlyUrl: String,
        yearlyUrl: String,
        monthlyUtilizationUrl: String,
        yearlyUtilizationUrl: String,
        monthlyOriginUrl: String,
        yearlyOriginUrl: String,
        snapshotUrl: String,
        snapshotArrivalsLabel: String,
        snapshotOvernightsLabel: String,
        snapshotRoomLabel: String,
    };

    connect() {
         const isPreview = document.documentElement.hasAttribute('data-turbo-preview');
         if(isPreview) {
             return;
         }
        if (this.monthlyUrlValue && this.yearlyUrlValue) {
            this.drawMonthlyTurnover();
            this.drawYearlyTurnover();
        }
        if (this.monthlyUtilizationUrlValue && this.yearlyUtilizationUrlValue) {
            this.drawMonthlyUtilization();
            this.drawYearlyUtilization();
        }
        if (this.monthlyOriginUrlValue && this.yearlyOriginUrlValue) {
            this.drawMonthlyOrigin();
            this.drawYearlyOrigin();
        }
        if (this.snapshotUrlValue) {
            this.drawSnapshot();
        }
    }

    // ----- Turnover (Chart.js) -----
    drawMonthlyTurnoverAction(event) {
        if (event) event.preventDefault();
        this.drawMonthlyTurnover();
    }

    drawYearlyTurnoverAction(event) {
        if (event) event.preventDefault();
        this.drawYearlyTurnover();
    }

    async drawMonthlyTurnover() {
        await this.drawTurnoverChart('monthly', this.monthlyUrlValue);
    }

    async drawYearlyTurnover() {
        await this.drawTurnoverChart('yearly', this.yearlyUrlValue);
    }

    async drawTurnoverChart(type, url) {
        this.toggleRefreshSpinner(type, true);
        if (!url || !(await this.waitForChart())) return;
        const canvas = this[`${type}ChartTarget`];
        if (!canvas) return;

        const startYear = parseInt(this[`${type}StartYearTarget`].value, 10);
        const endYear = parseInt(this[`${type}EndYearTarget`].value, 10);
        const params = new URLSearchParams();
        params.append('yearStart', startYear);
        params.append('yearEnd', endYear);
        // add invoice status form data if present
        if (this.hasInvoiceStatusFormTarget) {
            new FormData(this.invoiceStatusFormTarget).forEach((v, k) => params.append(k, v));
        }

        try {
            const response = await fetch(`${url}?${params.toString()}`);
            const data = await response.json();
            const cfg = {
                type: 'bar',
                data: {
                    labels: data.labels,
                    datasets: data.datasets,
                },
                options: {
                    responsive: true,
                    plugins: { legend: { display: false } },
                },
            };

            const existing = window.Chart.getChart(canvas);
            if (existing) {
                existing.destroy();
            }
            new window.Chart(canvas, cfg);
        } finally {
            this.toggleRefreshSpinner(type, false);
        }
    }

    // ----- Utilization (flot line) -----
    drawMonthlyUtilizationAction(event) {
        if (event) event.preventDefault();
        this.drawMonthlyUtilization();
    }

    drawYearlyUtilizationAction(event) {
        if (event) event.preventDefault();
        this.drawYearlyUtilization();
    }

    async drawMonthlyUtilization() {
        await this.drawUtilizationChart('monthly', this.monthlyUtilizationUrlValue, false);
    }

    async drawYearlyUtilization() {
        await this.drawUtilizationChart('yearly', this.yearlyUtilizationUrlValue, true);
    }

    async drawUtilizationChart(type, url, yearOnly) {
        this.toggleRefreshSpinner(type, true);
        if (!url || !(await this.waitForChart())) return;
        const canvas = this[`${type}ChartTarget`];
        if (!canvas) return;   

        try {
            const params = this.utilizationParams(yearOnly);
            const response = await fetch(`${url}?${params.toString()}`);
            const data = await response.json();

            const cfg = {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: data.datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: (context) => `${context.dataset.label}: ${context.parsed.y.toFixed(2)}%`,
                            },
                        },
                    },
                    elements: {
                        line: { tension: 0.2, },
                    },
                    scales: {
                        y: {
                            min: 0,
                            title: { display: true, text: '%' },
                        },
                    },
                },
            };

            const existing = window.Chart.getChart(canvas);
            if (existing) {
                existing.destroy();
            }
            new window.Chart(canvas, cfg);
        } finally {
            this.toggleRefreshSpinner(type, false);
        }
    }

    utilizationParams(yearOnly = false) {
        const params = new URLSearchParams();
        if (this.hasObjectsTarget) {
            params.append('objectId', this.objectsTarget.value);
        }
        if (!yearOnly) {
            params.append('monthStart', this.monthlyStartTarget.value);
            params.append('monthEnd', this.monthlyEndTarget.value);
        }
        params.append('yearStart', (yearOnly ? this.yearlyStartYearTarget : this.monthlyStartYearTarget).value);
        params.append('yearEnd', (yearOnly ? this.yearlyEndYearTarget : this.monthlyEndYearTarget).value);
        return params;
    }

    // ----- Reservation origin (Chart.js pie) -----
    drawMonthlyOriginAction(event) {
        if (event) event.preventDefault();
        this.drawMonthlyOrigin();
    }

    drawYearlyOriginAction(event) {
        if (event) event.preventDefault();
        this.drawYearlyOrigin();
    }

    async drawMonthlyOrigin() {
        await this.drawOriginChart('monthly', this.monthlyOriginUrlValue, false);
    }

    async drawYearlyOrigin() {
        await this.drawOriginChart('yearly', this.yearlyOriginUrlValue, true);
    }

    async drawOriginChart(type, url, yearOnly) {
        this.toggleRefreshSpinner(type, true);
        if (!url || !(await this.waitForChart())) return;
        const canvas = this[`${type}ChartTarget`];
        if (!canvas) return;

        try {
            const params = this.originParams(yearOnly);
            const response = await fetch(`${url}?${params.toString()}`);
            const data = await response.json();

            const cfg = {
                type: 'pie',
                data: {
                    labels: data.labels,
                    datasets: data.datasets,
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: (context) => `${context.label}: ${context.parsed.toLocaleString()}`,
                            },
                        },
                    },
                },
            };

            const existing = window.Chart.getChart(canvas);
            if (existing) existing.destroy();
            new window.Chart(canvas, cfg);
        } finally {
            this.toggleRefreshSpinner(type, false);
        }
    }

    originParams(yearOnly = false) {
        const params = new URLSearchParams();
        if (this.hasObjectsTarget) {
            params.append('objectId', this.objectsTarget.value);
        }
        if (!yearOnly) {
            params.append('monthStart', this.monthlyStartTarget.value);
            params.append('monthEnd', this.monthlyEndTarget.value);
        }
        params.append('yearStart', (yearOnly ? this.yearlyStartYearTarget : this.monthlyStartYearTarget).value);
        params.append('yearEnd', (yearOnly ? this.yearlyEndYearTarget : this.monthlyEndYearTarget).value);
        return params;
    }

    async waitForChart() {
        if (window.Chart) return true;
        const attempts = 10;
        const delay = 75;
        for (let i = 0; i < attempts; i += 1) {
            await new Promise((resolve) => setTimeout(resolve, delay));
            if (window.Chart) return true;
        }
        return false;
    }

    toggleRefreshSpinner(type, active) {
        const id = type === 'yearly' ? 'refreshYearly' : 'refreshMonthly';
        const icon = document.getElementById(id);
        if (!icon) return;
        icon.classList.toggle('fa-spin', active);
        icon.classList.toggle('disabled', active);
    }


    // ----- Tourism snapshot -----
    drawSnapshotAction(event) {
        if (event) event.preventDefault();
        this.drawSnapshot(false);
    }

    drawSnapshotForceAction(event) {
        if (event) event.preventDefault();
        this.drawSnapshot(true);
    }

    async drawSnapshot(force = false) {
        if (!this.snapshotUrlValue) return;
        const params = this.snapshotParams(force);
        const response = await fetch(`${this.snapshotUrlValue}?${params.toString()}`);
        const data = await response.json();
        const countryNames = data.countryNames || {};

        this.updateSnapshotSummary(data.metrics || {});
        this.updateSnapshotWarnings(data.warnings || []);
        this.updateSnapshotByCountryTable(
            (data.metrics && data.metrics.tourism) ? data.metrics.tourism : {},
            countryNames
        );
        await this.drawSnapshotCharts(
            (data.metrics && data.metrics.tourism) ? data.metrics.tourism : {},
            countryNames
        );
    }

    snapshotParams(force = false) {
        const params = new URLSearchParams();
        if (this.hasObjectsTarget) {
            params.append('objectId', this.objectsTarget.value);
        }
        if (this.hasSnapshotMonthTarget) {
            params.append('month', this.snapshotMonthTarget.value);
        }
        if (this.hasSnapshotYearTarget) {
            params.append('year', this.snapshotYearTarget.value);
        }
        if (force) {
            params.append('force', '1');
        }
        return params;
    }

    updateSnapshotSummary(metrics) {
        const inventory = metrics.inventory || {};
        const tourism = metrics.tourism || {};
        const utilization = metrics.utilization || {};

        if (this.hasSnapshotArrivalsTotalTarget) {
            this.snapshotArrivalsTotalTarget.textContent = (tourism.arrivals_total ?? 0).toLocaleString();
        }
        if (this.hasSnapshotOvernightsTotalTarget) {
            this.snapshotOvernightsTotalTarget.textContent = (tourism.overnights_total ?? 0).toLocaleString();
        }
        if (this.hasSnapshotRoomsTotalTarget) {
            this.snapshotRoomsTotalTarget.textContent = (inventory.rooms_total ?? 0).toLocaleString();
        }
        if (this.hasSnapshotBedsTotalTarget) {
            this.snapshotBedsTotalTarget.textContent = (inventory.beds_total ?? 0).toLocaleString();
        }
        if (this.hasSnapshotUtilizationTarget) {
            const util = utilization.month_percent ?? 0;
            this.snapshotUtilizationTarget.textContent = `${util.toFixed(2)}%`;
        }
    }

    updateSnapshotWarnings(warnings) {
        if (!this.hasSnapshotWarningsTarget) return;
        this.snapshotWarningsTarget.innerHTML = '';
        if (!warnings.length) {
            const li = document.createElement('li');
            li.className = 'text-muted';
            li.textContent = this.snapshotWarningsTarget.dataset.emptyText || '';
            this.snapshotWarningsTarget.appendChild(li);
            return;
        }
        warnings.forEach((warning) => {
            const li = document.createElement('li');
            const start = warning.start_date || '';
            const end = warning.end_date || '';
            const roomLabel = this.snapshotRoomLabelValue || '';
            const room = warning.appartment_number ? ` ${roomLabel} ${warning.appartment_number}` : '';
            li.textContent = `${warning.message || ''}${room} ${start} - ${end}`.trim();
            this.snapshotWarningsTarget.appendChild(li);
        });
    }

    updateSnapshotByCountryTable(tourism, countryNames) {
        if (!this.hasSnapshotByCountryBodyTarget) return;
        const arrivals = tourism.arrivals_by_country || {};
        const overnights = tourism.overnights_by_country || {};
        const countries = Array.from(new Set([
            ...Object.keys(arrivals),
            ...Object.keys(overnights),
        ])).sort();

        this.snapshotByCountryBodyTarget.innerHTML = '';
        countries.forEach((country) => {
            const label = this.mapCountryLabel(country, countryNames);
            const tr = document.createElement('tr');
            const tdCountry = document.createElement('td');
            const tdArrivals = document.createElement('td');
            const tdOvernights = document.createElement('td');
            tdCountry.textContent = label;
            tdArrivals.textContent = (arrivals[country] ?? 0).toLocaleString();
            tdOvernights.textContent = (overnights[country] ?? 0).toLocaleString();
            tr.appendChild(tdCountry);
            tr.appendChild(tdArrivals);
            tr.appendChild(tdOvernights);
            this.snapshotByCountryBodyTarget.appendChild(tr);
        });
    }

    async drawSnapshotCharts(tourism, countryNames) {
        if (!(await this.waitForChart())) return;
        const arrivals = tourism.arrivals_by_country || {};
        const overnights = tourism.overnights_by_country || {};
        const codes = Array.from(new Set([
            ...Object.keys(arrivals),
            ...Object.keys(overnights),
        ])).sort();
        const labels = codes;
        const arrivalsData = codes.map((code) => arrivals[code] ?? 0);
        const overnightsData = codes.map((code) => overnights[code] ?? 0);

        if (this.hasSnapshotArrivalsChartTarget) {
            const canvas = this.snapshotArrivalsChartTarget;
            const existing = window.Chart.getChart(canvas);
            if (existing) existing.destroy();
            new window.Chart(canvas, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [
                        {
                            label: this.snapshotArrivalsLabelValue || 'Arrivals',
                            data: arrivalsData,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    plugins: { legend: { display: false } },
                },
            });
        }

        if (this.hasSnapshotOvernightsChartTarget) {
            const canvas = this.snapshotOvernightsChartTarget;
            const existing = window.Chart.getChart(canvas);
            if (existing) existing.destroy();
            new window.Chart(canvas, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [
                        {
                            label: this.snapshotOvernightsLabelValue || 'Overnights',
                            data: overnightsData,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    plugins: { legend: { display: false } },
                },
            });
        }
    }

    mapCountryLabel(code, countryNames) {
        if (!code) return '';
        const upper = code.toUpperCase();
        return countryNames[upper] || countryNames[code] || code;
    }
}
