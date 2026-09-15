#!/usr/bin/env php
<?php
/**
 * Backfill member_membership_years — frozen roster per calendar year.
 *
 * Usage:
 *   php scripts/backfill_membership_years.php
 *   php scripts/backfill_membership_years.php --from-year=2016
 *   php scripts/backfill_membership_years.php --year=2026 --replace
 *
 * Year range (full backfill):
 *   - End year is always the current calendar year.
 *   - Start year is the earliest of: --from-year, or MIN(year) in payments/fulfillments.
 *   - Years with no payment/fulfillment rows get 0 members (reports fall back until data exists).
 *
 * Per-year rules:
 *   - Current calendar year: live "current member" rules (renewal year + payment/life/free).
 *   - Prior years: distinct members with a payment or fulfillment for that year.
 */

require_once __DIR__ . '/../includes/cli_only_script.php';
flightops_require_cli();

$baseDir = dirname(__DIR__);
require_once $baseDir . '/includes/db.php';

$opts = getopt('', ['year:', 'from-year:', 'replace']);
$singleYear = isset($opts['year']) ? (int) $opts['year'] : null;
$fromYear   = isset($opts['from-year']) ? (int) $opts['from-year'] : null;
$replace    = array_key_exists('replace', $opts);

if ($singleYear !== null && ($singleYear < 1990 || $singleYear > 2100)) {
    fwrite(STDERR, "Invalid --year (use 1990–2100).\n");
    exit(1);
}
if ($fromYear !== null && ($fromYear < 1990 || $fromYear > 2100)) {
    fwrite(STDERR, "Invalid --from-year (use 1990–2100).\n");
    exit(1);
}

ensureMembershipYearsTable($pdo);
$currentYear = membershipStatusYear();

$years = [];
if ($singleYear !== null) {
    $years = [$singleYear];
} else {
    $stmt = $pdo->query('
        SELECT MIN(y) AS min_y, MAX(y) AS max_y FROM (
            SELECT year AS y FROM payments
            UNION
            SELECT year AS y FROM member_fulfillments
        ) t
    ');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $dataMinY = (int) ($row['min_y'] ?? 0);
    $dataMaxY = (int) ($row['max_y'] ?? 0);
    $minY = $fromYear ?? ($dataMinY > 0 ? $dataMinY : $currentYear);
    if ($fromYear !== null && $dataMinY > 0 && $dataMinY < $fromYear) {
        echo "Note: payment/fulfillment data starts at {$dataMinY}; years {$fromYear}–" . ($dataMinY - 1) . " will be empty unless you import older payment history.\n\n";
    }
    $maxY = max($dataMaxY, $currentYear);
    if ($minY < 1990) {
        $minY = 1990;
    }
    for ($y = $minY; $y <= $maxY; $y++) {
        $years[] = $y;
    }
}

echo "Backfilling member_membership_years" . ($replace ? ' (replace)' : '') . "…\n\n";

foreach ($years as $year) {
    $before = countRecordedMembersForYear($pdo, $year);
    $added  = snapshotMembershipYear($pdo, $year, 'backfill', $replace);
    $after  = countRecordedMembersForYear($pdo, $year);
    $rule   = $year === $currentYear ? 'current-member rules' : 'payment/fulfillment history';
    echo sprintf(
        "  %d: %d members (%s)%s\n",
        $year,
        $after,
        $rule,
        $replace ? " — rebuilt (was $before, inserted $added rows)" : ($after > $before ? " — +$added new" : '')
    );
}

echo "\nDone. Reports and dashboard year-over-year counts now use these frozen rosters.\n";
echo "Future renewals append rows automatically; past years stay unchanged.\n";
