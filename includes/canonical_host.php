<?php
/**
 * Canonical host enforcement.
 *
 * The app must answer on a single hostname. When it is reachable on both the
 * apex (rcflightops.example.com) and the www host (www.rcflightops.example.com),
 * session cookies are host-only and absolute URLs are built from whichever host
 * the visitor used — so a login/session on one host does not apply on the other,
 * causing repeated login prompts, broken redirects, and popup/CSP oddities.
 *
 * This issues a single 301 (or 308 for non-GET/HEAD) redirect from the
 * non-canonical host to the canonical one, before session_start() or output.
 *
 * Canonical host resolution:
 *   1. $config['canonical_host'] when set (e.g. 'rcflightops.example.com').
 *   2. Otherwise, default behaviour: strip a leading "www." so the apex wins.
 *
 * Call from includes/db.php after config is loaded and before
 * flightops_apply_session_cookie_params()/session_start().
 */
declare(strict_types=1);

require_once __DIR__ . '/session_ini.php';

/**
 * Resolve the canonical host (no port) for the current request, or null when no
 * redirect is warranted.
 *
 * @param string               $currentHost Raw Host header value (may include port).
 * @param array<string, mixed> $config
 */
function flightops_resolve_canonical_host(string $currentHost, array $config): ?string
{
    // Strip port for comparison; ports are preserved separately by the caller.
    $hostNoPort = strtolower(preg_replace('/:\d+$/', '', trim($currentHost)) ?? '');
    if ($hostNoPort === '') {
        return null;
    }

    $configured = isset($config['canonical_host'])
        ? strtolower(preg_replace('/:\d+$/', '', trim((string) $config['canonical_host'])) ?? '')
        : '';

    if ($configured !== '') {
        $canonical = $configured;
    } elseif (str_starts_with($hostNoPort, 'www.')) {
        // Default: apex host is canonical.
        $canonical = substr($hostNoPort, 4);
    } else {
        return null;
    }

    // Validate the target host to avoid emitting a malformed Location header.
    if (!preg_match('/^[a-z0-9.-]+$/', $canonical)) {
        return null;
    }

    return $canonical === $hostNoPort ? null : $canonical;
}

/**
 * Redirect to the canonical host when the request arrives on another host.
 *
 * @param array<string, mixed>|null $config Loads config.php when omitted.
 */
function flightops_enforce_canonical_host(?array $config = null): void
{
    // CLI scripts (cron, setup) have no Host and must never redirect.
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }

    $currentHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($currentHost === '') {
        return;
    }

    if ($config === null) {
        $configFile = dirname(__DIR__) . '/config.php';
        if (!is_file($configFile)) {
            return;
        }
        $config = require $configFile;
    }

    $canonical = flightops_resolve_canonical_host($currentHost, $config);
    if ($canonical === null) {
        return;
    }

    // Preserve any explicit non-default port from the original Host header.
    $port = '';
    if (preg_match('/(:\d+)$/', $currentHost, $m)) {
        $port = $m[1];
    }

    $scheme = flightops_request_scheme($config);
    $uri    = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    if ($uri === '' || $uri[0] !== '/') {
        $uri = '/' . $uri;
    }

    $location = $scheme . '://' . $canonical . $port . $uri;

    // 308 preserves method/body for POST etc.; 301 is the SEO-standard for GET/HEAD.
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $status = in_array($method, ['GET', 'HEAD'], true) ? 301 : 308;

    header('Location: ' . $location, true, $status);
    exit;
}
