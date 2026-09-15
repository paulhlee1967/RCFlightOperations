<?php
/**
 * Print a member's badge (front + back). Fills template with member data.
 * "Mark as printed" records badge_printed_at on the member.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

require_once __DIR__ . '/includes/badge_print_helpers.php';

requireLogin();

if (!canEditMembers() && !canProcessMemberships()) {
    header('Location: index.php');
    exit;
}

// Detect calling context so the back-link is contextually correct
$fromProcess = !empty($_GET['from_process']);
$fromWizard  = !empty($_GET['wizard']);
$workYear    = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$memberId    = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$membershipTypeLabels = enabledMembershipTypeLabels($pdo);

badge_print_handle_post($pdo);

if ($memberId <= 0) {
    header('Location: members.php');
    exit;
}

$member = badge_print_load_member($pdo, $memberId);
if ($member === false) {
    header('Location: members.php');
    exit;
}

$memberData = badge_member_data_from_row($member, $membershipTypeLabels, $memberId);

$designs           = badge_print_designs_list($pdo);
$requestedDesignId = isset($_GET['design_id']) ? (int) $_GET['design_id'] : 0;
$selection         = badge_print_select_design($designs, $requestedDesignId);
$selectedDesign    = $selection['design'];
$templateData      = $selection['templateData'];
$selectedDesignId  = $selection['designId'];

$cardDims            = badge_cr80_dimensions();
$cardWidthLandscape  = $cardDims['cardWidthLandscape'];
$cardHeightLandscape = $cardDims['cardHeightLandscape'];
$cardWidthPortrait   = $cardDims['cardWidthPortrait'];
$cardHeightPortrait  = $cardDims['cardHeightPortrait'];

$printed = isset($_GET['printed']);
$printFront = isset($_GET['front']) ? (int) $_GET['front'] : 1;
$printBack = isset($_GET['back']) ? (int) $_GET['back'] : 1;
if (!$printFront && !$printBack) {
    $printFront = 1;
    $printBack = 1;
}
$pageTitle = 'Print card: ' . $memberData['full_name'];
$noNav = true;

// Used by js/badge_print.js to optionally mark the workflow checklist item and return.
$returnTo = $fromProcess
    ? ('member_process.php?id=' . $memberId . '&year=' . $workYear . ($fromWizard ? '&wizard=1' : '') . '#fulfill')
    : ('member_edit.php?id=' . $memberId);

require_once __DIR__ . '/includes/header.php';
?>

<div class="no-print d-flex flex-wrap align-items-center gap-2 mb-3 p-2 border-bottom bg-light">

<?php /* Context-aware back link */ ?>
<?php if ($fromProcess): ?>
<a href="member_process.php?id=<?= $memberId ?>&year=<?= $workYear ?><?= $fromWizard ? '&wizard=1' : '' ?>#fulfill"
   class="btn btn-outline-secondary btn-sm">← Back to Workflow</a>
<?php else: ?>
<a href="member_edit.php?id=<?= $memberId ?>"
   class="btn btn-outline-secondary btn-sm">← Back to Member</a>
<?php endif; ?>

<?php /* Design picker — choose which saved design to print */ ?>
<?php if (count($designs) > 1): ?>
<form method="get" class="d-flex align-items-center gap-1" id="design-picker-form">
    <input type="hidden" name="id" value="<?= $memberId ?>">
    <?php if ($fromProcess): ?>
        <input type="hidden" name="from_process" value="1">
        <input type="hidden" name="year" value="<?= $workYear ?>">
        <?php if ($fromWizard): ?>
        <input type="hidden" name="wizard" value="1">
        <?php endif; ?>
    <?php endif; ?>
    <input type="hidden" name="front" value="<?= $printFront ? '1' : '0' ?>">
    <input type="hidden" name="back" value="<?= $printBack ? '1' : '0' ?>">
    <label for="design_id" class="form-label small mb-0 text-muted">Design</label>
    <select name="design_id" id="design_id" class="form-select form-select-sm" style="width:auto">
        <?php foreach ($designs as $d): ?>
            <?php
            $tags = [];
            if ((int) $d['is_default']) { $tags[] = 'default'; }
            $label = $d['name'] . ($tags ? ' (' . implode(', ', $tags) . ')' : '');
            ?>
            <option value="<?= (int) $d['id'] ?>"<?= (int) $d['id'] === $selectedDesignId ? ' selected' : '' ?>>
                <?= htmlspecialchars($label) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <noscript><button type="submit" class="btn btn-outline-secondary btn-sm">Go</button></noscript>
