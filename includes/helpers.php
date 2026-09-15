<?php
/**
 * includes/helpers.php
 *
 * Shared utility functions used across the app. Include this file instead of
 * re-defining these helpers inline. Required by db.php so it is available
 * everywhere without an explicit require_once in each page.
 *
 * Functions defined here:
 *   h()                   — HTML-escape a value for output
 *   checked()             — Return ' checked' if a value is truthy
 *   selected()            — Return ' selected' if two values match
 *   defaultRenewalYear()  — Return the working renewal year (threshold from system_config when $pdo given)
 *   formatMoney()         — Format a float as a dollar string
 *   formatDate()          — Format a Y-m-d date string for display
 *   memberStatusBadge()   — Return Bootstrap badge HTML for a member's status
 *
 * Membership types and dues calculation live in includes/dues_helpers.php
 * (loaded below) to keep this file limited to display/formatting utilities.
 */

/**
 * HTML-escape a value for safe output.
 * Accepts any scalar; casts to string before escaping.
 *
 * @param  mixed  $s  Value to escape.
 * @return string     HTML-safe string.
 */
function h(mixed $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Normalize an email address for storage and matching (trim + lowercase).
 *
 * Avoids duplicate members/subscribers when the same address arrives with different casing
 * (e.g. Sender.net, manual entry).
 */
function normalize_email(string $email): string
{
    return strtolower(trim($email));
}

/**
 * Return the HTML attribute string ' checked' when $value is truthy.
 * Use inside checkbox inputs: <input type="checkbox" <?= checked($member['active']) ?>>
 *
 * @param  mixed  $value  Value to test.
 * @return string         ' checked' or ''.
 */
function checked(mixed $value): string {
    return $value ? ' checked' : '';
}

/**
 * Return the HTML attribute string ' selected' when $current equals $option.
 * Use inside <option> elements: <option value="foo" <?= selected($current, 'foo') ?>>
 *
 * @param  mixed  $current  The current/active value.
 * @param  mixed  $option   The value this option represents.
 * @return string           ' selected' or ''.
 */
function selected(mixed $current, mixed $option): string {
    return (string) $current === (string) $option ? ' selected' : '';
}

/**
 * Default renewal year for the signup/renewal workflow.
 * Uses renewal_prebook_start_month / renewal_prebook_start_day from system_config
 * (see Installation) when $pdo is passed; otherwise defaults to October 15 as the
 * first day that pre-books the next calendar year.
 *
 * @return int  Four-digit renewal year.
 */
function defaultRenewalYear(?PDO $pdo = null): int {
    $startMonth = 10;
    $startDay   = 15;
    if ($pdo !== null) {
        require_once __DIR__ . '/installation_config.php';
        $startMonth = renewal_prebook_start_month($pdo);
        $startDay   = renewal_prebook_start_day($pdo);
    }
    $month = (int) date('n');
    $day   = (int) date('j');
    $year  = (int) date('Y');
    $rolledOver = ($month > $startMonth)
        || ($month === $startMonth && $day >= $startDay);
    return $rolledOver ? $year + 1 : $year;
}

/**
 * Format a numeric value as a US dollar string.
 * Example: formatMoney(160) → '$160.00'
 *
 * @param  float|int|string $amount
 * @return string
 */
function formatMoney(float|int|string $amount): string {
    return '$' . number_format((float) $amount, 2);
}

/**
 * Format a Y-m-d database date string for human-readable display.
 * Returns an empty string if the value is empty or unparseable.
 *
 * @param  string|null $date    Database date string (Y-m-d).
 * @param  string      $format  PHP date() format. Default: 'M j, Y'.
 * @return string
 */
function formatDate(?string $date, string $format = 'M j, Y'): string {
    if (empty($date)) return '';
    $ts = strtotime($date);
    return $ts !== false ? date($format, $ts) : '';
}

/**
 * Return Bootstrap badge HTML representing a member's current status.
 * Checks inactive, suspended, life_member, and free_membership flags.
 *
 * @param  array  $member  Associative array with member flag columns.
 * @return string          One or more Bootstrap badge elements, or empty string.
 */
function memberStatusBadge(array $member): string {
    $badges = [];
    if (!empty($member['suspended'])) {
        $badges[] = '<span class="badge bg-danger">Suspended</span>';
    }
    if (!empty($member['inactive'])) {
        $badges[] = '<span class="badge bg-secondary">Inactive</span>';
    }
    if (!empty($member['life_member'])) {
        $badges[] = '<span class="badge bg-primary">Life</span>';
    }
    if (!empty($member['free_membership'])) {
        $badges[] = '<span class="badge bg-info text-dark">Free</span>';
    }
    return implode(' ', $badges);
}

require_once __DIR__ . '/membership_status.php';
require_once __DIR__ . '/dues_helpers.php';
require_once __DIR__ . '/csp_nonce.php';
