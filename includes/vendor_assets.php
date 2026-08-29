<?php
/**
 * includes/vendor_assets.php
 *
 * Pinned third-party front-end assets served from /assets/vendor/ (no CDN).
 * Refresh files with: bash scripts/fetch_vendor_assets.sh
 */

declare(strict_types=1);

const FLIGHTOPS_BOOTSTRAP_VERSION       = '5.3.8';
const FLIGHTOPS_BOOTSTRAP_ICONS_VERSION = '1.11.3';
const FLIGHTOPS_FABRIC_VERSION          = '7.4.0';

/**
 * URL path to a static asset under /assets/ (web root relative).
 *
 * @param string $base Optional prefix (e.g. '../' from docs/)
 */
function flightops_asset_url(string $relativePath, string $base = ''): string
{
    return $base . 'assets/' . ltrim($relativePath, '/');
}

function flightops_bootstrap_css_url(string $base = ''): string
{
    return flightops_asset_url('vendor/bootstrap/css/bootstrap.min.css', $base);
}

/**
 * Cache-busted URL for the shared app stylesheet (club tokens stay in header.php).
 */
function flightops_app_css_url(string $base = ''): string
{
    $relative = 'css/app.css';
    $fs = dirname(__DIR__) . '/assets/' . $relative;
    $url = flightops_asset_url($relative, $base);
    if (is_readable($fs)) {
        $url .= '?v=' . filemtime($fs);
    }

    return $url;
}

function flightops_bootstrap_js_url(string $base = ''): string
{
    return flightops_asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', $base);
}

function flightops_bootstrap_icons_css_url(string $base = ''): string
{
    return flightops_asset_url('vendor/bootstrap-icons/bootstrap-icons.min.css', $base);
}

function flightops_fabric_js_url(string $base = ''): string
{
    return flightops_asset_url('vendor/fabric/fabric.min.js', $base);
}
