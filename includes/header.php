<?php
/**
 * includes/header.php
 *
 * Shared layout: HTML head, navbar (with active state + user badge), breadcrumbs,
 * flash toast container, open body and container.
 *
 * Set $pageTitle before including.
 * Set $noNav = true to hide navbar (e.g. login page, badge print).
 * Set $breadcrumbs = [['label'=>'...', 'url'=>'...'], ...] for breadcrumb trail.
 */

require_once __DIR__ . '/security_headers.php';
flightops_send_security_headers();

require_once __DIR__ . '/csrf.php';

$pageTitle = isset($pageTitle) ? $pageTitle : 'RC Flight Operations';
$showNav   = empty($noNav) && !empty($_SESSION['user_id']);

// ── Load club theme (single row in `club`) ────────────────────────────────────
$theme = [
    'name'               => 'RC Flight Operations',
    'logo_path'          => null,
    'favicon_path'       => null,
    'color_primary'      => '#6f7c3d',
    'color_primary_dark' => '#556030',
    'color_bg'           => '#f3efe4',
    'color_muted'        => '#665e52',
    'color_text'         => '#252018',
];
if (isset($pdo)) {
    try {
        $stmt = $pdo->query('SELECT name, logo_path, favicon_path, color_primary, color_primary_dark, color_bg, color_muted, color_text FROM club WHERE id = 1 LIMIT 1');
        $row = $stmt ? $stmt->fetch() : false;
        if ($row) {
            $theme['name']               = $row['name']               ?: 'RC Flight Operations';
            $theme['logo_path']          = $row['logo_path'] ?? null;
            $theme['favicon_path']       = $row['favicon_path'] ?? null;
            $theme['color_primary']      = $row['color_primary']      ?: '#6f7c3d';
            $theme['color_primary_dark'] = $row['color_primary_dark'] ?: '#556030';
            $theme['color_bg']           = $row['color_bg']           ?: '#f3efe4';
            $theme['color_muted']        = $row['color_muted']        ?: '#665e52';
            $theme['color_text']         = $row['color_text']         ?: '#252018';
        }
    } catch (Throwable $e) {
    }
}

function hexToRgb(string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 6) {
        return implode(',', array_map('hexdec', str_split($hex, 2)));
    }
    return '111,124,61'; // default primary olive #6f7c3d
}

/** WCAG relative luminance (0–1); used to pick light/dark text on --club-primary. */
function flightops_relative_luminance(string $hex): float {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) {
        return 0.2;
    }
    $toLin = static function (int $c): float {
        $s = $c / 255;
        return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
    };
    $r = $toLin(hexdec(substr($hex, 0, 2)));
    $g = $toLin(hexdec(substr($hex, 2, 2)));
    $b = $toLin(hexdec(substr($hex, 4, 2)));
    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
}

$primaryRgb = hexToRgb($theme['color_primary']);
$_clubPrimaryLum = flightops_relative_luminance($theme['color_primary']);
// Bright primaries need dark navbar type; saturated dark primaries use light type.
$_headerNavbarBsTheme = $_clubPrimaryLum >= 0.45 ? 'light' : 'dark';
$_headerOnPrimary     = $_headerNavbarBsTheme === 'light' ? '#252018' : '#ffffff';
$_headerOnPrimaryRgb  = $_headerNavbarBsTheme === 'light' ? '37,32,24' : '255,255,255';

require_once __DIR__ . '/flightops_logo.php';

// ── Active nav helper ─────────────────────────────────────────────────────────
// Returns ' active' if the given filename matches the current script basename.
function navActive(string|array $pages): string {
    $current = basename($_SERVER['PHP_SELF'] ?? '');
    $pages   = is_array($pages) ? $pages : [$pages];
    return in_array($current, $pages, true) ? ' active' : '';
}

// ── Flash messages ────────────────────────────────────────────────────────────
$_headerFlashes = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);

