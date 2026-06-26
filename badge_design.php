<?php
/**
 * badge_design.php — CR80 Badge Designer (overhauled)
 *
 * Improvements over the original:
 *   - Two-column layout: left sidebar (field panel + properties) / right canvas
 *   - Field panel: click any field to add it; no hunting through a dropdown
 *   - Selected-object property panel: font size, bold, italic, colour, alignment
 *   - Live member preview: pick any real member from a dropdown and the canvas
 *     renders with their actual data so you can see exactly how the card looks
 *   - Front/back tabs integrated cleanly in the canvas column
 *   - "Preview member" is separate from "Design mode" — no accidental saves
 *     of member-filled data
 *
 * Data model and save/load format are identical to the original so existing
 * saved templates load without migration.
 *
 * Editor/Admin only.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

requireLogin();
requireFeature('badge_designer');

if (!canEditMembers()) {
    header('Location: index.php');
    exit;
}
$userId   = currentUserId();
$membershipTypeLabels = enabledMembershipTypeLabels($pdo);

/** Uniform JSON exit for designer API endpoints (except raw template load). */
function badge_api_json(array $payload): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

// ── API: load ──────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'load') {
    header('Content-Type: application/json');
    $stmt = $pdo->query('SELECT template_data FROM badge_templates ORDER BY id ASC LIMIT 1');
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    echo $row ? $row['template_data'] : '{}';
    exit;
}

// ── API: save ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    csrf_validate(['json' => true]);
    header('Content-Type: application/json; charset=utf-8');
    $json = $_POST['template'] ?? '';
    if ($json === '') {
        badge_api_json(['ok' => false, 'error' => 'No template data']);
    }
    try {
        $row = $pdo->query('SELECT id FROM badge_templates ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $stmt = $pdo->prepare('UPDATE badge_templates SET template_data = ? WHERE id = ?');
            $stmt->execute([$json, $row['id']]);
        } else {
            $pdo->prepare('INSERT INTO badge_templates (template_data) VALUES (?)')->execute([$json]);
        }
        badge_api_json(['ok' => true]);
    } catch (Throwable $e) {
        badge_api_json(['ok' => false, 'error' => 'Save failed — database error']);
    }
}

// ── API: background image upload ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['background']) && is_uploaded_file($_FILES['background']['tmp_name'])) {
    csrf_validate(['json' => true]);
    header('Content-Type: application/json; charset=utf-8');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($_FILES['background']['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];
    if (!isset($allowed[$mime]) || $_FILES['background']['size'] > 3 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'error' => 'Invalid or too-large file (max 3 MB, JPEG/PNG/GIF)']);
        exit;
    }
    $uploadDir = __DIR__ . '/uploads/branding';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $ext      = $allowed[$mime];
    $filename = 'badge_background.' . $ext;
    $path     = $uploadDir . '/' . $filename;
    if (move_uploaded_file($_FILES['background']['tmp_name'], $path)) {
        $url = 'uploads/branding/' . $filename;
        echo json_encode(['ok' => true, 'url' => $url]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Upload failed — check permissions on uploads/']);
    }
    exit;
}

// ── API: member data for preview ───────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'member_data') {
    header('Content-Type: application/json; charset=utf-8');
    $mid = (int) ($_GET['member_id'] ?? 0);
    if ($mid <= 0) {
        badge_api_json(['ok' => false, 'error' => 'Invalid member']);
    }

    // Load member + primary address (same logic as member_envelope: Home > Work > Other)
    $stmt = $pdo->prepare('
        SELECT m.id, m.first_name, m.last_name, m.date_joined, m.membership_type_slot,
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
    try {
        $stmt->execute([$mid]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        badge_api_json(['ok' => false, 'error' => 'Database error']);
    }
    if (!$m) {
        badge_api_json(['ok' => false, 'error' => 'Member not found']);
    }

    $memberSince = '';
    if (!empty($m['date_joined'])) {
        $memberSince =  date('m/d/Y', strtotime($m['date_joined']));
    }

    $photoDataUrl = '';
    if (!empty($m['photo_path'])) {
        $pf = __DIR__ . '/' . $m['photo_path'];
        if (is_file($pf) && is_readable($pf)) {
            $ext  = strtolower(pathinfo($pf, PATHINFO_EXTENSION));
            $mime = $ext === 'png' ? 'image/png' : ($ext === 'gif' ? 'image/gif' : 'image/jpeg');
            $photoDataUrl = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($pf));
        }
    }

    // Full address block (street; street2 if set; city, state zip) — same format as envelope
    $addressBlock = '';
    if (!empty($m['street']) && !empty($m['city'])) {
        $addressBlock = trim($m['street']);
        if (!empty($m['street2'])) {
            $addressBlock .= "\n" . trim($m['street2']);
        }
        $addressBlock .= "\n" . trim(($m['city'] ?? '') . ', ' . ($m['state'] ?? '') . ' ' . ($m['postal_code'] ?? ''));
    }

    badge_api_json([
        'ok'              => true,
        'full_name'       => trim(($m['last_name'] ?? '') . ', ' . ($m['first_name'] ?? '')),
        'full_name_first_last' => trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')),
        'first_name'      => $m['first_name'] ?? '',
        'last_name'       => $m['last_name'] ?? '',
        'member_since'    => $memberSince,
        'date_joined'     => $m['date_joined'] ?? '',
        'membership_type' => ((int) ($m['membership_type_slot'] ?? 0)) > 0
            ? ($membershipTypeLabels[(int) $m['membership_type_slot']] ?? ('Type ' . (int) $m['membership_type_slot']))
            : '',
        'renewal_year'    => $m['membership_renewal_year'] ?? '',
        'ama_number'      => $m['ama_number'] ?? '',
        'faa_number'      => $m['faa_number'] ?? '',
        'gate_key_number' => $m['gate_key_number'] ?? '',
        'street'          => $m['street'] ?? '',
        'street2'         => $m['street2'] ?? '',
        'city'            => $m['city'] ?? '',
        'state'           => $m['state'] ?? '',
        'postal_code'     => $m['postal_code'] ?? '',
        'address_block'   => $addressBlock,
        'emergency_contact_name'         => $m['emergency_contact_name'] ?? '',
        'emergency_contact_relationship' => $m['emergency_contact_relationship'] ?? '',
        'emergency_contact_phone'        => $m['emergency_contact_phone'] ?? '',
        'photo_data_url'  => $photoDataUrl,
    ]);
}