</form>
<?php endif; ?>

<?php /* Primary print action */ ?>
<button type="button" class="btn btn-primary btn-sm" id="do-print">
    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor"
         class="me-1" viewBox="0 0 16 16" aria-hidden="true">
        <path d="M2.5 8a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1"/>
        <path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2
                 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2zm6
                 14H5a1 1 0 0 1-1-1v-1h8v1a1 1 0 0 1-1 1M4 3a1 1 0 0 1 1-1h6a1 1 0 0 1
                 1 1v2H4zm1 5a2 2 0 0 0-2 2v1H2a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h12a1 1 0
                 0 1 1 1v3a1 1 0 0 1-1 1h-1v-1a2 2 0 0 0-2-2z"/>
    </svg>
    Print Card
</button>

<?php /* Mark-as-printed — from workflow: updates checklist + redirects to #fulfill */ ?>
<form method="post" class="d-inline" id="mark-printed-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="mark_printed">
    <input type="hidden" name="member_id" value="<?= $memberId ?>">
    <?php if ($fromProcess): ?>
    <input type="hidden" name="from_process" value="1">
    <input type="hidden" name="year" value="<?= $workYear ?>">
    <?php if ($fromWizard): ?>
    <input type="hidden" name="wizard" value="1">
    <?php endif; ?>
    <?php endif; ?>
    <button type="submit" class="btn btn-outline-success btn-sm">Mark as printed</button>
</form>

<?php if ($printed): ?>
<span class="text-success small ms-2">✓ Recorded as printed.</span>
<?php endif; ?>

<?php /* Member name context so staff know whose card they're printing */ ?>
<span class="text-muted small ms-auto d-none d-md-inline">
    <?= htmlspecialchars($memberData['full_name']) ?>
</span>

</div>



<div id="badge-print-warning"
     class="alert alert-warning no-print py-2 mb-3"
     style="display:none;">
    <strong>Warning:</strong> Printing fallback will be used if the badge image cannot be exported.
</div>

<div id="card-print-area" data-print-front="<?= $printFront ? '1' : '0' ?>" data-print-back="<?= $printBack ? '1' : '0' ?>">
    <div class="card-sheet">
        <div class="card-front-wrap" id="card-front-wrap"<?= !$printFront ? ' style="display:none"' : '' ?>>
            <canvas id="badge-front" width="<?= $cardWidthLandscape ?>" height="<?= $cardHeightLandscape ?>"></canvas>
            <img id="badge-front-img" alt="Badge front" style="display:none;">
        </div>
        <div class="card-back-wrap" id="card-back-wrap"<?= !$printBack ? ' style="display:none"' : '' ?>>
            <div id="badge-back" class="badge-back-content"></div>
        </div>
    </div>
</div>
<p id="card-loading" class="no-print text-muted small">Loading card…</p>

