/**
 * js/my_profile.js — AMA status badge + verify on member portal profile.
 */
(function () {
    'use strict';

    function initAmaStatus() {
        var wrap = document.getElementById('ama-expiration-wrap');
        var badge = document.getElementById('ama-status-badge');
        var expEl = document.getElementById('ama_expiration');
        if (!wrap || !badge || !expEl) return;

        function update() {
            var val = (expEl.value || '').trim();
            wrap.classList.remove('ama-valid', 'ama-warning', 'ama-expired');
            badge.classList.remove('ama-valid', 'ama-warning', 'ama-expired');
            badge.textContent = '';
            if (!val) return;

            var exp = new Date(val + 'T12:00:00');
            var today = new Date();
            today.setHours(0, 0, 0, 0);
            var endOfYear = new Date(today.getFullYear(), 11, 31);

            if (exp < today) {
                wrap.classList.add('ama-expired');
                badge.classList.add('ama-expired');
                badge.textContent = 'Expired';
            } else if (exp >= endOfYear) {
                wrap.classList.add('ama-valid');
                badge.classList.add('ama-valid');
                badge.textContent = 'Current (valid through 12/31/' + endOfYear.getFullYear() + ')';
            } else {
                wrap.classList.add('ama-warning');
                badge.classList.add('ama-warning');
                var m = exp.getMonth() + 1, d = exp.getDate(), y = exp.getFullYear();
                badge.textContent = 'Expires ' + m + '/' + d + '/' + y;
            }
        }

        update();
        expEl.addEventListener('change', update);
        expEl.addEventListener('input', update);
        window._updateAmaStatus = update;
    }

    function initAmaVerify() {
        var btn = document.getElementById('verify-ama-btn');
        var statusEl = document.getElementById('verify-ama-status');
        var csrfEl = document.getElementById('page_csrf_token');
        if (!btn || !statusEl) return;

        btn.addEventListener('click', function () {
            var amaEl = document.getElementById('ama_number');
            var ama = amaEl ? (amaEl.value || '').trim() : '';
            if (!ama) {
                statusEl.textContent = 'Enter your AMA number first.';
                statusEl.className = 'small text-danger';
                return;
            }

            statusEl.textContent = 'Checking…';
            statusEl.className = 'small text-muted';
            btn.disabled = true;

            var fd = new FormData();
            fd.append('ama_number', ama);
            if (csrfEl) fd.append('csrf_token', csrfEl.value);

            fetch('api_verify_ama_member.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
            })
                .then(function (r) {
                    if (!r.ok) {
                        return r.json().then(function (data) {
                            throw new Error((data && data.message) || ('Server ' + r.status));
                        }).catch(function (err) {
                            if (err && err.message) throw err;
                            throw new Error('Server ' + r.status);
                        });
                    }
                    return r.json();
                })
                .then(function (data) {
                    if (data && data.valid) {
                        statusEl.textContent = data.message || 'Verified.';
                        statusEl.className = 'small text-success';
                        var expInput = document.getElementById('ama_expiration');
                        if (expInput && data.expiration) expInput.value = data.expiration;
                        var lifeEl = document.getElementById('ama_life_member');
                        if (lifeEl) lifeEl.checked = !!data.life_member;
                        if (window._updateAmaStatus) window._updateAmaStatus();
                    } else {
                        statusEl.textContent = (data && data.message) ? data.message : 'Could not verify.';
                        statusEl.className = 'small text-danger';
                    }
                })
                .catch(function (err) {
                    statusEl.textContent = err && err.message ? err.message : 'Verification request failed.';
                    statusEl.className = 'small text-danger';
                })
                .finally(function () {
                    btn.disabled = false;
                });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initAmaStatus();
        initAmaVerify();
    });
})();
