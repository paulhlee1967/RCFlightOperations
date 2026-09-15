<?php
/**
 * Absolute URLs for email assets (Sender.net strips data: URIs from HTML).
 */

require_once __DIR__ . '/logo_thumb.php';

/**
 * Public site base URL for links and images in outbound email.
 *
 * Uses config public_base_url, then canonical_host (https), then the current request.
 *
 * @param array<string, mixed>|null $config
 */
function email_public_base_url(?array $config = null): ?string
{
    if ($config === null) {
        $cf = dirname(__DIR__) . '/config.php';
        $config = is_file($cf) ? require $cf : [];
    }

    $explicit = trim((string) ($config['public_base_url'] ?? ''));
    if ($explicit !== '') {
        return rtrim($explicit, '/');
    }

    $host = trim((string) ($config['canonical_host'] ?? ''));
    if ($host !== '') {
        return 'https://' . preg_replace('/:\d+$/', '', strtolower($host));
    }

    if (PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
        require_once __DIR__ . '/session_ini.php';
        $scheme   = flightops_request_scheme($config);
        $httpHost = (string) $_SERVER['HTTP_HOST'];
        $basePath = rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($basePath === '/' || $basePath === '\\') {
            $basePath = '';
        }

        return $scheme . '://' . $httpHost . $basePath;
    }

    return null;
}

/**
 * HTTPS URL for the club logo thumb (or original) when embedding in email HTML.
 */
function email_club_logo_public_url(?string $logoPath, ?array $config = null): ?string
{
    $logoPath = trim((string) $logoPath);
    if ($logoPath === '') {
        return null;
    }

    $base = email_public_base_url($config);
    if ($base === null) {
        return null;
    }

    $appRoot   = dirname(__DIR__);
    $thumbFile = clubLogoThumbFile($logoPath);
    if ($thumbFile !== null && is_file($thumbFile)) {
        $relative = ltrim(str_replace('\\', '/', substr($thumbFile, strlen($appRoot))), '/');
        if ($relative !== '') {
            return $base . '/' . $relative;
        }
    }

    return $base . '/' . ltrim(str_replace('\\', '/', $logoPath), '/');
}