// Legacy compat: ?saved=1 from various pages
if (!empty($_GET['saved'])) {
    $_headerFlashes[] = ['type' => 'success', 'msg' => 'Changes saved.'];
}

// ── Current user display ──────────────────────────────────────────────────────
$_navUserName = $_SESSION['user_name'] ?? ($_SESSION['user_email'] ?? '');
$_navUserRole = $_SESSION['user_role'] ?? '';
$_navInitials = '';
if ($_navUserName) {
    $parts = explode(' ', trim($_navUserName));
    $_navInitials = strtoupper(
        substr($parts[0], 0, 1) .
        (count($parts) > 1 ? substr(end($parts), 0, 1) : '')
    );
}

$_headerBaseHref = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($_headerBaseHref !== ''): ?>
    <base href="<?= htmlspecialchars($_headerBaseHref) ?>">
    <?php endif; ?>
    <title><?= htmlspecialchars($pageTitle) ?> – <?= htmlspecialchars($theme['name']) ?></title>
    <?php
    $_faviconHref = null;
    $_faviconType = null;
    if (!empty($theme['favicon_path'])) {
        $_faviconFs = dirname(__DIR__) . '/' . $theme['favicon_path'];
        if (is_readable($_faviconFs)) {
            $_faviconHref = htmlspecialchars($theme['favicon_path']) . '?t=' . filemtime($_faviconFs);
            $_faviconType = match (strtolower(pathinfo($_faviconFs, PATHINFO_EXTENSION))) {
                'ico' => 'image/x-icon',
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                default => null,
            };
        }
    }
    if ($_faviconHref === null && is_readable(flightops_logo_asset_file())) {
        $_faviconHref = htmlspecialchars(flightops_logo_asset_src());
        $_faviconType = 'image/png';
    }
    ?>
    <?php if ($_faviconHref !== null): ?>
    <link rel="icon" href="<?= $_faviconHref ?>"<?= $_faviconType ? (' type="' . htmlspecialchars($_faviconType) . '"') : '' ?>>
    <?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style<?= csp_nonce_attr() ?>>
        /* ── CSS custom properties (theme) ─────────────────────────────── */
        :root {
            --club-primary:      <?= htmlspecialchars($theme['color_primary']) ?>;
            --club-primary-dark: <?= htmlspecialchars($theme['color_primary_dark']) ?>;
            --club-primary-rgb:  <?= $primaryRgb ?>;
            --club-on-primary:     <?= htmlspecialchars($_headerOnPrimary) ?>;
            --club-on-primary-rgb: <?= htmlspecialchars($_headerOnPrimaryRgb) ?>;
            --club-on-primary-muted: rgba(var(--club-on-primary-rgb), 0.72);
            --club-bg:           <?= htmlspecialchars($theme['color_bg']) ?>;
            --club-muted:        <?= htmlspecialchars($theme['color_muted']) ?>;
            --club-text:         <?= htmlspecialchars($theme['color_text']) ?>;
            --bs-primary:        var(--club-primary);
            --bs-primary-rgb:    var(--club-primary-rgb);
        }

        /* ── Accent tint (hover backgrounds, focus rings) ────────────────
           Bootstrap 5 computes hover tints from --bs-primary-rgb using its
           own internal math. We sidestep it by defining our own accent
           variable driven directly from the primary RGB.                   */
        :root {
            --club-accent:     rgba(var(--club-primary-rgb), 0.08);
            --club-accent-mid: rgba(var(--club-primary-rgb), 0.14);
            --club-focus-ring: rgba(var(--club-primary-rgb), 0.25);
        }

        /* ── Base ────────────────────────────────────────────────────── */
        body { background-color: var(--club-bg); color: var(--club-text); }

        /* ── Bootstrap primary overrides ─────────────────────────────── */
        .navbar.bg-primary                     { background-color: var(--club-primary) !important; }
        .btn-primary                           { background-color: var(--club-primary); border-color: var(--club-primary); color: var(--club-on-primary); }
        .btn-primary:hover, .btn-primary:focus { background-color: var(--club-primary-dark); border-color: var(--club-primary-dark); color: var(--club-on-primary); }
        .btn-outline-primary                   { color: var(--club-primary); border-color: var(--club-primary); }
        .btn-outline-primary:hover             { background-color: var(--club-primary); border-color: var(--club-primary); color: var(--club-on-primary); }
        /* Danger-style buttons use theme (e.g. Delete member) so the app stays on-palette */
        .btn-danger                            { background-color: var(--club-primary); border-color: var(--club-primary); color: var(--club-on-primary); }
        .btn-danger:hover, .btn-danger:focus   { background-color: var(--club-primary-dark); border-color: var(--club-primary-dark); color: var(--club-on-primary); }
        .btn-outline-danger                    { color: var(--club-primary); border-color: var(--club-primary); }
        .btn-outline-danger:hover              { background-color: var(--club-primary); border-color: var(--club-primary); color: var(--club-on-primary); }
        .form-check-input:checked              { background-color: var(--club-primary); border-color: var(--club-primary); }
        .border-primary                        { border-color: var(--club-primary) !important; }
        .text-primary                          { color: var(--club-primary) !important; }
        .text-muted                            { color: var(--club-muted) !important; }

        /* ── Fix Bootstrap's blue/purple focus rings and hover tints ─────
           Override every place Bootstrap uses its hardcoded blue tint so
           club colours are used consistently throughout.                   */
        .form-control:focus,
        .form-select:focus                     { border-color: var(--club-primary) !important;
                                                 box-shadow: 0 0 0 0.2rem var(--club-focus-ring) !important; }
        .form-check-input:focus                { box-shadow: 0 0 0 0.2rem var(--club-focus-ring) !important; }
        .btn-primary:focus-visible,
        .btn-outline-primary:focus-visible,
        .btn-danger:focus-visible,
        .btn-outline-danger:focus-visible      { box-shadow: 0 0 0 0.2rem var(--club-focus-ring) !important; }

        /* Nav-tabs: kill Bootstrap's tint on hover; use our accent instead */
        .nav-tabs .nav-link:not(.active):hover { background-color: var(--club-accent) !important;
                                                 color: var(--club-primary) !important;
                                                 border-color: var(--club-muted) !important; }

        /* ── Navbar ──────────────────────────────────────────────────── */
        .navbar-brand {
            min-width: 0;
            max-width: 100%;
        }
        .navbar-brand-text {
            display: inline-block;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            vertical-align: bottom;
        }
        @media (max-width: 991.98px) {
            .navbar-brand { max-width: calc(100vw - 7rem); }
        }
        @media (min-width: 992px) {
            .navbar-brand-text {
                max-width: none;
                overflow: visible;
                text-overflow: clip;
                white-space: normal;
            }
        }
        /* Club navbar: text colour follows primary luminance (gold → dark type). */
        .navbar.navbar-club .navbar-brand,
        .navbar.navbar-club .nav-link { color: var(--club-on-primary); }
        .navbar.navbar-club .navbar-brand:hover,
        .navbar.navbar-club .navbar-brand:focus { color: var(--club-on-primary); }
        .navbar.navbar-club .nav-link { opacity: 0.88; transition: opacity 0.15s; }
        .navbar.navbar-club .nav-link:hover,
        .navbar.navbar-club .nav-link:focus,
        .navbar.navbar-club .nav-link.active { opacity: 1; font-weight: 500; color: var(--club-on-primary); }
        .navbar.navbar-club .nav-link.active::after {
            content: '';
            display: block;
            height: 2px;
            background: var(--club-on-primary-muted);
            border-radius: 1px;
            margin-top: 2px;
        }

        /* User avatar initials badge in navbar */
        .nav-user-avatar {
            width: 28px; height: 28px;
            border-radius: 50%;
            background: rgba(var(--club-on-primary-rgb), 0.2);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 700; letter-spacing: 0.02em;
            color: var(--club-on-primary);
            flex-shrink: 0;
        }

        /* ── Toast container ─────────────────────────────────────────── */
        .toast-container-fixed {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            z-index: 1090;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            pointer-events: none;
        }
        .toast-container-fixed .toast {
            pointer-events: auto;
            min-width: 260px;
            max-width: 400px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.18);
        }

        /* ── Breadcrumb ──────────────────────────────────────────────── */
        .app-breadcrumb {
            font-size: 0.8rem;
            margin-bottom: 0.75rem;
            padding: 0;
            background: none;
        }
        .app-breadcrumb .breadcrumb { margin-bottom: 0; }
        .app-breadcrumb .breadcrumb-item + .breadcrumb-item::before { color: var(--club-muted); }

        /* ── Content tabs (member edit, config, etc.) ────────────────── */
        #memberTabs.nav-tabs,
        #configTabs.nav-tabs {
            border-bottom: 2px solid var(--club-primary);
        }
        #memberTabs .nav-link,
        #configTabs .nav-link {
            color: var(--club-text);
            background: var(--club-bg);
            border: 1px solid var(--club-muted);
            border-bottom: none;
            margin-bottom: -2px;
            font-weight: 500;
        }
        #memberTabs .nav-link:hover,
        #configTabs .nav-link:hover {
            background: var(--club-accent);
            color: var(--club-primary);
            border-color: var(--club-muted);
        }
        #memberTabs .nav-link.active,
        #configTabs .nav-link.active {
            background: var(--club-primary);
            color: var(--club-on-primary);
            border-color: var(--club-primary);
        }

        /* ── Address tabs ────────────────────────────────────────────── */
        #addressTabs.nav-tabs             { border-bottom: 1px solid var(--club-muted); }
        #addressTabs .nav-link            { font-size: 0.9rem; padding: 0.35rem 0.75rem; color: var(--club-text); background: transparent; border-color: var(--club-muted); }
        #addressTabs .nav-link:hover      { color: var(--club-primary); background: var(--club-accent); }
        #addressTabs .nav-link.active     { color: var(--club-primary); font-weight: 500; border-color: var(--club-muted); border-bottom-color: var(--club-bg); background: var(--club-bg); }

        /* ── AMA expiration status ───────────────────────────────────── */
        #ama-expiration-wrap.ama-valid    { border-left: 4px solid #198754; padding-left: 0.5rem; margin-left: -0.5rem; }
        #ama-expiration-wrap.ama-warning  { border-left: 4px solid #ffc107; padding-left: 0.5rem; margin-left: -0.5rem; }
        #ama-expiration-wrap.ama-expired  { border-left: 4px solid #dc3545; padding-left: 0.5rem; margin-left: -0.5rem; }
        .ama-status-badge                 { display: inline-block; margin-top: 0.25rem; font-size: 0.75rem; font-weight: 500; padding: 0.15rem 0.4rem; border-radius: 4px; }
        .ama-status-badge.ama-valid       { background: #198754; color: #fff; }
        .ama-status-badge.ama-warning     { background: #ffc107; color: #212529; }
        .ama-status-badge.ama-expired     { background: #dc3545; color: #fff; }

        /* ── Card hover shadow (dashboard nav cards) ─────────────────── */
        .hover-shadow { transition: box-shadow 0.15s, border-color 0.15s; }
        .hover-shadow:hover { box-shadow: 0 4px 12px rgba(0,0,0,.1); border-color: var(--club-primary) !important; }

        /* ── Shared sidebar nav component ────────────────────────────────
           Used by badge_design.php and any future page that
           needs a left-rail navigation panel.                              */
        .sidebar-nav-link {
            display: block; padding: 0.35rem 1rem;
            font-size: 0.875rem; text-decoration: none;
            color: var(--bs-body-color);
            border-left: 3px solid transparent;
            transition: background 0.1s, color 0.1s;
        }
        .sidebar-nav-link:hover { background: var(--club-accent); color: var(--club-primary); }
        .sidebar-nav-link.active {
            background: var(--club-accent);
            color: var(--club-primary);
            border-left-color: var(--club-primary);
            font-weight: 600;
        }

        /* ── Stat cards (dashboard + reports) ───────────────────────── */
        .stat-card { border: 1px solid #e9ecef; cursor: default; }
        a.stat-card { cursor: pointer; }
        a.stat-card:hover { border-color: var(--club-primary); box-shadow: 0 4px 12px rgba(0,0,0,.08); }
        .stat-icon { font-size: 1.25rem; margin-bottom: 0.35rem; }
        .stat-value { font-size: 1.9rem; font-weight: 700; line-height: 1.1; }
        .stat-label { font-size: 0.8rem; font-weight: 600; color: #555; margin-top: 0.2rem; }
        .stat-sub   { font-size: 0.75rem; margin-top: 0.1rem; }
    </style>
</head>
<body>
<?php if (empty($noNav)): ?>
    <nav class="navbar navbar-expand-lg navbar-club navbar-<?= htmlspecialchars($_headerNavbarBsTheme) ?>"
         data-bs-theme="<?= htmlspecialchars($_headerNavbarBsTheme) ?>"
         style="background-color: var(--club-primary);">
        <div class="container-fluid">

        <!-- ── Brand ──────────────────────────────────────────────────────── -->
        <a class="navbar-brand d-flex align-items-center gap-2 text-decoration-none"
           href="<?= $_headerBaseHref ?>index.php">
            <?php if (!empty($theme['logo_path'])
                      && is_readable(dirname(__DIR__) . '/' . $theme['logo_path'])): ?>
            <img src="<?= htmlspecialchars($theme['logo_path']) ?>?t=<?= filemtime(dirname(__DIR__) . '/' . $theme['logo_path']) ?>"
                 alt="" height="34" class="d-inline-block align-text-top">
            <?php else: ?>
            <?php flightops_logo(34, false); ?>
            <?php endif; ?>
            <span class="navbar-brand-text"><?= htmlspecialchars($theme['name']) ?></span>
        </a>

        <!-- ── Hamburger ──────────────────────────────────────────────────── -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                data-bs-target="#navbarMain" aria-controls="navbarMain"
                aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarMain">

            <!-- ── Left links ─────────────────────────────────────────────── -->
            <ul class="navbar-nav me-auto">

                <!-- Home -->
                <li class="nav-item">
                    <a class="nav-link<?= navActive('index.php') ?>"
                       href="<?= $_headerBaseHref ?>index.php">Home</a>
                </li>

                <!-- Members (viewers + above) -->
                <?php if (function_exists('canViewMembers') && canViewMembers()): ?>
                <li class="nav-item">
                    <a class="nav-link<?= navActive(['members.php', 'member_edit.php', 'member_view.php', 'member_delete.php', 'member_process.php']) ?>"
                       href="<?= $_headerBaseHref ?>members.php">Members</a>
                </li>
                <?php endif; ?>

                <!-- Reports (members + above; read-only aggregates) -->
                <?php if (function_exists('canViewReports') && canViewReports()): ?>
                <li class="nav-item">
                    <a class="nav-link<?= navActive('reports.php') ?>"
                       href="<?= $_headerBaseHref ?>reports.php">Reports</a>
                </li>
                <?php endif; ?>

                <!-- Incidents (member safety incidents) -->
                <?php if ((function_exists('canViewReports') && canViewReports()) || (function_exists('canEditMembers') && canEditMembers())): ?>
                <li class="nav-item">
                    <a class="nav-link<?= navActive('incidents.php') ?>"
                       href="<?= $_headerBaseHref ?>incidents.php">Incidents</a>
                </li>
                <?php endif; ?>

                <!-- Badge Design — gated by badge_designer feature flag -->
                <?php if (function_exists('canEditMembers') && canEditMembers() && function_exists('featureEnabled') && featureEnabled('badge_designer')): ?>
                <li class="nav-item">
                    <a class="nav-link<?= navActive('badge_design.php') ?>"
                       href="<?= $_headerBaseHref ?>badge_design.php">Badge Design</a>
                </li>
                <?php endif; ?>

                <!-- Administration dropdown — Users + Configuration only (admin only) -->
                <?php if (function_exists('canManageUsers') && canManageUsers()): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle<?= navActive(['users.php', 'user_edit.php', 'config_site.php', 'installation.php']) ?>"
                       href="#" id="navAdmin" role="button"
                       data-bs-toggle="dropdown" aria-expanded="false">
                        Administration
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="navAdmin">
                        <li>
                            <a class="dropdown-item<?= navActive(['users.php', 'user_edit.php', 'user_delete.php']) ? ' active' : '' ?>"
                               href="<?= $_headerBaseHref ?>users.php">Users</a>
                        </li>
                        <li>
                            <a class="dropdown-item<?= navActive('config_site.php') ? ' active' : '' ?>"
                               href="<?= $_headerBaseHref ?>config_site.php">Configuration</a>
                        </li>
                        <li>
                            <a class="dropdown-item<?= navActive('installation.php') ? ' active' : '' ?>"
                               href="<?= $_headerBaseHref ?>installation.php">Installation</a>
                        </li>
                        <li>
                            <a class="dropdown-item<?= navActive('audit_log_viewer.php') ? ' active' : '' ?>"
                               href="<?= $_headerBaseHref ?>audit_log_viewer.php">Audit log</a>
                        </li>
                        <li>
                            <a class="dropdown-item<?= navActive('logs_viewer.php') ? ' active' : '' ?>"
                               href="<?= $_headerBaseHref ?>logs_viewer.php">File logs</a>
                        </li>
                    </ul>
                </li>
                <?php endif; ?>

            </ul><!-- /.navbar-nav.me-auto -->

            <!-- ── Right side ──────────────────────────────────────────────── -->
            <?php if (!empty($_SESSION['user_id'])): ?>
            <ul class="navbar-nav ms-auto align-items-center gap-1">

                <!-- Help / Docs icon — always visible, not buried in a dropdown -->
                <li class="nav-item">
                    <a class="nav-link d-flex align-items-center px-2"
                       href="<?= $_headerBaseHref ?>docs/index.html"
                       title="Help &amp; Documentation">
                        <!-- Bootstrap bi-question-circle -->
                        <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17"
                             fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                            <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14m0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16"/>
                            <path d="M5.255 5.786a.237.237 0 0 0 .241.247h.825c.138 0 .248-.113.266-.25.09-.656.54-1.134 1.342-1.134.686 0 1.314.343 1.314 1.168 0 .635-.374.927-.965 1.371-.673.489-1.206 1.06-1.168 1.987l.003.217a.25.25 0 0 0 .25.246h.811a.25.25 0 0 0 .25-.25v-.105c0-.718.273-.927 1.01-1.486.609-.463 1.244-.977 1.244-2.056 0-1.511-1.276-2.241-2.673-2.241-1.267 0-2.655.59-2.75 2.286m1.557 5.763c0 .533.425.927 1.01.927.609 0 1.028-.394 1.028-.927 0-.552-.42-.94-1.029-.94-.584 0-1.009.388-1.009.94"/>
                        </svg>
                        <span class="visually-hidden">Help</span>
                    </a>
                </li>

                <!-- User avatar + account dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center gap-2 py-1"
                       href="#" id="navUser" role="button"
                       data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="nav-user-avatar"><?= htmlspecialchars($_navInitials ?: '?') ?></span>
                        <span class="d-none d-lg-inline"><?= htmlspecialchars($_navUserName) ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navUser">

                        <!-- Role label (non-interactive header) -->
                        <?php if ($_navUserRole): ?>
                        <li>
                            <span class="dropdown-item-text text-muted small">
                                <?= htmlspecialchars(ucfirst($_navUserRole)) ?>
                            </span>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <?php endif; ?>

                        <!-- Change password (profile.php) -->
                        <li>
                            <a class="dropdown-item d-flex align-items-center gap-2"
                               href="<?= $_headerBaseHref ?>profile.php">
                                <!-- bi-key -->
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14"
                                     fill="currentColor" class="text-muted flex-shrink-0" viewBox="0 0 16 16"
                                     aria-hidden="true">
                                    <path d="M0 8a4 4 0 0 1 7.465-2H14L15 7l1 1-1 1-1 1-1-1-1 1-1-1-1 1-1-1V6H7.465A4 4 0 0 1 0 8m4-3a3 3 0 1 0 0 6 3 3 0 0 0 0-6"/>
                                    <path d="M3.5 8a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1"/>
                                </svg>
                                Change password
                            </a>
                        </li>

                        <li><hr class="dropdown-divider"></li>

                        <!-- Log out -->
                        <li>
                            <a class="dropdown-item" href="<?= $_headerBaseHref ?>logout.php">
                                Log out
                            </a>
                        </li>

                    </ul>
                </li>

            </ul><!-- /.navbar-nav.ms-auto -->
            <?php endif; ?>

        </div><!-- /.collapse -->
    </div><!-- /.container-fluid -->
</nav>
<?php endif; ?>

<?php if (!empty($GLOBALS['_maintenanceMode'])): ?>
<div class="alert alert-warning mb-0 rounded-0 border-0 border-bottom text-center py-2"
     style="font-size:.875rem;background:#fff3cd;">
    <strong>Scheduled maintenance in progress.</strong>
    Some features may be temporarily unavailable. Check back soon.
</div>
<?php endif; ?>

<!-- ── Flash toasts ────────────────────────────────────────────────────────── -->
<?php if (!empty($_headerFlashes)): ?>
<div class="toast-container-fixed" id="flashToastContainer">
    <?php foreach ($_headerFlashes as $flash):
        $bgClass  = match($flash['type'] ?? 'success') {
            'danger'  => 'bg-danger text-white',
            'warning' => 'bg-warning',
            'info'    => 'bg-info text-white',
            default   => 'bg-success text-white',
        };
        $btnClass = in_array($flash['type'] ?? '', ['danger','info']) ? 'btn-close-white' : '';
    ?>
    <div class="toast align-items-center <?= $bgClass ?> border-0 show" role="alert" aria-live="assertive">
        <div class="d-flex">
            <div class="toast-body"><?= htmlspecialchars($flash['msg']) ?></div>
            <button type="button" class="btn-close <?= $btnClass ?> me-2 m-auto"
                    data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Breadcrumbs ────────────────────────────────────────────────────────── -->
<?php if (!empty($breadcrumbs)): ?>
<div class="container-fluid">
    <nav aria-label="breadcrumb" class="app-breadcrumb">
        <ol class="breadcrumb">
            <?php foreach ($breadcrumbs as $i => $crumb): ?>
            <?php if ($i < count($breadcrumbs) - 1): ?>
            <li class="breadcrumb-item">
                <a href="<?= htmlspecialchars($crumb['url'] ?? '#') ?>"><?= htmlspecialchars($crumb['label']) ?></a>
            </li>
            <?php else: ?>
            <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($crumb['label']) ?></li>
            <?php endif; ?>
            <?php endforeach; ?>
        </ol>
    </nav>
</div>
<?php endif; ?>

<div class="container-fluid pb-5">