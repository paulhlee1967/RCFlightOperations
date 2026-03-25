<?php
/**
 * Safe redirect URL validation to prevent open redirects.
 * Only allow relative paths within the application (no scheme, no //, no leading / that escapes).
 *
 * Usage: $redirect = safe_redirect_url($_GET['redirect'] ?? 'index.php');
 *        header('Location: ' . $redirect);
 */

/**
 * Return a safe redirect URL. If the given value is unsafe, returns the default.
 * Allowed: relative paths like "index.php", "members.php".
 * Rejected: any URL with "://", starting with "//", or containing "..".
 *
 * @param string $candidate Raw redirect parameter (e.g. from $_GET['redirect'])
 * @param string $default   Default path when candidate is empty or invalid (e.g. "index.php")
 * @return string Safe path (no leading slash for app-relative, or default)
 */
function safe_redirect_url(string $candidate, string $default = 'index.php'): string {
    $candidate = trim($candidate);
    if ($candidate === '') {
        return $default;
    }

    // Reject absolute URLs and protocol-relative.
    if (strpos($candidate, '://') !== false || strpos($candidate, '//') === 0) {
        return $default;
    }

    // Reject path traversal.
    if (strpos($candidate, '..') !== false) {
        return $default;
    }

    // Reject backslashes (avoid odd path parsing edge-cases).
    if (strpos($candidate, '\\') !== false) {
        return $default;
    }

    // Reject raw control characters.
    if (preg_match('/[\x00-\x1f\x7f]/', $candidate)) {
        return $default;
    }

    // Percent-encoded control characters (e.g. %0a/%0d) are unsafe.
    // Also reject stray '%' not followed by two hex digits.
    if (str_contains($candidate, '%')) {
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $candidate)) {
            return $default;
        }
        preg_match_all('/%([0-9A-Fa-f]{2})/', $candidate, $hexPairs);
        foreach ($hexPairs[1] as $hex) {
            $byte = hexdec($hex);
            if ($byte <= 0x1F || $byte === 0x7F) {
                return $default;
            }
        }
    }

    // Validate structure via parse_url: we only want relative paths/query.
    $parts = parse_url($candidate);
    if ($parts === false) {
        return $default;
    }

    if (!empty($parts['scheme']) || !empty($parts['host']) || !empty($parts['user']) || !empty($parts['pass'])) {
        return $default;
    }

    return $candidate;
}
