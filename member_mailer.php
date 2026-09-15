<?php
/**
 * member_mailer.php — Mailing Packet Print View
 *
 * Prints two items (as separate browser print jobs):
 *   1. A #10 envelope with return address (from club config) + member address
 *   2. A welcome/renewal letter on club letterhead
 *
 * Architecture mirrors badge_print.php — focused, no-nav print page.
 * When badge_print.php is overhauled, this page is unaffected.
 *
 * GET  ?id=N&year=Y           — show print view
 * GET  ?id=N&year=Y&type=new  — force letter type (new|renewal); auto-detected otherwise
 * POST action=mark_mailer     — record mailer as printed in member_fulfillments
 *
 * The club's return address is pulled from club settings when available.
 * If no address is stored, a placeholder is shown with a link to set it.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

requireLogin();
if (!canEditMembers() && !canProcessMemberships()) {
    header('Location: index.php');
    exit;
}
$userId   = currentUserId();
$memberId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$year     = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');

// ---------------------------------------------------------------------------
// POST handlers (CSRF required for all POST paths)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    // POST action: mark mailer as printed
    if (($_POST['action'] ?? '') === 'mark_mailer') {
        $mid = (int) ($_POST['member_id'] ?? 0);
        $yr  = (int) ($_POST['year'] ?? $year);

        if ($mid > 0) {
            // Upsert fulfillment row then mark mailer done
            $pdo->prepare('
                INSERT INTO member_fulfillments (member_id, year)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE id = id
            ')->execute([$mid, $yr]);

            $pdo->prepare('
                UPDATE member_fulfillments
                SET mailer_printed_at = NOW(), mailer_printed_by = ?
                WHERE member_id = ? AND year = ?
            ')->execute([$userId, $mid, $yr]);
        }

        header('Location: member_process.php?id=' . $mid . '&year=' . $yr . '#fulfill');
        exit;
    }
}

if ($memberId <= 0) {
    header('Location: members.php');
    exit;
}

// ---------------------------------------------------------------------------
// Load member + primary address
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare('
    SELECT m.id, m.first_name, m.last_name, m.email,
           m.membership_type_slot, m.membership_renewal_year,
           m.date_joined, m.ama_number, m.faa_number,
           m.life_member, m.free_membership,
           m.address_street AS street, m.address_street2 AS street2,
           m.address_city AS city, m.address_state AS state, m.address_postal_code AS postal_code
    FROM members m
    WHERE m.id = ?
');
$stmt->execute([$memberId]);
$member = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$member) {
    header('Location: members.php');
    exit;
}

$typeLabelsForLetter = enabledMembershipTypeLabels($pdo);
$slot = (int) ($member['membership_type_slot'] ?? 0);
$member['membership_type'] = $slot > 0
    ? ($typeLabelsForLetter[$slot] ?? 'Member')
    : 'Member';

// ---------------------------------------------------------------------------
// Load club info for letterhead + return address
// ---------------------------------------------------------------------------
$tStmt = $pdo->query('SELECT name, logo_path FROM club WHERE id = 1');
$clubRow  = $tStmt ? $tStmt->fetch(PDO::FETCH_ASSOC) : false;
$clubName = $clubRow['name'] ?? 'RC Flight Operations';
$logoPath = $clubRow['logo_path'] ?? null;

// Embed the logo as a base64 data URI so it renders correctly both on screen
// and in the browser's print dialog — avoids all URL path / subdirectory /
// reverse-proxy / REQUEST_SCHEME issues entirely. Same approach badge_print.php
// uses for member photos.
$logoDataUri = null;
if ($logoPath) {
    $logoFile = __DIR__ . '/' . ltrim($logoPath, '/');
    if (is_file($logoFile) && is_readable($logoFile)) {
        $ext  = strtolower(pathinfo($logoFile, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'svg'  => 'image/svg+xml',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
        $logoDataUri = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoFile));
    }
}

// ---------------------------------------------------------------------------
// Determine letter type: new member vs. renewal
// ---------------------------------------------------------------------------
$forceType = $_GET['type'] ?? null;
if ($forceType === 'new') {
    $letterType = 'new';
} elseif ($forceType === 'renewal') {
    $letterType = 'renewal';
} else {
    // Auto-detect: if renewal year matches current working year they just joined, treat as new
    $dateJoined  = $member['date_joined'] ?? null;
    $joinYear    = $dateJoined ? (int) date('Y', strtotime($dateJoined)) : null;
    $letterType  = ($joinYear === $year) ? 'new' : 'renewal';
}

// ---------------------------------------------------------------------------
// Check fulfillment state (for "mark as printed" button display)
// ---------------------------------------------------------------------------
$fStmt = $pdo->prepare('
    SELECT mailer_printed_at FROM member_fulfillments
    WHERE member_id = ? AND year = ?
');
$fStmt->execute([$memberId, $year]);
$fulfillment = $fStmt->fetch(PDO::FETCH_ASSOC);
$mailerPrinted = !empty($fulfillment['mailer_printed_at']);

// ---------------------------------------------------------------------------
// Build address block strings
// ---------------------------------------------------------------------------
$memberName   = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
$hasAddress   = !empty($member['street']) && !empty($member['city']);
$memberAddr   = [
    'name'    => $memberName,
    'street'  => $member['street']  ?? '',
    'street2' => $member['street2'] ?? '',
    'city'    => $member['city']    ?? '',
    'state'   => $member['state']   ?? '',
    'postal'  => $member['postal_code'] ?? '',
];

$today       = date('F j, Y');
$pageTitle   = 'Mailing Packet: ' . $memberName;
$noNav       = true;
require_once __DIR__ . '/includes/header.php';

?>

<?php /* ── No-print toolbar ───────────────────────────────────────── */ ?>
<div class="no-print d-flex flex-wrap align-items-center gap-2 mb-4 p-2 border-bottom bg-light">
    <a href="member_process.php?id=<?= $memberId ?>&year=<?= $year ?>#fulfill"
       class="btn btn-outline-secondary btn-sm">← Back to Process</a>

    <button type="button" class="btn btn-primary btn-sm" id="print-envelope-btn">
        Print Envelope
    </button>
    <button type="button" class="btn btn-primary btn-sm" id="print-letter-btn">
        Print Letter
    </button>

    <?php if ($mailerPrinted): ?>
    <span class="text-success small ms-2">
        ✓ Mailer recorded <?= date('M j, Y', strtotime($fulfillment['mailer_printed_at'])) ?>
    </span>
    <?php else: ?>
    <form method="post" class="d-inline" id="mark-mailer-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mark_mailer">
        <input type="hidden" name="member_id" value="<?= $memberId ?>">
        <input type="hidden" name="year" value="<?= $year ?>">
        <button type="submit" class="btn btn-outline-success btn-sm">Mark mailer as printed</button>
    </form>
    <?php endif; ?>

    <div class="ms-auto d-flex gap-2 align-items-center">
        <span class="text-muted small">Letter type:</span>
        <a href="?id=<?= $memberId ?>&year=<?= $year ?>&type=new"
           class="btn btn-sm <?= $letterType === 'new' ? 'btn-secondary' : 'btn-outline-secondary' ?>">New member</a>
        <a href="?id=<?= $memberId ?>&year=<?= $year ?>&type=renewal"
           class="btn btn-sm <?= $letterType === 'renewal' ? 'btn-secondary' : 'btn-outline-secondary' ?>">Renewal</a>
    </div>
