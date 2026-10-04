import { Controller } from '@hotwired/stimulus';
import { request } from '../js/http.js';
import { enableDeletePopover } from '../js/utils.js';

/**
 * "Online check-in" tab of the reservation dialog.
 *
 * The link is fetched on demand instead of being rendered into the page, so it only reaches
 * users allowed to hand it out. While reviewing, each person card shows the changes for the
 * record picked in its select (all variants are rendered by the server), a record can be picked
 * for one person only, and the "room afterwards" list follows the choices. Confirming or
 * discarding reloads the dialog with the server's answer, as does marking a check-in at the desk.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['linkBox', 'url', 'qrBox', 'qr', 'qrDownload', 'error', 'regenerateBox', 'person', 'room', 'overflow', 'moreOptions'];
    static values = {
        linkUrl: String,
        regenerateUrl: String,
        token: String,
    };

    connect() {
        enableDeletePopover({ root: this.element });
        this.sync();
    }

    sync() {
        if (!this.hasPersonTarget) {
            return;
        }

        const selects = this.personTargets.map((person) => person.querySelector('select'));
        this.preventDoubleAssignment(selects);
        this.personTargets.forEach((person, index) => this.showChanges(person, selects[index]));
        this.updateRoom(selects);

        if (this.hasMoreOptionsTarget) {
            const isBooker = selects[0].value === 'booker';
            this.moreOptionsTarget.classList.toggle('d-none', isBooker);
            const input = this.moreOptionsTarget.querySelector('input');
            input.disabled = isBooker;
            if (isBooker) {
                input.checked = false;
            }
        }
    }

    /** A record chosen for one person is not offered to the others. */
    preventDoubleAssignment(selects) {
        const taken = new Map();
        selects.forEach((select, index) => {
            const key = select.selectedOptions[0]?.dataset.key;
            if (key) {
                taken.set(key, index);
            }
        });
        selects.forEach((select, index) => {
            for (const option of select.options) {
                const key = option.dataset.key;
                option.disabled = option.value === '' || (key !== undefined && taken.has(key) && taken.get(key) !== index);
            }
        });
    }

    showChanges(person, select) {
        const summary = person.querySelector('[data-person-summary]');
        let shown = null;
        person.querySelectorAll('[data-diff-for]').forEach((diff) => {
            const visible = diff.dataset.diffFor === select.value;
            diff.classList.toggle('d-none', !visible);
            if (visible) {
                shown = diff;
            }
        });
        summary.textContent = select.value === '' || !shown
            ? summary.dataset.empty
            : `${select.selectedOptions[0].text.trim()} · ${shown.dataset.summary}`;
        summary.classList.toggle('text-warning-emphasis', select.value === '');
        summary.classList.toggle('text-body-secondary', select.value !== '');
    }

    /**
     * Mirrors GuestCheckInApplyService: linked guests matched by a person stay (checked in), the
     * others stay unless marked as not travelling, everybody else is added while places last.
     */
    updateRoom(selects) {
        if (!this.hasRoomTarget) {
            return;
        }

        const matched = new Set();
        const added = [];
        selects.forEach((select, index) => {
            const roomGuest = select.selectedOptions[0]?.dataset.roomGuest;
            if (roomGuest) {
                matched.add(roomGuest);
            }
            const isAdded = select.value !== '' && select.value !== 'skip' && !roomGuest;
            const item = this.roomTarget.querySelector(`[data-room-person="${index}"]`);
            item.classList.toggle('d-none', !isAdded);
            if (isAdded) {
                added.push(item);
            }
        });

        let count = 0;
        this.roomTarget.querySelectorAll('[data-room-guest]').forEach((item) => {
            const isMatched = matched.has(item.dataset.roomGuest);
            const remove = item.querySelector('[data-remove] input');
            remove.disabled = isMatched;
            if (isMatched) {
                remove.checked = false;
            }
            item.querySelector('[data-remove]').classList.toggle('d-none', isMatched);
            item.querySelector('[data-checked-in]').classList.toggle('d-none', !isMatched);
            item.querySelector('[data-name]').classList.toggle('text-decoration-line-through', remove.checked);
            item.classList.toggle('text-body-secondary', remove.checked);
            if (!remove.checked) {
                count += 1;
            }
        });

        const capacity = Number(this.roomTarget.dataset.capacity);
        let overflow = false;
        added.forEach((item) => {
            count += 1;
            const noPlace = count > capacity;
            overflow ||= noPlace;
            item.querySelector('[data-no-place]').classList.toggle('d-none', !noPlace);
        });
        this.overflowTarget.classList.toggle('d-none', !overflow);
    }

    showLink(event) {
        event.preventDefault();
        this.fetchLink(this.linkUrlValue, () => this.linkBoxTarget.classList.remove('d-none'));
    }

    showQr(event) {
        event.preventDefault();
        this.fetchLink(this.linkUrlValue, () => this.qrBoxTarget.classList.remove('d-none'));
    }

    regenerate(event) {
        event.preventDefault();
        this.fetchLink(this.regenerateUrlValue, () => {
            this.linkBoxTarget.classList.remove('d-none');
            if (this.hasRegenerateBoxTarget) {
                window.bootstrap?.Collapse.getOrCreateInstance(this.regenerateBoxTarget).hide();
            }
        });
    }

    /** Sends a form of the tab (confirm, desk check-in) and shows the dialog the server returns. */
    post(event) {
        event.preventDefault();
        const form = event.target.closest('form');
        request({
            url: form.action,
            method: 'POST',
            data: new FormData(form),
            target: document.getElementById('modal-content-ajax'),
        });
    }

    fetchLink(url, onShown) {
        this.errorTarget.textContent = '';
        request({
            url,
            method: 'POST',
            data: { _token: this.tokenValue },
            loader: false,
            onSuccess: (text) => {
                const data = JSON.parse(text);
                this.urlTarget.value = data.url;
                this.qrTarget.src = data.qr;
                this.qrDownloadTarget.href = data.qr;
                onShown();
            },
            onError: (message) => {
                let error = message;
                try {
                    error = JSON.parse(message).error || message;
                } catch (e) {
                    // not JSON, show as is
                }
                this.errorTarget.textContent = error;
            },
        });
    }
}
