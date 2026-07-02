<?php
/**
 * docs/docs-theme.php
 *
 * Outputs a CSS custom property block that overrides the design tokens in
 * docs.css with the club's stored colors.  Linked by every
 * docs/*.html page as <link rel="stylesheet" href="docs-theme.php">.
 *
 * Falls back gracefully to the RC Flight Operations retro earth-tone default palette when:
 *  - No session / user not logged in
 *  - The database is unavailable
 *  - The doc page is opened directly without a PHP session
 *
 * Zero dependencies beyond the app's own includes.
 */

header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=300');

require_once dirname(__DIR__) . '/includes/club_theme.php';

$theme = flightops_club_theme_defaults();

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!empty($_SESSION['user_id'])) {
        $configPath = dirname(__DIR__) . '/config.php';
        if (is_file($configPath)) {
            $cfg = require $configPath;
            $db  = $cfg['db'] ?? [];
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                $db['host'] ?? 'localhost',
                $db['name'] ?? ''
            );
            $pdo = new PDO($dsn, $db['user'] ?? '', $db['password'] ?? '', [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            $stmt = $pdo->prepare(
                'SELECT color_primary, color_primary_dark, color_bg, color_muted, color_text
                   FROM club WHERE id = 1'
            );
            $stmt->execute();
            $row = $stmt->fetch();

            if ($row) {
                foreach (['color_primary', 'color_primary_dark', 'color_bg', 'color_muted', 'color_text'] as $key) {
                    $val = trim($row[$key] ?? '');
                    if (preg_match('/^#[0-9A-Fa-f]{6}$/', $val)) {
                        $theme[$key] = $val;
                    }
                }
            }
        }
    }
} catch (Throwable $e) {
}

$primaryRgb     = flightops_hex_to_rgb($theme['color_primary']);
$primaryDarkRgb = flightops_hex_to_rgb($theme['color_primary_dark']);
$_onPrimary     = flightops_on_primary_for($theme['color_primary']);
$_clubStatus    = flightops_club_status_tokens();
?>
:root {
    --club-primary:      <?= htmlspecialchars($theme['color_primary']) ?>;
    --club-primary-dark: <?= htmlspecialchars($theme['color_primary_dark']) ?>;
    --club-bg:           <?= htmlspecialchars($theme['color_bg']) ?>;
    --club-muted:        <?= htmlspecialchars($theme['color_muted']) ?>;
    --club-text:         <?= htmlspecialchars($theme['color_text']) ?>;
    --club-primary-rgb:  <?= $primaryRgb ?>;
    --club-primary-dark-rgb: <?= $primaryDarkRgb ?>;
    --club-on-primary:     <?= htmlspecialchars($_onPrimary['color']) ?>;
    --club-on-primary-rgb: <?= htmlspecialchars($_onPrimary['rgb']) ?>;
    --club-on-primary-muted: rgba(var(--club-on-primary-rgb), 0.72);
    --club-card:         color-mix(in srgb, <?= htmlspecialchars($theme['color_bg']) ?> 88%, #ffffff);
    --club-border:       color-mix(in srgb, <?= htmlspecialchars($theme['color_muted']) ?> 35%, <?= htmlspecialchars($theme['color_bg']) ?>);
    --club-accent:       rgba(<?= $primaryRgb ?>, 0.08);
    --club-success:      <?= $_clubStatus['success'] ?>;
    --club-warning:      <?= $_clubStatus['warning'] ?>;
    --club-danger:       <?= $_clubStatus['danger'] ?>;
    --club-success-rgb:  <?= $_clubStatus['success_rgb'] ?>;
    --club-warning-rgb:  <?= $_clubStatus['warning_rgb'] ?>;
    --club-danger-rgb:   <?= $_clubStatus['danger_rgb'] ?>;

    --bs-primary: var(--club-primary);
    --bs-primary-rgb: var(--club-primary-rgb);
    --bs-link-color: var(--club-primary);
    --bs-link-hover-color: var(--club-primary-dark);
    --bs-link-color-rgb: var(--club-primary-rgb);
    --bs-link-hover-color-rgb: var(--club-primary-dark-rgb);
    --bs-focus-ring-color: rgba(var(--club-primary-rgb), 0.28);
}
