<?php
/**
 * Backfill processed renewals for year-based membership counts.
 *
 * Passes:
 *   A) Payments — payment without processed fulfillment for that year
 *   B) Fulfillments — fulfillment row exists but processed_at is NULL
 *   C) Renewal year — members.membership_renewal_year = Y, no processed fulfillment for Y
 *
 * Usage:
 *   php scripts/reconcile_membership_fulfillments.php --diagnose
 *   php scripts/reconcile_membership_fulfillments.php --year=2026
 *   php scripts/reconcile_membership_fulfillments.php --execute --sync-renewal-year
 *
 * Options:
 *   --diagnose          Print counts only (no changes); run this first
 *   --execute           Apply changes (default is dry run)
 *   --year=Y            Only this membership year
 *   --from-year=Y / --to-year=Y
 *   --sync-renewal-year Bump members.membership_renewal_year when reconciling
 *   --limit=N           Max rows per pass (default 5000; 0 = no limit)
 */

require_once __DIR__ . '/../includes/cli_only_script.php';
require_once __DIR__ . '/../includes/membership_status.php';

flightops_require_cli();

$baseDir = dirname(__DIR__);
if (!is_file($baseDir . '/config.php')) {
    fwrite(STDERR, "Missing config.php. Run from project root or ensure config exists.\n");
    exit(1);
}

$config = require $baseDir . '/config.php';
$db = $config['db'];
$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    $db['host'],
    $db['name'],
    $db['charset'] ?? 'utf8mb4'
);