// ── API: member list for preview picker ────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'member_list') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $badgeYear = membershipStatusYear();
        $stmt = $pdo->prepare('
            SELECT m.id, m.first_name, m.last_name
            FROM members m
            WHERE ' . currentMemberWhereSql('m', $badgeYear) . '
            ORDER BY m.last_name, m.first_name
            LIMIT 200
        ');
        $stmt->execute(currentMemberWhereParams($badgeYear));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rows = [];
        badge_api_json(['ok' => false, 'error' => 'Could not load members', 'members' => []]);
    }
    badge_api_json(['ok' => true, 'members' => $rows]);
}

// ── Page render ────────────────────────────────────────────────────────────
$pageTitle = 'Badge Designer';
require_once __DIR__ . '/includes/header.php';

// CR80: 85.6 × 53.98 mm
$cardWidthLandscape  = 400;
$cardHeightLandscape = (int) round(400 * 53.98 / 85.6);
$cardWidthPortrait   = $cardHeightLandscape;
$cardHeightPortrait  = $cardWidthLandscape;

// Data fields available for placement (freeform = static text, same on every badge)
$dataFields = [
    'freeform'        => ['label' => 'Freeform text',    'placeholder' => 'Your text here',   'icon' => '✏️'],
    'full_name'       => ['label' => 'Full name (Last, First)', 'placeholder' => '<LAST, FIRST>', 'icon' => '👤'],
    'full_name_first_last' => ['label' => 'Full name (First Last)', 'placeholder' => '<FIRST LAST>', 'icon' => '👤'],
    'first_name'      => ['label' => 'First name',       'placeholder' => '<FIRST>',          'icon' => '👤'],
    'last_name'       => ['label' => 'Last name',        'placeholder' => '<LAST>',           'icon' => '👤'],
    'member_since'    => ['label' => 'Member since',     'placeholder' => '<XX/XX/XXXX>',   'icon' => '📅'],
    'date_joined'     => ['label' => 'Date joined',      'placeholder' => '<DATE JOINED>',    'icon' => '📅'],
    'membership_type' => ['label' => 'Membership type',  'placeholder' => '<TYPE>',           'icon' => '🏷'],
    'renewal_year'    => ['label' => 'Renewal year',     'placeholder' => '<YEAR>',           'icon' => '🗓'],
    'ama_number'      => ['label' => 'AMA number',       'placeholder' => '<AMA #>',          'icon' => '✈'],
    'faa_number'      => ['label' => 'FAA number',       'placeholder' => '<FAA #>',          'icon' => '✈'],
    'gate_key_number' => ['label' => 'Gate key #',       'placeholder' => '<GATE KEY>',       'icon' => '🔑'],
    'street'          => ['label' => 'Address (street)', 'placeholder' => '<STREET>',         'icon' => '📍'],
    'street2'         => ['label' => 'Address (suite/apt)', 'placeholder' => '<SUITE/APT>', 'icon' => '📍'],
    'city'            => ['label' => 'City',            'placeholder' => '<CITY>',           'icon' => '📍'],
    'state'           => ['label' => 'State',            'placeholder' => '<STATE>',          'icon' => '📍'],
    'postal_code'     => ['label' => 'Postal code',      'placeholder' => '<ZIP>',            'icon' => '📍'],
    'address_block'   => ['label' => 'Full address',    'placeholder' => '<ADDRESS>',       'icon' => '📍'],
    'emergency_contact_name'         => ['label' => 'Emergency contact',   'placeholder' => '<EMERGENCY NAME>',   'icon' => '🆘'],
    'emergency_contact_relationship' => ['label' => 'Emergency relation',   'placeholder' => '<RELATIONSHIP>',     'icon' => '🆘'],
    'emergency_contact_phone'        => ['label' => 'Emergency phone',     'placeholder' => '<EMERGENCY PHONE>',   'icon' => '🆘'],
];
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h2 mb-0">Badge Designer</h1>
        <p class="text-muted small mb-0">Design your CR80 member ID card (3.375″ × 2.125″)</p>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <span id="save-status" class="small text-muted"></span>
        <input type="hidden" id="csrf_token_value" value="<?= htmlspecialchars(csrf_token()) ?>">
        <button type="button" class="btn btn-primary" id="saveDesign">
            Save Design
        </button>
    </div>
</div>

