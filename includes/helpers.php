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
 *   defaultRenewalYear()  — Return the working renewal year (Oct–Dec pre-books next year)
 *   formatMoney()         — Format a float as a dollar string
 *   formatDate()          — Format a Y-m-d date string for display
 *   memberStatusBadge()   — Return Bootstrap badge HTML for a member's status
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
 * October through December pre-book for the following calendar year;
 * January through September use the current year.
 *
 * @return int  Four-digit renewal year.
 */
function defaultRenewalYear(): int {
    return (int) date('n') >= 10 ? (int) date('Y') + 1 : (int) date('Y');
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

/**
 * Return the club's configured membership type slots (1–4).
 *
 * @return array<int, array{slot:int,label:string,enabled:bool}>
 */
function membershipTypeSlots(PDO $pdo): array {
    $defaults = [
        1 => ['slot' => 1, 'label' => 'Adult',  'enabled' => true],
        2 => ['slot' => 2, 'label' => 'Youth',  'enabled' => true],
        3 => ['slot' => 3, 'label' => 'Senior', 'enabled' => true],
        4 => ['slot' => 4, 'label' => 'Spouse', 'enabled' => true],
    ];

    try {
        $stmt = $pdo->query('
            SELECT
                membership_type1_label, membership_type2_label, membership_type3_label, membership_type4_label,
                membership_type1_enabled, membership_type2_enabled, membership_type3_enabled, membership_type4_enabled
            FROM club
            WHERE id = 1
            LIMIT 1
        ');
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (!$row) return array_values($defaults);

        for ($i = 1; $i <= 4; $i++) {
            $labelKey = "membership_type{$i}_label";
            $enKey    = "membership_type{$i}_enabled";
            $label    = trim((string) ($row[$labelKey] ?? $defaults[$i]['label']));
            $enabled  = !empty($row[$enKey]);
            if ($label === '') $label = $defaults[$i]['label'];
            $defaults[$i] = ['slot' => $i, 'label' => $label, 'enabled' => $enabled];
        }
    } catch (Throwable $e) {
        return array_values($defaults);
    }

    return array_values($defaults);
}

/**
 * Enabled slots only, keyed by slot number.
 *
 * @return array<int, string> [slot => label]
 */
function enabledMembershipTypeLabels(PDO $pdo): array {
    $out = [];
    foreach (membershipTypeSlots($pdo) as $slot) {
        if (!empty($slot['enabled'])) {
            $out[(int) $slot['slot']] = (string) $slot['label'];
        }
    }
    return $out;
}

/**
 * Fetch dues rules keyed by membership_type_slot.
 *
 * @return array<int, array{annual_dues:float,prorated_dues:float,initiation_fee:float,prorate_start_month:int,prorate_end_month:int}>
 */
function duesRules(PDO $pdo): array {
    $out = [];
    try {
        $stmt = $pdo->query('
            SELECT membership_type_slot, annual_dues, prorated_dues, initiation_fee, prorate_start_month, prorate_end_month
            FROM dues_rules
        ');
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $slot = (int) ($r['membership_type_slot'] ?? 0);
            if ($slot < 1 || $slot > 4) continue;
            $out[$slot] = [
                'annual_dues' => (float) ($r['annual_dues'] ?? 0),
                'prorated_dues' => (float) ($r['prorated_dues'] ?? 0),
                'initiation_fee' => (float) ($r['initiation_fee'] ?? 0),
                'prorate_start_month' => (int) ($r['prorate_start_month'] ?? 7),
                'prorate_end_month' => (int) ($r['prorate_end_month'] ?? 10),
            ];
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

/**
 * Calculate dues + initiation fee for a membership type slot.
 *
 * @param array|null  $prefetchedRules   Result of duesRules(); null = fetch internally
 */
function calculateDues(
    PDO    $pdo,
    int    $typeSlot,
    string $renewalType,
    ?array $prefetchedRules = null
): array {

    $duesConfig = [
        'dues_adult_regular'  => 160,
        'dues_adult_prorated' => 80,
        'dues_initiation'     => 50,
        'dues_reduced'        => 20,
    ];

    try {
        $tStmt = $pdo->query(
            'SELECT dues_adult_regular, dues_adult_prorated, dues_initiation, dues_reduced
               FROM club WHERE id = 1 LIMIT 1'
        );
        $tRow = $tStmt ? $tStmt->fetch(PDO::FETCH_ASSOC) : false;
        if ($tRow) {
            foreach (['dues_adult_regular', 'dues_adult_prorated', 'dues_initiation', 'dues_reduced'] as $k) {
                if (isset($tRow[$k])) {
                    $duesConfig[$k] = (float) $tRow[$k];
                }
            }
        }
    } catch (Throwable $e) {
    }

    $duesRules = $prefetchedRules ?? duesRules($pdo);
    $rule      = $duesRules[$typeSlot] ?? null;

    // Slots 2–4 are traditionally "reduced" rate; slot 1 is "adult regular".
    $reducedSlots = [2, 3, 4];
    $usesReduced  = in_array($typeSlot, $reducedSlots, true);

    $regularDues = $rule
        ? (float) ($rule['annual_dues']    ?? 0)
        : ($usesReduced
            ? (float) $duesConfig['dues_reduced']
            : (float) $duesConfig['dues_adult_regular']);

    $proratedDues = $rule
        ? (float) ($rule['prorated_dues']  ?? 0)
        : ($usesReduced
            ? (float) $duesConfig['dues_reduced']
            : (float) $duesConfig['dues_adult_prorated']);

    $initiationFee = $rule
        ? (float) ($rule['initiation_fee'] ?? 0)
        : (float) $duesConfig['dues_initiation'];

    // ── Apply renewal type ────────────────────────────────────────────────────
    $dues = 0.0;
    $init = 0.0;

    if ($renewalType === 'new') {
        $dues = $proratedDues;
        $init = $initiationFee;
    } elseif ($renewalType === 'on_time') {
        $dues = $regularDues;
        $init = 0.0;
    } elseif ($renewalType === 'late') {
        $dues = $regularDues;
        $init = $initiationFee;
    }

    return [
        'regularDues'  => $regularDues,
        'proratedDues' => $proratedDues,
        'initiationFee'=> $initiationFee,
        'dues'         => $dues,
        'init'         => $init,
    ];
}

require_once __DIR__ . '/csp_nonce.php';
