#!/usr/bin/env php
<?php
/**
 * Send scheduled reminder emails (AMA/FAA expiry). Run from cron, e.g. daily:
 *   php scripts/send_reminders.php
 *   php scripts/send_reminders.php --dry-run
 *   php scripts/send_reminders.php --test-email=you@example.com
 *   php scripts/send_reminders.php --test-email=you@example.com --test-limit=3
 *   php scripts/send_reminders.php --test-email=you@example.com --dump-sender-payload
 *   php scripts/send_reminders.php --dry-run --test-email=you@example.com --dump-sender-payload
 *
 * --dump-sender-payload[=path]  Write the first Sender.net API request body to JSON
 *                               (default: logs/sender_payload_dump.json). Token redacted.
 *                               Works with --dry-run (builds payload without sending).
 *
 * --test-email=addr  Send all matching reminders to one address instead of the
 *                    real member email. Also relaxes the date filter to show
 *                    anyone expiring in the next 90 days so you can verify
 *                    templates without waiting for an exact trigger date.
 * --test-limit=N     With --test-email, stop after N reminder sends (default: no
 *                    limit). Counts each template attempt (sent, skipped, or
 *                    dry-run), across AMA and FAA batches.
 *
 * Skips recipients who opted out of transactional (reminder) email in Sender.net
 * when a Sender API token is configured (Administration → Installation).
 * Sends via Sender transactional API when configured (per-recipient unsubscribe links).
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
$dryRun              = false;
$testEmail           = null;
$testLimit           = null;
$testCount           = 0;
$dumpSenderPayload   = null;
$senderPayloadDumped = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--dump-sender-payload') {
        $dumpSenderPayload = $baseDir . '/logs/sender_payload_dump.json';
    } elseif (preg_match('/^--dump-sender-payload=(.+)$/', $arg, $m)) {
        $dumpSenderPayload = $m[1];
        if ($dumpSenderPayload !== '' && $dumpSenderPayload[0] !== '/') {
            $dumpSenderPayload = $baseDir . '/' . ltrim($dumpSenderPayload, '/');
        }
    } elseif (preg_match('/^--test-email=(.+)$/', $arg, $m)) {
        $testEmail = trim($m[1]);
    } elseif (preg_match('/^--test-limit=(\d+)$/', $arg, $m)) {
        $testLimit = (int) $m[1];
        if ($testLimit < 1) {
            fwrite(STDERR, "--test-limit must be a positive integer.\n");
            exit(1);
        }
    }
}

$isTest = ($testEmail !== null);

if ($testLimit !== null && !$isTest) {
    fwrite(STDERR, "--test-limit requires --test-email.\n");
    exit(1);
}

$stmt = $pdo->query('SELECT name FROM club WHERE id = 1');
$clubRow  = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
$clubName = $clubRow['name'] ?? 'RC Flight Operations';

if ($isTest) {
    echo "TEST MODE — all emails will be sent to: {$testEmail}\n";
    echo "Date filter relaxed to: expiring within 90 days\n";
    if ($testLimit !== null) {
        echo "Test limit: {$testLimit} reminder(s)\n";
    }
    echo "\n";
} elseif ($senderOn) {
    echo "Sender.net opt-out check enabled (transactional / reminder status).\n\n";
} else {
    echo "WARNING: Sender.net API token not set — reminders will not check opt-out status.\n";
    echo "         Set it under Administration → Installation.\n\n";
}

if ($dumpSenderPayload !== null) {
    echo "Sender payload dump enabled → {$dumpSenderPayload}\n\n";
}

$sent    = 0;
$skipped = 0;
$errors  = 0;
$startedAt = microtime(true);
flightops_log('INFO', 'send_reminders: start', [
    'dry_run'     => $dryRun,
    'test_email'  => $testEmail,
    'test_limit'  => $testLimit,
    'sender_check'=> $senderOn,
], 'cron');

function send_reminder_test_limit_reached(?int $testLimit, int $testCount): bool
{
    return $testLimit !== null && $testCount >= $testLimit;
}

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
    array $appConfig,
    bool $dryRun,
    bool $isTest,
    ?string $testEmail,
    ?string $dumpSenderPayload,
    bool &$senderPayloadDumped,
    int &$sent,
    int &$skipped,
    int &$errors
): void {
    $memberEmail = trim((string) ($member['email'] ?? ''));
    $memberLabel = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
    $useSender   = sender_net_is_configured($senderCfg);
    $recipient   = $memberEmail;

    if (!$isTest && $useSender) {
        $prep = sender_net_prepare_recipient(
            $memberEmail,
            (string) ($member['first_name'] ?? ''),
            (string) ($member['last_name'] ?? ''),
            $senderCfg,
            !$dryRun
        );
        if (!$prep['send']) {
            $detail = $prep['api_error'] ?? $prep['reason'];
            $displayEmail = $prep['normalized_email'] !== '' ? $prep['normalized_email'] : $memberEmail;
            $prefix = $dryRun ? '[dry-run] Would SKIP' : 'SKIPPED';
            echo "{$prefix} {$templateKey} to {$displayEmail} ({$memberLabel}): {$detail}\n";
            if (!$dryRun) {
                flightops_log('INFO', 'send_reminders: skipped (sender opt-out)', [
                    'template'  => $templateKey,
                    'to'        => $displayEmail,
                    'member_id' => (int) ($member['id'] ?? 0),
                    'reason'    => $prep['reason'],
                    'api_error' => $prep['api_error'],
                ], 'cron');
            }
            $skipped++;
            return;
        }

        $recipient = $prep['normalized_email'];
        sender_net_set_reminder_unsubscribe_vars($vars, $recipient, $pdo, $appConfig, $senderCfg);
        if ($prep['created'] && !$dryRun) {
            flightops_log('INFO', 'send_reminders: created Sender.net subscriber', [
                'template'  => $templateKey,
                'to'        => $recipient,
                'member_id' => (int) ($member['id'] ?? 0),
            ], 'cron');
        }
    } elseif ($isTest) {
        $recipient = (string) $testEmail;
        if ($useSender) {
            if (!$dryRun) {
                $ensure = sender_net_ensure_subscriber(
                    $recipient,
                    (string) ($member['first_name'] ?? ''),
                    (string) ($member['last_name'] ?? ''),
                    $senderCfg,
                    true
                );
                if ($ensure['ok']) {
                    $recipient = sender_net_normalize_email($recipient);
                }
            }
            sender_net_set_reminder_unsubscribe_vars($vars, $recipient, $pdo, $appConfig, $senderCfg);
        }
    }

    if ($dryRun && ($dumpSenderPayload === null || !$useSender)) {
        echo "[dry-run] Would send {$templateKey} to {$recipient} ({$memberLabel})\n";
        $sent++;
        return;
    }

    try {
        $data = render_email_template($templateKey, $vars, $pdo);
        $text = $data['text'];
        $unsubUrl = trim((string) ($vars['unsubscribe_url'] ?? ''));
        if ($text !== null && $unsubUrl !== '') {
            $text = rtrim($text) . sender_net_unsubscribe_plain_text_line($unsubUrl);
        }

        if ($useSender) {
            $liquidVars = sender_net_liquid_variables(
                $recipient,
                (string) ($member['first_name'] ?? ''),
                (string) ($member['last_name'] ?? '')
            );
            $from = [
                'email' => $mailCfg['from_address'] ?? '',
                'name'  => $mailCfg['from_name'] ?? '',
            ];

            if ($dumpSenderPayload !== null && !$senderPayloadDumped) {
                $request = sender_net_build_transactional_request(
                    $recipient,
                    $memberLabel,
                    $data['subject'],
                    $data['html'],
                    $text,
                    $from,
                    $senderCfg,
                    $liquidVars
                );
                $ok = sender_net_dump_transactional_request($request, $dumpSenderPayload, [
                    'template_key' => $templateKey,
                    'recipient'    => $recipient,
                    'member_id'    => (int) ($member['id'] ?? 0),
                    'dry_run'        => $dryRun,
                ]);
                $senderPayloadDumped = true;
                if ($ok) {
                    echo "Sender API payload dumped to {$dumpSenderPayload}\n";
                } else {
                    fwrite(STDERR, "FAILED to write Sender payload dump to {$dumpSenderPayload}\n");
                }
            }

            if ($dryRun) {
                echo "[dry-run] Would send {$templateKey} to {$recipient} ({$memberLabel}) via Sender.net\n";
                $sent++;
                return;
            }

            $sendResult = sender_net_send_transactional(
                $recipient,
                $memberLabel,
                $data['subject'],
                $data['html'],
                $text,
                $from,
                $senderCfg,
                $liquidVars
            );
            if ($sendResult['ok']) {
                echo "Sent {$templateKey} to {$recipient} (member: {$memberLabel}) via Sender.net\n";
                $sent++;
            } else {
                fwrite(STDERR, "FAILED {$templateKey} to {$recipient}: {$sendResult['error']}\n");
                flightops_log('WARN', 'send_reminders: Sender.net send failed', [
                    'template'  => $templateKey,
                    'to'        => $recipient,
                    'member_id' => (int) ($member['id'] ?? 0),
                    'error'     => $sendResult['error'],
                ], 'cron');
                $errors++;
            }
        } else {
            if (send_mail($recipient, $data['subject'], $data['html'], $text, $mailCfg)) {
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
    if ($isTest && send_reminder_test_limit_reached($testLimit, $testCount)) {
        break;
    }
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
        $config,
        $dryRun,
        $isTest,
        $testEmail,
        $dumpSenderPayload,
        $senderPayloadDumped,
        $sent,
        $skipped,
        $errors
    );
    if ($isTest) {
        $testCount++;
    }
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
            $config,
            $dryRun,
            $isTest,
            $testEmail,
            $dumpSenderPayload,
            $senderPayloadDumped,
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
    if ($isTest && send_reminder_test_limit_reached($testLimit, $testCount)) {
        break;
    }
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
        $config,
        $dryRun,
        $isTest,
        $testEmail,
        $dumpSenderPayload,
        $senderPayloadDumped,
        $sent,
        $skipped,
        $errors
    );
    if ($isTest) {
        $testCount++;
    }
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
            $config,
            $dryRun,
            $isTest,
            $testEmail,
            $dumpSenderPayload,
            $senderPayloadDumped,
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