<div class="row g-3" id="designer-root">

    <?php /* ── Left sidebar ─────────────────────────────────────────────── */ ?>
    <div class="col-lg-3 col-md-4 order-2 order-md-1" id="designer-sidebar">

        <?php /* Card: orientation + front/back tabs */ ?>
        <div class="card mb-3 shadow-sm">
            <div class="card-header fw-semibold small py-2">Card options</div>
            <div class="card-body py-2 px-3">
                <div class="mb-2">
                    <label class="form-label small mb-1">Side</label>
                    <div class="btn-group w-100" role="group" id="sideToggle">
                        <input type="radio" class="btn-check" name="sideRadio" id="sideRadioFront" value="front" checked>
                        <label class="btn btn-outline-primary btn-sm" for="sideRadioFront">Front</label>
                        <input type="radio" class="btn-check" name="sideRadio" id="sideRadioBack" value="back">
                        <label class="btn btn-outline-primary btn-sm" for="sideRadioBack">Back</label>
                    </div>
                </div>
                <div class="mb-2" id="orientation-wrap">
                    <label class="form-label small mb-1" for="orientation">Front orientation</label>
                    <select id="orientation" class="form-select form-select-sm">
                        <option value="landscape">Landscape</option>
                        <option value="portrait">Portrait</option>
                    </select>
                </div>
                <div class="mb-2" id="back-orientation-wrap" style="display:none">
                    <label class="form-label small mb-1" for="back-orientation">Back orientation</label>
                    <select id="back-orientation" class="form-select form-select-sm">
                        <option value="landscape">Landscape</option>
                        <option value="portrait">Portrait</option>
                    </select>
                </div>
                <label class="btn btn-outline-secondary btn-sm w-100 mb-0 mt-1">
                    <input type="file" accept="image/*" id="bg-upload" class="d-none">
                    📷 Set background image
                </label>
                <button type="button" class="btn btn-outline-secondary btn-sm w-100 mt-1" id="bg-remove" title="Remove background image">
                    🗑 Remove background
                </button>
                <div id="bg-status" class="small text-muted mt-1"></div>
            </div>
        </div>

        <?php /* Card: add fields (only shown when on front side) */ ?>
        <div class="card mb-3 shadow-sm" id="fields-panel">
            <div class="card-header fw-semibold small py-2">Add field to front</div>
            <div class="card-body py-2 px-2">
                <div class="d-grid gap-1">
                    <?php foreach ($dataFields as $field => $info): ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm text-start add-field-btn"
                            data-field="<?= htmlspecialchars($field) ?>"
                            data-placeholder="<?= htmlspecialchars($info['placeholder']) ?>">
                        <span class="me-1"><?= $info['icon'] ?></span>
                        <?= htmlspecialchars($info['label']) ?>
                    </button>
                    <?php endforeach; ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm text-start" id="addPhoto">
                        🖼 Member photo
                    </button>
                </div>
                <hr class="my-2">
                <button type="button" class="btn btn-outline-danger btn-sm w-100" id="deleteObj">
                    🗑 Delete selected
                </button>
                <div class="btn-group btn-group-sm w-100 mt-1" role="group" aria-label="Undo redo">
                    <button type="button" class="btn btn-outline-secondary" id="undo-canvas" title="Undo (Ctrl+Z)">↶ Undo</button>
                    <button type="button" class="btn btn-outline-secondary" id="redo-canvas" title="Redo (Ctrl+Shift+Z)">↷ Redo</button>
                </div>
            </div>
        </div>

        <?php /* Card: selected object properties (hidden until something is selected) */ ?>
        <div class="card mb-3 shadow-sm" id="props-panel" style="display:none">
            <div class="card-header fw-semibold small py-2">
                Selected field
                <span id="props-field-name" class="text-muted fw-normal ms-1 small"></span>
            </div>
            <div class="card-body py-2 px-3">
                <div class="mb-2">
                    <label class="form-label small mb-1" for="prop-fontsize">Font size</label>
                    <input type="number" id="prop-fontsize" class="form-control form-control-sm"
                           min="6" max="72" step="1" value="14">
                </div>
                <div class="mb-2">
                    <label class="form-label small mb-1">Style</label>
                    <div class="btn-group btn-group-sm" role="group">
                        <input type="checkbox" class="btn-check" id="prop-bold">
                        <label class="btn btn-outline-secondary" for="prop-bold"><strong>B</strong></label>
                        <input type="checkbox" class="btn-check" id="prop-italic">
                        <label class="btn btn-outline-secondary" for="prop-italic"><em>I</em></label>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label small mb-1" for="prop-color">Text colour</label>
                    <input type="color" id="prop-color" class="form-control form-control-sm form-control-color"
                           value="#000000" style="width:4rem">
                </div>
                <div class="mb-2">
                    <label class="form-label small mb-1">Alignment</label>
                    <div class="btn-group btn-group-sm" role="group" id="prop-align">
                        <button type="button" class="btn btn-outline-secondary" data-align="left">⬛️←</button>
                        <button type="button" class="btn btn-outline-secondary" data-align="center">↔</button>
                        <button type="button" class="btn btn-outline-secondary" data-align="right">→⬛️</button>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label small mb-1" for="prop-width">
                        Fixed width <span class="text-muted">(px, 0&nbsp;=&nbsp;auto)</span>
                    </label>
                    <input type="number" id="prop-width" class="form-control form-control-sm"
                           min="0" max="800" step="1" value="0"
                           title="Set a fixed width so text alignment has room to work">
                </div>
            </div>
        </div>

        <?php /* Card: live member preview */ ?>
        <div class="card shadow-sm" id="preview-panel">
            <div class="card-header fw-semibold small py-2">Live preview</div>
            <div class="card-body py-2 px-3">
                <p class="small text-muted mb-2">
                    Pick a member to see the card filled with their real data.
                    This does <strong>not</strong> affect the saved design.
                </p>
                <select id="preview-member-select" class="form-select form-select-sm mb-2">
                    <option value="">— Design mode (placeholders) —</option>
                </select>
                <div id="preview-status" class="small text-muted"></div>
            </div>
        </div>

    </div><!-- /.col (sidebar) -->

    <?php /* ── Right canvas column ─────────────────────────────────────── */ ?>
    <div class="col-lg-9 col-md-8 order-1 order-md-2" id="designer-canvas-col">

        <?php /* Front canvas */ ?>
        <div id="front-panel">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="badge bg-secondary">Front</span>
                    <span class="small text-muted">CR80 — drag fields to position, click to select &amp; edit properties</span>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="small text-muted text-nowrap mb-0" id="canvas-zoom-label">View</span>
                    <div class="btn-group btn-group-sm" role="group" aria-labelledby="canvas-zoom-label" id="canvas-zoom-btns">
                        <input type="radio" class="btn-check canvas-zoom-radio" name="canvasZoomFront" id="canvasZoomFit" value="fit" autocomplete="off">
                        <label class="btn btn-outline-secondary" for="canvasZoomFit" title="Scale to fit the column width">Fit</label>
                        <input type="radio" class="btn-check canvas-zoom-radio" name="canvasZoomFront" id="canvasZoom1" value="1" autocomplete="off" checked>
                        <label class="btn btn-outline-secondary" for="canvasZoom1" title="Actual design pixels (1×)">1×</label>
                        <input type="radio" class="btn-check canvas-zoom-radio" name="canvasZoomFront" id="canvasZoom15" value="1.5" autocomplete="off">
                        <label class="btn btn-outline-secondary" for="canvasZoom15" title="150% size">1.5×</label>
                        <input type="radio" class="btn-check canvas-zoom-radio" name="canvasZoomFront" id="canvasZoom2" value="2" autocomplete="off">
                        <label class="btn btn-outline-secondary" for="canvasZoom2" title="200% size for fine placement">2×</label>
                    </div>
                </div>
            </div>
            <div class="border rounded bg-white shadow-sm" id="canvas-wrap">
                <canvas id="badge-canvas"
                        width="<?= $cardWidthLandscape ?>"
                        height="<?= $cardHeightLandscape ?>"></canvas>
            </div>
            <div class="mt-1 small text-muted">
                The entire card area prints — background should fill all edges.
            </div>
        </div>

        <?php /* Back panel (HTML editor) */ ?>
        <div id="back-panel" style="display:none">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="badge bg-secondary">Back</span>
                    <span class="small text-muted">HTML content for the card back</span>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="small text-muted text-nowrap mb-0" id="back-canvas-zoom-label">View</span>
                    <div class="btn-group btn-group-sm" role="group" aria-labelledby="back-canvas-zoom-label" id="back-canvas-zoom-btns">
                        <input type="radio" class="btn-check canvas-zoom-radio" name="canvasZoomBack" id="backCanvasZoomFit" value="fit" autocomplete="off">
                        <label class="btn btn-outline-secondary" for="backCanvasZoomFit" title="Scale to fit the column width">Fit</label>
                        <input type="radio" class="btn-check canvas-zoom-radio" name="canvasZoomBack" id="backCanvasZoom1" value="1" autocomplete="off">
                        <label class="btn btn-outline-secondary" for="backCanvasZoom1" title="Actual design pixels (1×)">1×</label>
                        <input type="radio" class="btn-check canvas-zoom-radio" name="canvasZoomBack" id="backCanvasZoom15" value="1.5" autocomplete="off">
                        <label class="btn btn-outline-secondary" for="backCanvasZoom15" title="150% size">1.5×</label>
                        <input type="radio" class="btn-check canvas-zoom-radio" name="canvasZoomBack" id="backCanvasZoom2" value="2" autocomplete="off">
                        <label class="btn btn-outline-secondary" for="backCanvasZoom2" title="200% size for fine placement">2×</label>
                    </div>
                </div>
            </div>
            <div class="mb-2">
                <div class="d-flex flex-wrap gap-1 mb-2" id="back-field-btns">
                    <?php foreach ($dataFields as $field => $info): ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm insert-back-field"
                            data-tag="{{<?= htmlspecialchars($field) ?>}}">
                        <?= htmlspecialchars($info['label']) ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <textarea id="back-html" class="form-control font-monospace small"
                          rows="10" placeholder="Paste or type HTML for the card back. Use {{field_name}} tokens."
                          style="resize:vertical;"></textarea>
            </div>
            <div class="border rounded bg-white p-2 shadow-sm" id="back-preview-wrap">
                <div class="small text-muted fw-semibold text-uppercase mb-1" style="letter-spacing:.06em;">Preview</div>
                <div id="back-preview-outer">
                    <div id="back-preview-scaler">
                        <div id="back-preview"></div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /.col (canvas) -->

