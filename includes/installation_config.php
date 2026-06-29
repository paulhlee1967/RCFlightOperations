<?php
/**
 * Load/save system_config keys and build mail config for installation / SMTP UI.
 * Used by installation.php (club admin).
 */

function installation_load_system_config(PDO $pdo): array {
    try {
        return $pdo->query('SELECT config_key, config_value FROM system_config')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function installation_save_config_key(PDO $pdo, string $key, string $value): void {
    $pdo->prepare('INSERT INTO system_config (config_key, config_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), updated_at = NOW()')->execute([$key, $value]);
}

/**
 * First calendar month (1–12) when the club “pre-books” into the next renewal year for
 * default year pickers. Default 10 (October): current month >= that month uses next calendar year.
 */
function renewal_prebook_start_month(PDO $pdo): int {
    $default = 10;
    try {
        $stmt = $pdo->prepare(
            'SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1'
        );
        $stmt->execute(['renewal_prebook_start_month']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['config_value']) && $row['config_value'] !== '') {
            $t = (int) $row['config_value'];
            if ($t >= 1 && $t <= 12) {
                return $t;
            }
        }
    } catch (Throwable $e) {
    }
    return $default;
}

/**
 * Day of the pre-book start month (1–31) when the club begins the next renewal year.
 * Default 15 (e.g. October 15): on/after this day in the start month, default year
 * pickers and "not yet renewed" roll forward to the next calendar year.
 */
function renewal_prebook_start_day(PDO $pdo): int {
    $default = 15;
    try {
        $stmt = $pdo->prepare(
            'SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1'
        );
        $stmt->execute(['renewal_prebook_start_day']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['config_value']) && $row['config_value'] !== '') {
            $t = (int) $row['config_value'];
            if ($t >= 1 && $t <= 31) {
                return $t;
            }
        }
    } catch (Throwable $e) {
    }
    return $default;
}

/**
 * First membership year for which the club has complete, trustworthy data.
 * Reports flag years before this as reconstructed/approximate. Default 2027.
 */
function reports_accurate_from_year(PDO $pdo): int {
    $default = 2027;
    try {
        $stmt = $pdo->prepare(
            'SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1'
        );
        $stmt->execute(['reports_accurate_from_year']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['config_value']) && $row['config_value'] !== '') {
            $t = (int) $row['config_value'];
            if ($t >= 2000 && $t <= 2100) {
                return $t;
            }
        }
    } catch (Throwable $e) {
    }
    return $default;
}

/**
 * Effective outbound mail settings: DB system_config overrides config.php email block.
 */
function installation_mail_config(PDO $pdo, ?array $sysConfig = null): array {
    $sysConfig = $sysConfig ?? installation_load_system_config($pdo);
    $defaults  = [];
    $cf        = dirname(__DIR__) . '/config.php';
    if (is_file($cf)) {
        $c = require $cf;
        $defaults = $c['email'] ?? [];
    }

    $host = $sysConfig['smtp_host'] ?? ($defaults['smtp']['host'] ?? '');
    if ($host === '' || $host === null) {
        return [
            'driver'       => 'mail',
            'from_address' => $sysConfig['smtp_from_email'] ?? ($defaults['from_address'] ?? ''),
            'from_name'    => $sysConfig['smtp_from_name'] ?? ($defaults['from_name'] ?? 'RC Flight Operations'),
        ];
    }

    return [
        'driver'       => 'smtp',
        'from_address' => $sysConfig['smtp_from_email'] ?? ($defaults['from_address'] ?? ''),
        'from_name'    => $sysConfig['smtp_from_name'] ?? ($defaults['from_name'] ?? 'RC Flight Operations'),
        'smtp' => [
            'host'       => $host,
            'port'       => (int) ($sysConfig['smtp_port'] ?? ($defaults['smtp']['port'] ?? 587)),
            'encryption' => $sysConfig['smtp_encryption'] ?? ($defaults['smtp']['encryption'] ?? 'tls'),
            'username'   => $sysConfig['smtp_username'] ?? ($defaults['smtp']['username'] ?? ''),
            'password'   => $sysConfig['smtp_password'] ?? ($defaults['smtp']['password'] ?? ''),
        ],
    ];
}
