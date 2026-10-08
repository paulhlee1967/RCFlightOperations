#!/usr/bin/env php
<?php
/**
 * Email the Current Members report as a PDF (cron-friendly).
 *
 * Run daily from cron; sends on or after the configured weekday, once per ISO week:
 *   php scripts/send_current_members_digest.php
 *   php scripts/send_current_members_digest.php --dry-run
 *   php scripts/send_current_members_digest.php --test-email=you@example.com
 *   php scripts/send_current_members_digest.php --force
 *
 * The roster year follows Administration → Configuration → Membership (renewal season start).
 * "Renewal year default — pre-book starts". On/after that date through
 * December 31 the PDF is next calendar year; otherwise it is the current year.
 *
 * --dry-run           Build the roster summary without sending or recording delivery.
 * --test-email=ADDR   Send to one test address; does not use the week's send slot.
 * --force             Bypass weekday check and weekly idempotency (still requires
 *                     enabled and configured recipients unless --test-email is used).
 *
 * Requires config.php and Dompdf (composer install). Does not use HTTP sessions.
 */

require_once __DIR__ . '/../includes/cli_only_script.php';
flightops_require_cli();

function send_current_members_digest_out(string $message, bool $isError = false): void
{
    $line = $message;
    if ($isError && stripos($line, 'error') !== 0) {
        $line = 'ERROR: ' . $line;
    }
    if ($line !== '' && !str_ends_with($line, "\n")) {
        $line .= "\n";
    }
    echo $line;
}

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    if (!in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    send_current_members_digest_out($err['message'] . ' in ' . $err['file'] . ':' . $err['line'], true);
});

$baseDir = dirname(__DIR__);
send_current_members_digest_out('send_current_members_digest: starting (PHP ' . PHP_VERSION . ', ' . php_sapi_name() . ')');
require_once $baseDir . '/includes/app_log.php';

if (!is_file($baseDir . '/config.php')) {
    send_current_members_digest_out('Missing config.php in ' . $baseDir, true);
    flightops_log('ERROR', 'send_current_members_digest: missing config.php', [], 'cron');
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
    send_current_members_digest_out('Database connection failed: ' . $e->getMessage(), true);
    flightops_log('ERROR', 'send_current_members_digest: DB connection failed', ['error' => $e->getMessage()], 'cron');
    exit(1);
}

try {
    require $baseDir . '/includes/mail.php';
    require $baseDir . '/includes/installation_config.php';
    require $baseDir . '/includes/current_members_digest.php';
} catch (Throwable $e) {
    send_current_members_digest_out('Bootstrap failed: ' . $e->getMessage(), true);
    exit(1);
}

$dryRun    = false;
$testEmail = null;
$force     = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--force') {
        $force = true;
    } elseif (preg_match('/^--test-email=(.+)$/', $arg, $m)) {
        $testEmail = trim($m[1]);
    } elseif ($arg === '--help' || $arg === '-h') {
        send_current_members_digest_out(implode("\n", [
            'Usage: php scripts/send_current_members_digest.php [--dry-run] [--test-email=ADDR] [--force]',
            '',
            '  --dry-run           Preview without sending or recording delivery',
            '  --test-email=ADDR   Send to a test address (does not use the weekly slot)',
            '  --force             Bypass weekday and weekly idempotency',
        ]));
        exit(0);
    } else {
        send_current_members_digest_out('Unknown argument: ' . $arg, true);
        exit(1);
    }
}

$when      = new DateTimeImmutable('now');
$weekKey   = current_members_digest_week_key($when);
$isTest    = $testEmail !== null && $testEmail !== '';
$claimSlot = !$dryRun && !$isTest;

flightops_log('INFO', 'send_current_members_digest: run', [
    'dry_run'    => $dryRun,
    'test_email' => $isTest ? $testEmail : null,
    'force'      => $force,
    'week'       => $weekKey,
], 'cron');

if ($isTest && !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
    send_current_members_digest_out('Invalid --test-email address.', true);
    flightops_log('ERROR', 'send_current_members_digest: invalid test email', ['email' => $testEmail], 'cron');
    exit(1);
}