</div><!-- /.row -->

<style<?= csp_nonce_attr() ?>>
/* ── Designer layout ───────────────────────────────────────────────────────── */
#designer-sidebar .card-header { background: rgba(0,0,0,.03); }
/* Hug the card — avoid a full-column white strip beside the canvas */
#canvas-wrap {
    display: inline-block;
    width: auto;
    max-width: 100%;
    overflow: auto;
    vertical-align: top;
    -webkit-overflow-scrolling: touch;
    box-sizing: border-box;
}
#canvas-wrap .canvas-container,
#canvas-wrap canvas { display: block; }

/* Back preview: same hug-the-card treatment as #canvas-wrap */
#back-preview-wrap {
    display: inline-block;
    width: auto;
    max-width: 100%;
    vertical-align: top;
    box-sizing: border-box;
    overflow: auto;
    -webkit-overflow-scrolling: touch;
}
#back-preview-outer {
    overflow: hidden;
    line-height: 0;
}
#back-preview-scaler {
    display: block;
    transform-origin: 0 0;
}
/* Vertically center short stacks in the CR80 frame; tall HTML still grows and scrolls */
#back-preview {
    font-size: 11px;
    box-sizing: border-box;
    overflow: auto;
    max-width: 100%;
    line-height: normal;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: stretch;
}

/* Larger back-side editor on phones / small tablets */
@media (max-width: 991.98px) {
    #back-html {
        min-height: min(55vh, 480px);
        font-size: 0.9375rem;
    }
}

/* Field add buttons: full-width, left-aligned, compact */
.add-field-btn      { font-size: .8rem; padding: .25rem .5rem; }

/* Props panel inputs */
#props-panel .form-control-sm { font-size: .8rem; }

/* Highlight selected align button */
#prop-align .btn.active { background: var(--club-primary, #6f7c3d); color: var(--club-on-primary, #faf7f0); border-color: var(--club-primary, #6f7c3d); }
</style>

<?php /* Fabric.js — same CDN as original */ ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js"></script>

