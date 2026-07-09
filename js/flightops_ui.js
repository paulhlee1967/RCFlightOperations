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
            if (form.hasAttribute('data-email-sending')) return;
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

    /** Color picker ↔ hex text sync (config_site theme tab). */
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

    var EMAIL_WAIT_MESSAGES = [
        'Preparing for departure…',
        'Clearing the runway…',
        'Contacting the tower (mail server)…',
        'Taxiing to the outbox…',
        'Wheels up — your message is on its way…',
        'Cruising altitude — almost there…',
        'On final approach to the inbox…'
    ];

    var emailWaitOverlay = null;
    var emailWaitStatusEl = null;
    var emailWaitRotateTimer = null;
    var emailWaitMsgIndex = 0;

    function ensureEmailWaitOverlay() {
        if (!emailWaitOverlay) {
            emailWaitOverlay = document.getElementById('fo-email-wait');
            emailWaitStatusEl = document.getElementById('fo-email-wait-status');
        }
        return emailWaitOverlay;
    }

    function rotateEmailWaitMessage() {
        if (!emailWaitStatusEl) return;
        emailWaitStatusEl.style.opacity = '0';
        window.setTimeout(function () {
            emailWaitMsgIndex = (emailWaitMsgIndex + 1) % EMAIL_WAIT_MESSAGES.length;
            emailWaitStatusEl.textContent = EMAIL_WAIT_MESSAGES[emailWaitMsgIndex];
            emailWaitStatusEl.style.opacity = '1';
        }, 200);
    }

    function showEmailWait(form) {
        var overlay = ensureEmailWaitOverlay();
        if (!overlay) return;

        var title = form.getAttribute('data-email-sending-title') || 'Sending email';
        var titleEl = document.getElementById('fo-email-wait-title');
        if (titleEl) titleEl.textContent = title;

        emailWaitMsgIndex = 0;
        if (emailWaitStatusEl) {
            emailWaitStatusEl.textContent = EMAIL_WAIT_MESSAGES[0];
            emailWaitStatusEl.style.opacity = '1';
        }

        overlay.hidden = false;
        document.body.style.overflow = 'hidden';

        if (emailWaitRotateTimer) window.clearInterval(emailWaitRotateTimer);
        emailWaitRotateTimer = window.setInterval(rotateEmailWaitMessage, 2800);

        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
            btn.disabled = true;
            btn.setAttribute('aria-disabled', 'true');
        });
    }

    /**
     * Pause navigation briefly so the browser can paint the overlay before the
     * synchronous POST unloads the page.
     */
    function submitFormAfterPaint(form) {
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(function () {
                window.setTimeout(function () {
                    form.submit();
                }, 40);
            });
        });
    }

    function bindEmailSendingForms() {
        document.querySelectorAll('form[data-email-sending]').forEach(function (form) {
            form.addEventListener('submit', function (ev) {
                if (form.getAttribute('data-fo-email-pending') === '1') return;

                var confirmMsg = form.getAttribute('data-confirm-submit');
                if (confirmMsg && !confirm(confirmMsg)) {
                    ev.preventDefault();
                    return;
                }

                ev.preventDefault();
                form.setAttribute('data-fo-email-pending', '1');
                showEmailWait(form);
                submitFormAfterPaint(form);
            });
        });
    }

    function bindFlashToasts() {
        document.querySelectorAll('#flashToastContainer .toast.bg-success').forEach(function (el) {
            window.setTimeout(function () {
                if (typeof bootstrap !== 'undefined' && bootstrap.Toast) {
                    bootstrap.Toast.getOrCreateInstance(el).hide();
                } else {
                    el.classList.remove('show');
                }
            }, 3000);
        });
    }

    function init() {
        bindConfirmClicks();
        bindConfirmSubmits();
        bindEmailSendingForms();
        bindFlashToasts();
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
