<?php
/**
 * docs/docs-theme.php
 *
 * Outputs a CSS custom property block that overrides the design tokens in
 * docs.css with the club's stored colours.  Linked by every
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
// Allow browser to cache for 5 minutes — short enough that config changes
// are reflected quickly without hammering the DB on every page load.
header('Cache-Control: public, max-age=300');

// ── Default palette — 1970s-inspired olive, cream, warm neutrals (no strong red/blue)
$defaults = [
    'color_primary'      => '#6f7c3d',
    'color_primary_dark' => '#556030',
    'color_bg'           => '#f3efe4',
    'color_muted'        => '#665e52',
    'color_text'         => '#252018',
];

$theme = $defaults;

// ── Try to load club theme when a user session exists + DB ────────────────────
try {
    // Bootstrap a minimal session without the full app stack
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!empty($_SESSION['user_id'])) {
        // config.php lives one directory up from docs/
        $configPath = dirname(__DIR__) . '/config.php';
        if (is_file($configPath)) {
            $cfg = require $configPath;
            $db  = $cfg['db'] ?? [];
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                $db['host']    ?? 'localhost',
                $db['name']    ?? ''
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
                // Only override if the value is a valid 6-digit hex
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
    // Silently fall back to defaults — docs should always load
}

// ── Helper: hex → "R,G,B" for rgba() use ─────────────────────────────────────
function hexToRgbComponents(string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return '111,124,61'; // default olive #6f7c3d
    [$r, $g, $b] = array_map('hexdec', str_split($hex, 2));
    return "$r,$g,$b";
}

function docs_theme_relative_luminance(string $hex): float {
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

$primaryRgb     = hexToRgbComponents($theme['color_primary']);
$primaryDarkRgb = hexToRgbComponents($theme['color_primary_dark']);
$_docsLum       = docs_theme_relative_luminance($theme['color_primary']);
$_onPrimary  = $_docsLum >= 0.45 ? '#252018' : '#faf7f0';
$_onPrimaryRgb = $_docsLum >= 0.45 ? '37,32,24' : '250,247,240';

// ── Output CSS ────────────────────────────────────────────────────────────────
// We emit ONLY the :root custom property block.  docs.css already defines all
// the selectors that consume these variables.  This file just overrides the
// token values so the docs follow whatever palette the admin configured,
// falling back to the retro earth-tone defaults above.
?>
:root {
    --club-primary:      <?= htmlspecialchars($theme['color_primary']) ?>;
    --club-primary-dark: <?= htmlspecialchars($theme['color_primary_dark']) ?>;
    --club-bg:           <?= htmlspecialchars($theme['color_bg']) ?>;
    --club-muted:        <?= htmlspecialchars($theme['color_muted']) ?>;
    --club-text:         <?= htmlspecialchars($theme['color_text']) ?>;
    --club-primary-rgb:  <?= $primaryRgb ?>;
    --club-primary-dark-rgb: <?= $primaryDarkRgb ?>;
    --club-on-primary:     <?= htmlspecialchars($_onPrimary) ?>;
    --club-on-primary-rgb: <?= htmlspecialchars($_onPrimaryRgb) ?>;
    --club-on-primary-muted: rgba(var(--club-on-primary-rgb), 0.72);
    --club-card:         #faf7f0;
    --club-border:       color-mix(in srgb, <?= htmlspecialchars($theme['color_muted']) ?> 40%, #ffffff);
    --club-accent:       rgba(<?= $primaryRgb ?>, 0.07);

    /* Keep Bootstrap components in sync when club colours change */
    --bs-primary: var(--club-primary);
    --bs-primary-rgb: var(--club-primary-rgb);
    --bs-link-color: var(--club-primary);
    --bs-link-hover-color: var(--club-primary-dark);
    --bs-link-color-rgb: var(--club-primary-rgb);
    --bs-link-hover-color-rgb: var(--club-primary-dark-rgb);
    --bs-focus-ring-color: rgba(var(--club-primary-rgb), 0.28);
}