<style<?= csp_nonce_attr() ?>>
/* ── Screen preview ─────────────────────────────────────────────────────── */
.card-sheet {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 1rem;
}
.card-front-wrap,
.card-back-wrap { border: 1px solid #ddd; }
.badge-back-content {
    width: <?= $cardWidthLandscape ?>px;
    min-height: <?= $cardHeightLandscape ?>px;
    padding: 8px;
    font-size: 11px;
    box-sizing: border-box;
}

/* ── Print ──────────────────────────────────────────────────────────────────
 *
 * Strategy: before window.print() JS moves #card-print-area to be a direct
 * child of <body> (bypassing Bootstrap's .container entirely), adds
 * body.printing to hide everything else, then restores the DOM after print.
 * This is more reliable than fighting inherited margins with CSS overrides.
 */
@media print {
    @page {
        size: 3.375in 2.125in;  /* CR80 landscape — JS overrides for portrait */
        margin: 0;
    }

    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }

    /* Hide all page chrome */
    .no-print, nav.navbar, .breadcrumb, footer,
    #card-loading { display: none !important; }

    /* While body.printing: hide everything except the card area */
    body.printing > *:not(#card-print-area) { display: none !important; }
    body { margin: 0 !important; padding: 0 !important; }

    /* Print area: no margin/padding so card sits at page origin */
    #card-print-area {
        margin: 0 !important;
        padding: 0 !important;
        position: relative !important;
    }

    /* Card sheet and wraps: no extra space, exact card size */
    .card-sheet {
        display: block !important;
        margin: 0 !important;
        padding: 0 !important;
        width: 3.375in !important;
        height: 2.125in !important;
    }
    .card-front-wrap,
    .card-back-wrap {
        position: fixed !important;
        left: 0 !important;
        top: 0 !important;
        width: 3.375in !important;
        height: 2.125in !important;
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
        box-shadow: none !important;
    }

    /* Front: img fills the fixed-size box so it prints at exact CR80 size */
    #badge-front-img {
        display: block !important;
        width: 100% !important;
        height: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        object-fit: fill !important;
        object-position: top left !important;
    }
    /* Raw Fabric canvas never prints */
    #badge-front { display: none !important; }

    /* If canvas export fails (cross-origin taint), print the on-screen canvas instead. */
    body.badge-tainted #badge-front { display: block !important; }
    body.badge-tainted #badge-front-img { display: none !important; }

    /* Back content: exact CR80 size, tight padding/font so all text fits without cutting off. */
    .badge-back-content {
        display: block !important;
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 3.375in !important;
        height: 2.125in !important;
        min-height: unset !important;
        max-height: 2.125in !important;
        padding: 0.06in !important;
        font-size: 7.5pt !important;
        line-height: 1.25 !important;
        box-sizing: border-box !important;
        overflow: hidden !important;
        margin: 0 !important;
        background: #fff !important;
        word-wrap: break-word !important;
        overflow-wrap: break-word !important;
        hyphens: auto !important;
    }
    /* No vertical gap between blocks so every line fits */
    .badge-back-content > *:first-child { margin-top: 0 !important; }
    .badge-back-content > *:last-child  { margin-bottom: 0 !important; }
    .badge-back-content p,
    .badge-back-content ul,
    .badge-back-content div { margin: 0 !important; }
    .badge-back-content p:first-child,
    .badge-back-content ul:first-child,
    .badge-back-content div:first-child { margin-top: 0 !important; }
    .badge-back-content p:last-child,
    .badge-back-content ul:last-child,
    .badge-back-content div:last-child { margin-bottom: 0 !important; }

    /* Two-step: hide the side NOT currently printing */
    body.print-step-front .card-back-wrap  { display: none !important; }
    body.print-step-back  .card-front-wrap { display: none !important; }

    /* Portrait overrides — body classes toggled by JS */
    body.print-portrait-front .card-front-wrap,
    body.print-portrait-front .card-sheet {
        width: 2.125in !important;
        height: 3.375in !important;
    }
    body.print-portrait-front #badge-front-img {
        width: 100% !important;
        height: 100% !important;
    }
    body.print-portrait-back .card-sheet {
        width: 2.125in !important;
        height: 3.375in !important;
    }
    body.print-portrait-back .card-back-wrap {
        width: 2.125in !important;
        height: 3.375in !important;
    }
    body.print-portrait-back .badge-back-content {
        width: 2.125in !important;
        height: 3.375in !important;
        max-height: 3.375in !important;
    }
}
</style>

<?php require_once __DIR__ . '/includes/vendor_assets.php'; ?>
<script src="<?= htmlspecialchars(flightops_fabric_js_url()) ?>"></script>
<script src="js/badge_fabric.js"></script>
<script<?= csp_nonce_attr() ?>>
window.FLIGHTOPS_BADGE_PRINT = <?= json_encode([
    'memberData'          => $memberData,
    'templateData'        => $templateData,
    'autoMarkCard'       => $fromProcess,
    'returnTo'           => $returnTo,
    'memberId'           => $memberId,
    'workYear'          => $workYear,
    'cardWidthLandscape'  => $cardWidthLandscape,
    'cardHeightLandscape' => $cardHeightLandscape,
    'cardWidthPortrait'   => $cardWidthPortrait,
    'cardHeightPortrait'  => $cardHeightPortrait,
], JSON_THROW_ON_ERROR) ?>;
</script>
<script src="js/badge_print.js"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>