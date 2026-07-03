<?php
/**
 * includes/application_webhook_config.php
 *
 * Webhook secret for WPForms / Uncanny Automator integration.
 */

require_once __DIR__ . '/installation_config.php';

function application_webhook_secret(PDO $pdo): string
{
    $fromDb = '';
    try {
        $stmt = $pdo->prepare('SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1');
        $stmt->execute(['application_webhook_secret']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['config_value'])) {
            $fromDb = trim((string) $row['config_value']);
        }
    } catch (Throwable $e) {
    }

    if ($fromDb !== '') {
        return $fromDb;
    }

    $configFile = dirname(__DIR__) . '/config.php';
    if (is_file($configFile)) {
        $config = require $configFile;
        return trim((string) ($config['application_webhook_secret'] ?? ''));
    }

    return '';
}

function application_webhook_secret_is_configured(PDO $pdo): bool
{
    return application_webhook_secret($pdo) !== '';
}
