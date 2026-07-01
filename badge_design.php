<?php
/**
 * badge_design.php — CR80 Badge Designer (overhauled)
 *
 * Improvements over the original:
 *   - Two-column layout: left sidebar (field panel + properties) / right canvas
 *   - Field panel: click any field to add it; no hunting through a dropdown
 *   - Selected-object property panel: font, font size, bold, italic, colour, alignment
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

if (!canEditMembers()) {
    header('Location: index.php');
    exit;
}
$userId   = currentUserId();
$membershipTypeLabels = enabledMembershipTypeLabels($pdo);

require_once __DIR__ . '/includes/badge_design_helpers.php';
require_once __DIR__ . '/includes/badge_design_api.php';

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

<div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
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

<?php /* ── Design picker: choose / create / rename / delete designs ────────── */ ?>
<div class="card mb-3 shadow-sm">
    <div class="card-body py-2 px-3">
        <div class="d-flex flex-wrap align-items-end gap-3">
            <div>
                <label class="form-label small mb-1" for="design-select">Design</label>
                <select id="design-select" class="form-select form-select-sm" style="min-width:220px"></select>
            </div>
            <div class="btn-group" role="group" aria-label="Design actions">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="newDesign">＋ New</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="renameDesign">Rename</button>
                <button type="button" class="btn btn-outline-danger btn-sm" id="deleteDesign">Delete</button>
            </div>
            <div class="vr d-none d-md-block align-self-stretch"></div>
            <div class="d-flex flex-column gap-1">
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" id="design-default">
                    <label class="form-check-label small" for="design-default">Default design</label>
                </div>
            </div>
        </div>
        <div class="form-text mt-1">The default design is used when printing badges unless another is chosen. Saved with <strong>Save Design</strong>.</div>
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
                <select class="form-select form-select-sm" id="add-field-select" aria-label="Add field to front">
                    <option value="" selected>➕ Add a field…</option>
                    <?php foreach ($dataFields as $field => $info): ?>
                    <option value="<?= htmlspecialchars($field) ?>"
                            data-placeholder="<?= htmlspecialchars($info['placeholder']) ?>">
                        <?= $info['icon'] ?> <?= htmlspecialchars($info['label']) ?>
                    </option>
                    <?php endforeach; ?>
                    <option value="__photo__">🖼 Member photo</option>
                </select>
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
                    <label class="form-label small mb-1" for="prop-fontfamily">Font</label>
                    <select id="prop-fontfamily" class="form-select form-select-sm">
                        <option value="Arial">Arial</option>
                        <option value="Helvetica">Helvetica</option>
                        <option value="Verdana">Verdana</option>
                        <option value="Tahoma">Tahoma</option>
                        <option value="Trebuchet MS">Trebuchet MS</option>
                        <option value="Georgia">Georgia</option>
                        <option value="Times New Roman">Times New Roman</option>
                        <option value="Courier New">Courier New</option>
                        <option value="Impact">Impact</option>
                    </select>
                </div>
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
                        <button type="button" class="btn btn-outline-secondary" data-align="left" title="Align left">⬅</button>
                        <button type="button" class="btn btn-outline-secondary" data-align="center" title="Align center">↔</button>
                        <button type="button" class="btn btn-outline-secondary" data-align="right" title="Align right">➡</button>
                    </div>
                    <div class="form-text">Text is anchored at its top-left corner. For center/right within a box, set a fixed width wider than the text.</div>
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

/* Props panel inputs */
#props-panel .form-control-sm { font-size: .8rem; }

/* Highlight selected align button */
#prop-align .btn.active { background: var(--club-primary, #6f7c3d); color: var(--club-on-primary, #faf7f0); border-color: var(--club-primary, #6f7c3d); }
</style>

<?php
require_once __DIR__ . '/includes/vendor_assets.php';
?>
<script src="<?= htmlspecialchars(flightops_fabric_js_url()) ?>"></script>
<script src="js/badge_fabric.js"></script>
<script<?= csp_nonce_attr() ?>>
window.FLIGHTOPS_BADGE_DESIGN = <?= json_encode([
    'dataFields' => $dataFields,
    'cardWidthLandscape' => $cardWidthLandscape,
    'cardHeightLandscape' => $cardHeightLandscape,
    'cardWidthPortrait' => $cardWidthPortrait,
    'cardHeightPortrait' => $cardHeightPortrait,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="js/badge_design.js"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>