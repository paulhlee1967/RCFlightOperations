<?php
/**
 * Weekly Current Members PDF email.
 *
 * The roster year follows the renewal season start (Configuration → Membership)
 * (defaultRenewalYear): on/after that date through December 31 the PDF is
 * next calendar year; otherwise it is the current calendar year.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/installation_config.php';
require_once __DIR__ . '/run_report.php';

/** Stale "sending" claims older than this may be reclaimed (seconds). */
const CURRENT_MEMBERS_DIGEST_STALE_CLAIM_SECONDS = 600;

/**
 * ISO weekday labels (1 = Monday … 7 = Sunday).
 *
 * @return array<int, string>
 */
function current_members_digest_weekday_labels(): array
{
    return [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];
}

function current_members_digest_weekday_label(int $weekday): string
{
    $labels = current_members_digest_weekday_labels();

    return $labels[$weekday] ?? 'Monday';
}

/**
 * Roster year for a given instant, using the same rule as defaultRenewalYear().
 */
function current_members_digest_report_year(int $startMonth, int $startDay, DateTimeInterface $when): int
{
    $startMonth = max(1, min(12, $startMonth));
    $startDay   = max(1, min(31, $startDay));
    $month = (int) $when->format('n');
    $day   = (int) $when->format('j');
    $year  = (int) $when->format('Y');
    $rolledOver = ($month > $startMonth)
        || ($month === $startMonth && $day >= $startDay);

    return $rolledOver ? $year + 1 : $year;
}

/**
 * Roster year for the weekly PDF, reading the renewal season start date.
 */
function current_members_digest_report_year_now(PDO $pdo, ?DateTimeInterface $when = null): int
{
    $when = $when ?? new DateTimeImmutable('now');

    return current_members_digest_report_year(
        renewal_prebook_start_month($pdo),
        renewal_prebook_start_day($pdo),
        $when
    );
}

/**
 * ISO week key for idempotency, e.g. "2026-W41".
 */
function current_members_digest_week_key(?DateTimeInterface $when = null): string
{
    $when = $when ?? new DateTimeImmutable('now');

    return $when->format('o') . '-W' . $when->format('W');
}

/**
 * Whether $when is on or after the configured weekday inside its ISO week.
 * A later run the same week still sends if the preferred day was missed.
 */
function current_members_digest_is_send_day(PDO $pdo, ?DateTimeInterface $when = null): bool
{
    $when = $when ?? new DateTimeImmutable('now');

    return (int) $when->format('N') >= current_members_digest_weekday($pdo);
}

function current_members_digest_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `current_members_digest_deliveries` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `week` varchar(8) NOT NULL COMMENT 'ISO week YYYY-Www',
              `report_year` smallint unsigned NOT NULL,
              `recipients` text NOT NULL,
              `status` enum('claimed','sending','sent','failed') NOT NULL DEFAULT 'claimed',
              `error_message` text DEFAULT NULL,
              `sent_at` datetime DEFAULT NULL,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uniq_current_members_digest_week` (`week`),
              KEY `idx_current_members_digest_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
    }
}

function current_members_digest_lock_name(string $week): string
{
    return 'roster_digest_' . preg_replace('/[^0-9W-]/', '', $week);
}

function current_members_digest_acquire_lock(PDO $pdo, string $week): bool
{
    $name = current_members_digest_lock_name($week);
    $stmt = $pdo->query('SELECT GET_LOCK(' . $pdo->quote($name) . ', 30)');

    return $stmt && (int) $stmt->fetchColumn() === 1;
}

function current_members_digest_release_lock(PDO $pdo, string $week): void
{
    $name = current_members_digest_lock_name($week);
    try {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($name) . ')');
    } catch (Throwable $e) {
    }
}

function current_members_digest_week_already_sent(PDO $pdo, string $week): bool
{
    current_members_digest_ensure_schema($pdo);
    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM current_members_digest_deliveries WHERE week = ? AND status = 'sent' LIMIT 1"
        );
        $stmt->execute([$week]);

        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Atomically claim this ISO week's send slot.
 * Returns the delivery row id when send should proceed, null when skipped.
 */
