#!/usr/bin/env php
<?php
/**
 * Find members whose payment/fulfillment year does not match membership_renewal_year.
 *
 * Usage:
 *   php scripts/check_renewal_year_mismatches.php
 *   php scripts/check_renewal_year_mismatches.php --year=2026
 */

require_once __DIR__ . '/../includes/cli_only_script.php';
flightops_require_cli();

require_once dirname(__DIR__) . '/includes/db.php';

$year = membershipStatusYear();
$opts = getopt('', ['year:']);
if (isset($opts['year'])) {
    $year = (int) $opts['year'];
    if ($year < 1990 || $year > 2100) {
        fwrite(STDERR, "Invalid --year.\n");
        exit(1);
    }
}

ensureMembershipYearsTable($pdo);

echo "Checking membership year mismatches for {$year}…\n\n";

// Paid/fulfilled for $year but renewal year on file is not $year
$stmt = $pdo->prepare("
    SELECT m.id, m.first_name, m.last_name, m.membership_renewal_year, m.inactive, m.suspended,
           m.life_member, m.free_membership,
           EXISTS (
               SELECT 1 FROM payments p
               WHERE p.member_id = m.id AND p.year = ? AND p.voided_at IS NULL
           ) AS has_payment,
           EXISTS (
               SELECT 1 FROM member_fulfillments f
               WHERE f.member_id = m.id AND f.year = ?
           ) AS has_fulfillment
    FROM members m
    WHERE m.id IN (
        SELECT DISTINCT member_id FROM payments WHERE year = ? AND voided_at IS NULL
        UNION
        SELECT DISTINCT member_id FROM member_fulfillments WHERE year = ?
    )
    AND (m.membership_renewal_year IS NULL OR m.membership_renewal_year != ?)
    ORDER BY m.last_name, m.first_name
");
$stmt->execute([$year, $year, $year, $year, $year]);
$paidWrongYear = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== Has {$year} payment/fulfillment but renewal year is NOT {$year} ===\n";
echo 'Count: ' . count($paidWrongYear) . "\n";
if (count($paidWrongYear) === 0) {
    echo "(none)\n";
} else {
    printf("%-6s %-24s %-24s %6s %8s %8s %s\n", 'ID', 'Last', 'First', 'RenYr', 'Inactive', 'Susp', 'Source');
    foreach ($paidWrongYear as $row) {
        $src = [];
        if (!empty($row['has_payment'])) {
            $src[] = 'payment';
        }
        if (!empty($row['has_fulfillment'])) {
            $src[] = 'fulfillment';
        }
        printf(
            "%-6d %-24s %-24s %6s %8s %8s %s\n",
            (int) $row['id'],
            mb_substr((string) $row['last_name'], 0, 24),
            mb_substr((string) $row['first_name'], 0, 24),
            $row['membership_renewal_year'] === null ? 'NULL' : (string) $row['membership_renewal_year'],
            (int) $row['inactive'],
            (int) $row['suspended'],
            implode('+', $src)
        );
    }
}

// Renewal year = $year but no payment/fulfillment (and not life/free)
$stmt = $pdo->prepare("
    SELECT m.id, m.first_name, m.last_name, m.membership_renewal_year, m.inactive, m.suspended,
           m.life_member, m.free_membership
    FROM members m
    WHERE m.membership_renewal_year = ?
      AND (m.life_member = 0 OR m.life_member IS NULL)
      AND (m.free_membership = 0 OR m.free_membership IS NULL)
      AND m.id NOT IN (
          SELECT DISTINCT member_id FROM payments WHERE year = ? AND voided_at IS NULL
          UNION
          SELECT DISTINCT member_id FROM member_fulfillments WHERE year = ?
      )
    ORDER BY m.last_name, m.first_name
");
$stmt->execute([$year, $year, $year]);
$yearNoPayment = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "\n=== Renewal year {$year} but NO {$year} payment/fulfillment (not life/free) ===\n";
echo 'Count: ' . count($yearNoPayment) . "\n";
if (count($yearNoPayment) === 0) {
    echo "(none)\n";
} else {
    printf("%-6s %-24s %-24s %8s %8s\n", 'ID', 'Last', 'First', 'Inactive', 'Susp');
    foreach ($yearNoPayment as $row) {
        printf(
            "%-6d %-24s %-24s %8d %8d\n",
            (int) $row['id'],
            mb_substr((string) $row['last_name'], 0, 24),
            mb_substr((string) $row['first_name'], 0, 24),
            (int) $row['inactive'],
            (int) $row['suspended']
        );
    }
}

echo "\nCurrent-member count for {$year} (live rules): " . countCurrentMembers($pdo, $year) . "\n";
echo "Recorded in member_membership_years: " . countRecordedMembersForYear($pdo, $year) . "\n";
