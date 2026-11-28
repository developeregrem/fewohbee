/**
 * Availability calendar for the public booking page (modern theme).
 *
 * Plain JS on purpose — the public page is served without AssetMapper, so no
 * Stimulus controller is available here. The calendar is a progressive
 * enhancement: the date inputs stay in the DOM, and without JavaScript the guest
 * simply types the dates as before.
 *
 * Availability comes from /book/calendar-data as per-night booleans. Nights are
 * half-open like the backend: a departure day is not an occupied night, so the
 * guest can arrive on the day another guest leaves.
 *
 * Months are paged, not appended: the visible window is replaced when navigating,
 * which keeps the widget at a constant height. That matters most when embedded,
 * where the host sizes the iframe to the content and a growing calendar would
 * make the whole page jump.
 */
(function () {
    'use strict';

    var MS_PER_DAY = 86400000;
    /** Mirrors PublicBookingCalendarService::MAX_MONTHS_PER_REQUEST. */
    var MAX_MONTHS_PER_REQUEST = 3;
    /** Longest stay we pre-load nights for while a departure is still open. */
    var MAX_SPAN_MONTHS = 6;
    /** Mirrors ReservationPeriodService::MAX_NIGHTS closely enough to bound the walk. */
    var MAX_STAY_NIGHTS = 366;

    function toKey(date) {
        var month = String(date.getMonth() + 1).padStart(2, '0');
        var day = String(date.getDate()).padStart(2, '0');
        return date.getFullYear() + '-' + month + '-' + day;
    }

    function fromKey(key) {
        var parts = key.split('-');
        return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    }

    function addDays(date, days) {
        var next = new Date(date.getTime());
        next.setDate(next.getDate() + days);
        return next;
    }

    function addMonths(date, months) {
        return new Date(date.getFullYear(), date.getMonth() + months, 1);
    }

    function startOfMonth(date) {
        return new Date(date.getFullYear(), date.getMonth(), 1);
    }

    function monthKey(date) {
        return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0');
    }

    /** Whole months between two month starts. */
    function monthDiff(from, to) {
        return (to.getFullYear() - from.getFullYear()) * 12 + (to.getMonth() - from.getMonth());
    }

    function Calendar(root) {
        this.root = root;
        this.endpoint = root.dataset.fhbCalendar;
        this.labels = JSON.parse(root.dataset.fhbCalendarLabels || '{}');
        this.locale = root.dataset.fhbCalendarLocale || 'de';
        this.monthsAhead = Math.max(1, parseInt(root.dataset.fhbCalendarMonths || '12', 10));
        // Measure the widget, not the viewport: embedded in an iframe the viewport is
        // whatever width the host gave us, which rarely matches a device breakpoint.
        this.visibleMonths = root.getBoundingClientRect().width >= 560 ? 2 : 1;

        this.grid = root.querySelector('[data-fhb-calendar-grid]');
        this.statusEl = root.querySelector('[data-fhb-calendar-status]');
        this.prevButton = root.querySelector('[data-fhb-calendar-prev]');
        this.nextButton = root.querySelector('[data-fhb-calendar-next]');
        this.monthSelect = root.querySelector('[data-fhb-calendar-jump]');
        this.fromInput = document.getElementById('dateFrom');
        this.toInput = document.getElementById('dateTo');
        this.roomInput = document.querySelector('[data-fhb-room-input]');

        /** night key => true when bookable; missing means "not loaded yet". */
        this.nights = {};
        this.loadedMonths = {};
        this.room = null;
        /** The server's today: it validates the stay, so the guest's clock may not undercut it. */
        this.serverToday = root.dataset.fhbCalendarToday || null;
        this.todayKey = this.currentToday();
        /** Exclusive end of the booking horizon once the server reported it. */
        this.horizonEnd = null;
        this.firstMonth = startOfMonth(new Date());
        this.lastMonth = addMonths(this.firstMonth, this.monthsAhead - 1);
        this.viewMonth = this.firstMonth;
        this.selectionStart = null;
        this.selectionEnd = null;
        this.hoverEnd = null;
        this.loading = false;

        this.buildMonthOptions();
        this.bindRoomSwitcher();
        this.bindNavigation();
        this.bindGrid();

        this.restoreRoom();
        this.restoreSelection();
        this.takeOverDateFields();
    }

    /**
     * The first day a stay may start: the later of the server's and the guest's
     * today. A browser a day behind the server would otherwise offer a day the
     * server rejects; one ahead simply moves on at its own midnight.
     */
    Calendar.prototype.currentToday = function () {
        var local = toKey(new Date());
        return this.serverToday !== null && this.serverToday > local ? this.serverToday : local;
    };

    /* ── Month navigation ─────────────────────────────────────────────── */

    Calendar.prototype.buildMonthOptions = function () {
        if (!this.monthSelect) {
            return;
        }

        for (var i = 0; i < this.monthsAhead; i++) {
            var month = addMonths(this.firstMonth, i);
            var option = document.createElement('option');
            option.value = monthKey(month);
            option.textContent = month.toLocaleDateString(this.locale, { month: 'long', year: 'numeric' });
            this.monthSelect.appendChild(option);
        }
    };

    Calendar.prototype.bindNavigation = function () {
        var self = this;

        if (this.prevButton) {
            this.prevButton.addEventListener('click', function (event) {
                event.preventDefault();
                self.goTo(addMonths(self.viewMonth, -self.visibleMonths));
            });
        }

        if (this.nextButton) {
            this.nextButton.addEventListener('click', function (event) {
                event.preventDefault();
                self.goTo(addMonths(self.viewMonth, self.visibleMonths));
            });
        }

        if (this.monthSelect) {
            this.monthSelect.addEventListener('change', function () {
                var parts = self.monthSelect.value.split('-');
                self.goTo(new Date(Number(parts[0]), Number(parts[1]) - 1, 1));
            });
        }
    };

    /** Move the visible window, clamped to today and the booking horizon. */
    Calendar.prototype.goTo = function (month) {
        var latestStart = addMonths(this.lastMonth, -(this.visibleMonths - 1));
        if (latestStart < this.firstMonth) {
            latestStart = this.firstMonth;
        }

        if (month < this.firstMonth) {
            month = this.firstMonth;
        }
        if (month > latestStart) {
            month = latestStart;
        }

        this.viewMonth = month;
        this.render();
        this.ensureLoaded();
    };

    Calendar.prototype.updateNavigation = function () {
        var latestStart = addMonths(this.lastMonth, -(this.visibleMonths - 1));

        if (this.prevButton) {
            this.prevButton.disabled = monthDiff(this.firstMonth, this.viewMonth) <= 0;
        }
        if (this.nextButton) {
            this.nextButton.disabled = monthDiff(this.viewMonth, latestStart) <= 0;
        }
        if (this.monthSelect) {
            this.monthSelect.value = monthKey(this.viewMonth);
        }
    };

    /* ── Rooms ────────────────────────────────────────────────────────── */

    Calendar.prototype.bindRoomSwitcher = function () {
        var self = this;

        this.root.querySelectorAll('[data-fhb-room]').forEach(function (element) {
            if (element.tagName === 'OPTION') {
                return; // the select's change handler below owns these
            }
            element.addEventListener('click', function (event) {
                event.preventDefault();
                self.selectRoom(element.dataset.fhbRoom, element);
            });
        });

        var select = this.root.querySelector('[data-fhb-room-select]');
        if (select) {
            select.addEventListener('change', function () {
                self.selectRoom(select.value, null);
            });
        }
    };

    /**
     * Re-select the accommodation the guest had chosen before submitting.
     *
     * Every step posts the room back and the server renders it into the hidden
     * field. Without this the widget would fall back to the first room and the page
     * would price — and judge the capacity of — the wrong one.
     */
    Calendar.prototype.restoreRoom = function () {
        var previous = this.roomInput ? this.roomInput.value : '';
        var candidates = this.root.querySelectorAll('[data-fhb-room]');
        var initial = null;

        if (previous) {
            for (var i = 0; i < candidates.length; i++) {
                if (candidates[i].dataset.fhbRoom === previous) {
                    initial = candidates[i];
                    break;
                }
            }
        }

        if (!initial && candidates.length > 0) {
            initial = candidates[0];
        }
        if (!initial) {
            return;
        }

        var select = this.root.querySelector('[data-fhb-room-select]');
        if (select) {
            select.value = initial.dataset.fhbRoom;
        }

        this.selectRoom(initial.dataset.fhbRoom, initial);
    };

    Calendar.prototype.selectRoom = function (roomUuid, element) {
        if (!roomUuid || roomUuid === this.room) {
            return;
        }

        this.room = roomUuid;
        // Travels with every step: the booking flow resolves the room from it.
        if (this.roomInput) {
            this.roomInput.value = roomUuid;
        }
        this.nights = {};
        this.loadedMonths = {};
        this.clearSelection();

        var capacity = 0;
        this.root.querySelectorAll('[data-fhb-room]').forEach(function (candidate) {
            var active = candidate.dataset.fhbRoom === roomUuid;
            candidate.classList.toggle('is-active', active);
            if (candidate.tagName !== 'OPTION') {
                candidate.setAttribute('aria-pressed', active ? 'true' : 'false');
            }
            if (active) {
                capacity = parseInt(candidate.dataset.fhbCapacity || '0', 10);
            }
        });

        // The guest form needs the bed count of whichever room is now selected to
        // judge whether the party fits.
        document.dispatchEvent(new CustomEvent('fhb:room-changed', { detail: { capacity: capacity } }));

        this.render();
        this.ensureLoaded();
    };

    /* ── Availability ─────────────────────────────────────────────────── */

    /**
     * Fetch whatever the current view needs and is not cached yet.
     *
     * Requests run in chunks because the endpoint serves at most a few months at a
     * time, and only the months the server actually returned are marked as loaded —
     * marking more would leave unknown nights looking occupied.
     */
    Calendar.prototype.ensureLoaded = function () {
        var self = this;
        if (this.loading || !this.room) {
            return;
        }

        var range = this.requiredRange();
        var missing = null;
        for (var cursor = range.start; cursor <= range.end; cursor = addMonths(cursor, 1)) {
            if (!this.loadedMonths[monthKey(cursor)]) {
                missing = cursor;
                break;
            }
        }

        if (missing === null) {
            return;
        }

        var count = Math.min(MAX_MONTHS_PER_REQUEST, monthDiff(missing, range.end) + 1);

        this.loading = true;
        this.setStatusIfIdle(this.labels.loading || '');

        var url = this.endpoint
            + (this.endpoint.indexOf('?') === -1 ? '?' : '&')
            + 'room=' + encodeURIComponent(this.room)
            + '&from=' + encodeURIComponent(monthKey(missing))
            + '&months=' + count;

        var room = this.room;
        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('unavailable');
                }
                return response.json();
            })
            .then(function (data) {
                // The guest switched rooms while this was in flight: these nights belong
                // to the previous room. Drop them and load the current one instead.
                if (room !== self.room) {
                    self.loading = false;
                    self.ensureLoaded();
                    return;
                }

                self.applyCalendarEnd(data);
                Object.keys(data.nights || {}).forEach(function (night) {
                    self.nights[night] = data.nights[night] === 1;
                });

                // Trust the response, not the request: the server clamps the window.
                var loaded = startOfMonth(fromKey(data.from));
                var end = fromKey(data.toExclusive);
                while (loaded < end) {
                    self.loadedMonths[monthKey(loaded)] = true;
                    loaded = addMonths(loaded, 1);
                }

                self.loading = false;
                // Also clamps a stale view when the server reached the horizon.
                self.goTo(self.viewMonth);
                self.describeSelection();
            })
            .catch(function () {
                // The date inputs remain the source of truth, so a failed load costs
                // the guest nothing beyond the calendar itself.
                self.loading = false;
                if (room !== self.room) {
                    self.ensureLoaded();
                    return;
                }
                self.setStatus(self.labels.unavailable || '');
            });
    };

    /**
     * Treat the server's pagination boundary as the regular end of the calendar.
     *
     * The rendered month count already prevents the usual extra request. This is
     * still authoritative when settings changed after the page was opened or a
     * cached page carries an older horizon.
     */
    Calendar.prototype.applyCalendarEnd = function (data) {
        if (data.hasMore !== false || !data.toExclusive) {
            return;
        }

        this.horizonEnd = data.toExclusive;

        // toExclusive is a departure boundary, so the preceding day identifies the
        // final month that contains at least one bookable night.
        var lastBookableMonth = startOfMonth(addDays(fromKey(data.toExclusive), -1));
        if (lastBookableMonth < this.firstMonth) {
            lastBookableMonth = this.firstMonth;
        }
        if (lastBookableMonth >= this.lastMonth) {
            return;
        }

        this.lastMonth = lastBookableMonth;
        if (this.monthSelect) {
            var lastKey = monthKey(this.lastMonth);
            Array.from(this.monthSelect.options).forEach(function (option) {
                if (option.value > lastKey) {
                    option.remove();
                }
            });
        }
    };

    /**
     * The months that must be known right now.
     *
     * Normally that is the visible window. While an arrival is picked but the stay
     * is still open, it reaches back to the arrival month: the range check walks
     * every night in between, and jumping here through the month list would
     * otherwise skip them and make a perfectly free period look occupied.
     */
    Calendar.prototype.requiredRange = function () {
        var start = this.viewMonth;
        var end = addMonths(this.viewMonth, this.visibleMonths - 1);
        if (end > this.lastMonth) {
            end = this.lastMonth;
        }

        if (this.selectionStart && !this.selectionEnd) {
            var arrival = startOfMonth(fromKey(this.selectionStart));
            // Beyond this distance the guest is starting over rather than booking a
            // stay of that length, so there is nothing worth pre-loading.
            if (arrival < start && monthDiff(arrival, end) <= MAX_SPAN_MONTHS) {
                start = arrival;
            }
        }

        return { start: start, end: end };
    };

    /* ── Rendering ────────────────────────────────────────────────────── */

    Calendar.prototype.render = function () {
        this.grid.textContent = '';
        for (var i = 0; i < this.visibleMonths; i++) {
            var month = addMonths(this.viewMonth, i);
            if (month > this.lastMonth) {
                break;
            }
            this.grid.appendChild(this.buildMonth(month));
        }
        this.paint();
        this.updateNavigation();
    };

    Calendar.prototype.buildMonth = function (month) {
        var wrapper = document.createElement('div');
        wrapper.className = 'fhb-cal-month';

        var caption = document.createElement('div');
        caption.className = 'fhb-cal-caption';
        caption.textContent = month.toLocaleDateString(this.locale, { month: 'long', year: 'numeric' });
        wrapper.appendChild(caption);

        var head = document.createElement('div');
        head.className = 'fhb-cal-weekdays';
        // Monday-first: 2024-01-01 was a Monday, so it seeds the weekday names.
        for (var d = 0; d < 7; d++) {
            var name = document.createElement('span');
            name.textContent = new Date(2024, 0, 1 + d).toLocaleDateString(this.locale, { weekday: 'short' });
            head.appendChild(name);
        }
        wrapper.appendChild(head);

        var days = document.createElement('div');
        days.className = 'fhb-cal-days';

        var first = new Date(month.getFullYear(), month.getMonth(), 1);
        var leading = (first.getDay() + 6) % 7;
        for (var lead = 0; lead < leading; lead++) {
            var filler = document.createElement('span');
            filler.className = 'fhb-cal-day is-empty';
            days.appendChild(filler);
        }

        var cursor = new Date(first.getTime());
        while (cursor.getMonth() === month.getMonth()) {
            var cell = document.createElement('button');
            cell.type = 'button';
            cell.className = 'fhb-cal-day';
            cell.dataset.fhbDay = toKey(cursor);
            cell.textContent = String(cursor.getDate());
            // A bare day number means nothing to a screen reader.
            cell.setAttribute('aria-label', cursor.toLocaleDateString(this.locale, {
                weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
            }));
            days.appendChild(cell);
            cursor = addDays(cursor, 1);
        }

        wrapper.appendChild(days);
        return wrapper;
    };

    Calendar.prototype.paint = function () {
        var self = this;
        // Re-read on every paint: a page left open past midnight must not keep
        // offering yesterday, nor hold on to an arrival that has passed since.
        this.todayKey = this.currentToday();
        if (this.selectionStart && this.selectionStart < this.todayKey) {
            this.clearSelection();
            this.clearForm();
            this.describeSelection();
        }
        var end = this.selectionEnd || this.hoverEnd;
        var rangeValid = this.selectionStart && end && this.canDepart(this.selectionStart, end);
        // While the departure is open, only days up to the first occupied night can
        // end the stay. That day itself is fine: the next guest arrives after we leave.
        var departing = this.selectionStart && !this.selectionEnd;
        var lastDeparture = departing ? this.lastDepartureFrom(this.selectionStart) : null;

        this.grid.querySelectorAll('[data-fhb-day]').forEach(function (cell) {
            var key = cell.dataset.fhbDay;
            var free = self.isNightFree(key);
            // Past days and days beyond the horizon were never bookable — grey them
            // out instead of striking them through like an actual booking.
            var outside = key < self.todayKey || (self.horizonEnd !== null && key >= self.horizonEnd);
            // A possible or chosen departure is never drawn as unavailable, even when
            // its own night is taken or lies past the horizon.
            var departure = key === self.selectionEnd
                || (departing && lastDeparture !== null && key > self.selectionStart && key <= lastDeparture);

            cell.classList.toggle('is-today', key === self.todayKey);
            if (key === self.todayKey) {
                cell.setAttribute('aria-current', 'date');
            } else {
                cell.removeAttribute('aria-current');
            }
            cell.classList.toggle('is-outside', outside && !departure);
            cell.classList.toggle('is-busy', !free && !outside && !departure);
            // Only free days: taken ones already read as taken and must stay distinct.
            cell.classList.toggle('is-muted', departing && free && !departure && key !== self.selectionStart);
            cell.disabled = !free && !departure;
            cell.classList.remove('is-start', 'is-end', 'is-between');

            if (key === self.selectionStart) {
                cell.classList.add('is-start');
            }
            if (rangeValid && key === end) {
                cell.classList.add('is-end');
            }
            if (rangeValid && key > self.selectionStart && key < end) {
                cell.classList.add('is-between');
            }
        });
    };

    /* ── Selection ────────────────────────────────────────────────────── */

    /**
     * A night is bookable when the server said so; unknown nights stay unselectable.
     * The server reports whole months, so nights earlier today are filtered here.
     */
    Calendar.prototype.isNightFree = function (key) {
        return key >= this.todayKey && this.nights[key] === true;
    };

    /** The latest possible departure for an arrival: the morning after the last free night. */
    Calendar.prototype.lastDepartureFrom = function (startKey) {
        if (!this.isNightFree(startKey)) {
            return null;
        }
        var night = fromKey(startKey);
        for (var i = 0; i < MAX_STAY_NIGHTS && this.isNightFree(toKey(night)); i++) {
            night = addDays(night, 1);
        }
        return toKey(night);
    };

    /**
     * A day can start a stay when its own night is free. Whether the previous night
     * is taken does not matter — that guest departs this morning.
     */
    Calendar.prototype.canArrive = function (key) {
        return this.isNightFree(key);
    };

    /** A stay may end on a day when every night from arrival up to it is free. */
    Calendar.prototype.canDepart = function (startKey, endKey) {
        var night = fromKey(startKey);
        var end = fromKey(endKey);
        if (end <= night) {
            return false;
        }
        while (night < end) {
            if (!this.isNightFree(toKey(night))) {
                return false;
            }
            night = addDays(night, 1);
        }
        return true;
    };

    Calendar.prototype.bindGrid = function () {
        var self = this;

        this.grid.addEventListener('click', function (event) {
            var cell = event.target.closest('[data-fhb-day]');
            if (!cell) {
                return;
            }
            event.preventDefault();
            self.pick(cell.dataset.fhbDay);
        });

        // Preview the range while moving across the grid after picking an arrival.
        this.grid.addEventListener('pointermove', function (event) {
            if (!self.selectionStart || self.selectionEnd) {
                return;
            }
            var cell = event.target.closest('[data-fhb-day]');
            var key = cell ? cell.dataset.fhbDay : null;
            if (key !== self.hoverEnd) {
                self.hoverEnd = key;
                self.paint();
            }
        });

        this.grid.addEventListener('pointerleave', function () {
            if (self.hoverEnd) {
                self.hoverEnd = null;
                self.paint();
            }
        });
    };

    Calendar.prototype.pick = function (key) {
        // Restart whenever there is no open selection, the click lands before the
        // current arrival, or the range would span an occupied night.
        if (!this.selectionStart || this.selectionEnd || !this.canDepart(this.selectionStart, key)) {
            if (!this.canArrive(key)) {
                return;
            }
            this.selectionStart = key;
            this.selectionEnd = null;
            this.hoverEnd = null;
            // The form must not keep submitting the previous stay while the guest
            // is still choosing the new one.
            this.clearForm();
            this.paint();
            this.describeSelection();
            return;
        }

        this.selectionEnd = key;
        this.hoverEnd = null;
        this.paint();
        this.applyToForm();
    };

    Calendar.prototype.clearSelection = function () {
        this.selectionStart = null;
        this.selectionEnd = null;
        this.hoverEnd = null;
    };

    /** Show the period the guest already picked, so a round trip does not lose it. */
    Calendar.prototype.restoreSelection = function () {
        if (!this.fromInput || !this.toInput) {
            return;
        }

        var from = this.fromInput.value;
        var to = this.toInput.value;
        // A stale page can carry an arrival that has passed since; drop it.
        if (!from || !to || from >= to || from < this.todayKey) {
            return;
        }

        this.selectionStart = from;
        this.selectionEnd = to;
        // Open the window on the arrival month rather than making the guest navigate
        // back to their own booking.
        this.goTo(startOfMonth(fromKey(from)));
    };

    /** Empty the date fields, e.g. when a new arrival starts a fresh selection. */
    Calendar.prototype.clearForm = function () {
        [this.fromInput, this.toInput].forEach(function (input) {
            if (input && input.value !== '') {
                input.value = '';
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    };

    Calendar.prototype.applyToForm = function () {
        if (!this.fromInput || !this.toInput) {
            return;
        }

        this.fromInput.value = this.selectionStart;
        this.toInput.value = this.selectionEnd;
        this.fromInput.dispatchEvent(new Event('change', { bubbles: true }));
        this.toInput.dispatchEvent(new Event('change', { bubbles: true }));

        this.describeSelection();
    };

    /* ── Status line ──────────────────────────────────────────────────── */

    /**
     * Hand the date choice over to the calendar.
     *
     * Only done once the widget is actually running, so a visitor without
     * JavaScript keeps the plain date fields and the original button wording.
     */
    Calendar.prototype.takeOverDateFields = function () {
        var page = document.querySelector('.fhb-booking-root');
        if (page) {
            page.classList.add('fhb-calendar-active');
        }

        // Turn the date inputs into hidden fields rather than merely hiding them: a
        // display:none input that fails HTML5 validation blocks submitting with
        // "An invalid form control ... is not focusable", and hidden inputs are
        // never validated. They keep their name and value either way.
        [this.fromInput, this.toInput].forEach(function (input) {
            if (input) {
                input.type = 'hidden';
            }
        });

        document.querySelectorAll('[data-fhb-label-default]').forEach(function (label) {
            label.hidden = true;
        });
        document.querySelectorAll('[data-fhb-label-calendar]').forEach(function (label) {
            label.hidden = false;
        });

        this.describeSelection();
    };

    Calendar.prototype.describeSelection = function () {
        if (!this.selectionStart) {
            this.setStatus(this.labels.pickArrival || '');
            return;
        }
        if (!this.selectionEnd) {
            this.setStatus(this.labels.pickDeparture || '');
            return;
        }

        var nights = Math.round((fromKey(this.selectionEnd) - fromKey(this.selectionStart)) / MS_PER_DAY);
        var template = nights === 1 ? this.labels.selectedOne : this.labels.selectedMany;
        var range = this.formatDate(this.selectionStart) + ' – ' + this.formatDate(this.selectionEnd);

        this.setStatus(range + ' · ' + (template || '').replace('%count%', String(nights)));
    };

    Calendar.prototype.formatDate = function (key) {
        return fromKey(key).toLocaleDateString(this.locale, { day: '2-digit', month: '2-digit', year: 'numeric' });
    };

    Calendar.prototype.setStatus = function (text) {
        if (this.statusEl) {
            this.statusEl.textContent = text;
        }
    };

    /** Never let a loading notice overwrite the guest's own selection. */
    Calendar.prototype.setStatusIfIdle = function (text) {
        if (!this.selectionStart) {
            this.setStatus(text);
        }
    };

    function boot() {
        document.querySelectorAll('[data-fhb-calendar]').forEach(function (root) {
            new Calendar(root);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
})();
