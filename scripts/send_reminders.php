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
 * Skips recipients whose Sender.net promotional email status is not "active"
 * when a Sender API token is configured (Administration → Installation).
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
require $baseDir . '/includes/sender_net.php';

$mailCfg    = installation_mail_config($pdo);
$senderCfg  = sender_net_load_config($pdo);
$senderOn   = sender_net_is_configured($senderCfg);

// ── Parse CLI flags ───────────────────────────────────────────────────────────
$dryRun    = false;
$testEmail = null;

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
} elseif ($senderOn) {
    echo "Sender.net opt-out check enabled (promotional email status).\n\n";
} else {
    echo "WARNING: Sender.net API token not set — reminders will not check opt-out status.\n";
    echo "         Set it under Administration → Installation.\n\n";
}

$sent    = 0;
$skipped = 0;
$errors  = 0;
$startedAt = microtime(true);
flightops_log('INFO', 'send_reminders: start', [
    'dry_run'     => $dryRun,
    'test_email'  => $testEmail,
    'sender_check'=> $senderOn,
], 'cron');

/**
 * @param array<string, mixed> $member
 * @param array<string, mixed> $vars
 */
function send_reminder_message(
    PDO $pdo,
    array $member,
    string $templateKey,
    array $vars,
    array $mailCfg,
    array $senderCfg,
    bool $dryRun,
    bool $isTest,
    ?string $testEmail,
    int &$sent,
    int &$skipped,
    int &$errors
): void {
    $memberEmail = trim((string) ($member['email'] ?? ''));
    $recipient   = $isTest ? (string) $testEmail : $memberEmail;
    $memberLabel = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));

    if (!$isTest) {
        $check = sender_net_may_email_recipient($memberEmail, $senderCfg);
        if (!$check['send']) {
            $detail = $check['api_error'] ?? $check['reason'];
            $prefix = $dryRun ? '[dry-run] Would SKIP' : 'SKIPPED';
            echo "{$prefix} {$templateKey} to {$memberEmail} ({$memberLabel}): {$detail}\n";
            if (!$dryRun) {
                flightops_log('INFO', 'send_reminders: skipped (sender opt-out)', [
                    'template'  => $templateKey,
                    'to'        => $memberEmail,
                    'member_id' => (int) ($member['id'] ?? 0),
                    'reason'    => $check['reason'],
                    'api_error' => $check['api_error'],
                ], 'cron');
            }
            $skipped++;
            return;
        }

        $subscriberId = is_array($check['subscriber']) ? ($check['subscriber']['id'] ?? null) : null;
        $unsubUrl     = sender_net_unsubscribe_url($memberEmail, is_string($subscriberId) ? $subscriberId : null, $senderCfg);
        if ($unsubUrl !== null) {
            $vars['unsubscribe_url'] = $unsubUrl;
        } elseif (sender_net_is_configured($senderCfg)) {
            $vars['show_unsubscribe_notice'] = true;
        }
        if ($check['reason'] === 'not_in_sender' && !$dryRun) {
            flightops_log('INFO', 'send_reminders: recipient not in Sender.net (sending anyway)', [
                'template'  => $templateKey,
                'to'        => $memberEmail,
                'member_id' => (int) ($member['id'] ?? 0),
            ], 'cron');
        }
    } else {
        $unsubUrl = sender_net_unsubscribe_url($memberEmail, null, $senderCfg);
        if ($unsubUrl !== null) {
            $vars['unsubscribe_url'] = $unsubUrl;
        } elseif (sender_net_is_configured($senderCfg)) {
            $vars['show_unsubscribe_notice'] = true;
        }
    }

    if ($dryRun) {
        echo "[dry-run] Would send {$templateKey} to {$recipient} ({$memberLabel})\n";
        $sent++;
        return;
    }

    try {
        $data = render_email_template($templateKey, $vars, $pdo);
        $text = $data['text'];
        if ($text !== null && isset($vars['unsubscribe_url'])) {
            $text = sender_net_append_unsubscribe_text($text, $vars['unsubscribe_url']);
        }

        $mailOptions = null;
        if (!empty($vars['unsubscribe_url'])) {
            $mailOptions = ['list_unsubscribe_url' => $vars['unsubscribe_url']];
        }

        if (send_mail($recipient, $data['subject'], $data['html'], $text, $mailCfg, $mailOptions)) {
            echo "Sent {$templateKey} to {$recipient} (member: {$memberLabel})\n";
            $sent++;
        } else {
            $lastErr = function_exists('get_last_mail_error') ? get_last_mail_error() : 'unknown';
            fwrite(STDERR, "FAILED {$templateKey} to {$recipient}: {$lastErr}\n");
            flightops_log('WARN', 'send_reminders: email send failed', [
                'template'  => $templateKey,
                'to'        => $recipient,
                'member_id' => (int) ($member['id'] ?? 0),
                'error'     => $lastErr,
            ], 'cron');
            $errors++;
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR {$templateKey} to {$recipient}: " . $e->getMessage() . "\n");
        flightops_log('ERROR', 'send_reminders: exception while sending', [
            'template'  => $templateKey,
            'to'        => $recipient,
            'member_id' => (int) ($member['id'] ?? 0),
            'error'     => $e->getMessage(),
        ], 'cron');
        $errors++;
    }
}

