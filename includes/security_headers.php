<?php
/**
 * Send baseline HTTP security headers (idempotent if already sent).
 *
 * CSP: strict nonces for parser-inserted script/style blocks; script-src-attr and
 * style-src-attr allow inline event handlers and style="" (Bootstrap / legacy UI).
 */
declare(strict_types=1);

require_once __DIR__ . '/csp_nonce.php';

function flightops_send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 0');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');

    $n = flightops_csp_nonce();
    $nonceSrc = "'nonce-" . $n . "'";

    $cdns = 'https://cdn.jsdelivr.net https://cdnjs.cloudflare.com';
    $fontCdns = 'https://fonts.googleapis.com ' . $cdns;

    // script-src: app bundle under /js + CDNs. No script-src-attr (no inline handlers).
    // style-src-elem / style-src-attr: inline <style> nonces + style="" on elements (Bootstrap).
    $directives = [
        "default-src 'self'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'self'",
        "script-src 'self' $nonceSrc $cdns",
        "style-src-elem 'self' $nonceSrc $fontCdns",
        "style-src-attr 'unsafe-inline'",
        "style-src 'self' $nonceSrc $fontCdns 'unsafe-inline'",
        "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net",
        "img-src 'self' data: blob: https:",
        "connect-src 'self'",
    ];

    header('Content-Security-Policy: ' . implode('; ', $directives));
}
