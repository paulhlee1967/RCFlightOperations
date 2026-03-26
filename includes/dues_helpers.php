<?php
/**
 * includes/dues_helpers.php
 *
 * Membership type labels, dues rules, and renewal amount calculation.
 * Loaded from helpers.php so it is available anywhere the DB is bootstrapped.
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
 * @param array|null $prefetchedRules      Result of duesRules(); null = fetch internally.
 * @param array|null $prefetchedClubDuesRow Row from `club` with dues_adult_regular, dues_adult_prorated,
 *                                          dues_initiation, dues_reduced; null = query club inside this call.
 */
function calculateDues(
    PDO $pdo,
    int $typeSlot,
    string $renewalType,
    ?array $prefetchedRules = null,
    ?array $prefetchedClubDuesRow = null
): array {

    $duesConfig = [
        'dues_adult_regular'  => 160,
        'dues_adult_prorated' => 80,
        'dues_initiation'     => 50,
        'dues_reduced'        => 20,
    ];

    $tRow = $prefetchedClubDuesRow;
    if ($tRow === null) {
        try {
            $tStmt = $pdo->query(
                'SELECT dues_adult_regular, dues_adult_prorated, dues_initiation, dues_reduced
                   FROM club WHERE id = 1 LIMIT 1'
            );
            $tRow = $tStmt ? $tStmt->fetch(PDO::FETCH_ASSOC) : false;
        } catch (Throwable $e) {
            $tRow = false;
        }
    }
    if ($tRow) {
        foreach (['dues_adult_regular', 'dues_adult_prorated', 'dues_initiation', 'dues_reduced'] as $k) {
            if (isset($tRow[$k])) {
                $duesConfig[$k] = (float) $tRow[$k];
            }
        }
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
