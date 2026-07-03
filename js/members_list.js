/**
 * js/members_list.js — member list bulk select + quick-view offcanvas.
 */
(function () {
    'use strict';

    // ── Bulk select ───────────────────────────────────────────────────────
    const form      = document.getElementById('bulk-form');
    const selectAll = document.getElementById('select-all');
    const deleteBtn = document.getElementById('bulk-delete-btn');
    const countSpan = document.getElementById('selected-count');

    if (deleteBtn && form) {
        deleteBtn.addEventListener('click', function (e) {
            const checked = form.querySelectorAll('.row-checkbox:checked').length;
            if (checked <= 0) return;

            // Explicit confirmation for the sensitive destructive action.
            const msg = checked === 1
                ? 'Are you sure you want to delete 1 member?'
                : 'Are you sure you want to delete ' + checked + ' members?';
            if (!window.confirm(msg)) e.preventDefault();
        });
    }

    function updateBulkCount() {
        if (!form) return;
        const checked = form.querySelectorAll('.row-checkbox:checked').length;
        if (deleteBtn) {
            deleteBtn.disabled = checked === 0;
            if (checked === 1) {
                deleteBtn.textContent = 'Delete 1 member';
            } else if (checked > 1) {
                deleteBtn.textContent = 'Delete ' + checked + ' members';
            } else {
                deleteBtn.textContent = 'Delete selected';
            }
        }
        if (countSpan) countSpan.textContent = checked ? checked + ' selected' : '';
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            form.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = selectAll.checked);
            updateBulkCount();
        });
    }
    if (form) {
        form.querySelectorAll('.row-checkbox').forEach(cb => cb.addEventListener('change', updateBulkCount));
    }
    updateBulkCount();

    // ── Type chip counts follow URL status (fixes stale counts on back/forward cache) ──
    function membersPageStatus() {
        const s = new URLSearchParams(window.location.search).get('status');
        if (s === 'all' || s === 'current' || s === 'inactive') return s;
        return 'current';
    }

    function refreshTypeChipCounts() {
        const status = membersPageStatus();
        document.querySelectorAll('.js-type-chip').forEach(function (chip) {
            const label = chip.getAttribute('data-type-label') || '';
            let counts = {};
            try {
                counts = JSON.parse(chip.getAttribute('data-type-counts') || '{}');
            } catch (e) { /* ignore */ }
            const n = Object.prototype.hasOwnProperty.call(counts, status) ? counts[status] : 0;
            chip.textContent = label + ' (' + n + ')';
        });
    }

    document.addEventListener('DOMContentLoaded', refreshTypeChipCounts);
    window.addEventListener('pageshow', refreshTypeChipCounts);

    // ── Quick-view offcanvas ──────────────────────────────────────────────
    // Requires member_detail.php?id=N&format=json endpoint.
    // Falls back gracefully if that endpoint doesn't exist yet.
    const quickViewBody = document.getElementById('quickViewBody');

    document.querySelectorAll('.quick-view-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const memberId = this.dataset.memberId;
            if (!quickViewBody || !memberId) return;

            quickViewBody.innerHTML = '<p class="text-muted small">Loading&hellip;</p>';

            fetch('member_detail.php?id=' + encodeURIComponent(memberId) + '&format=json', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            })
                .then(async r => {
                    if (!r.ok) {
                        const bodyText = await r.text().catch(() => '');
                        const err = new Error('HTTP ' + r.status);
                        err.status = r.status;
                        err.bodyText = bodyText;
                        throw err;
                    }
                    return r.json();
                })
                .then(data => {
                    quickViewBody.innerHTML = buildQuickViewHtml(data);
                })
                .catch(err => {
                    const status = (err && err.status) ? err.status : 'unknown';
                    const requiresText =
                        status === 404
                            ? '<p class="text-muted small mb-3">Quick-view endpoint missing: <code>member_detail.php</code>.</p>'
                            : '<p class="text-muted small mb-3">Quick-view failed to load (HTTP ' + status + ').</p>';
                    quickViewBody.innerHTML =
                        requiresText +
                        '<a href="member_edit.php?id=' + encodeURIComponent(memberId) + '" class="btn btn-primary btn-sm">Open full record</a>';

                    // Keep it debuggable without exposing internals to users.
                    console.error('Quick-view fetch failed', err);
                });
        });
    });

    /**
     * Render quick-view offcanvas body from the JSON payload returned by
     * member_detail.php. Expected keys: name, type, renewal_year, ama_number,
     * faa_number, gate_key, phones (array of {type, number}), email, flags.
     *
     * @param {Object} d
     * @returns {string} HTML string
     */
    function buildQuickViewHtml(d) {
        const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        const canEdit = !!(window.FLIGHTOPS_MEMBERS_LIST && window.FLIGHTOPS_MEMBERS_LIST.canEdit);
        const recordUrl = canEdit ? ('member_edit.php?id=' + esc(d.id)) : ('member_view.php?id=' + esc(d.id));

        let html = '<div class="d-flex align-items-center gap-3 mb-3">';
        if (d.photo_url) {
            html += '<img src="' + esc(d.photo_url) + '" class="member-avatar" style="width:52px;height:52px;" alt="">';
        } else {
            const initials = (d.name || '??').split(' ').map(w => w[0]).join('').toUpperCase().slice(0,2);
            html += '<div class="member-initials member-avatar" style="width:52px;height:52px;font-size:1rem;background:#5b7fa6;">' + esc(initials) + '</div>';
        }
        html += '<div><div class="fw-semibold">' + esc(d.name) + '</div>';
        if (d.type) html += '<span class="badge bg-secondary" style="font-size:11px;">' + esc(d.type) + '</span> ';
        if (d.renewal_year) html += '<span class="badge bg-success" style="font-size:11px;">' + esc(d.renewal_year) + '</span>';
        html += '</div></div>';

        const rows = [
            ['Email',      d.email],
            ['Phone',      d.phone],
            ['AMA #',      d.ama_number],
            ['FAA #',      d.faa_number],
            ['Gate key',   d.gate_key],
        ];
        html += '<dl class="row g-1 small mb-3">';
        rows.forEach(([label, val]) => {
            if (val) {
                html += '<dt class="col-5 text-muted">' + esc(label) + '</dt><dd class="col-7 mb-0">' + esc(val) + '</dd>';
            }
        });
        html += '</dl>';

        if (d.id) {
            html += '<a href="' + recordUrl + '" class="btn btn-outline-primary btn-sm w-100">Open full record →</a>';
        }
        return html;
    }
})();
