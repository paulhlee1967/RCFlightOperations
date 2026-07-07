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
 *   php scripts/send_reminders.php --staff-digest
 *   php scripts/send_reminders.php --staff-digest --only-staff-digest
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
 * --staff-digest             Send a weekly summary email (one email per staff user)
 *                            to active users with role staff or manager, listing
 *                            current members whose AMA/FAA credentials are expired
 *                            or expiring soon (default: 60 days).
 * --staff-digest-window=N    Window in days for expiring-soon (default: 60).
 * --only-staff-digest        Skip member reminders; run digest only.
 *
 * Skips recipients who opted out of transactional (reminder) email in Sender.net
 * when a Sender API token is configured (Administration → Installation).
 * Sends via Sender transactional API when configured (per-recipient unsubscribe links).
 *
 * Member reminders and the staff digest only include current members for the
 * current calendar year (same rules as the dashboard and compliance report).
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

/**
 * Write CLI output to STDOUT (cPanel Terminal often hides STDERR).
 */
function send_reminders_out(string $message, bool $isError = false): void
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
    send_reminders_out($err['message'] . ' in ' . $err['file'] . ':' . $err['line'], true);
});

$baseDir = dirname(__DIR__);
send_reminders_out('send_reminders: starting (PHP ' . PHP_VERSION . ', ' . php_sapi_name() . ')');
require_once $baseDir . '/includes/app_log.php';
if (!is_file($baseDir . '/config.php')) {
    send_reminders_out('Missing config.php in ' . $baseDir, true);
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
    send_reminders_out('Database connection failed: ' . $e->getMessage(), true);
    flightops_log('ERROR', 'send_reminders: DB connection failed', ['error' => $e->getMessage()], 'cron');
    exit(1);
}

try {
    require $baseDir . '/includes/mail.php';
    require $baseDir . '/includes/email_templates.php';
    require $baseDir . '/includes/installation_config.php';
    require $baseDir . '/includes/sender_net.php';
    require $baseDir . '/templates/email/email_layout.php';
} catch (Throwable $e) {
    send_reminders_out('Bootstrap failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), true);
    exit(1);
}

$mailCfg    = installation_mail_config($pdo);
$senderCfg  = sender_net_load_config($pdo);
$senderOn   = sender_net_is_configured($senderCfg);

$membershipYear       = membershipStatusYear();
$currentMemberWhere   = currentMemberWhereSql('m', $membershipYear);
$currentMemberParams  = currentMemberWhereParams($membershipYear);

// ── Parse CLI flags ───────────────────────────────────────────────────────────
$dryRun              = false;
$testEmail           = null;
$testLimit           = null;
$testCount           = 0;
$dumpSenderPayload   = null;
$senderPayloadDumped = false;
$staffDigest         = false;
$staffDigestWindow   = 60;
$onlyStaffDigest     = false;

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
            send_reminders_out("--test-limit must be a positive integer.", true);
            exit(1);
        }
    } elseif ($arg === '--staff-digest') {
        $staffDigest = true;
    } elseif ($arg === '--only-staff-digest') {
        $onlyStaffDigest = true;
    } elseif (preg_match('/^--staff-digest-window=(\d+)$/', $arg, $m)) {
        $staffDigestWindow = (int) $m[1];
        if ($staffDigestWindow < 1 || $staffDigestWindow > 365) {
            send_reminders_out('--staff-digest-window must be between 1 and 365.', true);
            exit(1);
        }
    }
}

$isTest = ($testEmail !== null);

if ($testLimit !== null && !$isTest) {
    send_reminders_out('--test-limit requires --test-email.', true);
    exit(1);
}

$onlyStaffDigest = $onlyStaffDigest || ($staffDigest && $isTest); // test mode: keep output focused

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
    'staff_digest' => $staffDigest,
    'staff_digest_window' => $staffDigestWindow,
    'only_staff_digest' => $onlyStaffDigest,
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
                    send_reminders_out("FAILED to write Sender payload dump to {$dumpSenderPayload}", true);
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
                send_reminders_out("FAILED {$templateKey} to {$recipient}: {$sendResult['error']}", true);
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
                send_reminders_out("FAILED {$templateKey} to {$recipient}: {$lastErr}", true);
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
        send_reminders_out("ERROR {$templateKey} to {$recipient}: " . $e->getMessage(), true);
        flightops_log('ERROR', 'send_reminders: exception while sending', [
            'template'  => $templateKey,
            'to'        => $recipient,
            'member_id' => (int) ($member['id'] ?? 0),
            'error'     => $e->getMessage(),
        ], 'cron');
        $errors++;
    }
}