<script<?= csp_nonce_attr() ?>>
(function () {
'use strict';

/** Single source for badge field metadata (same keys as PHP $dataFields). */
var BADGE_DATA_FIELDS = <?= json_encode($dataFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

/* ── Constants ──────────────────────────────────────────────────────────────── */
var CARD_W_L = <?= $cardWidthLandscape ?>;
var CARD_H_L = <?= $cardHeightLandscape ?>;
var CARD_W_P = <?= $cardWidthPortrait ?>;
var CARD_H_P = <?= $cardHeightPortrait ?>;

var dataFieldProp = 'dataField';   // custom Fabric property key
var previewMode   = false;         // true when showing a real member's data

/* ── Undo / redo stack (design mode; preview mode bypasses history) ─────── */
var undoStack = [];
var redoStack = [];
var UNDO_MAX = 35;
var historyPaused = false;
var historyTimer = null;

function resetHistoryFromCanvas() {
    undoStack.length = 0;
    redoStack.length = 0;
    if (previewMode) return;
    try {
        undoStack.push(JSON.stringify(canvas.toJSON([dataFieldProp])));
    } catch (err) {}
}

function scheduleHistorySnapshot() {
    if (previewMode || historyPaused) return;
    clearTimeout(historyTimer);
    historyTimer = setTimeout(function () {
        try {
            undoStack.push(JSON.stringify(canvas.toJSON([dataFieldProp])));
            if (undoStack.length > UNDO_MAX) undoStack.shift();
            redoStack = [];
        } catch (e) {}
    }, 220);
}

function applyHistoryJSON(jsonStr) {
    historyPaused = true;
    var parsed = JSON.parse(jsonStr);
    canvas.loadFromJSON(parsed, function () {
        restoreDataFields(canvas, parsed);
        historyPaused = false;
        canvas.requestRenderAll();
        scheduleDesignerViewSync();
        propsPanel.style.display = 'none';
    });
}

/* ── Extend Fabric serialisation to include our custom dataField ──────────── */
fabric.Object.prototype.toObject = (function (toObject) {
    return function (properties) {
        return fabric.util.object.extend(toObject.call(this, properties || []), {
            dataField:   this[dataFieldProp],
            _fixedWidth: this._fixedWidth || 0   // persist fixed width across saves
        });
        };
})(fabric.Object.prototype.toObject);

/* ── Canvas setup ────────────────────────────────────────────────────────── */
var canvas = new fabric.Canvas('badge-canvas', {
    selection: true,
    enableRetinaScaling: false
});

var currentCardW = CARD_W_L;
var currentCardH = CARD_H_L;

/** sessionStorage key for view zoom preference (fit | 1 | 1.5 | 2) */
var CANVAS_ZOOM_STORAGE = 'badge_designer_canvas_zoom_v1';

/**
 * 'fit' = scale to column width (clamped); number = explicit CSS multiplier (1, 1.5, 2 …).
 * Only affects on-screen size — saved JSON stays CR80 logical pixels.
 */
var canvasPixelZoom = '1';
try {
    var _savedZ = sessionStorage.getItem(CANVAS_ZOOM_STORAGE);
    if (_savedZ === 'fit' || _savedZ === '1' || _savedZ === '1.5' || _savedZ === '2') {
        canvasPixelZoom = _savedZ === 'fit' ? 'fit' : parseFloat(_savedZ, 10);
    }
} catch (eSaveZ) {}

function syncCanvasZoomRadios() {
    var val = canvasPixelZoom === 'fit' ? 'fit' : String(canvasPixelZoom);
    document.querySelectorAll('input.canvas-zoom-radio').forEach(function (r) {
        r.checked = (r.value === val);
    });
}

/**
 * Scale only the on-screen canvas (CSS) so the card fills the column on small
 * / mid screens without changing saved coordinates (backing store stays CR80 px).
 */
var designerViewTimer = null;

function scheduleDesignerViewSync() {
    clearTimeout(designerViewTimer);
    designerViewTimer = setTimeout(function () {
        syncCanvasCssScale();
        syncBackPreviewZoom();
    }, 60);
}

function syncCanvasCssScale() {
    var col = document.getElementById('designer-canvas-col');
    if (!col || !canvas) return;
    var avail = col.getBoundingClientRect().width - 20;
    if (avail < 72) return;
    var scale;
    if (canvasPixelZoom === 'fit') {
        scale = avail / currentCardW;
        scale = Math.max(0.52, Math.min(2.45, scale));
    } else {
        scale = typeof canvasPixelZoom === 'number' ? canvasPixelZoom : parseFloat(canvasPixelZoom, 10);
        if (isNaN(scale) || scale <= 0) scale = 1;
        scale = Math.max(0.5, Math.min(3, scale));
    }
    try {
        // Fabric 5 cssOnly path does not append 'px'; numeric values are invalid
        // for style.width/height and are ignored by browsers — must use strings.
        var cssW = (currentCardW * scale) + 'px';
        var cssH = (currentCardH * scale) + 'px';
        canvas.setDimensions({ width: cssW, height: cssH }, { cssOnly: true });
        canvas.requestRenderAll();
    } catch (err) {}
}

/**
 * Resize the canvas to match the chosen orientation.
 * @param {string} ori  'landscape' | 'portrait'
 */
function setCardSize(ori) {
    if (ori === 'portrait') {
        currentCardW = CARD_W_P;
        currentCardH = CARD_H_P;
    } else {
        currentCardW = CARD_W_L;
        currentCardH = CARD_H_L;
    }
    canvas.setWidth(currentCardW);
    canvas.setHeight(currentCardH);
    canvas.requestRenderAll();
    scheduleDesignerViewSync();
}

window.addEventListener('resize', scheduleDesignerViewSync);
window.addEventListener('orientationchange', scheduleDesignerViewSync);
if (typeof ResizeObserver !== 'undefined') {
    (function () {
        var col = document.getElementById('designer-canvas-col');
        if (col) {
            var ro = new ResizeObserver(scheduleDesignerViewSync);
            ro.observe(col);
        }
    })();
}
syncCanvasZoomRadios();
scheduleDesignerViewSync();
if (typeof requestAnimationFrame !== 'undefined') {
    requestAnimationFrame(function () { scheduleDesignerViewSync(); });
}

document.querySelectorAll('input.canvas-zoom-radio').forEach(function (radio) {
    radio.addEventListener('change', function () {
        if (!this.checked) return;
        var v = this.value;
        canvasPixelZoom = (v === 'fit') ? 'fit' : parseFloat(v, 10);
        try { sessionStorage.setItem(CANVAS_ZOOM_STORAGE, v); } catch (eZ) {}
        syncCanvasZoomRadios();
        syncCanvasCssScale();
        syncBackPreviewZoom();
    });
});

/* ── Background image helpers ───────────────────────────────────────────── */
function setBackgroundToCover(img) {
    var w = img.get('width'), h = img.get('height');
    if (!w || !h) return;
    var scale  = Math.max(currentCardW / w, currentCardH / h);
    var left   = (currentCardW  - w * scale) / 2;
    var top    = (currentCardH - h * scale) / 2;
    img.set({ scaleX: scale, scaleY: scale, left: left, top: top,
              originX: 'left', originY: 'top' });
    canvas.setBackgroundImage(img, canvas.renderAll.bind(canvas), {
        scaleX: scale, scaleY: scale, left: left, top: top,
        originX: 'left', originY: 'top'
    });
    canvas.requestRenderAll();
}

/**
 * Resolve a relative path (e.g. 'uploads/…') to an absolute URL based on
 * the current page's location. Needed when loading saved templates.
 */
function resolveUrl(path) {
    if (!path || path.indexOf('data:') === 0 || path.indexOf('http') === 0) return path;
    var base = window.location.href.replace(/[#?].*$/, '').replace(/\/[^/]*$/, '/');
    return new URL(path, base).href;
}

/* ── Orientation control ────────────────────────────────────────────────── */
document.getElementById('orientation').addEventListener('change', function () {
    setCardSize(this.value);
});

/* ── Front / Back side toggle ───────────────────────────────────────────── */
var frontPanel     = document.getElementById('front-panel');
var backPanel      = document.getElementById('back-panel');
var fieldsPanel    = document.getElementById('fields-panel');
var orientWrap     = document.getElementById('orientation-wrap');
var backOrientWrap = document.getElementById('back-orientation-wrap');
var propsPanel     = document.getElementById('props-panel');

document.querySelectorAll('input[name="sideRadio"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
        var isFront = this.value === 'front';
        frontPanel.style.display     = isFront ? '' : 'none';
        backPanel.style.display      = isFront ? 'none' : '';
        fieldsPanel.style.display    = isFront ? '' : 'none';
        orientWrap.style.display     = isFront ? '' : 'none';
        backOrientWrap.style.display = isFront ? 'none' : '';
        if (isFront) {
            // Re-render canvas when switching back to front
            canvas.requestRenderAll();
            scheduleDesignerViewSync();
        } else {
            propsPanel.style.display = 'none';
        }
    });
});

/* ── Background upload / remove ─────────────────────────────────────────── */
document.getElementById('bg-upload').addEventListener('change', function () {
    var file = this.files[0];
    if (!file) return;
    var bgStatus = document.getElementById('bg-status');
    bgStatus.textContent = 'Uploading…';
    var fd = new FormData();
    fd.append('background', file);
    var csrfEl = document.getElementById('csrf_token_value');
    if (csrfEl) fd.append('csrf_token', csrfEl.value);
    fetch('badge_design.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok && data.url) {
                bgStatus.textContent = 'Loaded.';
                var bgUrl = resolveUrl(data.url) + (data.url.indexOf('?') !== -1 ? '&' : '?') + 't=' + Date.now();
                fabric.Image.fromURL(bgUrl, function (img) {
                    if (img) setBackgroundToCover(img);
                }, { crossOrigin: 'anonymous' });
            } else {
                bgStatus.textContent = data.error || 'Upload failed.';
            }
        })
        .catch(function () { bgStatus.textContent = 'Network error.'; });
    this.value = '';
});

document.getElementById('bg-remove').addEventListener('click', function () {
    canvas.setBackgroundImage(null);
    canvas.requestRenderAll();
    document.getElementById('bg-status').textContent = 'Background removed.';
});

