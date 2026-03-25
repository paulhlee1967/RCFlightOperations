<?php
/**
 * Print a member's badge (front + back). Fills template with member data.
 * "Mark as printed" records badge_printed_at on the member.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

requireLogin();
requireFeature('badge_designer');

if (!canEditMembers() && !canProcessMemberships()) {
    header('Location: index.php');
    exit;
}

// Detect calling context so the back-link is contextually correct
$fromProcess = !empty($_GET['from_process']);
$workYear    = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$memberId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$membershipTypeLabels = enabledMembershipTypeLabels($pdo);

// POST: mark card as printed
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_printed') {
    csrf_validate();
    $mid = isset($_POST['member_id']) ? (int) $_POST['member_id'] : 0;
    if ($mid > 0) {
        $pdo->prepare('UPDATE members SET badge_printed_at = NOW() WHERE id = ?')->execute([$mid]);
    }
    header('Location: badge_print.php?id=' . $mid . '&printed=1');
    exit;
}

if ($memberId <= 0) {
    header('Location: members.php');
    exit;
}

// Load member + primary address (same logic as member_envelope: Home > Work > Other)
$stmt = $pdo->prepare('
    SELECT m.id, m.first_name, m.last_name, m.email, m.date_joined, m.membership_type_slot,
           m.membership_renewal_year, m.ama_number, m.faa_number, m.gate_key_number, m.photo_path,
           m.emergency_contact_name, m.emergency_contact_relationship, m.emergency_contact_phone,
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



// Build member data for JS (match dataField keys)
$memberSince = '';
if (!empty($member['date_joined'])) {
    $memberSince = date('m/d/Y', strtotime($member['date_joined']));
}
$photoDataUrl = '';
$photoUrl = '';
if (!empty($member['photo_path'])) {
    $photoFile = __DIR__ . '/' . $member['photo_path'];
    if (is_file($photoFile) && is_readable($photoFile)) {
        $photoUrl = 'badge_photo.php?id=' . $memberId;
        $photoData = base64_encode(file_get_contents($photoFile));
        $ext = strtolower(pathinfo($photoFile, PATHINFO_EXTENSION));
        $mime = $ext === 'png' ? 'image/png' : ($ext === 'gif' ? 'image/gif' : 'image/jpeg');
        $photoDataUrl = 'data:' . $mime . ';base64,' . $photoData;
    }
}
// Full address block (same format as envelope / badge designer)
$addressBlock = '';
if (!empty($member['street']) && !empty($member['city'])) {
    $addressBlock = trim($member['street']);
    if (!empty($member['street2'])) {
        $addressBlock .= "\n" . trim($member['street2']);
    }
    $addressBlock .= "\n" . trim(($member['city'] ?? '') . ', ' . ($member['state'] ?? '') . ' ' . ($member['postal_code'] ?? ''));
}

$memberData = [
    'full_name' => trim($member['last_name'] . ', ' . $member['first_name']),
    'first_name' => $member['first_name'] ?? '',
    'last_name' => $member['last_name'] ?? '',
    'member_since' => $memberSince !== '' ? $memberSince : '',
    'date_joined' => $member['date_joined'] ?? '',
    'membership_type' => ((int) ($member['membership_type_slot'] ?? 0)) > 0
        ? ($membershipTypeLabels[(int) $member['membership_type_slot']] ?? ('Type ' . (int) $member['membership_type_slot']))
        : '',
    'renewal_year' => $member['membership_renewal_year'] ?? '',
    'ama_number' => $member['ama_number'] ?? '',
    'faa_number' => $member['faa_number'] ?? '',
    'gate_key_number' => $member['gate_key_number'] ?? '',
    'street' => $member['street'] ?? '',
    'street2' => $member['street2'] ?? '',
    'city' => $member['city'] ?? '',
    'state' => $member['state'] ?? '',
    'postal_code' => $member['postal_code'] ?? '',
    'address_block' => $addressBlock,
    'emergency_contact_name' => $member['emergency_contact_name'] ?? '',
    'emergency_contact_relationship' => $member['emergency_contact_relationship'] ?? '',
    'emergency_contact_phone' => $member['emergency_contact_phone'] ?? '',
    'photo_path' => $member['photo_path'] ?? '',
    'photo_data_url' => $photoDataUrl,
    'photo_url' => $photoUrl,
];

$templateData = null;
$stmt = $pdo->query('SELECT template_data FROM badge_templates ORDER BY id ASC LIMIT 1');
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row && $row['template_data']) {
    $templateData = json_decode($row['template_data'], true);
}

$cardWidthLandscape = 400;
$cardHeightLandscape = (int) round(400 * 53.98 / 85.6);
$cardWidthPortrait = $cardHeightLandscape;
$cardHeightPortrait = $cardWidthLandscape;

$printed = isset($_GET['printed']);
$printFront = isset($_GET['front']) ? (int) $_GET['front'] : 1;
$printBack = isset($_GET['back']) ? (int) $_GET['back'] : 1;
if (!$printFront && !$printBack) {
    $printFront = 1;
    $printBack = 1;
}
$pageTitle = 'Print card: ' . $memberData['full_name'];
$noNav = true;
require_once __DIR__ . '/includes/header.php';
?>

<div class="no-print d-flex flex-wrap align-items-center gap-2 mb-3 p-2 border-bottom bg-light">

<?php /* Context-aware back link */ ?>
<?php if ($fromProcess): ?>
<a href="member_process.php?id=<?= $memberId ?>&year=<?= $workYear ?>#fulfill"
   class="btn btn-outline-secondary btn-sm">← Back to Workflow</a>
