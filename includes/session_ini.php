<?php
/**
 * Session cookie parameters (Secure, HttpOnly, SameSite) before session_start().
 * Call from db.php before session_start().
 */
declare(strict_types=1);

require_once __DIR__ . '/trusted_proxy.php';

/**
 * @param array<string, mixed>|null $config  Required for forwarded-proto trust (uses trusted_proxies).
 */
function flightops_is_https_request(?array $config = null): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    $trust = $config !== null && flightops_should_trust_forwarded_proto($config);
    if ($trust && !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        return strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
    }
    return false;
}

/**
 * http or https for building absolute URLs (password reset emails, etc.).
 *
 * @param array<string, mixed>|null $config
 */
function flightops_request_scheme(?array $config = null): string
{
    return flightops_is_https_request($config) ? 'https' : 'http';
}

/**
 * Apply cookie params. Loads config.php when $config is omitted.
 */
function flightops_apply_session_cookie_params(?array $config = null): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    if ($config === null) {
        $configFile = dirname(__DIR__) . '/config.php';
        if (!is_file($configFile)) {
            return;
        }
        $config = require $configFile;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $secure = flightops_is_https_request($config);
    $params = [
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    session_set_cookie_params($params);
}