/* ── Add field to canvas ────────────────────────────────────────────────── */
function addTextField(field, placeholder) {
    var text = new fabric.IText(placeholder, {
        left: 20,
        top: 20,
        fontFamily: 'Arial',
        fontSize: 14,
        fill: '#000000'
    });
    text.set(dataFieldProp, field);
    canvas.add(text);
    canvas.setActiveObject(text);
    canvas.requestRenderAll();
}

document.querySelectorAll('.add-field-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        addTextField(this.dataset.field, this.dataset.placeholder);
    });
});

document.getElementById('addPhoto').addEventListener('click', function () {
    var rect  = new fabric.Rect({ width: 80, height: 100, fill: '#e0e0e0',
                                   stroke: '#aaa', strokeWidth: 1 });
    var label = new fabric.Text('Photo', { fontSize: 11, fill: '#555',
                                            originX: 'center', originY: 'center',
                                            left: 40, top: 50 });
    var grp   = new fabric.Group([rect, label], { left: 20, top: 20 });
    grp.set(dataFieldProp, 'photo');
    canvas.add(grp);
    canvas.setActiveObject(grp);
    canvas.requestRenderAll();
});

document.getElementById('deleteObj').addEventListener('click', function () {
    var active = canvas.getActiveObjects();
    if (active.length) {
        canvas.discardActiveObject();
        active.forEach(function (obj) { canvas.remove(obj); });
        canvas.requestRenderAll();
    }
    propsPanel.style.display = 'none';
});

canvas.on('object:modified', scheduleHistorySnapshot);
canvas.on('object:added', scheduleHistorySnapshot);
canvas.on('object:removed', scheduleHistorySnapshot);

document.getElementById('undo-canvas').addEventListener('click', function () {
    if (previewMode || historyPaused || undoStack.length < 2) return;
    var cur = undoStack.pop();
    redoStack.push(cur);
    applyHistoryJSON(undoStack[undoStack.length - 1]);
});

document.getElementById('redo-canvas').addEventListener('click', function () {
    if (previewMode || historyPaused || !redoStack.length) return;
    var next = redoStack.pop();
    undoStack.push(next);
    applyHistoryJSON(next);
});

document.addEventListener('keydown', function (e) {
    if (previewMode || historyPaused) return;
    var el = document.activeElement;
    if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT')) return;
    var mod = e.ctrlKey || e.metaKey;
    if (mod && e.key === 'z' && !e.shiftKey) {
        e.preventDefault();
        document.getElementById('undo-canvas').click();
    } else if (mod && (e.key === 'y' || (e.key === 'z' && e.shiftKey))) {
        e.preventDefault();
        document.getElementById('redo-canvas').click();
    }
}, true);

resetHistoryFromCanvas();

/* ── Selection → properties panel ──────────────────────────────────────── */
var propFontSize  = document.getElementById('prop-fontsize');
var propBold      = document.getElementById('prop-bold');
var propItalic    = document.getElementById('prop-italic');
var propColor     = document.getElementById('prop-color');
var propsLabel    = document.getElementById('props-field-name');
var alignBtns     = document.querySelectorAll('#prop-align button');

/** Populate the properties panel from the currently selected object. */
function syncPropsPanel(obj) {
    if (!obj || obj.type === 'group') {
        propsPanel.style.display = 'none';
        return;
    }
    propsPanel.style.display = '';
    var field = obj[dataFieldProp] || '';

    var fi = BADGE_DATA_FIELDS[field];
    propsLabel.textContent = (fi && fi.label) ? fi.label : (field || '');

    propFontSize.value       = obj.fontSize || 14;
    propBold.checked         = obj.fontWeight === 'bold';
    propItalic.checked       = obj.fontStyle === 'italic';
    propColor.value          = obj.fill || '#000000';

    var align = obj.textAlign || 'left';
    alignBtns.forEach(function (b) {
        b.classList.toggle('active', b.dataset.align === align);
    });

    // Populate fixed-width field (0 means auto-sizing)
    document.getElementById('prop-width').value = obj._fixedWidth || 0;
}

canvas.on('selection:created',  function (e) { syncPropsPanel(e.selected && e.selected[0]); });
canvas.on('selection:updated',  function (e) { syncPropsPanel(e.selected && e.selected[0]); });
canvas.on('selection:cleared',  function ()  { propsPanel.style.display = 'none'; });

/* Apply property changes back to the selected object */
function applyPropChange(fn) {
    var obj = canvas.getActiveObject();
    if (!obj) return;
    fn(obj);
    canvas.requestRenderAll();
}

propFontSize.addEventListener('input', function () {
    applyPropChange(function (o) { o.set('fontSize', parseInt(propFontSize.value, 10) || 14); });
});
propBold.addEventListener('change', function () {
    applyPropChange(function (o) { o.set('fontWeight', propBold.checked ? 'bold' : 'normal'); });
});
propItalic.addEventListener('change', function () {
    applyPropChange(function (o) { o.set('fontStyle', propItalic.checked ? 'italic' : 'normal'); });
});
propColor.addEventListener('input', function () {
    applyPropChange(function (o) { o.set('fill', propColor.value); });
});
alignBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
        applyPropChange(function (o) { o.set('textAlign', btn.dataset.align); });
        alignBtns.forEach(function (b) { b.classList.toggle('active', b === btn); });
    });
});

document.getElementById('prop-width').addEventListener('input', function () {
    var w = parseInt(this.value, 10) || 0;
    applyPropChange(function (o) {
        if (w > 0) {
            // IText recalculates its own width aggressively — we have to
            // override _calcTextWidth so the box truly stays at our fixed size
            // instead of snapping back to fit the text content.
            o._fixedWidth = w;
            o.set({
                width:         w,
                lockScalingX:  true   // prevent resize handle from changing width
            });
            // Patch _calcTextWidth so Fabric stops overriding our width
            o._calcTextWidth = function () { return this._fixedWidth; };
            o.initDimensions();
            o.setCoords();
        } else {
            // Restore auto-sizing: remove the patch and recalculate naturally
            o._fixedWidth = 0;
            o.lockScalingX = false;
            delete o._calcTextWidth;   // restore Fabric's original method
            o.initDimensions();
            o.setCoords();
        }
        canvas.requestRenderAll();
    });
});
/* ── Back side HTML editor ──────────────────────────────────────────────── */
var backHtmlEl  = document.getElementById('back-html');
var backPreview = document.getElementById('back-preview');

/**
 * CR80-sized HTML preview + same Fit / 1× / 1.5× / 2× scaling as the front canvas.
 */
