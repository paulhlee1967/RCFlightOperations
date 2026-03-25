<?php
/**
 * member_envelope.php — Standalone Envelope Print
 *
 * Prints a single #10 envelope for any member, entirely outside the
 * membership-processing workflow. Useful for one-off mailings, notices,
 * holiday cards, etc.
 *
 * Intentionally thin: it does NOT mark fulfillment state and has no
 * dependency on member_process.php. The workflow-integrated envelope
 * remains in member_mailer.php.
 *
 * GET  ?id=N                   — show envelope preview for member N
 * GET  ?id=N&from=edit         — "back" link returns to member_edit.php
 * GET  ?id=N&from=process      — "back" link returns to member_process.php
 * GET  ?id=N&from=list         — "back" link returns to members.php
 *
 * There is no POST action — this page has no side-effects. If an admin
 * wants to record that an envelope was printed they should use
 * member_mailer.php (which records fulfillment state).
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();
if (!canEditMembers() && !canProcessMemberships()) {
    header('Location: index.php');
    exit;
}
$memberId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($memberId <= 0) {
    header('Location: members.php');
    exit;
}

// Determine where the "back" link should go
$fromParam = $_GET['from'] ?? 'edit';
$backUrl = match ($fromParam) {
    'process' => 'member_process.php?id=' . $memberId,
    'list'    => 'members.php',
    default   => 'member_edit.php?id=' . $memberId,
};
$backLabel = match ($fromParam) {
    'process' => '← Back to Workflow',
    'list'    => '← Back to Members',
    default   => '← Back to Member',
};

// ---------------------------------------------------------------------------
// Load member + primary address
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare('
    SELECT m.id, m.first_name, m.last_name, m.allow_postal,
           a.street, a.street2, a.city, a.state, a.postal_code
    FROM members m
    LEFT JOIN member_addresses a
           ON a.member_id = m.id
          AND a.id = (
              SELECT id FROM member_addresses
               WHERE member_id = m.id
               ORDER BY FIELD(type, "Home", "Work", "Other")
               LIMIT 1
          )
    WHERE m.id = ?
');
$stmt->execute([$memberId]);
$member = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$member) {
    header('Location: members.php');
    exit;
}

// ---------------------------------------------------------------------------
// Load club name for return address
// ---------------------------------------------------------------------------
$tStmt = $pdo->query('SELECT name FROM club WHERE id = 1');
$clubRow  = $tStmt ? $tStmt->fetch(PDO::FETCH_ASSOC) : false;
$clubName = $clubRow['name'] ?? 'RC Flight Operations';

// ---------------------------------------------------------------------------
// Build address block
// ---------------------------------------------------------------------------
$memberName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
$hasAddress = !empty($member['street']) && !empty($member['city']);

$pageTitle = 'Print Envelope: ' . $memberName;
$noNav     = true;
require_once __DIR__ . '/includes/header.php';
?>

<?php /* ── No-print toolbar ─────────────────────────────────────────────── */ ?>
<div class="no-print d-flex flex-wrap align-items-center gap-2 mb-4 p-2 border-bottom bg-light">
    <a href="<?= h($backUrl) ?>" class="btn btn-outline-secondary btn-sm"><?= h($backLabel) ?></a>

    <button type="button" class="btn btn-primary btn-sm" id="print-btn">
        <?php /* Printer icon (Bootstrap Icons inline SVG) */ ?>
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor"
             class="me-1" viewBox="0 0 16 16" aria-hidden="true">
            <path d="M2.5 8a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1"/>
            <path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2
                     2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2zm6
                     14H5a1 1 0 0 1-1-1v-1h8v1a1 1 0 0 1-1 1M4 3a1 1 0 0 1 1-1h6a1 1 0 0 1 1
                     1v2H4zm1 5a2 2 0 0 0-2 2v1H2a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h12a1 1 0 0 1
                     1 1v3a1 1 0 0 1-1 1h-1v-1a2 2 0 0 0-2-2z"/>
        </svg>
        Print Envelope
    </button>

    <span class="text-muted small ms-2">
        Printing for: <strong><?= h($memberName) ?></strong>
    </span>

    <?php if (!$hasAddress): ?>
    <span class="text-warning small ms-2">
        ⚠ No address on file —
        <a href="member_edit.php?id=<?= $memberId ?>#pane-contact" class="alert-link">add one →</a>
    </span>
    <?php endif; ?>
</div>

<?php if (isset($member['allow_postal']) && !(int) $member['allow_postal']): ?>
<div class="no-print alert alert-secondary mb-3">
    <strong>Postal opt-out on file.</strong> This member has opted out of postal mailings. Confirm before sending.
    <a href="member_edit.php?id=<?= $memberId ?>#pane-contact" class="alert-link">Edit preferences →</a>
</div>
<?php endif; ?>

<?php /* ── Envelope preview ──────────────────────────────────────────────── */ ?>
<div class="no-print text-muted small fw-semibold text-uppercase mb-2" style="letter-spacing:.08em;">
    Envelope preview (#10 — 9.5&Prime; &times; 4.125&Prime;)
</div>

<div id="envelope-wrap">
    <div id="envelope">

        <?php /* Return address — top-left */ ?>
        <div id="return-address">
            <strong><?= h($clubName) ?></strong>
        </div>

        <?php /* Recipient address — lower-center */ ?>
        <div id="recipient-address">
            <?php if ($hasAddress): ?>
                <?= h($memberName) ?><br>
                <?= h($member['street']) ?>
                <?php if (!empty($member['street2'])): ?><br><?= h($member['street2']) ?><?php endif; ?><br>
                <?= h($member['city']) ?>, <?= h($member['state']) ?> <?= h($member['postal_code']) ?>
            <?php else: ?>
                <span class="text-danger">[No address on file]</span>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php /* ── Styles ───────────────────────────────────────────────────────── */ ?>
<style<?= csp_nonce_attr() ?>>
/* ── Screen: envelope mock ─────────────────────────────────────────────────── */
#envelope-wrap {
    max-width: 700px;
}
#envelope {
    position: relative;
    width: 100%;
    min-height: 200px;
    border: 1px solid #aaa;
    border-radius: 4px;
    background: #fff;
    padding: 1.5rem 2rem;
    font-family: monospace;
    font-size: 14px;
}
#return-address {
    font-size: 11px;
    line-height: 1.6;
    margin-bottom: 2.5rem;
}
#recipient-address {
    text-align: center;
    font-size: 15px;
    line-height: 1.8;
}

/* ── Print: real #10 envelope layout ──────────────────────────────────────── */
@media print {
    .no-print          { display: none !important; }
    nav.navbar,
    .breadcrumb,
    footer             { display: none !important; }
    body               { background: #fff; margin: 0; padding: 0; }

    /* Set page to landscape #10 envelope size */
    @page {
        size: 9.5in 4.125in landscape;
        margin: 0;
    }

    #envelope-wrap {
        max-width: none;
    }

    #envelope {
        border: none !important;
        border-radius: 0;
        width: 9.5in;
        height: 4.125in;
        padding: 0;
        position: relative;
        page-break-inside: avoid;
        font-size: 12pt;
    }

    #return-address {
        position: absolute;
        top: 0.35in;
        left: 0.4in;
        font-size: 10pt;
        line-height: 1.4;
    }

    #recipient-address {
        position: absolute;
        top: 1.6in;
        left: 0;
        right: 0;
        text-align: center;
        font-size: 12pt;
        line-height: 1.8;
    }
}
</style>

<script<?= csp_nonce_attr() ?>>
(function () {
    'use strict';
    document.getElementById('print-btn').addEventListener('click', function () {
        window.print();
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>