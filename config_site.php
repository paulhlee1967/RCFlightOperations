<?php
/**
 * config_site.php
 *
 * Club configuration: name, logo, colors, contacts, renewal season, membership types, dues.
 *
 * Admin only. POST saves to `club` (branding, type labels), `dues_rules`, and a few
 * `system_config` keys (contacts, renewal season, reports year). File uploads go to uploads/branding/.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/flightops_logo.php';
require_once __DIR__ . '/includes/installation_config.php';

requireAdmin();
$clubId = 1;
$stmt = $pdo->prepare('SELECT * FROM club WHERE id = ?');
$stmt->execute([$clubId]);
$club = $stmt->fetch();
if (!$club) {
    header('Location: index.php');
    exit;
}

$saved = false;
$error = '';
$configRows = installation_load_system_config($pdo);

// Load current slot labels, enabled flags, and dues rules for display
$membershipTypeSlots  = membershipTypeSlots($pdo);
$membershipTypeLabels = enabledMembershipTypeLabels($pdo);
$duesRules            = duesRules($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    $supportEmail    = trim((string) ($_POST['support_email'] ?? ''));
    $membershipEmail = trim((string) ($_POST['membership_email'] ?? ''));
    if ($supportEmail !== '' && !filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Support email must be a valid address, or left blank.';
    } elseif ($membershipEmail !== '' && !filter_var($membershipEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Membership email must be a valid address, or left blank.';
    }

    if ($error !== '') {
        // Keep the typed addresses visible when validation fails.
        $configRows['support_email'] = $supportEmail;
        $configRows['membership_email'] = $membershipEmail;
    }

    if ($error === '') {
    // ── General / branding fields ─────────────────────────────────────────────
    $name             = trim($_POST['club_name']          ?? '');
    $colorPrimary     = trim($_POST['color_primary']      ?? '') ?: '#6f7c3d';
    $colorPrimaryDark = trim($_POST['color_primary_dark'] ?? '') ?: '#556030';
    $colorBg          = trim($_POST['color_bg']           ?? '') ?: '#f3efe4';
    $colorMuted       = trim($_POST['color_muted']        ?? '') ?: '#665e52';
    $colorText        = trim($_POST['color_text']         ?? '') ?: '#252018';

    // Ensure # prefix on hex values
    foreach (['colorPrimary', 'colorPrimaryDark', 'colorBg', 'colorMuted', 'colorText'] as $k) {
        if ($$k !== '' && $$k[0] !== '#') {
            $$k = '#' . $$k;
        }
    }

    // Validate hex; fall back to defaults if invalid
    $hexDefault = [
        'colorPrimary'     => '#6f7c3d',
        'colorPrimaryDark' => '#556030',
        'colorBg'          => '#f3efe4',
        'colorMuted'       => '#665e52',
        'colorText'        => '#252018',
    ];
    foreach ($hexDefault as $var => $default) {
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $$var)) {
            $$var = $default;
        }
    }

    // ── Logo and favicon uploads ──────────────────────────────────────────────
    $logoPath    = $club['logo_path'];
    $faviconPath = $club['favicon_path'];
    $uploadDir   = __DIR__ . '/uploads/branding';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    if (!empty($_POST['remove_logo']))    $logoPath    = null;
    if (!empty($_POST['remove_favicon'])) $faviconPath = null;

    if (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
        $finfo   = new finfo(FILEINFO_MIME_TYPE);
        $mime    = $finfo->file($_FILES['logo']['tmp_name']);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];
        if (isset($allowed[$mime]) && $_FILES['logo']['size'] <= 2 * 1024 * 1024) {
            $ext  = $allowed[$mime];
            $file = $uploadDir . '/logo.' . $ext;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $file)) {
                $logoPath = 'uploads/branding/logo.' . $ext;
            }
        }
    }

    if (!empty($_FILES['favicon']['tmp_name']) && is_uploaded_file($_FILES['favicon']['tmp_name'])) {
        $finfo   = new finfo(FILEINFO_MIME_TYPE);
        $mime    = $finfo->file($_FILES['favicon']['tmp_name']);
        $allowed = ['image/x-icon' => 'ico', 'image/png' => 'png', 'image/jpeg' => 'jpg'];
        if (isset($allowed[$mime]) && $_FILES['favicon']['size'] <= 512 * 1024) {
            $ext  = $allowed[$mime];
            $file = $uploadDir . '/favicon.' . $ext;
            if (move_uploaded_file($_FILES['favicon']['tmp_name'], $file)) {
                $faviconPath = 'uploads/branding/favicon.' . $ext;
            }
        }
    }

    // ── Save core branding ────────────────────────────────────────────────────
    $pdo->prepare('
        UPDATE club
        SET name = ?, logo_path = ?, favicon_path = ?,
            color_primary = ?, color_primary_dark = ?,
            color_bg = ?, color_muted = ?, color_text = ?
        WHERE id = ?
    ')->execute([
        $name ?: $club['name'],
        $logoPath,
        $faviconPath,
        $colorPrimary,
        $colorPrimaryDark,
        $colorBg,
        $colorMuted,
        $colorText,
        $clubId,
    ]);

    // ── Save membership type labels, enabled flags, and dues per slot ─────────
    for ($slot = 1; $slot <= 4; $slot++) {
        $label   = trim($_POST["membership_type{$slot}_label"] ?? '') ?: match ($slot) { 1 => 'Adult', 2 => 'Youth', 3 => 'Senior', 4 => 'Spouse' };
        $enabled = !empty($_POST["membership_type{$slot}_enabled"]) ? 1 : 0;

        foreach (["membership_type{$slot}_label" => $label, "membership_type{$slot}_enabled" => $enabled] as $col => $val) {
            try {
                $pdo->prepare("UPDATE club SET `{$col}` = ? WHERE id = ?")->execute([$val, $clubId]);
            } catch (PDOException $e) {
                // Column may not exist on older installs — silently skip
            }
        }

        // Dues rates for this slot
        $annual    = (float) ($_POST["dues_slot{$slot}_annual"]              ?? 0);
        $prorated  = (float) ($_POST["dues_slot{$slot}_prorated"]            ?? 0);
        $initiation = (float) ($_POST["dues_slot{$slot}_initiation"]         ?? 0);
        $psMonth   = max(1, min(12, (int) ($_POST["dues_slot{$slot}_prorate_start_month"] ?? 7)));
        $peMonth   = max(1, min(12, (int) ($_POST["dues_slot{$slot}_prorate_end_month"]   ?? 10)));

        try {
            $pdo->prepare('
                INSERT INTO dues_rules
                    (membership_type_slot, annual_dues, prorated_dues,
                     initiation_fee, prorate_start_month, prorate_end_month)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    annual_dues          = VALUES(annual_dues),
                    prorated_dues        = VALUES(prorated_dues),
                    initiation_fee       = VALUES(initiation_fee),
                    prorate_start_month  = VALUES(prorate_start_month),
                    prorate_end_month    = VALUES(prorate_end_month)
            ')->execute([$slot, $annual, $prorated, $initiation, $psMonth, $peMonth]);
        } catch (Throwable $e) {
            // dues_rules table may not exist on very old installs — ignore
        }
    }

    try {
        foreach ([
            'support_email',
            'membership_email',
            'renewal_prebook_start_month',
            'renewal_prebook_start_day',
            'reports_accurate_from_year',
        ] as $configKey) {
            installation_save_config_key($pdo, $configKey, installation_posted_config_value($configKey, $_POST));
        }
    } catch (Throwable $e) {
        $error = 'Branding and dues were saved, but contacts and the renewal calendar could not be saved.';
    }

    $stmt = $pdo->prepare('SELECT * FROM club WHERE id = ?');
    $stmt->execute([$clubId]);
    $club               = $stmt->fetch();
    $membershipTypeSlots  = membershipTypeSlots($pdo);
    $membershipTypeLabels = enabledMembershipTypeLabels($pdo);
    $duesRules            = duesRules($pdo);
    $configRows           = installation_load_system_config($pdo);
    if ($error === '') {
        $saved = true;
    }
    }
}

$pageTitle = 'Configuration';
$breadcrumbs = [
    ['label' => 'Administration', 'url' => 'users.php'],
    ['label' => 'Configuration', 'url' => ''],
];
require_once __DIR__ . '/includes/page_header.php';

ob_start();
?>
        <div class="text-muted mb-1" style="font-size:0.65rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;text-align:right;">Platform</div>
        <div class="d-flex align-items-center gap-2 rounded px-3 py-2 border bg-light">
            <img src="<?= htmlspecialchars(flightops_logo_asset_src()) ?>"
                 alt="RC Flight Operations"
                 width="22"
                 height="22"
                 decoding="async"
                 style="height:22px;width:auto;display:block;object-fit:contain;">
            <div>
                <div style="font-weight:700;font-size:0.82rem;color:#252018;line-height:1.2;">RC Flight Operations</div>
                <div style="font-size:0.67rem;color:#868e96;letter-spacing:.06em;line-height:1.2;">Member Management</div>
            </div>
        </div>
<?php
$configHeaderActions = ob_get_clean();

require_once __DIR__ . '/includes/header.php';

render_page_header([
    'title'    => 'Configuration',
    'subtitle' => 'Club name, look, contacts, dues, and the renewal calendar. Changes apply as soon as you save.',
    'border'   => true,
    'actions'  => $configHeaderActions,
]);
?>

<?php if ($saved): ?>

<div class="alert alert-success alert-dismissible fade show" role="alert">
    Configuration saved.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="post" action="config_site.php" enctype="multipart/form-data">
    <?= csrf_field() ?>

<ul class="nav nav-tabs mb-4" id="configTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="club-tab" data-bs-toggle="tab"
                data-bs-target="#club" type="button" role="tab">Club</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="membership-tab" data-bs-toggle="tab"
                data-bs-target="#membership" type="button" role="tab">Membership</button>
    </li>
</ul>

<div class="tab-content" id="configTabContent">

    <!-- ══════════════════════════════════════════════════════════════════
         GENERAL TAB
         ══════════════════════════════════════════════════════════════ -->
    <div class="tab-pane fade show active" id="club" role="tabpanel">
        <div class="card mb-4">
            <div class="card-header fw-semibold">Club</div>
            <div class="card-body">

                <div class="mb-3">
                    <label class="form-label">Club name</label>
                    <input type="text" class="form-control" name="club_name"
                           value="<?= htmlspecialchars($club['name']) ?>"
                           placeholder="RC Flight Operations">
                    <div class="form-text">Shown in the navbar and email notifications.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Logo</label>
                    <?php if (!empty($club['logo_path']) && is_readable(__DIR__ . '/' . $club['logo_path'])): ?>
                    <div class="mb-2">
                        <img src="<?= htmlspecialchars($club['logo_path']) ?>?t=<?= time() ?>"
                             alt="Current logo" style="max-height:48px;">
                    </div>
                    <div class="form-check mb-2">
                        <input type="checkbox" class="form-check-input" name="remove_logo" id="remove_logo" value="1">
                        <label class="form-check-label" for="remove_logo">Remove custom logo (use default RC Flight Operations logo)</label>
                    </div>
                    <?php else: ?>
                    <p class="mb-2 text-muted small">Default RC Flight Operations logo is currently shown in the navbar.</p>
                    <?php endif; ?>
                    <input type="file" class="form-control" name="logo" accept="image/jpeg,image/png,image/gif">
                    <div class="form-text">Optional. Max 2 MB. JPEG, PNG, or GIF. Horizontal logos work best.</div>
                </div>

                <div class="mb-0">
                    <label class="form-label">Favicon</label>
                    <?php if (!empty($club['favicon_path']) && is_readable(__DIR__ . '/' . $club['favicon_path'])): ?>
                    <div class="mb-2">
                        <img src="<?= htmlspecialchars($club['favicon_path']) ?>?t=<?= time() ?>"
                             alt="Current favicon" width="32" height="32">
                    </div>
                    <div class="form-check mb-2">
                        <input type="checkbox" class="form-check-input" name="remove_favicon" id="remove_favicon" value="1">
                        <label class="form-check-label" for="remove_favicon">Remove custom favicon (use default)</label>
                    </div>
                    <?php else: ?>
                    <p class="mb-2 text-muted small">Default favicon is currently shown in browser tabs.</p>
                    <?php endif; ?>
                    <input type="file" class="form-control" name="favicon" accept="image/x-icon,image/png,image/jpeg">
                    <div class="form-text">Optional. Browser tab icon. PNG, ICO, or JPEG. 32×32 or 64×64 px recommended.</div>
                </div>

            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header fw-semibold">Theme colors</div>
            <div class="card-body">
                <p class="text-muted small mb-4">
                    Match your club's official colors. Click the swatch to open a color
                    picker, or type a hex value directly (e.g. <code>#6f7c3d</code>).
                    Changes apply across the whole app on save.
                </p>
                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">Primary color</label>
                        <div class="input-group js-color-sync">
                            <input type="color" class="form-control form-control-color"
                                   value="<?= htmlspecialchars($club['color_primary'] ?: '#6f7c3d') ?>"
                                   title="Pick a color">
                            <input type="text" class="form-control" name="color_primary"
                                   value="<?= htmlspecialchars($club['color_primary']) ?>"
                                   placeholder="#6f7c3d" maxlength="7">
                        </div>
                        <div class="form-text">Navbar, buttons, active tabs.</div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Primary dark</label>
                        <div class="input-group js-color-sync">
                            <input type="color" class="form-control form-control-color"
                                   value="<?= htmlspecialchars($club['color_primary_dark'] ?: '#556030') ?>"
                                   title="Pick a color">
                            <input type="text" class="form-control" name="color_primary_dark"
                                   value="<?= htmlspecialchars($club['color_primary_dark']) ?>"
                                   placeholder="#556030" maxlength="7">
                        </div>
                        <div class="form-text">Button hover / pressed states. Usually 15–20% darker than Primary.</div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Page background</label>
                        <div class="input-group js-color-sync">
                            <input type="color" class="form-control form-control-color"
                                   value="<?= htmlspecialchars($club['color_bg'] ?: '#f3efe4') ?>"
                                   title="Pick a color">
                            <input type="text" class="form-control" name="color_bg"
                                   value="<?= htmlspecialchars($club['color_bg']) ?>"
                                   placeholder="#f3efe4" maxlength="7">
                        </div>
                        <div class="form-text">Background behind all page content.</div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Muted / borders</label>
                        <div class="input-group js-color-sync">
                            <input type="color" class="form-control form-control-color"
                                   value="<?= htmlspecialchars($club['color_muted'] ?: '#665e52') ?>"
                                   title="Pick a color">
                            <input type="text" class="form-control" name="color_muted"
                                   value="<?= htmlspecialchars($club['color_muted']) ?>"
                                   placeholder="#665e52" maxlength="7">
                        </div>
                        <div class="form-text">Borders, dividers, and helper text.</div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Body text</label>
                        <div class="input-group js-color-sync">
                            <input type="color" class="form-control form-control-color"
                                   value="<?= htmlspecialchars($club['color_text'] ?: '#252018') ?>"
                                   title="Pick a color">
                            <input type="text" class="form-control" name="color_text"
                                   value="<?= htmlspecialchars($club['color_text']) ?>"
                                   placeholder="#252018" maxlength="7">
                        </div>
                        <div class="form-text">Main readable text color — should contrast well against the background.</div>
                    </div>

                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header fw-semibold">Contacts</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="support_email">Support email</label>
                        <input type="email" class="form-control" id="support_email" name="support_email"
                               value="<?= h($configRows['support_email'] ?? '') ?>">
                        <div class="form-text">Club contact on applicant emails. Also used when membership email is blank.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="membership_email">Membership email</label>
                        <input type="email" class="form-control" id="membership_email" name="membership_email"
                               value="<?= h($configRows['membership_email'] ?? '') ?>">
                        <div class="form-text">New applications and member profile updates are sent here. If blank, support email is used, then the first active administrator.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════
         MEMBERSHIP TAB
         Renewal season (club-wide) plus one card per membership type.
         ══════════════════════════════════════════════════════════════ -->
    <div class="tab-pane fade" id="membership" role="tabpanel">
        <?php
        $monthNames = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
        $preMo = isset($configRows['renewal_prebook_start_month'])
            ? max(1, min(12, (int) $configRows['renewal_prebook_start_month']))
            : 10;
        $preDay = renewal_prebook_clamp_day(
            $preMo,
            isset($configRows['renewal_prebook_start_day']) ? (int) $configRows['renewal_prebook_start_day'] : 15
        );
        $reportsYear = isset($configRows['reports_accurate_from_year'])
            ? max(2000, min(2100, (int) $configRows['reports_accurate_from_year']))
            : 2027;
        $preDayMax = renewal_prebook_days_in_month($preMo);
        ?>

        <div class="card mb-4">
            <div class="card-header fw-semibold">Renewal season</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="renewal_prebook_start_month">Renewal season starts</label>
                        <div class="d-flex gap-2">
                            <select class="form-select" id="renewal_prebook_start_month" name="renewal_prebook_start_month">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>"<?= $preMo === $m ? ' selected' : '' ?>><?= h($monthNames[$m]) ?></option>
                                <?php endfor; ?>
                            </select>
                            <select class="form-select w-auto" id="renewal_prebook_start_day" name="renewal_prebook_start_day" aria-label="Renewal season start day">
                                <?php for ($d = 1; $d <= $preDayMax; $d++): ?>
                                <option value="<?= $d ?>"<?= $preDay === $d ? ' selected' : '' ?>><?= $d ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-text">
                            On this date, signup, renewal, and application defaults switch to <strong>next</strong> calendar year
                            through December 31. Before it, they use the current year. This also sets the on-time renewal
                            window below and the year on the weekly roster PDF. Default: October 15.
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="reports_accurate_from_year">First year with complete records</label>
                        <input type="number" class="form-control" id="reports_accurate_from_year" name="reports_accurate_from_year"
                               min="2000" max="2100" step="1"
                               value="<?= h((string) $reportsYear) ?>">
                        <div class="form-text">
                            Reports add a warning for <strong>earlier</strong> years, whose counts are rebuilt from payment
                            history and may undercount members or revenue. Default: 2027.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="alert alert-info d-flex gap-2 align-items-start mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor"
                 class="flex-shrink-0 mt-1" viewBox="0 0 16 16" aria-hidden="true">
                <path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16m.93-9.412-1 4.705c-.07.34.029.533.304.533.194 0 .487-.07.686-.246l-.088.416c-.287.346-.92.598-1.465.598-.703 0-1.002-.422-.808-1.319l.738-3.468c.064-.293.006-.399-.287-.47l-.451-.081.082-.381 2.29-.287zM8 5.5a1 1 0 1 1 0-2 1 1 0 0 1 0 2"/>
            </svg>
            <div class="small">
                <strong>How rates are used when recording a renewal:</strong>
                <ul class="mb-0 mt-1 ps-3">
                    <li><strong>Annual dues</strong> — charged for on-time renewals (<?= h(renewal_on_time_window_label($pdo)) ?> of prior year)</li>
                    <li><strong>Prorated dues</strong> — charged for new members joining mid-year (during the prorate window)</li>
                    <li><strong>Initiation fee</strong> — one-time fee added to new member or late renewal payments (set to $0 if your club doesn't charge one)</li>
                    <li>You can always override any amount at the time of recording</li>
                </ul>
            </div>
        </div>

        <div class="row g-4">
        <?php
        // Default labels in case columns don't exist on older installs
        $defaultLabels = [1 => 'Adult', 2 => 'Youth', 3 => 'Senior', 4 => 'Spouse'];

        for ($slot = 1; $slot <= 4; $slot++):
            // Pull current values from the club row
            $slotLabel   = trim((string) ($club["membership_type{$slot}_label"] ?? $defaultLabels[$slot]));
            $slotEnabled = (bool) ($club["membership_type{$slot}_enabled"] ?? true);
            $rule        = $duesRules[$slot] ?? null;

            $annual     = $rule ? (float) $rule['annual_dues']    : 0.0;
            $prorated   = $rule ? (float) $rule['prorated_dues']  : 0.0;
            $initiation = $rule ? (float) $rule['initiation_fee'] : 0.0;
            $psMonth    = $rule ? (int) $rule['prorate_start_month'] : 7;
            $peMonth    = $rule ? (int) $rule['prorate_end_month']   : 10;

            // Month names for the prorate selects
            $months = [
                1 => 'January', 2 => 'February', 3 => 'March',    4 => 'April',
                5 => 'May',     6 => 'June',      7 => 'July',     8 => 'August',
                9 => 'September',10 => 'October', 11 => 'November',12 => 'December',
            ];
        ?>
        <div class="col-12 col-lg-6">
            <div class="card h-100 <?= $slotEnabled ? '' : 'border-secondary opacity-75' ?>">

                <!-- Card header: type name + enabled toggle, side by side -->
                <div class="card-header d-flex align-items-center justify-content-between gap-3 py-2">
                    <div class="d-flex align-items-center gap-2 flex-grow-1">
                        <span class="badge bg-secondary" style="font-size:.7rem;min-width:1.6rem;">
                            <?= $slot ?>
                        </span>
                        <input type="text"
                               name="membership_type<?= $slot ?>_label"
                               value="<?= htmlspecialchars($slotLabel) ?>"
                               class="form-control form-control-sm fw-semibold border-0 bg-transparent p-0 slot-label-input"
                               style="font-size:.95rem;max-width:160px;"
                               placeholder="e.g. Adult"
                               aria-label="Membership type <?= $slot ?> name">
                    </div>
                    <div class="form-check form-switch mb-0 flex-shrink-0">
                        <input class="form-check-input" type="checkbox"
                               name="membership_type<?= $slot ?>_enabled"
                               id="mt<?= $slot ?>_enabled"
                               value="1"
                               <?= $slotEnabled ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="mt<?= $slot ?>_enabled">
                            <?= $slotEnabled ? 'Active' : 'Disabled' ?>
                        </label>
                    </div>
                </div>

                <div class="card-body">

                    <?php if (!$slotEnabled): ?>
                    <p class="text-muted small mb-3">
                        This type is disabled and won't appear in member forms or renewal options.
                        Enable it above to set rates.
                    </p>
                    <?php endif; ?>

                    <!-- Dues rates -->
                    <div class="row g-3">

                        <div class="col-12 col-sm-4">
                            <label class="form-label small fw-semibold mb-1"
                                   for="dues_<?= $slot ?>_annual">
                                Annual dues
                            </label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">$</span>
                                <input type="number" id="dues_<?= $slot ?>_annual"
                                       class="form-control"
                                       name="dues_slot<?= $slot ?>_annual"
                                       value="<?= htmlspecialchars(number_format($annual, 2, '.', '')) ?>"
                                       min="0" step="0.01">
                            </div>
                            <div class="form-text">On-time renewals</div>
                        </div>

                        <div class="col-12 col-sm-4">
                            <label class="form-label small fw-semibold mb-1"
                                   for="dues_<?= $slot ?>_prorated">
                                Prorated dues
                            </label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">$</span>
                                <input type="number" id="dues_<?= $slot ?>_prorated"
                                       class="form-control"
                                       name="dues_slot<?= $slot ?>_prorated"
                                       value="<?= htmlspecialchars(number_format($prorated, 2, '.', '')) ?>"
                                       min="0" step="0.01">
                            </div>
                            <div class="form-text">New members mid-year</div>
                        </div>

                        <div class="col-12 col-sm-4">
                            <label class="form-label small fw-semibold mb-1"
                                   for="dues_<?= $slot ?>_initiation">
                                Initiation fee
                            </label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">$</span>
                                <input type="number" id="dues_<?= $slot ?>_initiation"
                                       class="form-control"
                                       name="dues_slot<?= $slot ?>_initiation"
                                       value="<?= htmlspecialchars(number_format($initiation, 2, '.', '')) ?>"
                                       min="0" step="0.01">
                            </div>
                            <div class="form-text">New / late renewals</div>
                        </div>

                    </div><!-- /.row rates -->

                    <!-- Prorate window — collapsible to keep the card tidy -->
                    <details class="mt-3">
                        <summary class="text-muted small" style="cursor:pointer;user-select:none;">
                            Prorate window:
                            <strong><?= $months[$psMonth] ?> – <?= $months[$peMonth] ?></strong>
                            <span class="text-muted">(click to change)</span>
                        </summary>
                        <div class="mt-2 pt-2 border-top">
                            <p class="small text-muted mb-2">
                                Members who join during this window are offered the prorated rate.
                                Outside this window they pay the full annual dues.
                            </p>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label small mb-1">Window opens</label>
                                    <select class="form-select form-select-sm"
                                            name="dues_slot<?= $slot ?>_prorate_start_month">
                                        <?php foreach ($months as $num => $name): ?>
                                        <option value="<?= $num ?>" <?= $num === $psMonth ? 'selected' : '' ?>>
                                            <?= $name ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">Window closes</label>
                                    <select class="form-select form-select-sm"
                                            name="dues_slot<?= $slot ?>_prorate_end_month">
                                        <?php foreach ($months as $num => $name): ?>
                                        <option value="<?= $num ?>" <?= $num === $peMonth ? 'selected' : '' ?>>
                                            <?= $name ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </details>

                </div><!-- /.card-body -->
            </div><!-- /.card -->
        </div><!-- /.col -->
        <?php endfor; ?>
        </div><!-- /.row -->

    </div><!-- /#membership tab-pane -->

</div><!-- /.tab-content -->

<div class="mt-4 pt-2 border-top">
    <button type="submit" class="btn btn-primary">Save configuration</button>
    <a href="index.php" class="btn btn-outline-secondary ms-2">Cancel</a>
</div>

</form>

<script<?= csp_nonce_attr() ?>>
// Remember the active tab across saves so the user lands back where they were
(function () {
var KEY = 'config_site_tab';
var el  = document.getElementById('configTabs');
if (!el) return;

var aliases = { '#general': '#club', '#design': '#club', '#dues': '#membership' };
var stored = sessionStorage.getItem(KEY);
if (stored && aliases[stored]) stored = aliases[stored];
if (stored) {
    var btn = document.querySelector('#configTabs button[data-bs-target="' + stored + '"]');
    if (btn) new bootstrap.Tab(btn).show();
}

el.addEventListener('shown.bs.tab', function (e) {
    var target = e.target.getAttribute('data-bs-target');
    if (target) sessionStorage.setItem(KEY, target);
});

var monthSel = document.getElementById('renewal_prebook_start_month');
var daySel   = document.getElementById('renewal_prebook_start_day');
if (monthSel && daySel) {
    var daysInMonth = [31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

    function syncDays() {
        var max = daysInMonth[(parseInt(monthSel.value, 10) || 1) - 1];
        var want = parseInt(daySel.value, 10) || 1;
        daySel.innerHTML = '';
        for (var d = 1; d <= max; d++) {
            var opt = document.createElement('option');
            opt.value = String(d);
            opt.textContent = String(d);
            if (d === Math.min(want, max)) { opt.selected = true; }
            daySel.appendChild(opt);
        }
    }

    monthSel.addEventListener('change', syncDays);
}

})();

// Update the "Active / Disabled" label text next to each membership type toggle
document.querySelectorAll('.form-check-input[id^="mt"]').forEach(function (cb) {
cb.addEventListener('change', function () {
this.nextElementSibling.textContent = this.checked ? 'Active' : 'Disabled';
// Dim the card when disabled
var card = this.closest('.card');
if (card) {
card.classList.toggle('border-secondary', !this.checked);
card.classList.toggle('opacity-75', !this.checked);
}
});
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>