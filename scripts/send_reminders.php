#!/usr/bin/env php
<?php
/**
 * Send scheduled reminder emails (AMA/FAA expiry). Run from cron, e.g. daily:
 *   php scripts/send_reminders.php
 *   php scripts/send_reminders.php --dry-run
 *   php scripts/send_reminders.php --test-email=you@example.com
 *
 * --test-email=addr  Send all matching reminders to one address instead of the
 *                    real member email. Also relaxes the date filter to show
 *                    anyone expiring in the next 90 days so you can verify
 *                    templates without waiting for an exact trigger date.
 *
 * Requires config.php and optional email config (smtp or mail).
 * Templates in templates/email/.
 *
 * Each reminder is sent with a separate send_mail() call (no bulk API). For a
 * typical club this is fine; if your host SMTP throttles connections, insert a
 * short usleep() between sends or lower cron frequency.
 */

require_once __DIR__ . '/../includes/cli_only_script.php';
flightops_require_cli();

$baseDir = dirname(__DIR__);
require_once $baseDir . '/includes/app_log.php';
if (!is_file($baseDir . '/config.php')) {
    fwrite(STDERR, "Missing config.php.\n");
    flightops_log('ERROR', 'send_reminders: missing config.php', [], 'cron');
    exit(1);
}

$config = require $baseDir . '/config.php';
$db     = $config['db'];
$dsn    = sprintf(
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
    flightops_log('ERROR', 'send_reminders: DB connection failed', ['error' => $e->getMessage()], 'cron');
    exit(1);
}

require $baseDir . '/includes/mail.php';
require $baseDir . '/includes/email_templates.php';
require $baseDir . '/includes/installation_config.php';

$mailCfg = installation_mail_config($pdo);

// ── Parse CLI flags ───────────────────────────────────────────────────────────
$dryRun    = false;
$testEmail = null;   // when set: override recipient + relax date filter

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (preg_match('/^--test-email=(.+)$/', $arg, $m)) {
        $testEmail = trim($m[1]);
    }
}

$isTest = ($testEmail !== null);

$stmt = $pdo->query('SELECT name FROM club WHERE id = 1');
$clubRow  = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
$clubName = $clubRow['name'] ?? 'RC Flight Operations';

if ($isTest) {
    echo "TEST MODE — all emails will be sent to: {$testEmail}\n";
    echo "Date filter relaxed to: expiring within 90 days\n\n";
}

$sent   = 0;
$errors = 0;
$startedAt = microtime(true);
flightops_log('INFO', 'send_reminders: start', ['dry_run' => $dryRun, 'test_email' => $testEmail], 'cron');

// ── AMA expiry in 60 days (exclude life members) ──────────────────────────
if ($isTest) {
    $amaSql60 = "
        SELECT id, first_name, last_name, email, ama_number, ama_expiration
        FROM members
        WHERE (email IS NOT NULL AND email != '')
          AND allow_email = 1
          AND (ama_life_member = 0 OR ama_life_member IS NULL)
          AND ama_expiration BETWEEN CURDATE() AND CURDATE() + INTERVAL 90 DAY
    ";
} else {
    $amaSql60 = "
        SELECT id, first_name, last_name, email, ama_number, ama_expiration
        FROM members
        WHERE (email IS NOT NULL AND email != '')
          AND allow_email = 1
          AND (ama_life_member = 0 OR ama_life_member IS NULL)
          AND ama_expiration = CURDATE() + INTERVAL 60 DAY
    ";
}

$stmt = $pdo->prepare($amaSql60);
$stmt->execute();
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $recipient = $isTest ? $testEmail : $m['email'];
    $vars = [
        'first_name'     => $m['first_name'],
        'last_name'      => $m['last_name'],
        'email'          => $m['email'],
        'ama_number'     => $m['ama_number'],
        'ama_expiration' => $m['ama_expiration'],
        'days_remaining' => 60,
        'club_name'      => $clubName,
    ];
    if ($dryRun) {
        echo "[dry-run] Would send ama_expiry_60 to {$recipient} ({$m['first_name']} {$m['last_name']})\n";
        $sent++;
        continue;
    }
    try {
        $data = render_email_template('ama_expiry_60', $vars, $pdo);
        if (send_mail($recipient, $data['subject'], $data['html'], $data['text'], $mailCfg)) {
            echo "Sent ama_expiry_60 to {$recipient} (member: {$m['first_name']} {$m['last_name']})\n";
            $sent++;
        } else {
            $lastErr = function_exists('get_last_mail_error') ? get_last_mail_error() : 'unknown';
            fwrite(STDERR, "FAILED ama_expiry_60 to {$recipient}: {$lastErr}\n");
            flightops_log('WARN', 'send_reminders: email send failed', [
                'template' => 'ama_expiry_60',
                'to'       => $recipient,
                'member_id'=> (int) ($m['id'] ?? 0),
                'error'    => $lastErr,
            ], 'cron');
            $errors++;
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR ama_expiry_60 to {$recipient}: " . $e->getMessage() . "\n");
        flightops_log('ERROR', 'send_reminders: exception while sending', [
            'template' => 'ama_expiry_60',
            'to'       => $recipient,
            'member_id'=> (int) ($m['id'] ?? 0),
            'error'    => $e->getMessage(),
        ], 'cron');
        $errors++;
    }
}