</div>

<?php if (!$hasAddress): ?>
<div class="no-print alert alert-warning mb-3">
    <strong>No mailing address on file.</strong> The envelope will be blank.
    <a href="member_edit.php?id=<?= $memberId ?>#pane-contact" class="alert-link">Add address →</a>
</div>
<?php endif; ?>

<?php /* ═══════════════════════════════════════════════════════════════════
       ENVELOPE — prints when body has class "print-envelope"
       Sized for a standard #10 envelope (9.5" × 4.125")
       ══════════════════════════════════════════════════════════════════ */ ?>
<div id="envelope-area" class="mailer-block mb-5">
    <div class="no-print text-muted small fw-semibold text-uppercase mb-2" style="letter-spacing:.08em;">
        Envelope preview (#10)
    </div>
    <div id="envelope">
        <?php /* Return address — top left */ ?>
        <div id="return-address">
            <strong><?= h($clubName) ?></strong><br>
            <span class="text-muted small no-print">
                (Club return address — add in Site settings if needed)
            </span>
        </div>

        <?php /* Recipient address — centered */ ?>
        <div id="recipient-address">
            <?php if ($hasAddress): ?>
            <?= h($memberAddr['name']) ?><br>
            <?= h($memberAddr['street']) ?>
            <?php if ($memberAddr['street2']): ?><br><?= h($memberAddr['street2']) ?><?php endif; ?><br>
            <?= h($memberAddr['city']) ?>, <?= h($memberAddr['state']) ?> <?= h($memberAddr['postal']) ?>
            <?php else: ?>
            <span class="text-danger">[No address on file]</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php /* ═══════════════════════════════════════════════════════════════════
       LETTER — prints when body has class "print-letter"
       ══════════════════════════════════════════════════════════════════ */ ?>
<div id="letter-area" class="mailer-block">
    <div class="no-print text-muted small fw-semibold text-uppercase mb-2" style="letter-spacing:.08em;">
        Letter preview
    </div>
    <div id="letter-page">

        <?php /* Letterhead */ ?>
        <div id="letterhead">
            <?php if ($logoDataUri): ?>
            <img src="<?= h($logoDataUri) ?>" alt="<?= h($clubName) ?> logo" id="club-logo">
            <?php endif; ?>
            <div id="club-name-header"><?= h($clubName) ?></div>
        </div>

        <div id="letter-date"><?= h($today) ?></div>

        <?php /* Member address block */ ?>
        <div id="letter-address">
            <?= h($memberAddr['name']) ?><br>
            <?php if ($hasAddress): ?>
            <?= h($memberAddr['street']) ?>
            <?php if ($memberAddr['street2']): ?><br><?= h($memberAddr['street2']) ?><?php endif; ?><br>
            <?= h($memberAddr['city']) ?>, <?= h($memberAddr['state']) ?> <?= h($memberAddr['postal']) ?>
            <?php else: ?>
            <span class="text-muted">(No address on file)</span>
            <?php endif; ?>
        </div>

        <div id="letter-body">
            <?php if ($letterType === 'new'): ?>
            <?php include __DIR__ . '/templates/letter/new_member.php'; ?>
            <?php else: ?>
            <?php include __DIR__ . '/templates/letter/renewal.php'; ?>
            <?php endif; ?>
        </div>

        <div id="letter-signature">
            <p>Fly safe and blue skies,</p>
            <p class="sig-gap">&nbsp;</p>
            <p><strong><?= h($clubName) ?> Membership Team</strong></p>
        </div>

    </div>
</div>

<?php /* ── Styles ───────────────────────────────────────────────────── */ ?>
<style<?= csp_nonce_attr() ?>>
/* ── Screen layout ──────────────────────────────────────────────────────── */
.mailer-block { max-width: 780px; }

/* Envelope mock */
#envelope {
    position: relative;
    width: 100%;
    max-width: 700px;
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
    line-height: 1.5;
    margin-bottom: 2rem;
}
#recipient-address {
    text-align: center;
    font-size: 15px;
    line-height: 1.8;
}

