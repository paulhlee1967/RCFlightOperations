<?php
/**
 * includes/app_signing_secret.php
 *
 * HMAC signing secret for public application confirmation links, reminder
 * unsubscribe URLs, and similar tokens. Configure in Administration → Installation
 * or config.php as app_secret.
 */

function app_signing_secret(PDO $pdo): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    try {
        $stmt = $pdo->prepare('SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1');
        $stmt->execute(['app_secret']);
        $row = $stmt->fetchColumn();
        if (is_string($row) && trim($row) !== '') {
            return $cached = trim($row);
        }
    } catch (Throwable $e) {
        // system_config may not exist on very old installs.
    }

    $configFile = dirname(__DIR__) . '/config.php';
    if (is_file($configFile)) {
        $config = require $configFile;
        if (is_array($config)) {
            foreach (['app_secret', 'application_webhook_secret'] as $key) {
                $val = trim((string) ($config[$key] ?? ''));
                if ($val !== '') {
                    return $cached = $val;
                }
            }
        }
    }

    return $cached = 'flightops-app-fallback-' . md5(__DIR__);
}

function app_signing_secret_is_configured(PDO $pdo): bool
{
    $secret = app_signing_secret($pdo);

    return $secret !== '' && !str_starts_with($secret, 'flightops-app-fallback-');
}
