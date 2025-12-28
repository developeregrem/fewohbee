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
    ];

    static values = {
        monthlyUrl: String,
        yearlyUrl: String,
        monthlyUtilizationUrl: String,
        yearlyUtilizationUrl: String,
        monthlyOriginUrl: String,
        yearlyOriginUrl: String,
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
        if (!url || !(await this.waitForChart())) return;
        const canvas = this[`${type}ChartTarget`];
        if (!canvas) return;
        this.toggleRefreshSpinner(type, true);

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
        if (!url || !(await this.waitForChart())) return;
        const canvas = this[`${type}ChartTarget`];
        if (!canvas) return;
        this.toggleRefreshSpinner(type, true);

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
        if (!url || !(await this.waitForChart())) return;
        const canvas = this[`${type}ChartTarget`];
        if (!canvas) return;
        this.toggleRefreshSpinner(type, true);

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
}