/* Letter page mock */
#letter-page {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 2.5rem 3rem;
    font-family: Georgia, 'Times New Roman', serif;
    font-size: 14px;
    line-height: 1.7;
    max-width: 720px;
    color: #111;
}
#letterhead {
    display: flex;
    align-items: center;
    gap: 1rem;
    border-bottom: 2px solid var(--club-primary, #6f7c3d);
    padding-bottom: 1rem;
    margin-bottom: 1.5rem;
}
#club-logo {
    max-height: 52px;
    max-width: 160px;
    object-fit: contain;
}
#club-name-header {
    font-size: 1.25rem;
    font-weight: 700;
    font-family: sans-serif;
    color: var(--club-primary, #6f7c3d);
}
#letter-date { margin-bottom: 1.5rem; }
#letter-address { margin-bottom: 1.5rem; line-height: 1.5; }
#letter-body p { margin-bottom: 1rem; }
#letter-signature { margin-top: 2rem; }
.sig-gap { margin: 1rem 0; }

/* ── Print styles ───────────────────────────────────────────────────────── */
@media print {
    .no-print { display: none !important; }
    nav.navbar, .breadcrumb, footer { display: none !important; }
    body { background: #fff; margin: 0; padding: 0; }

    /* Default: hide everything */
    #envelope-area, #letter-area { display: none !important; }

    /* Print envelope only */
    body.print-envelope #envelope-area { display: block !important; }
    body.print-envelope #envelope {
        border: none !important;
        padding: 1in 1in;
        font-size: 13pt;
        max-width: none;
        page-break-inside: avoid;
    }
    body.print-envelope #return-address { font-size: 10pt; }
    body.print-envelope #recipient-address { font-size: 13pt; margin-top: 1.2in; }

    /* Print letter only */
    body.print-letter #letter-area { display: block !important; }
    body.print-letter #letter-page {
        border: none !important;
        padding: 0;
        max-width: none;
        font-size: 11.5pt;
    }
    body.print-letter #club-logo { max-height: 60px; }
    body.print-letter #club-name-header { font-size: 1rem; }
}
</style>

<script<?= csp_nonce_attr() ?>>
(function () {
    'use strict';

    function printAs(cssClass) {
        document.body.classList.remove('print-envelope', 'print-letter');
        document.body.classList.add(cssClass);
        window.print();
        window.addEventListener('afterprint', function cleanup() {
            document.body.classList.remove('print-envelope', 'print-letter');
            window.removeEventListener('afterprint', cleanup);
        }, { once: true });
    }

    var envBtn    = document.getElementById('print-envelope-btn');
    var letterBtn = document.getElementById('print-letter-btn');

    if (envBtn)    envBtn.addEventListener('click',    function () { printAs('print-envelope'); });
    if (letterBtn) letterBtn.addEventListener('click', function () { printAs('print-letter'); });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>