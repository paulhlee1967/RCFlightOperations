#!/usr/bin/env php
<?php
/**
 * Send the monthly board packet by email (cron-friendly).
 *
 * Run daily from cron; sends on the configured day of month when enabled:
 *   php scripts/send_board_packet.php
 *   php scripts/send_board_packet.php --dry-run
 *   php scripts/send_board_packet.php --test-email=you@example.com
 *   php scripts/send_board_packet.php --force
 *
 * --dry-run           Build packet and print actions without sending or logging delivery.
 * --test-email=ADDR   Send to one test address; does not consume the month's send slot.
 * --force             Bypass send-day check and monthly idempotency (still requires enabled
 *                     and configured recipients unless --test-email is used).
 *
 * Requires config.php. Does not use HTTP sessions.
 */

require_once __DIR__ . '/../includes/cli_only_script.php';
flightops_require_cli();

function send_board_packet_out(string $message, bool $isError = false): void
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
    send_board_packet_out($err['message'] . ' in ' . $err['file'] . ':' . $err['line'], true);
});

$baseDir = dirname(__DIR__);
send_board_packet_out('send_board_packet: starting (PHP ' . PHP_VERSION . ', ' . php_sapi_name() . ')');
require_once $baseDir . '/includes/app_log.php';

if (!is_file($baseDir . '/config.php')) {
    send_board_packet_out('Missing config.php in ' . $baseDir, true);
    flightops_log('ERROR', 'send_board_packet: missing config.php', [], 'cron');
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
    send_board_packet_out('Database connection failed: ' . $e->getMessage(), true);
    flightops_log('ERROR', 'send_board_packet: DB connection failed', ['error' => $e->getMessage()], 'cron');
    exit(1);
}

try {
    require $baseDir . '/includes/mail.php';
    require $baseDir . '/includes/installation_config.php';
    require $baseDir . '/includes/board_packet.php';
} catch (Throwable $e) {
    send_board_packet_out('Bootstrap failed: ' . $e->getMessage(), true);
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
        send_board_packet_out(implode("\n", [
            'Usage: php scripts/send_board_packet.php [--dry-run] [--test-email=ADDR] [--force]',
            '',
            '  --dry-run           Preview without sending or recording delivery',
            '  --test-email=ADDR   Send to a test address (does not use monthly slot)',
            '  --force             Bypass send day and monthly idempotency',
        ]));
        exit(0);
    } else {
        send_board_packet_out('Unknown argument: ' . $arg, true);
        exit(1);
    }
}

$when      = new DateTimeImmutable('now');
$monthKey  = board_packet_month_key($when);
$period    = board_packet_period_label($when);
$isTest    = $testEmail !== null && $testEmail !== '';
$claimSlot = !$dryRun && !$isTest;

flightops_log('INFO', 'send_board_packet: run', [
    'dry_run'    => $dryRun,
    'test_email' => $isTest ? $testEmail : null,
    'force'      => $force,
    'month'      => $monthKey,
], 'cron');

if ($isTest && !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
    send_board_packet_out('Invalid --test-email address.', true);
    flightops_log('ERROR', 'send_board_packet: invalid test email', ['email' => $testEmail], 'cron');
    exit(1);
}

if (!$isTest) {
    if (!board_packet_enabled($pdo)) {
        send_board_packet_out('Board packet automatic send is disabled (Installation settings).');
        flightops_log('INFO', 'send_board_packet: disabled', [], 'cron');
        exit(0);
    }

    $recipients = board_packet_recipients($pdo);
    if ($recipients === []) {
        send_board_packet_out('No board packet recipients configured.', true);
        flightops_log('WARN', 'send_board_packet: no recipients', [], 'cron');
        exit(1);
    }

    if (!$force && !board_packet_is_send_day($pdo, $when)) {
        $sendDay = board_packet_send_day($pdo);
        send_board_packet_out("Not send day (configured day {$sendDay}; today is " . $when->format('j') . '). Skipping.');
        flightops_log('INFO', 'send_board_packet: not send day', ['send_day' => $sendDay], 'cron');
        exit(0);
    }

    if (!$force && board_packet_month_already_sent($pdo, $monthKey)) {
        send_board_packet_out("Board packet already sent for {$monthKey}. Use --force to resend.");
        flightops_log('INFO', 'send_board_packet: already sent', ['month' => $monthKey], 'cron');
        exit(0);
    }
} else {
    $recipients = [$testEmail];
}

$recipientsCsv = implode(', ', $recipients);
$deliveryId    = null;

if ($claimSlot) {
    $deliveryId = board_packet_try_claim($pdo, $monthKey, $recipientsCsv, $force);
    if ($deliveryId === null) {
        send_board_packet_out('Could not claim send slot (already sent or another run in progress).', true);
        flightops_log('WARN', 'send_board_packet: claim failed', ['month' => $monthKey], 'cron');
        exit(1);
    }
}

$packet  = buildBoardPacket($pdo, ['when' => $when]);
$subject = boardPacketEmailSubject($packet);

send_board_packet_out('Board packet: ' . $period);
send_board_packet_out('Subject: ' . $subject);
send_board_packet_out('Recipients: ' . $recipientsCsv);

if ($dryRun) {
    send_board_packet_out('[dry-run] Would send board packet to ' . count($recipients) . ' recipient(s).');
    flightops_log('INFO', 'send_board_packet: dry-run complete', [
        'month'       => $monthKey,
        'recipients'  => count($recipients),
    ], 'cron');
    exit(0);
}

$mailCfg = installation_mail_config($pdo);
$result  = board_packet_send_email($pdo, $packet, $recipients, $mailCfg);

if ($claimSlot && $deliveryId !== null) {
    if ($result['sent'] > 0 && $result['failed'] === 0) {
        board_packet_mark_result($pdo, $deliveryId, true);
    } else {
        board_packet_mark_result($pdo, $deliveryId, false, $result['error'] ?? 'Mail send failed');
    }
}

if ($result['sent'] > 0 && $result['failed'] === 0) {
    $label = $isTest ? 'Test board packet sent' : 'Board packet sent';
    send_board_packet_out("{$label} to {$result['sent']} address(es).");
    flightops_log('INFO', 'send_board_packet: sent', [
        'month'      => $monthKey,
        'recipients' => count($recipients),
        'test'       => $isTest,
    ], 'cron');
    exit(0);
}

$err = $result['error'] ?? 'unknown error';
send_board_packet_out('Board packet send failed: ' . $err, true);
flightops_log('ERROR', 'send_board_packet: send failed', [
    'month'  => $monthKey,
    'error'  => $err,
    'failed' => $result['failed'],
], 'cron');
exit(1);
