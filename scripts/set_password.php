#!/usr/bin/env php
<?php
/**
 * One-time script to set the admin user password.
 *
 * Usage (CLI only):
 *   php scripts/set_password.php
 *
 * Prereqs:
 *   - `config.php` must exist in the project root and contain DB credentials.
 *   - Seed user email is `admin@yourclub.local` (created by `schema_full.sql`).
 */

require_once __DIR__ . '/../includes/cli_only_script.php';
flightops_require_cli();

$base = dirname(__DIR__);
require $base . '/includes/db.php';
require $base . '/includes/password_policy.php';

$email = 'admin@yourclub.local';
echo "Set password for: $email\n";

echo "New password: ";
$password = trim(fgets(STDIN));

list($pwOk, $pwError) = validate_password_policy($password);
if (!$pwOk) {
    die($pwError . "\n");
}

$hash = password_hash($password, PASSWORD_DEFAULT);
// Email is globally unique in users; update that row.
$stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE email = ? LIMIT 1');
$stmt->execute([$hash, $email]);

if ($stmt->rowCount() > 0) {
    echo "Password updated for $email. You can log in now.\n";
} else {
    echo "No user found with email $email. Check schema seed data.\n";
}
