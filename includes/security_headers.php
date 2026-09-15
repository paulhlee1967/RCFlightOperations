<?php
/**
 * Send baseline HTTP security headers (idempotent if already sent).
 *
 * CSP: strict nonces for parser-inserted script/style blocks; script-src-attr and
 * style-src-attr allow inline event handlers and style="" (Bootstrap / legacy UI).
 */
declare(strict_types=1);

require_once __DIR__ . '/csp_nonce.php';

function flightops_send_security_headers(array $options = []): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 0');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(self)');

    $n = flightops_csp_nonce();
    $nonceSrc = "'nonce-" . $n . "'";

    $scriptSrc = "'self' $nonceSrc";
    $connectSrc = "'self'";
    $frameSrc = "'self'";
    if (!empty($options['stripe'])) {
        $scriptSrc .= ' https://js.stripe.com';
        $connectSrc .= ' https://api.stripe.com https://m.stripe.com https://m.stripe.network';
        $frameSrc .= ' https://js.stripe.com https://hooks.stripe.com';
    }

    // script-src: app bundle under /js and /assets/vendor/. No script-src-attr (no inline handlers).
    // style-src-elem / style-src-attr: inline <style> nonces + style="" on elements (Bootstrap).
    $directives = [
        "default-src 'self'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'self'",
        "script-src $scriptSrc",
        "style-src-elem 'self' $nonceSrc",
        "style-src-attr 'unsafe-inline'",
        "style-src 'self' $nonceSrc 'unsafe-inline'",
        "font-src 'self'",
        "img-src 'self' data: blob: https:",
        "connect-src $connectSrc",
        "frame-src $frameSrc",
    ];

    header('Content-Security-Policy: ' . implode('; ', $directives));
}