/**
 * Build the staff digest list: current members with AMA/FAA expired or expiring soon.
 *
 * @return array<int, array<string, mixed>>
 */
function staff_digest_rows(PDO $pdo, int $windowDays): array
{
    global $currentMemberWhere, $currentMemberParams;

    $today = date('Y-m-d');
    $inN   = date('Y-m-d', strtotime('+' . $windowDays . ' days'));

    $sql = "SELECT
                m.id,
                m.first_name,
                m.last_name,
                m.email,
                m.ama_number,
                m.ama_expiration,
                m.faa_number,
                m.faa_expiration
            FROM members m
            WHERE {$currentMemberWhere}
              AND (
                (m.ama_expiration IS NOT NULL AND m.ama_expiration != '' AND m.ama_expiration <= ?)
                OR (m.faa_expiration IS NOT NULL AND m.faa_expiration != '' AND m.faa_expiration <= ?)
              )
            ORDER BY LEAST(
                COALESCE(NULLIF(m.ama_expiration, ''), '9999-12-31'),
                COALESCE(NULLIF(m.faa_expiration, ''), '9999-12-31')
            ) ASC, m.last_name, m.first_name";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($currentMemberParams, [$inN, $inN]));

    $rows = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $amaExp = trim((string) ($r['ama_expiration'] ?? ''));
        $faaExp = trim((string) ($r['faa_expiration'] ?? ''));
        $expired = ($amaExp !== '' && $amaExp < $today) || ($faaExp !== '' && $faaExp < $today);
        $rows[] = [
            'id'         => (int) ($r['id'] ?? 0),
            'first_name' => (string) ($r['first_name'] ?? ''),
            'last_name'  => (string) ($r['last_name'] ?? ''),
            'email'      => (string) ($r['email'] ?? ''),
            'ama_number' => (string) ($r['ama_number'] ?? ''),
            'ama_exp'    => $amaExp,
            'faa_number' => (string) ($r['faa_number'] ?? ''),
            'faa_exp'    => $faaExp,
            'status'     => $expired ? 'Expired' : 'Expiring',
        ];
    }

    return $rows;
}

/**
 * Send staff digest to manager/staff users (one email per staff user).
 */
