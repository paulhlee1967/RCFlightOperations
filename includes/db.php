<?php
/**
 * includes/db.php
 *
 * Database connection (PDO). Also bootstraps shared helpers and feature flags
 * so every page that includes db.php automatically has access to h(),
 * featureEnabled(), etc. without needing separate require_once calls.
 *
 * Requires config.php returning an array with key 'db' containing:
 *   host, name, user, password, charset (optional, default utf8mb4)
 *
 * Sets global $pdo. Include before auth.php and any page that needs the DB.
 *
 * @global \PDO $pdo
 */

// ── Bootstrap shared helpers (h(), defaultRenewalYear(), etc.) ────────────────
// Must come before any page output or function calls that use these helpers.
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/session_ini.php';

// ── Config ────────────────────────────────────────────────────────────────────
if (!isset($config)) {
    $configFile = dirname(__DIR__) . '/config.php';
    if (!is_file($configFile)) {
        die('Missing config.php. Copy config.php.example to config.php and set your database credentials.');
    }
    $config = require $configFile;
}

flightops_apply_session_cookie_params($config);

// ── PDO connection ────────────────────────────────────────────────────────────
$db  = $config['db'];
$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    $db['host'],
    $db['name'],
    $db['charset'] ?? 'utf8mb4'
);

try {
    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    $debug = !empty($config['debug']);
    error_log('Database connection failed: ' . $e->getMessage());
    if ($debug) {
        die('Database connection failed: ' . $e->getMessage());
    }
    die('Database connection failed. Check server logs and configuration.');
}

// ── Feature flags (session-cached; safe to include before session_start) ─────
// features.php defines FEATURES constant and featureEnabled()/requireFeature().
// Including here ensures every page has the functions available.
require_once __DIR__ . '/features.php';

// Default: header.php reads this for the maintenance banner. Refreshed after
// session_start() in auth.php when a club user is logged in (see
// flightops_refresh_maintenance_mode_global()).
$GLOBALS['_maintenanceMode'] = false;

/**
 * Set $GLOBALS['_maintenanceMode'] from system_config when a club user session exists.
 * Must run after session_start() — call from includes/auth.php, not from here.
 */
function flightops_refresh_maintenance_mode_global(PDO $pdo): void {
    $GLOBALS['_maintenanceMode'] = false;
    if (empty($_SESSION['user_id'])) {
        return;
    }
    try {
        $stmt = $pdo->query(
            "SELECT config_value FROM system_config WHERE config_key = 'maintenance_mode' LIMIT 1"
        );
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if ($row && ($row['config_value'] ?? '') === '1') {
            $GLOBALS['_maintenanceMode'] = true;
        }
    } catch (Throwable $e) {
        // system_config may be missing on older installs.
    }
}
