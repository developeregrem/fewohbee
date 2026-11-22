import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.root = document.documentElement;
        this.preference = this.root.getAttribute('data-theme-preference') || 'auto';
        this.mediaQuery = null;
        this.mediaListener = null;
        this.applyTheme();
        if (this.preference === 'auto') {
            this.bindMediaListener();
        }
    }

    disconnect() {
        this.unbindMediaListener();
    }

    applyTheme() {
        const theme = this.getPreferredTheme();
        this.root.setAttribute('data-bs-theme', theme);
        this.applyBarColour();
    }

    /**
     * The browser's bar and the status bar of the installed app take the colour of the top bar
     * (the theme's surface), in the light or the dark scheme as chosen.
     */
    applyBarColour() {
        const colour = getComputedStyle(this.root).getPropertyValue('--feb-surface').trim();
        if (colour === '') {
            return;
        }
        let meta = document.head.querySelector('meta[name="theme-color"]');
        if (!meta) {
            meta = document.createElement('meta');
            meta.name = 'theme-color';
            document.head.append(meta);
        }
        meta.content = colour;
    }

    getPreferredTheme() {
        if (this.preference === 'dark' || this.preference === 'light') {
            return this.preference;
        }
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            return 'dark';
        }
        return 'light';
    }

    bindMediaListener() {
        if (!window.matchMedia) {
            return;
        }
        this.mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        this.mediaListener = () => this.applyTheme();
        if (this.mediaQuery.addEventListener) {
            this.mediaQuery.addEventListener('change', this.mediaListener);
        }
    }

    unbindMediaListener() {
        if (!this.mediaQuery || !this.mediaListener) {
            return;
        }
        if (this.mediaQuery.removeEventListener) {
            this.mediaQuery.removeEventListener('change', this.mediaListener);
        }
        this.mediaQuery = null;
        this.mediaListener = null;
    }
}
