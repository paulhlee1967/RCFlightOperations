/**
 * js/member_wizard.js — Step navigation for member_wizard.php
 */
(function () {
    'use strict';

    var STEPS = ['contact', 'compliance', 'membership'];

    function stepIndexFromKey(key) {
        var idx = STEPS.indexOf(key);
        return idx >= 0 ? idx : 0;
    }

    function currentStepIndex() {
        var active = document.querySelector('.wizard-step-panel.is-active');
        if (!active) return 0;
        return parseInt(active.getAttribute('data-wizard-step'), 10) - 1;
    }

    function showStep(index) {
        var panels = document.querySelectorAll('.wizard-step-panel');
        panels.forEach(function (panel, i) {
            panel.classList.toggle('is-active', i === index);
        });

        var nav = document.querySelector('.member-wizard-nav');
        if (nav) {
            var items = nav.querySelectorAll('.member-wizard-step');
            items.forEach(function (item, i) {
                item.classList.remove('is-active', 'is-done', 'is-pending');
                if (i < index) {
                    item.classList.add('is-done');
                } else if (i === index) {
                    item.classList.add('is-active');
                } else if (i < 3) {
                    item.classList.add('is-pending');
                } else {
                    item.classList.add('is-pending');
                }
            });
        }

        var backBtn = document.getElementById('wizard-back');
        var nextBtn = document.getElementById('wizard-next');
        var submitBtn = document.getElementById('wizard-submit');
        var cancelLink = document.getElementById('wizard-cancel');
        var returnMode = document.getElementById('wizard-form') &&
            document.getElementById('wizard-form').getAttribute('data-return-process') === '1';

        if (backBtn) backBtn.style.display = index > 0 ? '' : 'none';
        if (nextBtn) nextBtn.style.display = index < STEPS.length - 1 ? '' : 'none';
        if (submitBtn) submitBtn.style.display = (!returnMode && index === STEPS.length - 1) ? '' : 'none';
        if (cancelLink) cancelLink.style.display = (!returnMode && index === 0) ? '' : 'none';
    }

    function focusField(fieldName) {
        if (!fieldName) return;

        var el = document.getElementById(fieldName);
        if (!el) {
            el = document.querySelector('[name="' + fieldName + '"]');
        }
        if (!el && fieldName === 'addresses') {
            el = document.querySelector('[name="addresses[0][street]"]');
        }
        if (!el) return;

        el.classList.add('is-invalid');
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        if (typeof el.focus === 'function') {
            el.focus({ preventScroll: true });
        }
    }

    function validateStep(index) {
        var form = document.getElementById('wizard-form');
        if (!form) return true;

        if (index === 0) {
            var first = form.querySelector('[name="first_name"]');
            var last  = form.querySelector('[name="last_name"]');
            if (!first || !first.value.trim()) {
                first && first.focus();
                first && first.classList.add('is-invalid');
                return false;
            }
            first.classList.remove('is-invalid');
            if (!last || !last.value.trim()) {
                last && last.focus();
                last && last.classList.add('is-invalid');
                return false;
            }
            last.classList.remove('is-invalid');
        }

        if (index === 2) {
            var typeSel = document.getElementById('membership_type_slot');
            if (!typeSel || !typeSel.value) {
                typeSel && typeSel.focus();
                typeSel && typeSel.classList.add('is-invalid');
                return false;
            }
            typeSel.classList.remove('is-invalid');
        }

        return true;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var form     = document.getElementById('wizard-form');
        var backBtn  = document.getElementById('wizard-back');
        var nextBtn  = document.getElementById('wizard-next');
        var initial  = form ? (form.getAttribute('data-initial-step') || 'contact') : 'contact';
        var field    = form ? (form.getAttribute('data-focus-field') || '') : '';
        var startIdx = stepIndexFromKey(initial);

        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                var idx = currentStepIndex();
                if (!validateStep(idx)) return;
                if (idx < STEPS.length - 1) {
                    showStep(idx + 1);
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            });
        }

        if (backBtn) {
            backBtn.addEventListener('click', function () {
                var idx = currentStepIndex();
                if (idx > 0) {
                    showStep(idx - 1);
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            });
        }

        if (form) {
            form.addEventListener('submit', function (e) {
                var idx = currentStepIndex();
                var returnMode = form.getAttribute('data-return-process') === '1';
                if (returnMode) {
                    if (!validateStep(0)) {
                        e.preventDefault();
                        showStep(0);
                        return;
                    }
                    if (idx < 2 && !validateStep(2)) {
                        // Allow save from earlier steps; server still requires membership type.
                    }
                } else if (!validateStep(2)) {
                    e.preventDefault();
                    showStep(2);
                }
            });
        }

        showStep(startIdx);
        if (field) {
            window.setTimeout(function () {
                focusField(field);
            }, 100);
        }
    });
})();
