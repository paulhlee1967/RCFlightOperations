#!/usr/bin/env php
<?php
/**
 * Export local database (schema + data) for importing on cPanel.
 * Run from project root: php scripts/export_db_for_cpanel.php
 *
 * Reads config.php for DB credentials and runs mysqldump. Output: export_for_cpanel.sql
 * (Add that file to .gitignore – do not commit it.)
 */

require_once __DIR__ . '/../includes/cli_only_script.php';
flightops_require_cli();

$baseDir = dirname(__DIR__);
if (!is_file($baseDir . '/config.php')) {
    fwrite(STDERR, "Missing config.php.\n");
    exit(1);
}

$config = require $baseDir . '/config.php';
$db = $config['db'] ?? null;
if (!$db || empty($db['name']) || empty($db['user'])) {
    fwrite(STDERR, "config.php must define db.name and db.user.\n");
    exit(1);
}

$host = $db['host'] ?? 'localhost';
$name = $db['name'];
$user = $db['user'];
$password = $db['password'] ?? '';
$outFile = $baseDir . '/export_for_cpanel.sql';

// Use a temp defaults file so the password is not visible in process list
$tmpIni = tempnam(sys_get_temp_dir(), 'mysqldump_');
$iniContent = "[client]\nuser=" . $user . "\npassword=" . $password . "\nhost=" . $host . "\n";
file_put_contents($tmpIni, $iniContent);
try {
    $cmd = sprintf(
        'mysqldump --defaults-extra-file=%s --single-transaction --routines --triggers %s > %s',
        escapeshellarg($tmpIni),
        escapeshellarg($name),
        escapeshellarg($outFile)
    );
    passthru($cmd, $exitCode);
    if ($exitCode !== 0) {
        fwrite(STDERR, "mysqldump failed (exit code $exitCode). Is MySQL/MariaDB running and are credentials correct?\n");
        @unlink($tmpIni);
        exit(1);
    }
    echo "Exported to " . $outFile . "\n";
} finally {
    @unlink($tmpIni);
}
