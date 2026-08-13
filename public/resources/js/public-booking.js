/**
 * Behaviour for the public booking page (modern theme).
 *
 * Plain JS on purpose: the public booking page is served without AssetMapper,
 * so no Stimulus controller is available here.
 *
 * Covers the guest-count steppers and, in embed mode, the position of the image
 * gallery lightbox.
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
    function init(root) {
        var adultsInput = root.querySelector('[data-pgc-target="adultsInput"]');
        var childrenInput = root.querySelector('[data-pgc-target="childrenInput"]');
        var personsInput = root.querySelector('[data-pgc-target="personsInput"]');
        var childAgesContainer = root.querySelector('[data-pgc-target="childAges"]');
        var childTemplate = root.querySelector('[data-pgc-target="childAgeTemplate"]');
        var warning = root.querySelector('[data-pgc-target="adultWarning"]');
        var form = root.closest('form');
        var ageLabelTemplate = root.dataset.pgcAgeLabel || '__N__';

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

        function recompute() {
            var adults = clamp(adultsInput);
            var children = childrenInput ? clamp(childrenInput) : 0;
            syncChildAgeRows(children);
            if (personsInput) {
                personsInput.value = String(Math.max(1, adults + children));
            }
            var valid = adults > 0;
            if (warning) {
                warning.classList.toggle('d-none', valid);
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