function syncBackPreviewZoom() {
    var outer = document.getElementById('back-preview-outer');
    var scaler = document.getElementById('back-preview-scaler');
    var prev = document.getElementById('back-preview');
    if (!outer || !scaler || !prev) return;

    var sel = document.getElementById('back-orientation');
    var portrait = sel && sel.value === 'portrait';
    var lw = portrait ? CARD_W_P : CARD_W_L;
    var lh = portrait ? CARD_H_P : CARD_H_L;

    prev.style.width = lw + 'px';
    prev.style.minHeight = lh + 'px';

    var col = document.getElementById('designer-canvas-col');
    if (!col) return;
    var avail = col.getBoundingClientRect().width - 20;
    if (avail < 72) return;

    var scale;
    if (canvasPixelZoom === 'fit') {
        scale = avail / lw;
        scale = Math.max(0.52, Math.min(2.45, scale));
    } else {
        scale = typeof canvasPixelZoom === 'number' ? canvasPixelZoom : parseFloat(canvasPixelZoom, 10);
        if (isNaN(scale) || scale <= 0) scale = 1;
        scale = Math.max(0.5, Math.min(3, scale));
    }

    var contentH = Math.max(lh, prev.scrollHeight);
    scaler.style.width = lw + 'px';
    scaler.style.height = contentH + 'px';
    scaler.style.transform = 'scale(' + scale + ')';
    scaler.style.transformOrigin = '0 0';

    outer.style.width = (lw * scale) + 'px';
    outer.style.height = (contentH * scale) + 'px';
}

function updateBackPreview() {
    var html = backHtmlEl.value;
    // Replace {{tokens}} with placeholder spans for preview
    var display = html.replace(/\{\{(\w+)\}\}/g, function (_, field) {
        var ph = BADGE_DATA_FIELDS[field] && BADGE_DATA_FIELDS[field].placeholder;
        return '<span style="background:#ffe8a1;padding:1px 3px;border-radius:3px;">' +
               (ph || field) + '</span>';
    });
    backPreview.innerHTML = display;
    if (typeof requestAnimationFrame !== 'undefined') {
        requestAnimationFrame(function () { syncBackPreviewZoom(); });
    } else {
        syncBackPreviewZoom();
    }
}

// backHtmlEl input listener added with scheduleAutosave below (badge design block)

// Insert token at cursor position
document.querySelectorAll('.insert-back-field').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var tag   = this.dataset.tag;
        var start = backHtmlEl.selectionStart;
        var end   = backHtmlEl.selectionEnd;
        var val   = backHtmlEl.value;
        backHtmlEl.value = val.substring(0, start) + tag + val.substring(end);
        backHtmlEl.selectionStart = backHtmlEl.selectionEnd = start + tag.length;
        backHtmlEl.focus();
        updateBackPreview();
        scheduleAutosave();
        updateBackPreview();
    });
});

/* ── Live member preview ────────────────────────────────────────────────── */
var previewSelect = document.getElementById('preview-member-select');
var previewStatus = document.getElementById('preview-status');

// Load member list into the dropdown
fetch('badge_design.php?action=member_list', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        var members = Array.isArray(data) ? data : (data.members || []);
        if (data && data.ok === false && window.console) console.warn(data.error || 'member_list');
        members.forEach(function (m) {
            var opt = document.createElement('option');
            opt.value = m.id;
            opt.textContent = m.last_name + ', ' + m.first_name;
            previewSelect.appendChild(opt);
        });
    })
    .catch(function () { if (window.console) console.warn('member_list request failed'); });

/** Replace placeholder text on all canvas objects with real member values. */
function applyPreview(memberData) {
    canvas.getObjects().forEach(function (obj) {
        var field = obj[dataFieldProp];
        if (!field) return;
        if (field === 'photo') {
            // Swap the photo placeholder for the member's actual photo
            if (memberData.photo_data_url) {
                fabric.Image.fromURL(memberData.photo_data_url, function (img) {
                    if (!img) return;
                    // Match position + size of existing placeholder group
                    img.set({
                        left: obj.left,
                        top: obj.top,
                        scaleX: obj.getScaledWidth()  / img.width,
                        scaleY: obj.getScaledHeight() / img.height,
                    });
                    img.set(dataFieldProp, 'photo_preview');
                    // Hide (don't remove) the placeholder so it can be
                    // restored on exitPreview — removing it caused the photo
                    // field to disappear from the saved template.
                    obj.set('visible', false);
                    canvas.add(img);
                    canvas.requestRenderAll();
                });
            }
        } else if (obj.type === 'i-text' || obj.type === 'text') {
            obj.set('text', memberData[field] !== undefined ? String(memberData[field]) : obj.text);
        }
    });
    canvas.requestRenderAll();
}

/**
 * Snapshot of placeholder texts so we can restore them when leaving preview.
 * Shape: { fabricObjectRef: 'original text' }
 */
var previewSnapshot = [];

function enterPreview(memberData) {
    previewSnapshot = [];
    canvas.getObjects().forEach(function (obj) {
        var field = obj[dataFieldProp];
        if (!field) return;
        if (obj.type === 'i-text' || obj.type === 'text') {
            previewSnapshot.push({ obj: obj, text: obj.text });
        }
    });
    applyPreview(memberData);
    previewMode = true;
}

function exitPreview() {
    previewSnapshot.forEach(function (item) {
        item.obj.set('text', item.text);
    });
    // Remove any photo images added during preview and restore the
    // (hidden) photo placeholder so it survives a save.
    canvas.getObjects().forEach(function (obj) {
        if (obj[dataFieldProp] === 'photo_preview') canvas.remove(obj);
        if (obj[dataFieldProp] === 'photo') obj.set('visible', true);
    });
    previewSnapshot = [];
    previewMode = false;
    canvas.requestRenderAll();
}

previewSelect.addEventListener('change', function () {
    if (previewMode) exitPreview();
    var mid = this.value;
    if (!mid) {
        previewStatus.textContent = '';
        return;
    }
    previewStatus.textContent = 'Loading…';
    fetch('badge_design.php?action=member_data&member_id=' + mid, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || data.ok === false || !data.full_name) {
                previewStatus.textContent = (data && data.error) ? data.error : 'Member not found.';
                return;
            }
            previewStatus.textContent = 'Showing: ' + data.last_name + ', ' + data.first_name;
            enterPreview(data);
        })
        .catch(function () { previewStatus.textContent = 'Failed to load member.'; });
});

