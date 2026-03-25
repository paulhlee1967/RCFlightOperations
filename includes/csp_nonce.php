<?php
/**
 * Per-request CSP nonce (128-bit random, hex-encoded).
 * Safe in CSP headers and HTML attributes; call flightops_send_security_headers()
 * before emitting any inline <script> or <style>.
 */
declare(strict_types=1);

function flightops_csp_nonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = bin2hex(random_bytes(16));
    }
    return strtolower($nonce);
}

/** HTML fragment: space + nonce="..." for inline script/style tags. */
function csp_nonce_attr(): string
{
    return ' nonce="' . htmlspecialchars(flightops_csp_nonce(), ENT_QUOTES, 'UTF-8') . '"';
}