function current_members_digest_try_claim(PDO $pdo, string $week, int $reportYear, string $recipientsCsv, bool $force = false): ?int
{
    current_members_digest_ensure_schema($pdo);

    if (!current_members_digest_acquire_lock($pdo, $week)) {
        return null;
    }

    $deliveryId = null;
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT id, status, updated_at FROM current_members_digest_deliveries WHERE week = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$week]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $status = (string) ($row['status'] ?? '');
            $deliveryId = (int) $row['id'];

            if ($status === 'sent' && !$force) {
                $pdo->commit();
                current_members_digest_release_lock($pdo, $week);

                return null;
            }

            if ($status === 'sending' && !$force) {
                $updated = strtotime((string) ($row['updated_at'] ?? ''));
                $claimActive = $updated === false
                    || (time() - $updated) < CURRENT_MEMBERS_DIGEST_STALE_CLAIM_SECONDS;
                if ($claimActive) {
                    $pdo->commit();
                    current_members_digest_release_lock($pdo, $week);

                    return null;
                }
            }

            $pdo->prepare(
                "UPDATE current_members_digest_deliveries
                 SET report_year = ?, recipients = ?, status = 'sending', error_message = NULL, sent_at = NULL
                 WHERE id = ?"
            )->execute([$reportYear, $recipientsCsv, $deliveryId]);
        } else {
            $pdo->prepare(
                "INSERT INTO current_members_digest_deliveries (week, report_year, recipients, status)
                 VALUES (?, ?, ?, 'sending')"
            )->execute([$week, $reportYear, $recipientsCsv]);
            $deliveryId = (int) $pdo->lastInsertId();
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        current_members_digest_release_lock($pdo, $week);
        error_log('current_members_digest_try_claim failed: ' . $e->getMessage());

        return null;
    }

    current_members_digest_release_lock($pdo, $week);

    return $deliveryId > 0 ? $deliveryId : null;
}

function current_members_digest_mark_result(PDO $pdo, int $deliveryId, bool $success, ?string $error = null): void
{
    if ($deliveryId <= 0) {
        return;
    }

    current_members_digest_ensure_schema($pdo);
    try {
        if ($success) {
            $pdo->prepare(
                "UPDATE current_members_digest_deliveries
                 SET status = 'sent', error_message = NULL, sent_at = NOW()
                 WHERE id = ?"
            )->execute([$deliveryId]);
        } else {
            $pdo->prepare(
                "UPDATE current_members_digest_deliveries
                 SET status = 'failed', error_message = ?
                 WHERE id = ?"
            )->execute([$error, $deliveryId]);
        }
    } catch (Throwable $e) {
        error_log('current_members_digest_mark_result failed: ' . $e->getMessage());
    }
}

/**
 * Build the Current Members report for the working renewal year.
 *
 * @return array{
 *   year:int,
 *   week:string,
 *   report:array<string, mixed>,
 *   club:array<string, mixed>,
 *   club_name:string,
 *   filename:string,
 *   count:int
 * }
 */
