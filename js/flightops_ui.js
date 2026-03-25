/**
 * Shared UI behaviors (CSP-friendly: no inline onclick/onchange/oninput).
 * Loaded deferred from includes/footer.php.
 */
(function () {
    'use strict';

    function bindConfirmClicks() {
        document.querySelectorAll('[data-confirm]').forEach(function (el) {
            var msg = el.getAttribute('data-confirm');
            if (!msg) return;
            el.addEventListener('click', function (ev) {
                if (!confirm(msg)) ev.preventDefault();
            });
        });
    }

    function bindConfirmSubmits() {
        document.querySelectorAll('form[data-confirm-submit]').forEach(function (form) {
            var msg = form.getAttribute('data-confirm-submit');
            if (!msg) return;
            form.addEventListener('submit', function (ev) {
                if (!confirm(msg)) ev.preventDefault();
            });
        });
    }

    function bindPrintButtons() {
        document.querySelectorAll('[data-action="print"]').forEach(function (el) {
            el.addEventListener('click', function (ev) {
                ev.preventDefault();
                window.print();
            });
        });
    }

    function bindSubmitOnChangeSelects() {
        document.querySelectorAll('select.js-submit-on-change').forEach(function (sel) {
            sel.addEventListener('change', function () {
                if (sel.form) sel.form.submit();
            });
        });
    }

    /** Colour picker ↔ hex text sync (config_site theme tab). */
    function bindColorSyncGroups() {
        document.querySelectorAll('.js-color-sync').forEach(function (group) {
            var colorIn = group.querySelector('input[type="color"]');
            var textIn = group.querySelector('input[type="text"]');
            if (!colorIn || !textIn) return;
            colorIn.addEventListener('input', function () {
                textIn.value = colorIn.value;
            });
            textIn.addEventListener('input', function () {
                if (/^#[0-9a-fA-F]{6}$/.test(textIn.value)) {
                    colorIn.value = textIn.value;
                }
            });
        });
    }

    /** Show/hide a block when a checkbox is toggled (incident AMA ref). */
    function bindCheckboxShowTargets() {
        document.querySelectorAll('[data-show-target]').forEach(function (cb) {
            if (cb.type !== 'checkbox') return;
            var id = cb.getAttribute('data-show-target');
            var target = id && document.getElementById(id);
            if (!target) return;
            function sync() {
                target.style.display = cb.checked ? '' : 'none';
            }
            cb.addEventListener('change', sync);
            sync();
        });
    }

    /** Operator communications: toggle club multi-select visibility. */
    function bindCommsTargetRadios() {
        var sel = document.getElementById('club-select');
        if (!sel) return;
        document.querySelectorAll('input.js-comms-target[type="radio"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (!radio.checked) return;
                var mode = radio.getAttribute('data-club-select-display') || 'none';
                sel.style.display = mode === 'block' ? 'block' : 'none';
            });
        });
    }

    function init() {
        bindConfirmClicks();
        bindConfirmSubmits();
        bindPrintButtons();
        bindSubmitOnChangeSelects();
        if (document.querySelector('.js-color-sync')) {
            bindColorSyncGroups();
        }
        bindCheckboxShowTargets();
        bindCommsTargetRadios();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
