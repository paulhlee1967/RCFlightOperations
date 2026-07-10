<?php
/**
 * Stripe API keys and client bootstrap for membership applications.
 */

require_once __DIR__ . '/installation_config.php';

/**
 * @return array{publishable_key:string,secret_key:string,webhook_secret:string,test_mode:bool}
 */
function stripe_load_config(PDO $pdo): array
{
    $config = installation_load_system_config($pdo);

    return [
        'publishable_key' => trim((string) ($config['stripe_publishable_key'] ?? '')),
        'secret_key'      => trim((string) ($config['stripe_secret_key'] ?? '')),
        'webhook_secret'  => trim((string) ($config['stripe_webhook_secret'] ?? '')),
        'test_mode'       => ((string) ($config['stripe_test_mode'] ?? '0')) === '1',
    ];
}

function stripe_is_configured(PDO $pdo): bool
{
    $cfg = stripe_load_config($pdo);

    return $cfg['publishable_key'] !== '' && $cfg['secret_key'] !== '';
}

/**
 * @return \Stripe\StripeClient|null
 */
function stripe_client(PDO $pdo): ?\Stripe\StripeClient
{
    if (!class_exists(\Stripe\StripeClient::class)) {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }
    if (!class_exists(\Stripe\StripeClient::class)) {
        return null;
    }

    $cfg = stripe_load_config($pdo);
    if ($cfg['secret_key'] === '') {
        return null;
    }

    return new \Stripe\StripeClient($cfg['secret_key']);
}
