<?php
/**
 * Mark members as INACTIVE if their membership expired before 2025.
 * Life members are never marked inactive.
 *
 * "Expired before 2025" = membership_renewal_year is NULL or < 2025.
 *
 * Usage:
 *   php scripts/mark_expired_inactive.php           # dry run (show what would change)
 *   php scripts/mark_expired_inactive.php --execute # apply changes
 */

require_once __DIR__ . '/../includes/cli_only_script.php';
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

$execute = in_array('--execute', array_slice($argv, 1), true);

// Members to mark inactive: renewal year NULL or < 2025, and NOT a life member
$where = "
    (membership_renewal_year IS NULL OR membership_renewal_year < 2025)
    AND (life_member = 0 OR life_member IS NULL)
    AND (inactive = 0 OR inactive IS NULL)
";

$countSql = "SELECT COUNT(*) FROM members WHERE $where";
$stmt = $pdo->query($countSql);
$count = (int) $stmt->fetchColumn();

if ($count === 0) {
    echo "No members found that need to be marked inactive (all expired before 2025, non–life, currently active).\n";
    exit(0);
}

echo "Members that would be marked INACTIVE (expired before 2025, not life members): $count\n";

$listSql = "
    SELECT id, first_name, last_name, email, membership_renewal_year, life_member
    FROM members
    WHERE $where
    ORDER BY last_name, first_name
    LIMIT 50
";
$stmt = $pdo->query($listSql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $m) {
    $year = $m['membership_renewal_year'] ?? 'NULL';
    echo "  id={$m['id']} {$m['last_name']}, {$m['first_name']} (renewal_year=$year)\n";
}
if ($count > 50) {
    echo "  ... and " . ($count - 50) . " more.\n";
}

if (!$execute) {
    echo "\nThis was a dry run. No rows were updated. Run with --execute to apply.\n";
    exit(0);
}

$updateSql = "UPDATE members SET inactive = 1 WHERE $where";
$affected = $pdo->exec($updateSql);
echo "\nDone. Marked $affected member(s) as INACTIVE.\n";
