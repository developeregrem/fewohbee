/**
 * Behaviour for the public booking page (modern theme).
 *
 * Plain JS on purpose: the public booking page is served without AssetMapper,
 * so no Stimulus controller is available here.
 *
 * Covers the guest-count steppers — including the age-to-category mapping that
 * decides how many beds a party actually occupies — and, in embed mode, the
 * position of the image gallery lightbox.
 */
(function () {
    'use strict';

    /**
     * Guest counts: two number inputs (adults + children), one age select per
     * child cloned from a <template>, the hidden `persons` field kept in sync for
     * capacity filtering, and submitting blocked while no adult is selected.
     *
     * Expected markup: a container with `data-pgc-root` and the age-label pattern
     * in `data-pgc-age-label` using `__N__` as the child-number placeholder.
     */
    /**
     * Mirror of GuestCategoryAgeMapper::matchByAge(): the category whose age range
     * contains the age, ties broken by sort order and then id.
     */
    function categoryForAge(categories, age) {
        var best = null;
        categories.forEach(function (category) {
            if (category.adult) {
                return;
            }
            if (category.minAge !== null && age < category.minAge) {
                return;
            }
            if (category.maxAge !== null && age > category.maxAge) {
                return;
            }
            if (best === null
                || category.sortOrder < best.sortOrder
                || (category.sortOrder === best.sortOrder && category.id < best.id)) {
                best = category;
            }
        });
        return best;
    }

    /**
     * Break a party down the same way the server does.
     *
     * Returns the counts per category plus the two numbers that must not be
     * confused: how many guests occupy a bed, and how many come along without one
     * (an infant in a cot). Capacity and the price tier follow the former; only the
     * summary mentions the latter.
     */
    function describeParty(categories, adults, childAges) {
        var adultCategory = null;
        categories.forEach(function (category) {
            if (category.adult && (adultCategory === null || category.sortOrder < adultCategory.sortOrder)) {
                adultCategory = category;
            }
        });

        var counts = [];
        function add(category, amount) {
            if (!category) {
                return;
            }
            for (var i = 0; i < counts.length; i++) {
                if (counts[i].category.id === category.id) {
                    counts[i].count += amount;
                    return;
                }
            }
            counts.push({ category: category, count: amount });
        }

        if (adults > 0) {
            add(adultCategory, adults);
        }
        childAges.forEach(function (age) {
            if (age >= 0) {
                add(categoryForAge(categories, age), 1);
            }
        });

        var occupying = 0;
        var withoutBed = 0;
        counts.forEach(function (entry) {
            if (entry.category.occupies) {
                occupying += entry.count;
            } else {
                withoutBed += entry.count;
            }
        });

        return { counts: counts, occupying: occupying, withoutBed: withoutBed };
    }

    function init(root) {
        var adultsInput = root.querySelector('[data-pgc-target="adultsInput"]');
        var childrenInput = root.querySelector('[data-pgc-target="childrenInput"]');
        var personsInput = root.querySelector('[data-pgc-target="personsInput"]');
        var childAgesContainer = root.querySelector('[data-pgc-target="childAges"]');
        var childTemplate = root.querySelector('[data-pgc-target="childAgeTemplate"]');
        var warning = root.querySelector('[data-pgc-target="adultWarning"]');
        var summaryEl = root.querySelector('[data-pgc-target="summary"]');
        var capacityWarning = root.querySelector('[data-pgc-target="capacityWarning"]');
        var form = root.closest('form');
        var ageLabelTemplate = root.dataset.pgcAgeLabel || '__N__';
        var categories = JSON.parse(root.dataset.pgcCategories || '[]');
        var labels = JSON.parse(root.dataset.pgcLabels || '{}');
        // Bed count of the accommodation, when one is known (calendar mode).
        var capacity = parseInt(root.dataset.pgcCapacity || '0', 10);

        if (!adultsInput) {
            return;
        }

        function clamp(input) {
            var min = parseInt(input.dataset.pgcMin || '0', 10);
            var value = Math.max(min, parseInt(input.value, 10) || min);
            if (String(value) !== input.value) {
                input.value = String(value);
            }
            return value;
        }

        function syncChildAgeRows(desired) {
            if (!childAgesContainer || !childTemplate) {
                return;
            }
            var rows = childAgesContainer.querySelectorAll('[data-pgc-child-row]');
            for (var i = rows.length; i < desired; i++) {
                var clone = childTemplate.content.firstElementChild.cloneNode(true);
                var label = clone.querySelector('[data-pgc-child-label]');
                if (label) {
                    label.textContent = ageLabelTemplate.replace('__N__', String(i + 1));
                }
                childAgesContainer.appendChild(clone);
            }
            var current = childAgesContainer.querySelectorAll('[data-pgc-child-row]');
            for (var j = current.length - 1; j >= desired; j--) {
                current[j].remove();
            }
        }

        function childAges() {
            var ages = [];
            root.querySelectorAll('[data-pgc-child-row] select').forEach(function (select) {
                ages.push(parseInt(select.value, 10));
            });
            return ages;
        }

        function describe(party) {
            if (!summaryEl) {
                return;
            }
            if (party.counts.length === 0) {
                summaryEl.textContent = '';
                return;
            }

            var parts = party.counts.map(function (entry) {
                var text = entry.count + ' \u00d7 ' + entry.category.name;
                return entry.category.occupies ? text : text + ' (' + (labels.noBed || '') + ')';
            });

            var text = parts.join(', ');
            if (capacity > 0) {
                text += ' \u00b7 ' + (labels.bedsUsed || '')
                    .replace('__USED__', String(party.occupying))
                    .replace('__MAX__', String(capacity));
            }
            summaryEl.textContent = text;
        }

        function recompute() {
            var adults = clamp(adultsInput);
            var children = childrenInput ? clamp(childrenInput) : 0;
            syncChildAgeRows(children);

            // Guests without a bed of their own must not count towards capacity or
            // the price tier, so derive both from the category rules rather than
            // from the raw head count.
            var party = describeParty(categories, adults, childAges());
            if (personsInput) {
                personsInput.value = String(Math.max(1, party.occupying));
            }
            describe(party);

            var overCapacity = capacity > 0 && party.occupying > capacity;
            if (capacityWarning) {
                capacityWarning.classList.toggle('d-none', !overCapacity);
                capacityWarning.textContent = (labels.overCapacity || '').replace('__MAX__', String(capacity));
            }

            var valid = adults > 0 && !overCapacity;
            if (warning) {
                warning.classList.toggle('d-none', adults > 0);
            }
            if (form) {
                form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
                    btn.disabled = !valid;
                });
            }
        }

        adultsInput.addEventListener('input', recompute);
        if (childrenInput) {
            childrenInput.addEventListener('input', recompute);
        }
        // Child ages are cloned in at runtime, so listen on the container.
        root.addEventListener('change', function (event) {
            if (event.target.closest('[data-pgc-child-row]')) {
                recompute();
            }
        });
        // The calendar announces the capacity of whichever room is selected.
        document.addEventListener('fhb:room-changed', function (event) {
            capacity = parseInt(event.detail && event.detail.capacity, 10) || 0;
            recompute();
        });
        recompute();
    }

    /** Stepper buttons (+/-) around the native number inputs. */
    function initSteppers(scope) {
        scope.querySelectorAll('[data-fhb-step-btn]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = document.getElementById(btn.dataset.fhbStepBtn);
                if (!target) {
                    return;
                }
                var min = parseInt(target.min || '0', 10);
                var delta = parseInt(btn.dataset.fhbStepDelta || '1', 10);
                var next = Math.max(min, (parseInt(target.value, 10) || min) + delta);
                target.value = String(next);
                target.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });
    }

    /**
     * Anchor the gallery lightbox to the image that opened it.
     *
     * Bootstrap modals are position:fixed and centre themselves in the viewport.
     * An embedded booking page has no viewport of its own — the host resizes the
     * iframe to the full content height — so a centred lightbox lands halfway
     * down the whole page, often far outside what the guest is looking at.
     */
    function initGalleryPosition() {
        var root = document.querySelector('.fhb-booking-root');
        if (!root || !root.classList.contains('fhb-embed')) {
            return;
        }

        document.addEventListener('show.bs.modal', function (event) {
            var modal = event.target;
            if (!modal || !modal.classList || !modal.classList.contains('modal')) {
                return;
            }

            var trigger = event.relatedTarget;
            var top = 0;
            if (trigger && typeof trigger.getBoundingClientRect === 'function') {
                top = trigger.getBoundingClientRect().top + (window.pageYOffset || 0);
            }

            modal.style.top = Math.max(0, Math.round(top) - 12) + 'px';
        });
    }

    function boot() {
        document.querySelectorAll('[data-pgc-root]').forEach(init);
        initSteppers(document);
        initGalleryPosition();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
})();