<?php else: ?>
<a href="member_edit.php?id=<?= $memberId ?>"
   class="btn btn-outline-secondary btn-sm">← Back to Member</a>
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

<?php /* Mark-as-printed form — unchanged logic */ ?>
<form method="post" class="d-inline" id="mark-printed-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="mark_printed">
    <input type="hidden" name="member_id" value="<?= $memberId ?>">
    <button type="submit" class="btn btn-outline-success btn-sm">Mark as printed</button>
</form>

<?php /* Envelope shortcut — one click from the badge print page */ ?>
<div class="vr d-none d-sm-block mx-1"></div>
<a href="member_envelope.php?id=<?= $memberId ?>&from=<?= $fromProcess ? 'process' : 'edit' ?><?= $fromProcess ? '&year=' . $workYear : '' ?>"
   class="btn btn-outline-secondary btn-sm" target="_blank" title="Print a mailing envelope for this member">
    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor"
         class="me-1" viewBox="0 0 16 16" aria-hidden="true">
        <path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm2-1a1
                 1 0 0 0-1 1v.217l7 4.2 7-4.2V4a1 1 0 0 0-1-1zm13 2.383-4.708 2.825L15
                 11.105zm-.034 6.876-5.64-3.471L8 9.583l-1.326-.795-5.64 3.47A1 1 0 0 0 2
                 13h12a1 1 0 0 0 .966-.741M1 11.105l4.708-2.897L1 5.383z"/>
    </svg>
    Print Envelope
</a>

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