/* ── Build payload for save / autosave ───────────────────────────────────── */
function getPayloadForSave() {
    var bg = canvas.backgroundImage;
    var backgroundPath = null;
    var backgroundDataUrl = null;
    if (bg && bg.getSrc) {
        var src = bg.getSrc();
        if (typeof src === 'string') {
            var cleanSrc = src.split('?')[0];
            if (cleanSrc.indexOf('data:') === 0) {
                backgroundDataUrl = cleanSrc;
            } else if (cleanSrc.indexOf('uploads/') !== -1) {
                backgroundPath = cleanSrc.substring(cleanSrc.indexOf('uploads/'));
            }
        }
    }
    if (!backgroundPath && bg && !backgroundDataUrl) {
        var el = (typeof bg.getElement === 'function' && bg.getElement()) || bg._element;
        if (el && el.complete && el.naturalWidth) {
            try {
                var c = document.createElement('canvas');
                c.width = el.naturalWidth; c.height = el.naturalHeight;
                c.getContext('2d').drawImage(el, 0, 0);
                backgroundDataUrl = c.toDataURL('image/png');
            } catch (e) {}
        }
    }
    var json = canvas.toJSON([dataFieldProp]);
    delete json.background;
    delete json.backgroundImage;
    return {
        canvas: json,
        backgroundPath: backgroundPath,
        backgroundDataUrl: backgroundDataUrl,
        orientation: document.getElementById('orientation').value,
        backOrientation: document.getElementById('back-orientation').value,
        backHtml: backHtmlEl.value.trim(),
        version: 1
    };
}

// Scope autosave per user so multiple admins on a shared browser never see each other's unsaved designs.
var AUTOSAVE_KEY = 'badge_design_backup_u<?= (int) $userId ?>';
var autosaveDebounce = null;
var AUTOSAVE_MS = 30000;
function scheduleAutosave() {
    if (previewMode) return;
    clearTimeout(autosaveDebounce);
    autosaveDebounce = setTimeout(function () {
        try {
            localStorage.setItem(AUTOSAVE_KEY, JSON.stringify(getPayloadForSave()));
        } catch (e) {}
    }, AUTOSAVE_MS);
}
canvas.on('object:modified', scheduleAutosave);
canvas.on('object:added', scheduleAutosave);
canvas.on('object:removed', scheduleAutosave);
document.getElementById('orientation').addEventListener('change', scheduleAutosave);
document.getElementById('back-orientation').addEventListener('change', function () {
    syncBackPreviewZoom();
    scheduleAutosave();
});
backHtmlEl.addEventListener('input', function () { updateBackPreview(); scheduleAutosave(); });
syncBackPreviewZoom();

/* ── Save design ────────────────────────────────────────────────────────── */
document.getElementById('saveDesign').addEventListener('click', function () {
    if (previewMode) {
        exitPreview();
        previewSelect.value = '';
        previewStatus.textContent = '';
    }
    var payload = getPayloadForSave();
    var saveStatus = document.getElementById('save-status');
    saveStatus.textContent = 'Saving…';
    saveStatus.className   = 'small text-muted';

    var fd = new FormData();
    fd.append('action', 'save');
    fd.append('template', JSON.stringify(payload));
    var csrfEl = document.getElementById('csrf_token_value');
    if (csrfEl) fd.append('csrf_token', csrfEl.value);

    fetch('badge_design.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                try { localStorage.removeItem(AUTOSAVE_KEY); } catch (e) {}
                saveStatus.textContent = '✓ Saved';
                saveStatus.className   = 'small text-success';
                window.setTimeout(function () {
                    saveStatus.textContent = '';
                    saveStatus.className   = 'small text-muted';
                }, 3000);
            } else {
                saveStatus.textContent = 'Error: ' + (data.error || 'Save failed');
                saveStatus.className   = 'small text-danger';
            }
        })
        .catch(function () {
            saveStatus.textContent = 'Network error — design not saved.';
            saveStatus.className   = 'small text-danger';
        });
});

/* ── Load existing template on page load ────────────────────────────────── */
function restoreDataFields(fabricCanvas, savedCanvas) {
    var objects   = fabricCanvas.getObjects();
    var savedObjs = (savedCanvas && savedCanvas.objects) || [];
    savedObjs.forEach(function (saved, i) {
        if (!objects[i]) return;
        if (saved.dataField)   objects[i].set(dataFieldProp, saved.dataField);
// Re-apply fixed width so textAlign keeps working after a page reload.
        // The _calcTextWidth patch must be re-applied — it doesn't serialise.
        if (saved._fixedWidth) {
            var fw = saved._fixedWidth;
            objects[i]._fixedWidth = fw;
            objects[i].set({ width: fw, lockScalingX: true });
            objects[i]._calcTextWidth = function () { return this._fixedWidth; };
            objects[i].initDimensions();
            objects[i].setCoords();
        }
        });
}
fetch('badge_design.php?action=load', { credentials: 'same-origin' })
    .then(function (r) {
        if (!r.ok) return null;
        return r.json();
    })
    .then(function (data) {
        var backup = null;
        try { backup = localStorage.getItem(AUTOSAVE_KEY); } catch (e) {}
        if (backup) {
            if (confirm('You have unsaved changes from a previous session. Restore them?')) {
                try { data = JSON.parse(backup); } catch (e) { data = null; }
            }
            try { localStorage.removeItem(AUTOSAVE_KEY); } catch (e) {}
        }
        if (!data || !data.canvas) {
            resetHistoryFromCanvas();
            scheduleDesignerViewSync();
            return;
        }

        var ori = (data.orientation === 'portrait') ? 'portrait' : 'landscape';
        document.getElementById('orientation').value = ori;
        setCardSize(ori);

        var backOri = (data.backOrientation === 'portrait') ? 'portrait' : 'landscape';
        document.getElementById('back-orientation').value = backOri;
        syncBackPreviewZoom();

        if (data.backHtml) {
            backHtmlEl.value = data.backHtml;
            updateBackPreview();
        }

        var bgUrl = (data.backgroundPath ? resolveUrl(data.backgroundPath) : null)
            || data.backgroundDataUrl
            || null;
        if (bgUrl && bgUrl.indexOf('data:') !== 0) {
            bgUrl = bgUrl + (bgUrl.indexOf('?') !== -1 ? '&' : '?') + 't=' + Date.now();
        }

        historyPaused = true;
        canvas.loadFromJSON(data.canvas, function () {
            restoreDataFields(canvas, data.canvas);
            if (bgUrl) {
                var opts = bgUrl.indexOf('data:') !== 0 ? { crossOrigin: 'anonymous' } : {};
                fabric.Image.fromURL(bgUrl, function (img) {
                    if (img) setBackgroundToCover(img);
                    canvas.requestRenderAll();
                    scheduleDesignerViewSync();
                    historyPaused = false;
                    resetHistoryFromCanvas();
                }, opts);
            } else {
                canvas.requestRenderAll();
                scheduleDesignerViewSync();
                historyPaused = false;
                resetHistoryFromCanvas();
            }
        });
    })
    .catch(function () { resetHistoryFromCanvas(); });

})(); // end IIFE
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>