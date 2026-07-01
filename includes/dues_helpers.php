<?php
/**
 * includes/dues_helpers.php
 *
 * Membership type labels, dues rules, and renewal amount calculation.
 * Loaded from helpers.php so it is available anywhere the DB is bootstrapped.
 *
 * Dues amounts live only in `dues_rules` (one row per membership type slot 1–4).
 */

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
        if (!$row) {
            return array_values($defaults);
        }

        for ($i = 1; $i <= 4; $i++) {
            $labelKey = "membership_type{$i}_label";
            $enKey    = "membership_type{$i}_enabled";
            $label    = trim((string) ($row[$labelKey] ?? $defaults[$i]['label']));
            $enabled  = !empty($row[$enKey]);
            if ($label === '') {
                $label = $defaults[$i]['label'];
            }
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
            if ($slot < 1 || $slot > 4) {
                continue;
            }
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
 * @param array|null $prefetchedRules Result of duesRules(); null = fetch internally.
 */
function calculateDues(
    PDO $pdo,
    int $typeSlot,
    string $renewalType,
    ?array $prefetchedRules = null
): array {
    $rules = $prefetchedRules ?? duesRules($pdo);
    $rule  = $rules[$typeSlot] ?? null;

    if ($rule === null) {
        $regularDues   = 0.0;
        $proratedDues  = 0.0;
        $initiationFee = 0.0;
    } else {
        $regularDues   = (float) ($rule['annual_dues'] ?? 0);
        $proratedDues  = (float) ($rule['prorated_dues'] ?? 0);
        $initiationFee = (float) ($rule['initiation_fee'] ?? 0);
    }

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
        'regularDues'   => $regularDues,
        'proratedDues'  => $proratedDues,
        'initiationFee' => $initiationFee,
        'dues'          => $dues,
        'init'          => $init,
    ];
}