<script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.0/fabric.min.js"></script>
<script<?= csp_nonce_attr() ?>>
(function() {
    var memberData = <?= json_encode($memberData) ?>;
    var templateData = <?= $templateData ? json_encode($templateData) : 'null' ?>;

    var printArea = document.getElementById('card-print-area');
    var printFront = printArea && printArea.getAttribute('data-print-front') === '1';
    var printBack = printArea && printArea.getAttribute('data-print-back') === '1';

    // Remember the original parent so we can restore after printing
    var printAreaOriginalParent   = printArea ? printArea.parentNode : null;
    var printAreaOriginalNextSibling = printArea ? printArea.nextSibling : null;

    /**
     * Move #card-print-area to be a direct child of <body>.
     * This bypasses Bootstrap's .container margin/padding entirely —
     * the card starts at the exact page origin with no inherited offsets.
     */
    function moveCardToBody() {
        if (printArea && printArea.parentNode !== document.body) {
            document.body.appendChild(printArea);
        }
        document.body.classList.add('printing');
    }

    /**
     * Restore #card-print-area to its original DOM position.
     */
    function restoreCard() {
        if (printArea && printAreaOriginalParent) {
            if (printAreaOriginalNextSibling) {
                printAreaOriginalParent.insertBefore(printArea, printAreaOriginalNextSibling);
            } else {
                printAreaOriginalParent.appendChild(printArea);
            }
        }
        document.body.classList.remove(
            'printing', 'print-step-front', 'print-step-back',
            'print-portrait-front', 'print-portrait-back'
        );
    }

    /**
     * Inject (or replace) a <style id="dynamic-page-size"> tag to set @page
     * to the correct physical CR80 dimensions for the given orientation.
     * Landscape = 3.375" × 2.125"  |  Portrait = 2.125" × 3.375"
     *
     * @param {string} ori   'landscape' | 'portrait'
     * @param {string} side  'front' | 'back'
     */
    function setPageSize(ori, side) {
        var w = ori === 'portrait' ? '2.125in' : '3.375in';
        var h = ori === 'portrait' ? '3.375in' : '2.125in';
        var el = document.getElementById('dynamic-page-size');
        if (!el) {
            el = document.createElement('style');
            el.id = 'dynamic-page-size';
            document.head.appendChild(el);
        }
        el.textContent = '@page { size: ' + w + ' ' + h + '; margin: 0; }';

        document.body.classList.remove('print-portrait-front', 'print-portrait-back');
        if (ori === 'portrait') {
            document.body.classList.add('print-portrait-' + side);
        }
    }

    document.getElementById('do-print').addEventListener('click', function() {
        var frontOri = (typeof orientation     !== 'undefined') ? orientation     : 'landscape';
        var backOri  = (typeof backOrientation !== 'undefined') ? backOrientation : 'landscape';

        function doPrintFront() {
            setPageSize(frontOri, 'front');
            moveCardToBody();
            document.body.classList.add('print-step-front');
            document.body.classList.remove('print-step-back');
            window.print();
        }
        function doPrintBack() {
            setPageSize(backOri, 'back');
            moveCardToBody();
            document.body.classList.remove('print-step-front');
            document.body.classList.add('print-step-back');
            window.print();
        }

        if (printFront && printBack) {
            doPrintFront();
            window.addEventListener('afterprint', function step2() {
                window.removeEventListener('afterprint', step2);
                doPrintBack();
                window.addEventListener('afterprint', function step3() {
                    window.removeEventListener('afterprint', step3);
                    restoreCard();
                }, { once: true });
            }, { once: true });
        } else if (printFront) {
            doPrintFront();
            window.addEventListener('afterprint', restoreCard, { once: true });
        } else if (printBack) {
            doPrintBack();
            window.addEventListener('afterprint', restoreCard, { once: true });
        }
    });

    if (!templateData || !templateData.canvas) {
        document.getElementById('badge-front').after(document.createTextNode('No badge template saved. Design one under Administration → Badge design.'));
        document.getElementById('badge-back').innerHTML = '<p class="text-muted">No back design.</p>';
        return;
    }

    var orientation = (templateData.orientation === 'portrait') ? 'portrait' : 'landscape';
    var backOrientation = (templateData.backOrientation === 'portrait') ? 'portrait' : 'landscape';
    var cardW = orientation === 'portrait' ? <?= $cardWidthPortrait ?> : <?= $cardWidthLandscape ?>;
    var cardH = orientation === 'portrait' ? <?= $cardHeightPortrait ?> : <?= $cardHeightLandscape ?>;
    var backW = backOrientation === 'portrait' ? <?= $cardWidthPortrait ?> : <?= $cardWidthLandscape ?>;
    var backH = backOrientation === 'portrait' ? <?= $cardHeightPortrait ?> : <?= $cardHeightLandscape ?>;

    document.getElementById('badge-front').width = cardW;
    document.getElementById('badge-front').height = cardH;
    document.querySelector('.badge-back-content').style.width = backW + 'px';
    document.querySelector('.badge-back-content').style.minHeight = backH + 'px';

    if (templateData.backHtml) {
        var backHtml = templateData.backHtml.replace(/\{\{(\w+)\}\}/g, function (_, field) {
            return getMemberValue(field);
        });
        document.getElementById('badge-back').innerHTML = backHtml;
        scaleBackToFit();
    } else {
        document.getElementById('badge-back').innerHTML = '';
    }

    /**
     * Measure back content height and scale font/padding so it fits in the card
     * without cutting off the last line(s). Uses inline styles with !important
     * so print CSS doesn't override.
     */
    function scaleBackToFit() {
        var el = document.getElementById('badge-back');
        if (!el || !el.innerHTML.trim()) return;
        var wrap = document.getElementById('card-back-wrap');
        if (!wrap) return;
        // Temporarily let content grow so we can measure full height
        el.style.height = 'auto';
        el.style.minHeight = '0';
        el.style.overflow = 'visible';
        el.style.position = 'absolute';
        el.style.left = '-9999px';
        el.style.top = '0';
        el.style.width = backW + 'px';
        var contentHeight = el.offsetHeight;
        // Restore for layout
        el.style.position = '';
        el.style.left = '';
        el.style.top = '';
        el.style.height = '';
        el.style.minHeight = '';
        el.style.overflow = '';
        // Use print content area height (card minus padding) in px at 96dpi
        var cardHeightIn = backOrientation === 'portrait' ? 3.375 : 2.125;
        var paddingIn = 0.06 * 2;
        var containerPx = Math.round((cardHeightIn - paddingIn) * 96);
        if (contentHeight <= 0) return;
        var scale = Math.min(1, containerPx / contentHeight);
        if (scale >= 1) return;
        // Apply scaled typography so content reflows and fits (override print CSS)
        el.style.setProperty('font-size', (7.5 * scale).toFixed(2) + 'pt', 'important');
        el.style.setProperty('padding', (0.06 * scale).toFixed(3) + 'in', 'important');
        el.style.setProperty('line-height', (1.25 * scale).toFixed(2), 'important');
    }

    function getMemberValue(field) {
        return memberData[field] !== undefined && memberData[field] !== null ? String(memberData[field]) : '';
    }

    var canvas = new fabric.StaticCanvas('badge-front', { enableRetinaScaling: false });
    canvas.setDimensions({ width: cardW, height: cardH });

// Always prefer the path on disk over the saved data-URL snapshot.
// This ensures a re-uploaded background (same filename) shows up correctly
// without needing to re-save the template after every background swap.
// The cache-busting ?t= prevents any server/proxy from serving stale bytes.
var bgUrl = null;
if (templateData.backgroundPath) {
    var base = window.location.href.replace(/[#?].*$/, '').replace(/\/[^/]*$/, '/');
    bgUrl = new URL(templateData.backgroundPath, base).href
            + '?t=' + Date.now();
} else if (templateData.backgroundDataUrl) {
    bgUrl = templateData.backgroundDataUrl;
}

    function resolveUrl(path) {
        if (!path || path.indexOf('data:') === 0 || path.indexOf('http') === 0) return path;
        var base = window.location.href.replace(/[#?].*$/, '').replace(/\/[^/]*$/, '/');
        return new URL(path, base).href;
    }

    function setBackground(done) {
        if (!bgUrl) { done(); return; }
        fabric.Image.fromURL(bgUrl, function(img) {
            if (img) {
                var w = img.get('width'), h = img.get('height');
                if (w && h) {
                    var scale = Math.max(cardW / w, cardH / h);
                    img.set('scaleX', scale);
                    img.set('scaleY', scale);
                    img.set('left', (cardW - w * scale) / 2);
                    img.set('top', (cardH - h * scale) / 2);
                    img.set('originX', 'left');
                    img.set('originY', 'top');
                }
                canvas.setBackgroundImage(img, canvas.renderAll.bind(canvas));
            }
            done();
        }, bgUrl.indexOf('data:') === 0 ? {} : { crossOrigin: 'anonymous' });
    }

    function applyMemberData(done) {
        var objects = canvas.getObjects();
        var savedObjs = (templateData.canvas.objects || []);
        // Prefer data URL so the canvas is never tainted — toDataURL('image/png') then works and the photo appears in print
        var photoDataUrl = getMemberValue('photo_data_url');
        var photoEndpoint = getMemberValue('photo_url');
        var photoPath = getMemberValue('photo_path');
        var photoUrl = photoDataUrl || photoEndpoint || (photoPath ? resolveUrl(photoPath) : '');
        var pending = 0;
        function checkDone() {
            pending--;
            if (pending === 0) done();
        }
        savedObjs.forEach(function(saved, i) {
            var obj = objects[i];
            if (!obj) return;
            var field = saved.dataField;
            if (!field) return;
            if (field === 'photo') {
                if (!photoUrl) {
                    canvas.requestRenderAll();
                    return;
                }
                pending++;
                (function(orig) {
                    var rect = orig.getBoundingRect();
                    var left = rect.left;
                    var top = rect.top;
                    var w = Math.max(rect.width || 80, 1);
                    var h = Math.max(rect.height || 100, 1);
                    var opts = (photoUrl.indexOf('data:') === 0 || photoUrl.indexOf('http') !== 0) ? {} : { crossOrigin: 'anonymous' };
                    fabric.Image.fromURL(photoUrl, function(img) {
                        if (img && canvas.getObjects().indexOf(orig) !== -1) {
                            img.set('left', left);
                            img.set('top', top);
                            img.set('originX', 'left');
                            img.set('originY', 'top');
                            if (w > 0 && h > 0) {
                                img.scaleToWidth(w);
                                if (img.getScaledHeight() > h) img.scaleToHeight(h);
                            }
                            canvas.add(img);
                            orig.set('visible', false);
                        }
                        canvas.requestRenderAll();
                        checkDone();
                    }, opts);
                })(obj);
            } else if (field === 'freeform') {
                // Freeform text: keep the text from the template (same on every badge)
                return;
            } else {
                var val = getMemberValue(field);
                if (typeof obj.setText === 'function') obj.setText(val);
                else obj.set('text', val);
                if (field === 'full_name') {
                    obj.set('fontSize', 24);
                }
            }
        });
        canvas.requestRenderAll();
        if (pending === 0) done();
    }


    function finalizeForPrint() {
        try {
            var dataUrl = canvas.toDataURL('image/png');
            var imgEl = document.getElementById('badge-front-img');
            imgEl.src = dataUrl;
            // Show at canvas pixel size for the on-screen preview only.
            // Print CSS overrides these with exact inch dimensions via position:fixed.
            imgEl.style.display = 'block';
            imgEl.style.width   = cardW + 'px';
            imgEl.style.height  = cardH + 'px';
            // Hide the raw Fabric canvas — the img is used for printing
            document.getElementById('badge-front').style.display = 'none';
        } catch (e) {
            // Cross-origin canvas taint: canvas stays visible as screen fallback;
            // fall back to printing the canvas itself.
            console.warn('badge_print: toDataURL() failed; canvas export blocked (possible cross-origin taint).', e);
            document.body.classList.add('badge-tainted');
            var warnEl = document.getElementById('badge-print-warning');
            if (warnEl) {
                warnEl.style.display = 'block';
                warnEl.innerHTML = '<strong>Warning:</strong> The badge image could not be exported for printing (cross-origin). Falling back to the on-screen canvas.';
            }
        }
        document.getElementById('card-loading').style.display = 'none';
    }

    // Order: load canvas (objects only), then set background (so it is not overwritten), then apply member data, then export to img for reliable printing.
    // Delay allows async photo load + canvas paint to complete before toDataURL().
    canvas.loadFromJSON(templateData.canvas, function() {
        setBackground(function() {
            applyMemberData(function() {
                canvas.requestRenderAll();
                var raf = (typeof fabric !== 'undefined' && fabric.util && fabric.util.requestAnimFrame)
                    ? fabric.util.requestAnimFrame.bind(fabric.util)
                    : (window.requestAnimationFrame || function(cb) { setTimeout(cb, 16); });
                raf(function() {
                    raf(function() {
                        raf(function() {
                            finalizeForPrint();
                        });
                    });
                });
            });
        });
    });
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>