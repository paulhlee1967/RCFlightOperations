#!/usr/bin/env php
<?php
/**
 * Import legacy "year roster" CSV and create member_fulfillments rows.
 *
 * This is designed for old systems where the only historical data available is:
 *   first name, last name, expires (year)
 *
 * It does NOT create payment rows (no amounts/dates). Instead it populates
 * member_fulfillments so year-based reports can work historically.
 *
 * Usage:
 *   php scripts/import_legacy_year_csv.php --file "/path/to/2019.csv" --execute
 *   php scripts/import_legacy_year_csv.php --file "/path/to/2019.csv" --year 2019 --execute
 *
 * Defaults to dry-run (no DB writes). Add --execute to apply.
 *
 * Notes:
 * - Members are matched by (first_name, last_name) case-insensitively.
 * - If multiple members match, the row is marked ambiguous and skipped.
 * - If no member matches, the row is marked missing and skipped.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/cli_only_script.php';
flightops_require_cli();

function usage(): void
{
    $msg = <<<TXT
Import legacy year CSV into member_fulfillments.

Required:
  --file PATH           CSV file path

Optional:
  --year YYYY           Force year (otherwise uses "expires" column if present)
  --delimiter ","       CSV delimiter (default: auto-detect , vs tab)
  --execute             Apply changes (default is dry-run)

Expected CSV columns (header-based; case-insensitive):
  first name, last name, expires

Accepted header variants (case-insensitive; punctuation/spaces ignored):
  - First:  firstname | first_name | first
  - Last:   lastname  | last_name  | last
  - Year:   expires   | year | renewalyear

Examples:
  php scripts/import_legacy_year_csv.php --file "/tmp/2018.csv"
  php scripts/import_legacy_year_csv.php --file "/tmp/2018.csv" --execute
  php scripts/import_legacy_year_csv.php --file "/tmp/2018.csv" --year 2018 --execute

TXT;
    echo $msg;
}

function normalizeHeaderKey(string $s): string
{
    $s = trim($s);
    $s = mb_strtolower($s);
    // Remove anything that isn't a letter/number so "First Name", "first_name", "FIRSTNAME" all match.
    $s = preg_replace('/[^a-z0-9]+/i', '', $s) ?? $s;
    return $s;
}

function normalizeName(string $s): string
{
    $s = trim($s);
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return mb_strtolower($s);
}

function detectDelimiter(string $file): string
{
    $fh = fopen($file, 'r');
    if (!$fh) return ',';
    $line = fgets($fh);
    fclose($fh);
    if ($line === false) return ',';
    $comma = substr_count($line, ',');
    $tab   = substr_count($line, "\t");
    // Prefer tab only when it clearly dominates.
    return ($tab > $comma) ? "\t" : ',';
}

function parseArgs(array $argv): array
{
    $out = [
        'file' => null,
        'year' => null,
        'delimiter' => null, // auto-detect unless explicitly set
        'execute' => false,
    ];
    for ($i = 1; $i < count($argv); $i++) {
        $a = $argv[$i];
        if ($a === '--execute') {
            $out['execute'] = true;
            continue;
        }
        if ($a === '--file' && isset($argv[$i + 1])) {
            $out['file'] = $argv[++$i];
            continue;
        }
        if ($a === '--year' && isset($argv[$i + 1])) {
            $out['year'] = (int) $argv[++$i];
            continue;
        }
        if ($a === '--delimiter' && isset($argv[$i + 1])) {
            $out['delimiter'] = (string) $argv[++$i];
            continue;
        }
        if ($a === '-h' || $a === '--help') {
            usage();
            exit(0);
        }
        fwrite(STDERR, "Unknown arg: $a\n");
        usage();
        exit(2);
    }
    return $out;
}

$args = parseArgs($argv);
$file = $args['file'];
$forcedYear = $args['year'];
$delimiter = $args['delimiter'] ?? null;
$execute = (bool) $args['execute'];

if (!$file) {
    fwrite(STDERR, "Missing --file\n");
    usage();
    exit(2);
}
if (!is_file($file) || !is_readable($file)) {
    fwrite(STDERR, "File not found or not readable: $file\n");
    exit(2);
}
if ($forcedYear !== null && ($forcedYear < 2000 || $forcedYear > 2100)) {
    fwrite(STDERR, "Invalid --year: $forcedYear\n");
    exit(2);
}
$delimiter = ($delimiter === null) ? detectDelimiter($file) : $delimiter;
if ($delimiter === '') {
    fwrite(STDERR, "Invalid --delimiter\n");
    exit(2);
}

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

$fh = fopen($file, 'r');
if (!$fh) {
    fwrite(STDERR, "Failed to open file: $file\n");
    exit(2);
}

// Read header
$headerRaw = fgetcsv($fh, 0, $delimiter);
if ($headerRaw === false) {
    fwrite(STDERR, "CSV appears empty: $file\n");
    exit(2);
}
$headers = array_map(fn($h) => normalizeHeaderKey((string) $h), $headerRaw);

// Accept common variants from Excel exports: FirstName / LastName / Expires
$firstAliases = ['firstname', 'firstname', 'first', 'givenname', 'fname'];
$lastAliases  = ['lastname', 'lastname', 'last', 'surname', 'lname'];
$yearAliases  = ['expires', 'year', 'renewalyear', 'membershipyear', 'renewal'];

$idxFirst = false;
foreach ($firstAliases as $k) {
    $idxFirst = array_search($k, $headers, true);
    if ($idxFirst !== false) break;
}
$idxLast = false;
foreach ($lastAliases as $k) {
    $idxLast = array_search($k, $headers, true);
    if ($idxLast !== false) break;
}
$idxExp = false;
foreach ($yearAliases as $k) {
    $idxExp = array_search($k, $headers, true);
    if ($idxExp !== false) break;
}

if ($idxFirst === false || $idxLast === false) {
    fwrite(STDERR, "CSV must have headers including first and last name.\n");
    fwrite(STDERR, "Found headers: " . implode(', ', $headers) . "\n");
    exit(2);
}
if ($forcedYear === null && $idxExp === false) {
    fwrite(STDERR, "Missing expires/year column, and no --year provided.\n");
    exit(2);
}

$findMember = $pdo->prepare('
    SELECT id, first_name, last_name
    FROM members
    WHERE LOWER(first_name) = ? AND LOWER(last_name) = ?
');
$insertFulfillment = $pdo->prepare('
    INSERT INTO member_fulfillments (member_id, year, processed_at, processed_by, renewal_type)
    VALUES (?, ?, NOW(), NULL, ?)
    ON DUPLICATE KEY UPDATE processed_at = NOW(), processed_by = NULL, renewal_type = VALUES(renewal_type)
');

$total = 0;
$matched = 0;
$inserted = 0;
$ambiguous = 0;
$missing = 0;
$skipped = 0;
$badYear = 0;

$ambiguousExamples = [];
$missingExamples = [];
$badYearExamples = [];

if ($execute) {
    $pdo->beginTransaction();
}

try {
    $line = 1; // header line
    while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
        $line++;
        if ($row === [null] || $row === []) {
            continue;
        }
        $first = trim((string) ($row[$idxFirst] ?? ''));
        $last  = trim((string) ($row[$idxLast] ?? ''));
        if ($first === '' && $last === '') {
            $skipped++;
            continue;
        }
        $total++;

        $year = $forcedYear;
        if ($year === null) {
            $yRaw = trim((string) ($row[$idxExp] ?? ''));
            $year = (preg_match('/^\d{4}$/', $yRaw) ? (int) $yRaw : 0);
        }
        if ($year < 2000 || $year > 2100) {
            $badYear++;
            if (count($badYearExamples) < 10) {
                $badYearExamples[] = "line $line: $first $last (expires=" . ($idxExp !== false ? (string) ($row[$idxExp] ?? '') : 'n/a') . ")";
            }
            continue;
        }

        $findMember->execute([normalizeName($first), normalizeName($last)]);
        $hits = $findMember->fetchAll(PDO::FETCH_ASSOC);
        if (count($hits) === 0) {
            $missing++;
            if (count($missingExamples) < 10) {
                $missingExamples[] = "line $line: $first $last";
            }
            continue;
        }
        if (count($hits) > 1) {
            $ambiguous++;
            if (count($ambiguousExamples) < 10) {
                $ids = implode(',', array_map(fn($h) => (string) $h['id'], $hits));
                $ambiguousExamples[] = "line $line: $first $last (matches ids: $ids)";
            }
            continue;
        }

        $matched++;
        $memberId = (int) $hits[0]['id'];

        if ($execute) {
            $insertFulfillment->execute([$memberId, $year, 'import']);
            $inserted++;
        }
    }

    if ($execute) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($execute && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fclose($fh);
    fwrite(STDERR, "Import failed: " . $e->getMessage() . "\n");
    exit(1);
}

fclose($fh);

echo ($execute ? "EXECUTED" : "DRY RUN") . " legacy year import\n";
echo "File: $file\n";
echo "Year: " . ($forcedYear !== null ? (string) $forcedYear : "from CSV expires column") . "\n";
echo "\n";
echo "Rows considered: $total\n";
echo "Matched members: $matched\n";
echo "Inserted/updated fulfillments: " . ($execute ? (string) $inserted : "0 (dry-run)") . "\n";
echo "Missing members: $missing\n";
echo "Ambiguous name matches: $ambiguous\n";
echo "Bad/missing year: $badYear\n";
echo "Skipped blank rows: $skipped\n";

if ($missingExamples) {
    echo "\nMissing examples (first 10):\n";
    foreach ($missingExamples as $m) echo "  - $m\n";
}
if ($ambiguousExamples) {
    echo "\nAmbiguous examples (first 10):\n";
    foreach ($ambiguousExamples as $m) echo "  - $m\n";
}
if ($badYearExamples) {
    echo "\nBad year examples (first 10):\n";
    foreach ($badYearExamples as $m) echo "  - $m\n";
}

if (!$execute) {
    echo "\nNo changes were made. Re-run with --execute to apply.\n";
}