// ── AMA expiry in 30 days ─────────────────────────────────────────────────
if (!$isTest) {
    $stmt = $pdo->prepare("
        SELECT id, first_name, last_name, email, ama_number, ama_expiration
        FROM members
        WHERE (email IS NOT NULL AND email != '')
          AND allow_email = 1
          AND (ama_life_member = 0 OR ama_life_member IS NULL)
          AND ama_expiration = CURDATE() + INTERVAL 30 DAY
    ");
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        if ($dryRun) {
            echo "[dry-run] Would send ama_expiry_30 to {$m['email']} ({$m['first_name']} {$m['last_name']})\n";
            $sent++;
            continue;
        }
        $vars = [
            'first_name'     => $m['first_name'],
            'last_name'      => $m['last_name'],
            'email'          => $m['email'],
            'ama_number'     => $m['ama_number'],
            'ama_expiration' => $m['ama_expiration'],
            'days_remaining' => 30,
            'club_name'      => $clubName,
        ];
        try {
            $data = render_email_template('ama_expiry_30', $vars, $pdo);
            if (send_mail($m['email'], $data['subject'], $data['html'], $data['text'], $mailCfg)) {
                echo "Sent ama_expiry_30 to {$m['email']}\n";
                $sent++;
            } else {
                $lastErr = function_exists('get_last_mail_error') ? get_last_mail_error() : 'unknown';
                fwrite(STDERR, "FAILED ama_expiry_30 to {$m['email']}: {$lastErr}\n");
                flightops_log('WARN', 'send_reminders: email send failed', [
                    'template' => 'ama_expiry_30',
                    'to'       => (string) $m['email'],
                    'member_id'=> (int) ($m['id'] ?? 0),
                    'error'    => $lastErr,
                ], 'cron');
                $errors++;
            }
        } catch (Throwable $e) {
            fwrite(STDERR, "ERROR ama_expiry_30 to {$m['email']}: " . $e->getMessage() . "\n");
            flightops_log('ERROR', 'send_reminders: exception while sending', [
                'template' => 'ama_expiry_30',
                'to'       => (string) $m['email'],
                'member_id'=> (int) ($m['id'] ?? 0),
                'error'    => $e->getMessage(),
            ], 'cron');
            $errors++;
        }
    }
}

// ── FAA expiry in 60 days ─────────────────────────────────────────────────
if ($isTest) {
    $faaSql = "
        SELECT id, first_name, last_name, email, faa_number, faa_expiration
        FROM members
        WHERE (email IS NOT NULL AND email != '')
          AND allow_email = 1
          AND faa_expiration BETWEEN CURDATE() AND CURDATE() + INTERVAL 90 DAY
    ";
} else {
    $faaSql = "
        SELECT id, first_name, last_name, email, faa_number, faa_expiration
        FROM members
        WHERE (email IS NOT NULL AND email != '')
          AND allow_email = 1
          AND faa_expiration = CURDATE() + INTERVAL 60 DAY
    ";
}

$stmt = $pdo->prepare($faaSql);
$stmt->execute();
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $recipient = $isTest ? $testEmail : $m['email'];
    $vars = [
        'first_name'     => $m['first_name'],
        'last_name'      => $m['last_name'],
        'email'          => $m['email'],
        'faa_number'     => $m['faa_number'],
        'faa_expiration' => $m['faa_expiration'],
        'days_remaining' => 60,
        'club_name'      => $clubName,
    ];
    if ($dryRun) {
        echo "[dry-run] Would send faa_expiry_60 to {$recipient} ({$m['first_name']} {$m['last_name']})\n";
        $sent++;
        continue;
    }
    try {
        $data = render_email_template('faa_expiry_60', $vars, $pdo);
        if (send_mail($recipient, $data['subject'], $data['html'], $data['text'], $mailCfg)) {
            echo "Sent faa_expiry_60 to {$recipient} (member: {$m['first_name']} {$m['last_name']})\n";
            $sent++;
        } else {
            $lastErr = function_exists('get_last_mail_error') ? get_last_mail_error() : 'unknown';
            fwrite(STDERR, "FAILED faa_expiry_60 to {$recipient}: {$lastErr}\n");
            flightops_log('WARN', 'send_reminders: email send failed', [
                'template' => 'faa_expiry_60',
                'to'       => $recipient,
                'member_id'=> (int) ($m['id'] ?? 0),
                'error'    => $lastErr,
            ], 'cron');
            $errors++;
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR faa_expiry_60 to {$recipient}: " . $e->getMessage() . "\n");
        flightops_log('ERROR', 'send_reminders: exception while sending', [
            'template' => 'faa_expiry_60',
            'to'       => $recipient,
            'member_id'=> (int) ($m['id'] ?? 0),
            'error'    => $e->getMessage(),
        ], 'cron');
        $errors++;
    }
}

echo "\nDone. Sent: $sent, Errors: $errors\n";
$elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
flightops_log('INFO', 'send_reminders: done', ['sent' => $sent, 'errors' => $errors, 'elapsed_ms' => $elapsedMs], 'cron');
exit($errors > 0 ? 1 : 0);
