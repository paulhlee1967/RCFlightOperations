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
