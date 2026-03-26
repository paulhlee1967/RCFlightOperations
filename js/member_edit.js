/**
 * js/member_edit.js
 *
 * Handles all interactive behaviour on member_edit.php:
 *   - Address tab UI (add/remove, type label sync)
 *   - Phone row dynamic add
 *   - AMA expiration status badge (valid / expiring / expired)
 *   - AMA live-verification button (fetch → api_verify_ama.php)
 *   - Print card shortcut (legacy, kept for back-compat)
 *
 * Loaded deferred via <script src="js/member_edit.js" defer> in member_edit.php.
 * No globals are polluted; everything runs inside a DOMContentLoaded callback.
 *
 * CSP: this file is loaded as an external script so no nonce is required.
 * The CSRF token is read from a hidden input (#page_csrf_token) already on
 * the page.
 */

(function () {
    'use strict';

    // ── Phone rows ──────────────────────────────────────────────────────────
    function initPhones() {
        var addBtn   = document.getElementById('add-phone');
        var phonesWrap = document.getElementById('phones-wrap');
        if (!addBtn || !phonesWrap) return;

        var phoneIndex = document.querySelectorAll('.phone-row').length;

        addBtn.addEventListener('click', function () {
            var div = document.createElement('div');
            div.className = 'row g-2 phone-row align-items-end';
            div.innerHTML =
                '<div class="col-auto">' +
                '<select name="phones[' + phoneIndex + '][type]" class="form-select form-select-sm" style="width:auto">' +
                '<option value="Home">Home</option>' +
                '<option value="Work">Work</option>' +
                '<option value="Cell">Cell</option>' +
                '<option value="Other">Other</option>' +
                '</select></div>' +
                '<div class="col"><input type="text" class="form-control form-control-sm" ' +
                'name="phones[' + phoneIndex + '][number]" placeholder="Number"></div>';
            phonesWrap.appendChild(div);
            phoneIndex++;
        });
    }

    // ── Address tabs ────────────────────────────────────────────────────────
    function initAddresses() {
        var wrap   = document.getElementById('addresses-wrap');
        var addBtn = document.getElementById('add-address');
        if (!wrap || !addBtn) return;

        function getNextAddrIndex() {
            var inTabs = document.getElementById('addressTabs');
            if (inTabs) {
                return document.querySelectorAll('#addressTabContent .tab-pane').length;
            }
            return document.querySelectorAll('.address-block').length;
        }

        function updateRemoveVisibility() {
            var panes      = document.querySelectorAll('#addressTabContent .tab-pane');
            var removeBtns = wrap.querySelectorAll('.remove-address');
            removeBtns.forEach(function (btn) {
                btn.style.visibility = panes.length <= 1 ? 'hidden' : 'visible';
            });
        }

        /** Snapshot current values from an .address-block (innerHTML does not preserve typed input). */
        function collectAddressBlockValues(block) {
            if (!block) {
                return { type: 'Home', street: '', street2: '', city: '', state: '', postal_code: '' };
            }
            var sel = block.querySelector('select[name^="addresses["]');
            var out = {
                type: sel ? sel.value : 'Home',
                street: '',
                street2: '',
                city: '',
                state: '',
                postal_code: ''
            };
            ['street', 'street2', 'city', 'state', 'postal_code'].forEach(function (key) {
                var el = block.querySelector('input[name*="[' + key + ']"]');
                if (el) out[key] = el.value;
            });
            return out;
        }

        function applyAddressBlockValues(pane, v) {
            if (!pane || !v) return;
            var sel = pane.querySelector('select.addr-type, select[name^="addresses["]');
            if (sel && v.type !== undefined) sel.value = v.type;
            ['street', 'street2', 'city', 'state', 'postal_code'].forEach(function (key) {
                var el = pane.querySelector('input[name*="[' + key + ']"]');
                if (el && v[key] !== undefined) el.value = v[key];
            });
        }

        // Remove address tab
        wrap.addEventListener('click', function (e) {
            if (!e.target.classList.contains('remove-address')) return;
            var pane = e.target.closest('.tab-pane');
            if (!pane || !pane.id) return;
            var tabBtn = document.getElementById('addr-tab-' + pane.dataset.addrIndex);
            if (tabBtn) tabBtn.closest('.nav-item').remove();
            pane.remove();
            updateRemoveVisibility();
        });

        // Sync tab label to type dropdown value
        wrap.addEventListener('change', function (e) {
            if (!e.target.classList.contains('addr-type')) return;
            var pane = e.target.closest('.tab-pane');
            if (!pane) return;
            var tabBtn = document.querySelector('#addressTabs button[data-bs-target="#' + pane.id + '"]');
            if (tabBtn) tabBtn.textContent = e.target.value;
        });

        // Add address tab
        addBtn.addEventListener('click', function () {
            var nav     = document.getElementById('addressTabs');
            var content = document.getElementById('addressTabContent');
            var idx     = getNextAddrIndex();

            if (nav && content) {
                // Tabs already exist — add another tab
                var paneId = 'addr-pane-' + idx;
                var li = document.createElement('li');
                li.className = 'nav-item';
                li.setAttribute('role', 'presentation');
                li.innerHTML =
                    '<button class="nav-link" id="addr-tab-' + idx + '" data-bs-toggle="tab" ' +
                    'data-bs-target="#' + paneId + '" type="button" role="tab">Address</button>';
                nav.appendChild(li);

                var pane = document.createElement('div');
                pane.className = 'tab-pane fade';
                pane.id        = paneId;
                pane.setAttribute('role', 'tabpanel');
                pane.dataset.addrIndex = idx;
                pane.innerHTML = addrPaneHtml(idx);
                content.appendChild(pane);
                updateRemoveVisibility();

                var bsTab = (window.bootstrap && bootstrap.Tab)
                    ? new bootstrap.Tab(li.querySelector('button'))
                    : null;
                if (bsTab) bsTab.show();

            } else {
                // No tabs yet — convert single block to tabbed layout if needed
                var blocks = wrap.querySelectorAll('.address-block');
                if (blocks.length === 1) {
                    var firstBlock = blocks[0];
                    var savedAddr  = collectAddressBlockValues(firstBlock);
                    var typeVal    = savedAddr.type || 'Home';

                    wrap.innerHTML =
                        '<ul class="nav nav-tabs nav-tabs-sm mt-1 mb-0" id="addressTabs" role="tablist">' +
                        '<li class="nav-item" role="presentation">' +
                        '<button class="nav-link active" id="addr-tab-0" data-bs-toggle="tab" ' +
                        'data-bs-target="#addr-pane-0" type="button" role="tab">' + typeVal + '</button></li>' +
                        '<li class="nav-item" role="presentation">' +
                        '<button class="nav-link" id="addr-tab-1" data-bs-toggle="tab" ' +
                        'data-bs-target="#addr-pane-1" type="button" role="tab">Address</button></li>' +
                        '</ul>' +
                        '<div class="tab-content border border-top-0 rounded-bottom p-2 mb-2" id="addressTabContent">' +
                        '<div class="tab-pane fade show active" id="addr-pane-0" role="tabpanel" data-addr-index="0">' +
                        '<div class="address-block d-flex justify-content-between align-items-start gap-2">' +
                        '<div class="flex-grow-1">' + firstBlock.innerHTML + '</div>' +
                        '<button type="button" class="btn btn-outline-danger btn-sm remove-address" ' +
                        'title="Remove this address">Remove</button>' +
                        '</div></div>' +
                        '<div class="tab-pane fade" id="addr-pane-1" role="tabpanel" data-addr-index="1">' +
                        addrPaneHtml(1) +
                        '</div></div>';

                    var sel0 = wrap.querySelector('#addr-pane-0 select');
                    if (sel0) sel0.classList.add('addr-type');
                    var pane0 = wrap.querySelector('#addr-pane-0');
                    if (pane0) applyAddressBlockValues(pane0, savedAddr);
                } else {
                    var div = document.createElement('div');
                    div.className = 'address-block border rounded p-2';
                    div.innerHTML = addrPaneHtml(idx, true);
                    wrap.appendChild(div);
                }
            }
        });
    }

    /** Build address pane inner HTML for index `idx`. Pass `plain=true` for non-tab layout. */
    function addrPaneHtml(idx, plain) {
        var wrap =
            '<div class="row g-2 mb-2"><div class="col-auto">' +
            '<select name="addresses[' + idx + '][type]" class="form-select form-select-sm addr-type" style="width:auto">' +
            '<option value="Home">Home</option><option value="Work">Work</option><option value="Other">Other</option>' +
            '</select></div></div>' +
            '<div class="row g-2">' +
            '<div class="col-12 col-md-6"><input type="text" class="form-control form-control-sm" name="addresses[' + idx + '][street]" placeholder="Street"></div>' +
            '<div class="col-12 col-md-6"><input type="text" class="form-control form-control-sm" name="addresses[' + idx + '][street2]" placeholder="Suite / Apt"></div>' +
            '</div>' +
            '<div class="row g-2 mt-1">' +
            '<div class="col-12 col-md-4"><input type="text" class="form-control form-control-sm" name="addresses[' + idx + '][city]" placeholder="City"></div>' +
            '<div class="col-6 col-md-2"><input type="text" class="form-control form-control-sm" name="addresses[' + idx + '][state]" placeholder="State"></div>' +
            '<div class="col-6 col-md-3"><input type="text" class="form-control form-control-sm" name="addresses[' + idx + '][postal_code]" placeholder="Postal code"></div>' +
            '</div>';

        if (plain) return wrap;

        return '<div class="address-block d-flex justify-content-between align-items-start gap-2">' +
            '<div class="flex-grow-1">' + wrap + '</div>' +
            '<button type="button" class="btn btn-outline-danger btn-sm remove-address" ' +
            'title="Remove this address">Remove</button>' +
            '</div>';
    }

    // ── AMA expiration status badge ─────────────────────────────────────────
    function initAmaStatus() {
        var wrap  = document.getElementById('ama-expiration-wrap');
        var badge = document.getElementById('ama-status-badge');
        var expEl = document.getElementById('ama_expiration');
        if (!wrap || !badge || !expEl) return;

        function update() {
            var val = (expEl.value || '').trim();
            wrap.classList.remove('ama-valid', 'ama-warning', 'ama-expired');
            badge.classList.remove('ama-valid', 'ama-warning', 'ama-expired');
            badge.textContent = '';
            if (!val) return;

            var exp      = new Date(val + 'T12:00:00');
            var today    = new Date(); today.setHours(0, 0, 0, 0);
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
        expEl.addEventListener('input',  update);

        // Expose for use by the AMA verify handler below
        window._updateAmaStatus = update;
    }

    // ── AMA live verification ───────────────────────────────────────────────
    function initAmaVerify() {
        var btn          = document.getElementById('verify-ama-btn');
        var statusEl     = document.getElementById('verify-ama-status');
        var csrfEl       = document.getElementById('page_csrf_token');
        if (!btn || !statusEl) return;

        btn.addEventListener('click', function () {
            var lastEl = document.getElementById('last_name');
            var amaEl  = document.getElementById('ama_number');
            var last   = lastEl ? (lastEl.value || '').trim() : '';
            var ama    = amaEl  ? (amaEl.value  || '').trim() : '';

            if (!ama || !last) {
                statusEl.textContent = 'Enter last name and AMA number first.';
                statusEl.className   = 'small text-danger';
                return;
            }

            statusEl.textContent = 'Checking…';
            statusEl.className   = 'small text-muted';
            btn.disabled         = true;

            var fd = new FormData();
            fd.append('lastname',   last);
            fd.append('ama_number', ama);
            if (csrfEl) fd.append('csrf_token', csrfEl.value);

            fetch('api_verify_ama.php', {
                method:      'POST',
                body:        fd,
                credentials: 'same-origin',
            })
            .then(function (r) {
                if (!r.ok) {
                    return r.text().then(function (t) {
                        throw new Error('Server ' + r.status + (t ? ': ' + t.substring(0, 80) : ''));
                    });
                }
                return r.json();
            })
            .then(function (data) {
                if (data && data.valid) {
                    statusEl.textContent = data.message || 'Verified.';
                    statusEl.className   = 'small text-success';

                    var expInput = document.getElementById('ama_expiration');
                    if (expInput && data.expiration) expInput.value = data.expiration;

                    var lifeEl = document.getElementById('ama_life_member');
                    if (lifeEl) lifeEl.checked = !!data.life_member;

                    if (window._updateAmaStatus) window._updateAmaStatus();
                } else {
                    statusEl.textContent = (data && data.message) ? data.message : 'Could not verify.';
                    statusEl.className   = 'small text-danger';
                }
            })
            .catch(function (err) {
                statusEl.textContent = err && err.message ? err.message : 'Verification request failed.';
                statusEl.className   = 'small text-danger';
            })
            .finally(function () {
                btn.disabled = false;
            });
        });
    }

    // ── Boot ────────────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        initPhones();
        initAddresses();
        initAmaStatus();
        initAmaVerify();
    });

})();