function send_staff_digest(
    PDO $pdo,
    string $clubName,
    int $windowDays,
    array $mailCfg,
    bool $dryRun,
    int &$sent,
    int &$errors
): void {
    $staff = [];
    try {
        $stmt = $pdo->prepare("SELECT email, name, role FROM users WHERE active = 1 AND role IN ('staff','manager','treasurer','editor')");
        $stmt->execute();
        $staff = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $staff = [];
    }

    $recipients = [];
    foreach ($staff as $u) {
        $email = trim((string) ($u['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $recipients[$email] = trim((string) ($u['name'] ?? ''));
        }
    }

    if ($recipients === []) {
        echo "No active manager/staff users found for staff digest.\n";
        return;
    }

    $rows  = staff_digest_rows($pdo, $windowDays);
    $count = count($rows);
    $todayLabel = date('M j, Y');
    $subject = "{$clubName} — Weekly AMA/FAA expiring-soon digest ({$count})";

    $table = '';
    if ($count === 0) {
        $table = '<p style="margin:0;color:#665e52;">No current members have AMA/FAA credentials expired or expiring within '
            . (int) $windowDays . " days.</p>";
    } else {
        $table .= '<table style="border-collapse:collapse;width:100%;margin-top:10px;">'
            . '<thead><tr>'
            . '<th style="text-align:left;padding:8px 10px;background:#6f7c3d;color:#fff;font-size:11px;text-transform:uppercase;letter-spacing:.03em;">Member</th>'
            . '<th style="text-align:left;padding:8px 10px;background:#6f7c3d;color:#fff;font-size:11px;text-transform:uppercase;letter-spacing:.03em;">Email</th>'
            . '<th style="text-align:left;padding:8px 10px;background:#6f7c3d;color:#fff;font-size:11px;text-transform:uppercase;letter-spacing:.03em;">AMA</th>'
            . '<th style="text-align:right;padding:8px 10px;background:#6f7c3d;color:#fff;font-size:11px;text-transform:uppercase;letter-spacing:.03em;">AMA exp</th>'
            . '<th style="text-align:left;padding:8px 10px;background:#6f7c3d;color:#fff;font-size:11px;text-transform:uppercase;letter-spacing:.03em;">FAA</th>'
            . '<th style="text-align:right;padding:8px 10px;background:#6f7c3d;color:#fff;font-size:11px;text-transform:uppercase;letter-spacing:.03em;">FAA exp</th>'
            . '<th style="text-align:right;padding:8px 10px;background:#6f7c3d;color:#fff;font-size:11px;text-transform:uppercase;letter-spacing:.03em;">Status</th>'
            . '</tr></thead><tbody>';
        $i = 0;
        foreach ($rows as $r) {
            $bg = ($i++ % 2 === 1) ? 'background:#f6f5ef;' : '';
            $member = htmlspecialchars(trim($r['last_name'] . ', ' . $r['first_name']));
            $email  = htmlspecialchars(trim((string) $r['email']));
            $amaNum = htmlspecialchars(trim((string) $r['ama_number']));
            $amaExp = htmlspecialchars(trim((string) $r['ama_exp']));
            $faaNum = htmlspecialchars(trim((string) $r['faa_number']));
            $faaExp = htmlspecialchars(trim((string) $r['faa_exp']));
            $status = htmlspecialchars((string) $r['status']);
            $table .= '<tr>'
                . '<td style="text-align:left;padding:7px 10px;border-bottom:1px solid #e3e0d7;font-size:13px;' . $bg . '">' . $member . '</td>'
                . '<td style="text-align:left;padding:7px 10px;border-bottom:1px solid #e3e0d7;font-size:13px;' . $bg . '">' . ($email !== '' ? $email : '—') . '</td>'
                . '<td style="text-align:left;padding:7px 10px;border-bottom:1px solid #e3e0d7;font-size:13px;' . $bg . '">' . ($amaNum !== '' ? $amaNum : '—') . '</td>'
                . '<td style="text-align:right;padding:7px 10px;border-bottom:1px solid #e3e0d7;font-size:13px;' . $bg . '">' . ($amaExp !== '' ? $amaExp : '—') . '</td>'
                . '<td style="text-align:left;padding:7px 10px;border-bottom:1px solid #e3e0d7;font-size:13px;' . $bg . '">' . ($faaNum !== '' ? $faaNum : '—') . '</td>'
                . '<td style="text-align:right;padding:7px 10px;border-bottom:1px solid #e3e0d7;font-size:13px;' . $bg . '">' . ($faaExp !== '' ? $faaExp : '—') . '</td>'
                . '<td style="text-align:right;padding:7px 10px;border-bottom:1px solid #e3e0d7;font-size:13px;' . $bg . '">' . $status . '</td>'
                . '</tr>';
        }
        $table .= '</tbody></table>';
    }

    $content = '<h1 style="margin:0 0 2px;font-size:20px;color:#252018;">AMA/FAA expiring-soon digest</h1>'
        . '<p style="color:#665e52;font-size:12px;margin:0 0 16px;">Generated ' . htmlspecialchars($todayLabel)
        . ' · Window: ' . (int) $windowDays . ' days</p>'
        . $table;

    $html = emailWrap($content, [
        'club_name'   => $clubName,
        'eyebrow'     => 'Staff digest',
        'footer_note' => 'This weekly digest is sent to Membership Manager and Club Staff users only.',
    ], $pdo);

    foreach ($recipients as $addr => $name) {
        if ($dryRun) {
            echo "[dry-run] Would send staff digest to {$addr}" . ($name !== '' ? " ({$name})" : '') . "\n";
            $sent++;
            continue;
        }
        if (send_mail($addr, $subject, $html, null, $mailCfg)) {
            echo "Sent staff digest to {$addr}\n";
            $sent++;
        } else {
            $lastErr = function_exists('get_last_mail_error') ? get_last_mail_error() : 'unknown';
            send_reminders_out("FAILED staff digest to {$addr}: {$lastErr}", true);
            flightops_log('WARN', 'send_reminders: staff digest send failed', [
                'to'    => $addr,
                'error' => $lastErr,
            ], 'cron');
            $errors++;
        }
    }
}

// Auto-enable weekly staff digest on Mondays when run from cron (daily job).
if (!$staffDigest && !$isTest && (int) date('N') === 1) {
    $staffDigest = true;
}

// ── Weekly staff digest ───────────────────────────────────────────────────────
if ($staffDigest) {
    send_staff_digest($pdo, $clubName, $staffDigestWindow, $mailCfg, $dryRun, $sent, $errors);
    if ($onlyStaffDigest) {
        echo "\nDone. Sent: $sent, Skipped: $skipped, Errors: $errors\n";
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
        flightops_log('INFO', 'send_reminders: done (digest only)', [
            'sent'       => $sent,
            'skipped'    => $skipped,
            'errors'     => $errors,
            'elapsed_ms' => $elapsedMs,
        ], 'cron');
        exit($errors > 0 ? 1 : 0);
    }
}

// ── AMA expiry in 60 days (exclude life members) ──────────────────────────
if ($isTest) {
    $amaSql60 = "
        SELECT m.id, m.first_name, m.last_name, m.email, m.ama_number, m.ama_expiration
        FROM members m
        WHERE (m.email IS NOT NULL AND m.email != '')
          AND (m.ama_life_member = 0 OR m.ama_life_member IS NULL)
          AND m.ama_expiration BETWEEN CURDATE() AND CURDATE() + INTERVAL 90 DAY
          AND {$currentMemberWhere}
    ";
} else {
    $amaSql60 = "
        SELECT m.id, m.first_name, m.last_name, m.email, m.ama_number, m.ama_expiration
        FROM members m
        WHERE (m.email IS NOT NULL AND m.email != '')
          AND (m.ama_life_member = 0 OR m.ama_life_member IS NULL)
          AND m.ama_expiration = CURDATE() + INTERVAL 60 DAY
          AND {$currentMemberWhere}
    ";
}

$stmt = $pdo->prepare($amaSql60);
$stmt->execute($currentMemberParams);
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
        SELECT m.id, m.first_name, m.last_name, m.email, m.ama_number, m.ama_expiration
        FROM members m
        WHERE (m.email IS NOT NULL AND m.email != '')
          AND (m.ama_life_member = 0 OR m.ama_life_member IS NULL)
          AND m.ama_expiration = CURDATE() + INTERVAL 30 DAY
          AND {$currentMemberWhere}
    ");
    $stmt->execute($currentMemberParams);
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
        SELECT m.id, m.first_name, m.last_name, m.email, m.faa_number, m.faa_expiration
        FROM members m
        WHERE (m.email IS NOT NULL AND m.email != '')
          AND m.faa_expiration BETWEEN CURDATE() AND CURDATE() + INTERVAL 90 DAY
          AND {$currentMemberWhere}
    ";
} else {
    $faaSql = "
        SELECT m.id, m.first_name, m.last_name, m.email, m.faa_number, m.faa_expiration
        FROM members m
        WHERE (m.email IS NOT NULL AND m.email != '')
          AND m.faa_expiration = CURDATE() + INTERVAL 60 DAY
          AND {$currentMemberWhere}
    ";
}

$stmt = $pdo->prepare($faaSql);
$stmt->execute($currentMemberParams);
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
        SELECT m.id, m.first_name, m.last_name, m.email, m.faa_number, m.faa_expiration
        FROM members m
        WHERE (m.email IS NOT NULL AND m.email != '')
          AND m.faa_expiration = CURDATE() + INTERVAL 30 DAY
          AND {$currentMemberWhere}
    ");
    $stmt->execute($currentMemberParams);
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