if (!$isTest) {
    if (!current_members_digest_enabled($pdo)) {
        send_current_members_digest_out('Weekly roster email is disabled (System → Scheduled mail).');
        flightops_log('INFO', 'send_current_members_digest: disabled', [], 'cron');
        exit(0);
    }

    $recipients = current_members_digest_recipients($pdo);
    if ($recipients === []) {
        send_current_members_digest_out('No weekly roster recipients configured.', true);
        flightops_log('WARN', 'send_current_members_digest: no recipients', [], 'cron');
        exit(1);
    }

    if (!$force && !current_members_digest_is_send_day($pdo, $when)) {
        $weekday = current_members_digest_weekday($pdo);
        $today   = current_members_digest_weekday_label((int) $when->format('N'));
        send_current_members_digest_out(
            'Not send day (configured ' . current_members_digest_weekday_label($weekday)
            . '; today is ' . $today . '). Skipping.'
        );
        flightops_log('INFO', 'send_current_members_digest: not send day', ['weekday' => $weekday], 'cron');
        exit(0);
    }

    if (!$force && current_members_digest_week_already_sent($pdo, $weekKey)) {
        send_current_members_digest_out("Current members PDF already sent for {$weekKey}. Use --force to resend.");
        flightops_log('INFO', 'send_current_members_digest: already sent', ['week' => $weekKey], 'cron');
        exit(0);
    }
} else {
    $recipients = [$testEmail];
}

$prepared = current_members_digest_prepare($pdo, $when);
$year = (int) $prepared['year'];
$recipientsCsv = implode(', ', $recipients);
$deliveryId = null;

send_current_members_digest_out('Week: ' . $prepared['week']);
send_current_members_digest_out('Roster year: ' . $year . ' (' . (int) $prepared['count'] . ' members)');
send_current_members_digest_out('PDF: ' . $prepared['filename']);
send_current_members_digest_out('Subject: ' . current_members_digest_subject($prepared));
send_current_members_digest_out('Recipients: ' . $recipientsCsv);
send_current_members_digest_out(current_members_digest_year_explanation($year, $when));

if ($dryRun) {
    require_once $baseDir . '/includes/report_pdf.php';
    if (!reportPdfAvailable()) {
        send_current_members_digest_out('Warning: PDF export is unavailable (Dompdf is not installed). A real send would fail until composer install.');
    }
    send_current_members_digest_out('[dry-run] Would email the Current Members PDF to ' . count($recipients) . ' recipient(s).');
    flightops_log('INFO', 'send_current_members_digest: dry-run complete', [
        'week'       => $weekKey,
        'year'       => $year,
        'members'    => (int) $prepared['count'],
        'recipients' => count($recipients),
    ], 'cron');
    exit(0);
}

if ($claimSlot) {
    $deliveryId = current_members_digest_try_claim($pdo, $weekKey, $year, $recipientsCsv, $force);
    if ($deliveryId === null) {
        send_current_members_digest_out('Could not claim send slot (already sent or another run in progress).', true);
        flightops_log('WARN', 'send_current_members_digest: claim failed', ['week' => $weekKey], 'cron');
        exit(1);
    }
}

$pdf = current_members_digest_render_pdf($prepared);
if (!$pdf['ok']) {
    $err = $pdf['error'] ?? 'PDF render failed';
    if ($claimSlot && $deliveryId !== null) {
        current_members_digest_mark_result($pdo, $deliveryId, false, $err);
    }
    send_current_members_digest_out($err, true);
    flightops_log('ERROR', 'send_current_members_digest: pdf failed', [
        'week'  => $weekKey,
        'error' => $err,
    ], 'cron');
    exit(1);
}

$mailCfg = installation_mail_config($pdo);
$result  = current_members_digest_send_email($pdo, $recipients, $mailCfg, $prepared, $pdf, $when);

if ($claimSlot && $deliveryId !== null) {
    if ($result['sent'] > 0 && $result['failed'] === 0) {
        current_members_digest_mark_result($pdo, $deliveryId, true);
    } else {
        current_members_digest_mark_result($pdo, $deliveryId, false, $result['error'] ?? 'Mail send failed');
    }
}

if ($result['sent'] > 0 && $result['failed'] === 0) {
    $label = $isTest ? 'Test Current Members PDF sent' : 'Current Members PDF sent';
    send_current_members_digest_out("{$label} to {$result['sent']} address(es).");
    flightops_log('INFO', 'send_current_members_digest: sent', [
        'week'       => $weekKey,
        'year'       => $year,
        'recipients' => count($recipients),
        'test'       => $isTest,
    ], 'cron');
    exit(0);
}

$err = $result['error'] ?? 'unknown error';
send_current_members_digest_out('Current Members PDF send failed: ' . $err, true);
flightops_log('ERROR', 'send_current_members_digest: send failed', [
    'week'   => $weekKey,
    'error'  => $err,
    'failed' => $result['failed'],
], 'cron');
exit(1);
