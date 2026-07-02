<?php
/**
 * includes/club_theme.php
 *
 * Shared club color defaults and token helpers used by header.php, docs-theme.php,
 * and email/PDF layouts that need the same palette logic.
 */

function flightops_club_theme_defaults(): array
{
    return [
        'name'               => 'RC Flight Operations',
        'logo_path'          => null,
        'favicon_path'       => null,
        'color_primary'      => '#6f7c3d',
        'color_primary_dark' => '#556030',
        'color_bg'           => '#f3efe4',
        'color_muted'        => '#665e52',
        'color_text'         => '#252018',
    ];
}

function flightops_hex_to_rgb(string $hex): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 6) {
        return implode(',', array_map('hexdec', str_split($hex, 2)));
    }

    return '111,124,61';
}

/** WCAG relative luminance (0–1); used to pick light/dark text on --club-primary. */
function flightops_relative_luminance(string $hex): float
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) {
        return 0.2;
    }
    $toLin = static function (int $c): float {
        $s = $c / 255;

        return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
    };
    $r = $toLin(hexdec(substr($hex, 0, 2)));
    $g = $toLin(hexdec(substr($hex, 2, 2)));
    $b = $toLin(hexdec(substr($hex, 4, 2)));

    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
}

/**
 * Text color for elements on top of --club-primary.
 *
 * @return array{color: string, rgb: string, bs_theme: string}
 */
function flightops_on_primary_for(string $primaryHex): array
{
    if (flightops_relative_luminance($primaryHex) >= 0.45) {
        return ['color' => '#252018', 'rgb' => '37,32,24', 'bs_theme' => 'light'];
    }

    return ['color' => '#ffffff', 'rgb' => '255,255,255', 'bs_theme' => 'dark'];
}

/** Earth-tone status colors that harmonize with the default club palette. */
function flightops_club_status_tokens(): array
{
    return [
        'success'      => '#4a6741',
        'warning'      => '#9a7b2f',
        'danger'       => '#8b4513',
        'success_rgb'  => '74,103,65',
        'warning_rgb'  => '154,123,47',
        'danger_rgb'   => '139,69,19',
    ];
}