// ── AMA expiry in 60 days (exclude life members) ──────────────────────────
if ($isTest) {
    $amaSql60 = "
        SELECT id, first_name, last_name, email, ama_number, ama_expiration
        FROM members
        WHERE (email IS NOT NULL AND email != '')
          AND (ama_life_member = 0 OR ama_life_member IS NULL)
          AND ama_expiration BETWEEN CURDATE() AND CURDATE() + INTERVAL 90 DAY
    ";
} else {
    $amaSql60 = "
        SELECT id, first_name, last_name, email, ama_number, ama_expiration
        FROM members
        WHERE (email IS NOT NULL AND email != '')
          AND (ama_life_member = 0 OR ama_life_member IS NULL)
          AND ama_expiration = CURDATE() + INTERVAL 60 DAY
    ";
}

$stmt = $pdo->prepare($amaSql60);
$stmt->execute();
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
    send_reminder_message(
        $pdo,
        $m,
        'ama_expiry_60',
        [
            'first_name'     => $m['first_name'],
            'last_name'      => $m['last_name'],
            'email'          => $m['email'],
            'ama_number'     => $m['ama_number'],
            'ama_expiration' => $m['ama_expiration'],
            'days_remaining' => 60,
            'club_name'      => $clubName,
        ],
        $mailCfg,
        $senderCfg,
        $dryRun,
        $isTest,
        $testEmail,
        $sent,
        $skipped,
        $errors
    );
}

// ── AMA expiry in 30 days ─────────────────────────────────────────────────
if (!$isTest) {
    $stmt = $pdo->prepare("
        SELECT id, first_name, last_name, email, ama_number, ama_expiration
        FROM members
        WHERE (email IS NOT NULL AND email != '')
          AND (ama_life_member = 0 OR ama_life_member IS NULL)
          AND ama_expiration = CURDATE() + INTERVAL 30 DAY
    ");
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        send_reminder_message(
            $pdo,
            $m,
            'ama_expiry_30',
            [
                'first_name'     => $m['first_name'],
                'last_name'      => $m['last_name'],
                'email'          => $m['email'],
                'ama_number'     => $m['ama_number'],
                'ama_expiration' => $m['ama_expiration'],
                'days_remaining' => 30,
                'club_name'      => $clubName,
            ],
            $mailCfg,
            $senderCfg,
            $dryRun,
            $isTest,
            $testEmail,
            $sent,
            $skipped,
            $errors
        );
    }
}

// ── FAA expiry in 60 days ─────────────────────────────────────────────────
if ($isTest) {
    $faaSql = "
        SELECT id, first_name, last_name, email, faa_number, faa_expiration
        FROM members
        WHERE (email IS NOT NULL AND email != '')
          AND faa_expiration BETWEEN CURDATE() AND CURDATE() + INTERVAL 90 DAY
    ";
} else {
    $faaSql = "
        SELECT id, first_name, last_name, email, faa_number, faa_expiration
        FROM members
        WHERE (email IS NOT NULL AND email != '')
          AND faa_expiration = CURDATE() + INTERVAL 60 DAY
    ";
}

$stmt = $pdo->prepare($faaSql);
$stmt->execute();
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
    send_reminder_message(
        $pdo,
        $m,
        'faa_expiry_60',
        [
            'first_name'     => $m['first_name'],
            'last_name'      => $m['last_name'],
            'email'          => $m['email'],
            'faa_number'     => $m['faa_number'],
            'faa_expiration' => $m['faa_expiration'],
            'days_remaining' => 60,
            'club_name'      => $clubName,
        ],
        $mailCfg,
        $senderCfg,
        $dryRun,
        $isTest,
        $testEmail,
        $sent,
        $skipped,
        $errors
    );
}

// ── FAA expiry in 30 days ─────────────────────────────────────────────────
if (!$isTest) {
    $stmt = $pdo->prepare("
        SELECT id, first_name, last_name, email, faa_number, faa_expiration
        FROM members
        WHERE (email IS NOT NULL AND email != '')
          AND faa_expiration = CURDATE() + INTERVAL 30 DAY
    ");
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        send_reminder_message(
            $pdo,
            $m,
            'faa_expiry_30',
            [
                'first_name'     => $m['first_name'],
                'last_name'      => $m['last_name'],
                'email'          => $m['email'],
                'faa_number'     => $m['faa_number'],
                'faa_expiration' => $m['faa_expiration'],
                'days_remaining' => 30,
                'club_name'      => $clubName,
            ],
            $mailCfg,
            $senderCfg,
            $dryRun,
            $isTest,
            $testEmail,
            $sent,
            $skipped,
            $errors
        );
    }
}

echo "\nDone. Sent: $sent, Skipped: $skipped, Errors: $errors\n";
$elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
flightops_log('INFO', 'send_reminders: done', [
    'sent'       => $sent,
    'skipped'    => $skipped,
    'errors'     => $errors,
    'elapsed_ms' => $elapsedMs,
], 'cron');
exit($errors > 0 ? 1 : 0);
