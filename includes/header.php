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
$flightopsCspOptions = $flightopsCspOptions ?? [];
flightops_send_security_headers($flightopsCspOptions);

require_once __DIR__ . '/vendor_assets.php';
require_once __DIR__ . '/csrf.php';

require_once __DIR__ . '/club_theme.php';

$pageTitle = isset($pageTitle) ? $pageTitle : 'RC Flight Operations';
$showNav   = empty($noNav) && !empty($_SESSION['user_id']);

// ── Load club theme (single row in `club`) ────────────────────────────────────
$theme = flightops_club_theme_defaults();
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

$primaryRgb = flightops_hex_to_rgb($theme['color_primary']);
$_onPrimary = flightops_on_primary_for($theme['color_primary']);
$_headerNavbarBsTheme = $_onPrimary['bs_theme'];
$_headerOnPrimary     = $_onPrimary['color'];
$_headerOnPrimaryRgb  = $_onPrimary['rgb'];
$_clubStatus          = flightops_club_status_tokens();

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

$_headerBaseHref = $baseHref ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
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
    <link href="<?= htmlspecialchars(flightops_bootstrap_css_url()) ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars(flightops_app_css_url()) ?>" rel="stylesheet">
    <style<?= csp_nonce_attr() ?>>
        /* Club theme tokens (club row). Shared rules live in assets/css/app.css. */
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
            --club-card:         color-mix(in srgb, <?= htmlspecialchars($theme['color_bg']) ?> 88%, #ffffff);
            --club-border:       color-mix(in srgb, <?= htmlspecialchars($theme['color_muted']) ?> 35%, <?= htmlspecialchars($theme['color_bg']) ?>);
            --club-success:      <?= $_clubStatus['success'] ?>;
            --club-warning:      <?= $_clubStatus['warning'] ?>;
            --club-danger:       <?= $_clubStatus['danger'] ?>;
            --club-success-rgb:  <?= $_clubStatus['success_rgb'] ?>;
            --club-warning-rgb:  <?= $_clubStatus['warning_rgb'] ?>;
            --club-danger-rgb:   <?= $_clubStatus['danger_rgb'] ?>;
            --club-font-sans: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", "Noto Sans", "Liberation Sans", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
            --bs-primary:        var(--club-primary);
            --bs-primary-rgb:    var(--club-primary-rgb);
            --club-accent:     rgba(var(--club-primary-rgb), 0.08);
            --club-accent-mid: rgba(var(--club-primary-rgb), 0.14);
            --club-focus-ring: rgba(var(--club-primary-rgb), 0.25);
        }
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

                <!-- Members (Club Staff and above) -->
                <?php if (function_exists('canViewMembers') && canViewMembers()): ?>
                <li class="nav-item">
                    <a class="nav-link<?= navActive(['members.php', 'member_edit.php', 'member_wizard.php', 'member_view.php', 'member_delete.php', 'member_process.php']) ?>"
                       href="<?= $_headerBaseHref ?>members.php">Members</a>
                </li>
                <?php endif; ?>

                <?php if ((function_exists('canEditMembers') && canEditMembers()) || (function_exists('canProcessMemberships') && canProcessMemberships())): ?>
                <?php
                $_navPendingApps = 0;
                if (isset($pdo)) {
                    try {
                        require_once __DIR__ . '/member_applications.php';
                        $_navPendingApps = application_pending_count($pdo);
                    } catch (Throwable $e) {
                    }
                }
                ?>
                <li class="nav-item">
                    <a class="nav-link<?= navActive('applications.php') ?>"
                       href="<?= $_headerBaseHref ?>applications.php">
                        Applications<?php if ($_navPendingApps > 0): ?><span class="badge text-bg-warning ms-1"><?= (int) $_navPendingApps ?></span><?php endif; ?>
                    </a>
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
                <?php if (canEditMembers()): ?>
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
                    <ul class="dropdown-menu" aria-labelledby="navAdmin" data-bs-theme="light">
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

                <!-- Help dropdown — documentation + about -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center gap-1 px-2<?= navActive('about.php') ?>"
                       href="#" id="navHelp" role="button"
                       data-bs-toggle="dropdown" aria-expanded="false"
                       title="Help">
                        <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17"
                             fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                            <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14m0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16"/>
                            <path d="M5.255 5.786a.237.237 0 0 0 .241.247h.825c.138 0 .248-.113.266-.25.09-.656.54-1.134 1.342-1.134.686 0 1.314.343 1.314 1.168 0 .635-.374.927-.965 1.371-.673.489-1.206 1.06-1.168 1.987l.003.217a.25.25 0 0 0 .25.246h.811a.25.25 0 0 0 .25-.25v-.105c0-.718.273-.927 1.01-1.486.609-.463 1.244-.977 1.244-2.056 0-1.511-1.276-2.241-2.673-2.241-1.267 0-2.655.59-2.75 2.286m1.557 5.763c0 .533.425.927 1.01.927.609 0 1.028-.394 1.028-.927 0-.552-.42-.94-1.029-.94-.584 0-1.009.388-1.009.94"/>
                        </svg>
                        <span class="d-none d-xl-inline">Help</span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navHelp" data-bs-theme="light">
                        <li>
                            <a class="dropdown-item" href="<?= $_headerBaseHref ?>docs/index.html">
                                Documentation
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item<?= navActive('about.php') ? ' active' : '' ?>"
                               href="<?= $_headerBaseHref ?>about.php">
                                About
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- User avatar + account dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center gap-2 py-1"
                       href="#" id="navUser" role="button"
                       data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="nav-user-avatar"><?= htmlspecialchars($_navInitials ?: '?') ?></span>
                        <span class="d-none d-lg-inline"><?= htmlspecialchars($_navUserName) ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navUser" data-bs-theme="light">

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