try {
    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, 'Database connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$args = array_slice($argv, 1);
$execute = in_array('--execute', $args, true);
$diagnose = in_array('--diagnose', $args, true);
$syncRenewalYear = in_array('--sync-renewal-year', $args, true);

$yearFilter = null;
$fromYear = null;
$toYear = null;
$limit = 5000;

foreach ($args as $arg) {
    if (preg_match('/^--year=(\d{4})$/', $arg, $m)) {
        $yearFilter = (int) $m[1];
    } elseif (preg_match('/^--from-year=(\d{4})$/', $arg, $m)) {
        $fromYear = (int) $m[1];
    } elseif (preg_match('/^--to-year=(\d{4})$/', $arg, $m)) {
        $toYear = (int) $m[1];
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

if ($yearFilter !== null) {
    $fromYear = $yearFilter;
    $toYear   = $yearFilter;
}

function yearFilterSql(string $column, ?int $fromYear, ?int $toYear): array
{
    if ($fromYear !== null && $toYear !== null) {
        return [" AND {$column} BETWEEN ? AND ?", [$fromYear, $toYear]];
    }
    if ($fromYear !== null) {
        return [" AND {$column} >= ?", [$fromYear]];
    }
    if ($toYear !== null) {
        return [" AND {$column} <= ?", [$toYear]];
    }
    return ['', []];
}

[$yearClauseP, $yearParamsP] = yearFilterSql('p.year', $fromYear, $toYear);
[$yearClauseMf, $yearParamsMf] = yearFilterSql('mf.year', $fromYear, $toYear);
[$yearClauseRy, $yearParamsRy] = yearFilterSql('m.membership_renewal_year', $fromYear, $toYear);

$currentYear = (int) date('Y');

if ($diagnose) {
    echo "Membership data diagnostic (DB: {$db['name']} @ {$db['host']})\n";
    echo "Server date: " . date('Y-m-d') . " — app uses calendar year {$currentYear} for dashboard\n\n";

    $q = static function (string $sql, array $params = []) use ($pdo): int {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    };

    for ($y = $currentYear; $y >= $currentYear - 3; $y--) {
        echo "── Year {$y} ──\n";
        echo "  Payments:                         " . $q(
            'SELECT COUNT(DISTINCT member_id) FROM payments WHERE year = ?',
            [$y]
        ) . "\n";
        echo "  Fulfillments (any row):           " . $q(
            'SELECT COUNT(DISTINCT member_id) FROM member_fulfillments WHERE year = ?',
            [$y]
        ) . "\n";
        echo "  Fulfillments (processed_at set):  " . $q(
            'SELECT COUNT(DISTINCT member_id) FROM member_fulfillments WHERE year = ? AND processed_at IS NOT NULL',
            [$y]
        ) . "\n";
        echo "  Fulfillments (processed_at NULL): " . $q(
            'SELECT COUNT(DISTINCT member_id) FROM member_fulfillments WHERE year = ? AND processed_at IS NULL',
            [$y]
        ) . "\n";
        echo "  members.membership_renewal_year:  " . $q(
            'SELECT COUNT(*) FROM members WHERE membership_renewal_year = ? AND (inactive = 0 OR inactive IS NULL)',
            [$y]
        ) . "\n";
        echo "  App active count (helper):        " . membershipCountActive($pdo, $y) . "\n";
        echo "  App not-yet-renewed (helper):     " . membershipCountNotYetRenewed($pdo, $y) . "\n";
        echo "  Non-archived + processed MF:      " . $q(
            'SELECT COUNT(*) FROM members m WHERE (m.inactive = 0 OR m.inactive IS NULL)
             AND EXISTS (
               SELECT 1 FROM member_fulfillments mf
               WHERE mf.member_id = m.id AND mf.year = ? AND mf.processed_at IS NOT NULL
             )',
            [$y]
        ) . "\n";
        echo "  Non-archived + renewal_year:      " . $q(
            'SELECT COUNT(*) FROM members m WHERE (m.inactive = 0 OR m.inactive IS NULL)
             AND m.membership_renewal_year = ?',
            [$y]
        ) . "\n";
        echo "  Fulfillments on archived members: " . $q(
            'SELECT COUNT(DISTINCT mf.member_id) FROM member_fulfillments mf
             INNER JOIN members m ON m.id = mf.member_id
             WHERE mf.year = ? AND mf.processed_at IS NOT NULL AND m.inactive = 1',
            [$y]
        ) . "\n";
        echo "\n";
    }

    echo "── Members (all years) ──\n";
    $chips = membershipStatusChipCounts($pdo, $currentYear);
    echo "  Status chips for {$currentYear}: active={$chips['active']}, not_yet_renewed={$chips['not_yet_renewed']}, inactive={$chips['inactive']}, archived={$chips['archived']}, all={$chips['all']}\n\n";

    echo "If payments or renewal_year are high but \"processed_at set\" is 0, run reconcile without --diagnose.\n";
    echo "If everything already has processed_at but active is still 0, the web app may be using a different config.php than this script.\n";
    exit(0);
}

$limitClause = $limit > 0 ? ' LIMIT ' . (int) $limit : '';

echo "Reconcile membership fulfillments\n";
echo $execute ? "Mode: EXECUTE\n" : "Mode: DRY RUN (add --execute to apply)\n";
if ($yearFilter !== null) {
    echo "Year filter: $yearFilter\n";
} elseif ($fromYear !== null || $toYear !== null) {
    echo 'Year range: ' . ($fromYear ?? '…') . ' – ' . ($toYear ?? '…') . "\n";
} else {
    echo "Year filter: all years\n";
}
echo "Tip: run with --diagnose first to see what is in your database.\n\n";

$insertFulfillment = $pdo->prepare('
    INSERT INTO member_fulfillments (member_id, year, processed_at, renewal_type)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        processed_at = COALESCE(processed_at, VALUES(processed_at)),
        renewal_type = COALESCE(renewal_type, VALUES(renewal_type))
');

$updateFulfillment = $pdo->prepare('
    UPDATE member_fulfillments
    SET processed_at = ?, renewal_type = COALESCE(renewal_type, ?)
    WHERE member_id = ? AND year = ? AND processed_at IS NULL
');

$syncRenewal = $pdo->prepare('
    UPDATE members
    SET membership_renewal_year = ?
    WHERE id = ?
      AND (membership_renewal_year IS NULL OR membership_renewal_year < ?)
');

$totalPlanned = 0;
$insertCount = 0;
$updateCount = 0;
$renewalSyncCount = 0;

if ($execute) {
    $pdo->beginTransaction();
}

try {
    // ── Pass A: from payments ─────────────────────────────────────────────────
    $paySql = "
        SELECT p.member_id, p.year, MAX(p.paid_at) AS paid_at, MAX(p.comp) AS comp,
               m.first_name, m.last_name, m.membership_renewal_year,
               mf.id AS fulfillment_id
        FROM payments p
        INNER JOIN members m ON m.id = p.member_id
        LEFT JOIN member_fulfillments mf
            ON mf.member_id = p.member_id AND mf.year = p.year
        WHERE (mf.processed_at IS NULL)
          {$yearClauseP}
        GROUP BY p.member_id, p.year, m.first_name, m.last_name, m.membership_renewal_year, mf.id
        ORDER BY p.year DESC, m.last_name, m.first_name
        {$limitClause}
    ";
    $stmt = $pdo->prepare($paySql);
    $stmt->execute($yearParamsP);
    $payRows = $stmt->fetchAll();

    if ($payRows !== []) {
        echo 'Pass A — from payments: ' . count($payRows) . " row(s)\n";
        foreach ($payRows as $r) {
            $memberId = (int) $r['member_id'];
            $year     = (int) $r['year'];
            $paidAt   = (string) ($r['paid_at'] ?? date('Y-m-d'));
            $processedAt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidAt)
                ? $paidAt . ' 12:00:00'
                : date('Y-m-d H:i:s');
            $renewalType = !empty($r['comp']) ? 'complementary' : 'on_time';
            $hadRow = $r['fulfillment_id'] !== null;
            echo sprintf(
                "  [payment] id=%d year=%d %s, %s\n",
                $memberId,
                $year,
                $r['last_name'] ?? '',
                $r['first_name'] ?? ''
            );
            $totalPlanned++;
            if ($execute) {
                if ($hadRow) {
                    $updateFulfillment->execute([$processedAt, $renewalType, $memberId, $year]);
                    if ($updateFulfillment->rowCount() > 0) {
                        $updateCount++;
                    }
                } else {
                    $insertFulfillment->execute([$memberId, $year, $processedAt, $renewalType]);
                    $insertCount++;
                }
                if ($syncRenewalYear) {
                    $syncRenewal->execute([$year, $memberId, $year]);
                    $renewalSyncCount += $syncRenewal->rowCount();
                }
            } else {
                $hadRow ? $updateCount++ : $insertCount++;
            }
        }
        echo "\n";
    }

    // ── Pass B: fulfillment rows without processed_at (no payment required) ─
    $orphanSql = "
        SELECT mf.member_id, mf.year, mf.created_at,
               m.first_name, m.last_name, m.membership_renewal_year
        FROM member_fulfillments mf
        INNER JOIN members m ON m.id = mf.member_id
        WHERE mf.processed_at IS NULL
          {$yearClauseMf}
        ORDER BY mf.year DESC, m.last_name, m.first_name
        {$limitClause}
    ";
    $stmt = $pdo->prepare($orphanSql);
    $stmt->execute($yearParamsMf);
    $orphanRows = $stmt->fetchAll();

    if ($orphanRows !== []) {
        echo 'Pass B — fulfillments missing processed_at: ' . count($orphanRows) . " row(s)\n";
        foreach ($orphanRows as $r) {
            $memberId = (int) $r['member_id'];
            $year     = (int) $r['year'];
            $created  = (string) ($r['created_at'] ?? '');
            $processedAt = preg_match('/^\d{4}-\d{2}-\d{2}/', $created)
                ? substr($created, 0, 10) . ' 12:00:00'
                : date('Y-m-d H:i:s');
            echo sprintf(
                "  [fulfillment] id=%d year=%d %s, %s\n",
                $memberId,
                $year,
                $r['last_name'] ?? '',
                $r['first_name'] ?? ''
            );
            $totalPlanned++;
            if ($execute) {
                $updateFulfillment->execute([$processedAt, 'on_time', $memberId, $year]);
                if ($updateFulfillment->rowCount() > 0) {
                    $updateCount++;
                }
                if ($syncRenewalYear) {
                    $syncRenewal->execute([$year, $memberId, $year]);
                    $renewalSyncCount += $syncRenewal->rowCount();
                }
            } else {
                $updateCount++;
            }
        }
        echo "\n";
    }

    // ── Pass C: membership_renewal_year on file, no processed fulfillment ─────
    $rySql = "
        SELECT m.id AS member_id, m.membership_renewal_year AS year,
               m.first_name, m.last_name
        FROM members m
        WHERE m.membership_renewal_year IS NOT NULL
          AND m.membership_renewal_year > 0
          {$yearClauseRy}
          AND NOT EXISTS (
              SELECT 1 FROM member_fulfillments mf
              WHERE mf.member_id = m.id
                AND mf.year = m.membership_renewal_year
                AND mf.processed_at IS NOT NULL
          )
        ORDER BY m.membership_renewal_year DESC, m.last_name, m.first_name
        {$limitClause}
    ";
    $stmt = $pdo->prepare($rySql);
    $stmt->execute($yearParamsRy);
    $ryRows = $stmt->fetchAll();

    if ($ryRows !== []) {
        echo 'Pass C — from membership_renewal_year: ' . count($ryRows) . " row(s)\n";
        foreach ($ryRows as $r) {
            $memberId = (int) $r['member_id'];
            $year     = (int) $r['year'];
            $processedAt = $year . '-06-01 12:00:00';
            echo sprintf(
                "  [renewal_year] id=%d year=%d %s, %s\n",
                $memberId,
                $year,
                $r['last_name'] ?? '',
                $r['first_name'] ?? ''
            );
            $totalPlanned++;
            if ($execute) {
                $insertFulfillment->execute([$memberId, $year, $processedAt, 'on_time']);
                $insertCount++;
            } else {
                $insertCount++;
            }
        }
        echo "\n";
    }

    if ($execute) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($execute && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($totalPlanned === 0) {
    echo "Nothing to reconcile in passes A/B/C.\n";
    echo "Run: php scripts/reconcile_membership_fulfillments.php --diagnose\n";
    echo "to see whether your data lives in payments, fulfillments, or renewal_year only.\n";
    exit(0);
}

echo "Summary" . ($execute ? ' (applied)' : ' (dry run)') . ":\n";
echo "  Total planned: $totalPlanned\n";
echo "  Fulfillment inserts: $insertCount\n";
echo "  Fulfillment updates: $updateCount\n";
if ($syncRenewalYear) {
    echo "  membership_renewal_year raised: $renewalSyncCount\n";
}

if (!$execute) {
    echo "\nNo changes written. Run with --execute to apply.\n";
} else {
    echo "\nAfter reconcile — active for {$currentYear}: " . membershipCountActive($pdo, $currentYear);
    echo '; not yet renewed: ' . membershipCountNotYetRenewed($pdo, $currentYear) . "\n";
}