function current_members_digest_prepare(PDO $pdo, ?DateTimeInterface $when = null): array
{
    require_once __DIR__ . '/report_pdf.php';

    $when = $when ?? new DateTimeImmutable('now');
    $year = current_members_digest_report_year_now($pdo, $when);
    $report = runReport($pdo, 'current_members', ['year' => $year]);

    $club = [];
    try {
        $club = $pdo->query(
            'SELECT name, logo_path, color_primary, color_primary_dark FROM club WHERE id = 1 LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $club = [];
    }

    $clubName = trim((string) ($club['name'] ?? ''));
    if ($clubName === '') {
        $clubName = 'RC Flight Operations';
    }

    return [
        'year'      => $year,
        'week'      => current_members_digest_week_key($when),
        'report'    => $report,
        'club'      => $club,
        'club_name' => $clubName,
        'filename'  => reportPdfFilename($report, $year, $when->format('Y-m-d')),
        'count'     => count($report['rows'] ?? []),
    ];
}

function current_members_digest_subject(array $prepared): string
{
    return (string) $prepared['club_name'] . ' — Current members · ' . (int) $prepared['year'];
}

/**
 * Short explanation of which roster the PDF contains.
 */
function current_members_digest_year_explanation(int $year, DateTimeInterface $when): string
{
    $calendar = (int) $when->format('Y');
    if ($year > $calendar) {
        return 'Today is in the renewal window, so this roster is ' . $year
            . ': members who have already signed up for ' . $year
            . ' (renewal year ' . $year . ' or later) and are not inactive or suspended.';
    }

    return 'This roster is current members for ' . $year
        . ': renewal year ' . $year . ' or later, and not inactive or suspended.';
}

/**
 * Render the branded PDF. Returns bytes and filename, or an error string.
 *
 * @param  array<string, mixed>  $prepared  From current_members_digest_prepare().
 * @return array{ok:bool, bytes:string, filename:string, error:?string}
 */
function current_members_digest_render_pdf(array $prepared): array
{
    require_once __DIR__ . '/report_pdf.php';

    $filename = (string) ($prepared['filename'] ?? 'current_members.pdf');
    if (!reportPdfAvailable()) {
        return [
            'ok'       => false,
            'bytes'    => '',
            'filename' => $filename,
            'error'    => 'PDF export is unavailable (Dompdf is not installed). Run composer install.',
        ];
    }

    try {
        $bytes = renderReportPdfBytes(
            $prepared['report'],
            $prepared['club'],
            (int) $prepared['year']
        );
    } catch (Throwable $e) {
        return [
            'ok'       => false,
            'bytes'    => '',
            'filename' => $filename,
            'error'    => 'Could not build the PDF: ' . $e->getMessage(),
        ];
    }

    if ($bytes === '') {
        return [
            'ok'       => false,
            'bytes'    => '',
            'filename' => $filename,
            'error'    => 'PDF render produced an empty file.',
        ];
    }

    return [
        'ok'       => true,
        'bytes'    => $bytes,
        'filename' => $filename,
        'error'    => null,
    ];
}

/**
 * Send the roster PDF to recipients. Returns [sent, failed, error].
 *
 * @param  array<int, string>     $recipients
 * @param  array<string, mixed>   $prepared
 * @param  array<string, mixed>   $pdf
 * @return array{sent:int, failed:int, error:?string}
 */
function current_members_digest_send_email(
    PDO $pdo,
    array $recipients,
    array $mailCfg,
    array $prepared,
    array $pdf,
    DateTimeInterface $when
): array {
    require_once __DIR__ . '/mail.php';
    require_once __DIR__ . '/../templates/email/email_layout.php';

    $recipients = array_values(array_unique(array_map('strtolower', $recipients)));
    if ($recipients === []) {
        return ['sent' => 0, 'failed' => 0, 'error' => 'No recipients.'];
    }
    if (empty($pdf['ok']) || !is_string($pdf['bytes'] ?? null) || $pdf['bytes'] === '') {
        return ['sent' => 0, 'failed' => count($recipients), 'error' => (string) ($pdf['error'] ?? 'PDF was not built.')];
    }

    $clubName = (string) $prepared['club_name'];
    $year     = (int) $prepared['year'];
    $count    = (int) $prepared['count'];
    $theme    = emailTheme(['club_name' => $clubName], $pdo);
    $subject  = current_members_digest_subject($prepared);
    $explain  = current_members_digest_year_explanation($year, $when);
    $countLabel = $count . ' member' . ($count === 1 ? '' : 's');
    $report   = $prepared['report'];

    $content = '<h1 style="margin:0 0 8px;font-size:20px;color:' . $theme['color_text'] . ';">Current members — ' . (int) $year . '</h1>'
        . '<p style="font-size:14px;margin:0 0 12px;">The roster PDF is attached (' . h($countLabel) . ').</p>'
        . '<p style="font-size:14px;margin:0 0 12px;">' . h($explain) . '</p>';
    if (!empty($report['accuracy_note'])) {
        $content .= '<p style="color:#7a5c00;background:#fdf6e3;border:1px solid #f0e2b8;border-radius:6px;padding:8px 10px;font-size:12px;margin:0;">'
            . '<strong>Note:</strong> ' . h((string) $report['accuracy_note']) . '</p>';
    }

    $html = emailWrap($content, [
        'club_name'         => $clubName,
        'eyebrow'           => 'Weekly roster',
        'footer_note'       => 'This is an internal club report. Generated '
            . h($when->format('M j, Y')) . ' from ' . h($clubName) . '.',
        'precomputed_theme' => $theme,
    ], $pdo);

    $text = "Current members — {$year}\n\n"
        . "The roster PDF is attached ({$countLabel}).\n\n"
        . $explain . "\n";
    if (!empty($report['accuracy_note'])) {
        $text .= "\nNote: " . $report['accuracy_note'] . "\n";
    }

    $sent = 0;
    $failed = 0;
    $ok = send_mail_to_many($recipients, $subject, $html, $text, $mailCfg, [
        'attachments' => [[
            'filename' => (string) $pdf['filename'],
            'content'  => $pdf['bytes'],
            'mime'     => 'application/pdf',
        ]],
    ]);
    if ($ok) {
        $sent = count($recipients);
    } else {
        $failed = count($recipients);
    }

    $err = null;
    if ($failed > 0 && function_exists('get_last_mail_error')) {
        $err = get_last_mail_error();
    }

    return ['sent' => $sent, 'failed' => $failed, 'error' => $err];
}
