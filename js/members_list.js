/**
 * js/members_list.js — member list bulk select + quick-view offcanvas.
 */
(function () {
    'use strict';

    const listCfg = window.FLIGHTOPS_MEMBERS_LIST || {};

    // ── Bulk select ───────────────────────────────────────────────────────
    const form      = document.getElementById('bulk-form');
    const selectAll = document.getElementById('select-all');
    const deleteBtn = document.getElementById('bulk-delete-btn');
    const countSpan = document.getElementById('selected-count');

    if (deleteBtn && form) {
        deleteBtn.addEventListener('click', function (e) {
            const checked = form.querySelectorAll('.row-checkbox:checked').length;
            if (checked <= 0) return;

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

    // ── Type chip counts follow URL status ──
    function membersPageStatus() {
        const s = new URLSearchParams(window.location.search).get('status');
        if (s === 'active') return 'current';
        if (s === 'all' || s === 'current') return s;
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
    const quickViewBody = document.getElementById('quickViewBody');
    if (!quickViewBody) return;

    document.querySelectorAll('.quick-view-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const memberId = btn.getAttribute('data-member-id');
            if (!memberId) return;

            quickViewBody.innerHTML = '<div class="text-center text-muted py-4">Loading…</div>';

            fetch('member_detail.php?id=' + encodeURIComponent(memberId) + '&format=json')
                .then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function (d) {
                    quickViewBody.innerHTML = buildQuickViewHtml(d);
                })
                .catch(function () {
                    quickViewBody.innerHTML = '<p class="text-danger small mb-0">Could not load member details.</p>';
                });
        });
    });

    function buildQuickViewHtml(d) {
        const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        const canEdit = !!listCfg.canEdit;
        const canProcess = !!listCfg.canProcess;
        const recordUrl = canEdit ? ('member_edit.php?id=' + esc(d.id)) : ('member_view.php?id=' + esc(d.id));
        const processUrl = 'member_process.php?id=' + esc(d.id);

        let html = '<div class="d-flex align-items-center gap-3 mb-3">';
        if (d.photo_url) {
            html += '<img src="' + esc(d.photo_url) + '" class="member-avatar qv-avatar" alt="">';
        } else {
            const initials = (d.name || '??').split(' ').map(w => w[0]).join('').toUpperCase().slice(0,2);
            html += '<div class="member-initials member-avatar qv-initials">' + esc(initials) + '</div>';
        }
        html += '<div><div class="fw-semibold">' + esc(d.name) + '</div>';
        if (d.type) html += '<span class="badge qv-badge-type">' + esc(d.type) + '</span> ';
        if (d.renewal_year) html += '<span class="badge qv-badge-year">' + esc(d.renewal_year) + '</span>';
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
            html += '<div class="d-grid gap-2">';
            if (canProcess) {
                html += '<a href="' + processUrl + '" class="btn btn-outline-primary btn-sm">Process →</a>';
            }
            html += '<a href="' + recordUrl + '" class="btn btn-outline-secondary btn-sm">Open full record →</a>';
            html += '</div>';
        }
        return html;
    }
})();
