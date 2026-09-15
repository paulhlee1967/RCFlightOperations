<?php
/**
 * Monthly board packet builder and delivery helpers.
 *
 * Web (board_packet.php) and CLI (scripts/send_board_packet.php) share this module
 * so packet content stays identical. Uses run_report.php / membership_status.php
 * for dashboard-consistent counts and accuracy caveats.
 */

require_once __DIR__ . '/run_report.php';
require_once __DIR__ . '/report_email_html.php';
require_once __DIR__ . '/installation_config.php';

/** Stale "sending" claims older than this may be reclaimed (seconds). */
const BOARD_PACKET_STALE_CLAIM_SECONDS = 600;

/**
 * Parse comma/semicolon-separated addresses. Returns deduped valid emails.
 *
 * @return array<int, string>
 */
function board_packet_parse_addresses(string $raw): array
{
    return report_email_parse_addresses($raw);
}

/**
 * Validate every token in a recipient list; returns invalid tokens for error messages.
 *
 * @return array<int, string>
 */
function board_packet_invalid_address_tokens(string $raw): array
{
    $parts = preg_split('/[,;]+/', $raw) ?: [];
    $invalid = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        if (!filter_var($p, FILTER_VALIDATE_EMAIL)) {
            $invalid[] = $p;
        }
    }

    return $invalid;
}

/**
 * Calendar month key for idempotency, e.g. "2026-07".
 */
function board_packet_month_key(?DateTimeInterface $when = null): string
{
    $when = $when ?? new DateTimeImmutable('now');

    return $when->format('Y-m');
}

/**
 * Human-readable month label, e.g. "July 2026".
 */
function board_packet_period_label(?DateTimeInterface $when = null): string
{
    $when = $when ?? new DateTimeImmutable('now');

    return $when->format('F Y');
}

/**
 * Whether today (or $when) is on/after the configured automatic send day (1–28).
 */
function board_packet_is_send_day(PDO $pdo, ?DateTimeInterface $when = null): bool
{
    $when = $when ?? new DateTimeImmutable('now');
    $day  = board_packet_send_day($pdo);

    return (int) $when->format('j') >= $day;
}

/**
 * Whether an automatic cron send should run for this month (enabled, day, recipients).
 */
function board_packet_automatic_send_ready(PDO $pdo, ?DateTimeInterface $when = null, bool $force = false): bool
{
    if (!board_packet_enabled($pdo)) {
        return false;
    }
    if (empty(board_packet_recipients($pdo))) {
        return false;
    }
    if ($force) {
        return true;
    }

    return board_packet_is_send_day($pdo, $when);
}

function board_packet_incident_type_label(string $type): string
{
    return match ($type) {
        'near_miss'       => 'Near Miss',
        'crash'           => 'Crash',
        'injury'          => 'Injury',
        'property_damage' => 'Property Damage',
        'airspace'        => 'Airspace',
        'other'           => 'Other',
        default           => ucfirst(str_replace('_', ' ', $type)),
    };
}

function board_packet_incident_status_label(string $status): string
{
    return match ($status) {
        'under_review' => 'Under review',
        'open'         => 'Open',
        default        => ucfirst(str_replace('_', ' ', $status)),
    };
}

function board_packet_short_text(?string $text, int $max = 160): string
{
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }
    if (mb_strlen($text) <= $max) {
        return $text;
    }

    return rtrim(mb_substr($text, 0, $max - 1)) . '…';
}

/**
 * Roster snapshot summary: current count, prior-year count, change only.
 *
 * @return array<string, mixed>
 */
function boardPacketRosterSummary(PDO $pdo): array
{
    $current = membershipStatusYear();
    $prior   = $current - 1;
    $curCnt  = countMembersForMembershipYear($pdo, $current);
    $priorCnt = countMembersForMembershipYear($pdo, $prior);
    $change  = $curCnt - $priorCnt;

    $report = [
        'slug'        => 'board_roster_summary',
        'title'       => 'Roster snapshot',
        'description' => 'Current member count with year-over-year change (summary only).',
        'columns'     => [
            ['key' => 'metric', 'label' => 'Metric', 'format' => 'text', 'align' => 'start'],
            ['key' => 'value',  'label' => 'Count',  'format' => 'int',  'align' => 'end'],
            ['key' => 'change', 'label' => 'Change vs prior year', 'format' => 'signed', 'align' => 'end'],
        ],
        'rows' => [
            [
                'metric' => 'Current members (' . $current . ')',
                'value'  => $curCnt,
                'change' => $change,
            ],
            [
                'metric' => 'Prior year (' . $prior . ')',
                'value'  => $priorCnt,
                'change' => null,
            ],
        ],
        'totals' => null,
        'note'   => 'Summary counts only — no member roster, AMA/FAA, or gate data.',
    ];

    return reportAppendAccuracyNote($pdo, 'membership_by_year', $current, $report);
}

/**
 * Relative path to the full not-yet-renewed report for a renewal year.
 */
function board_packet_not_yet_renewed_report_path(int $year): string
{
    return 'reports.php?report=not_yet_renewed&year=' . $year;
}

/**
 * Absolute URL for the full not-yet-renewed report (email/PDF), or null when unknown.
 */
function board_packet_not_yet_renewed_report_url(int $year, ?array $config = null): ?string
{
    require_once __DIR__ . '/email_urls.php';
    $base = email_public_base_url($config);
    if ($base === null) {
        return null;
    }

    return $base . '/' . board_packet_not_yet_renewed_report_path($year);
}

/**
 * Renewal pipeline: compact board summary (no member name list).
 *
 * Metrics for the working renewal year (defaultRenewalYear): prior-year roster,
 * how many of those have renewed, how many remain, and renewal percentage.
 *
 * @return array{count:int, year:int, report_path:string, report_url:?string, report:array<string,mixed>}
 */
function boardPacketRenewalPipeline(PDO $pdo): array
{
    $year     = defaultRenewalYear($pdo);
    $prevYear = $year - 1;

    $priorCount = countMembersForMembershipYear($pdo, $prevYear);

    $filter = notYetRenewedReportFilter($pdo, 'm', $year);
    $stmt   = $pdo->prepare("SELECT COUNT(*) FROM members m WHERE {$filter['where']}");
    $stmt->execute($filter['params']);
    $notYetCount = (int) $stmt->fetchColumn();

    $renewedCount = max(0, $priorCount - $notYetCount);
    $rate         = $priorCount > 0 ? round(($renewedCount / $priorCount) * 100, 1) : null;

    $reportPath = board_packet_not_yet_renewed_report_path($year);
    $reportUrl  = board_packet_not_yet_renewed_report_url($year);

    $report = [
        'slug'        => 'board_renewal_summary',
        'title'       => 'Renewal pipeline — ' . $year,
        'description' => 'Compact renewal progress for the working renewal year (summary only).',
        'columns'     => [
            ['key' => 'metric', 'label' => 'Metric', 'format' => 'text', 'align' => 'start'],
            ['key' => 'value',  'label' => 'Value',  'format' => 'text', 'align' => 'end'],
        ],
        'rows' => [
            [
                'metric' => 'Prior-year members (' . $prevYear . ')',
                'value'  => number_format($priorCount),
            ],
            [
                'metric' => 'Renewed for ' . $year,
                'value'  => number_format($renewedCount),
            ],
            [
                'metric' => 'Not yet renewed',
                'value'  => number_format($notYetCount),
            ],
            [
                'metric' => 'Renewal rate',
                'value'  => $rate !== null ? number_format($rate, 1) . '%' : '—',
            ],
        ],
        'totals' => null,
        'note' => 'Summary only — open the full Not yet renewed report for the member list. '
            . 'Same rules as the dashboard renewal pipeline.',
        'report_path' => $reportPath,
        'report_url'  => $reportUrl,
        'report_link_label' => 'View full not-yet-renewed report',
    ];

    $report = reportAppendAccuracyNote($pdo, 'not_yet_renewed', $year, $report);

    return [
        'count'       => $notYetCount,
        'year'        => $year,
        'report_path' => $reportPath,
        'report_url'  => $reportUrl,
        'report'      => $report,
    ];
}

/**
 * Revenue for the current membership year only.
 *
 * @return array<string, mixed>
 */
function boardPacketRevenueCurrentYear(PDO $pdo): array
{
    $year = membershipStatusYear();
    $full = runReport($pdo, 'revenue_by_year', []);

    $rows = [];
    foreach ($full['rows'] as $row) {
        if ((int) ($row['year'] ?? 0) === $year) {
            $rows[] = $row;
            break;
        }
    }

    $report = [
        'slug'        => 'board_revenue_current_year',
        'title'       => 'Revenue — ' . $year,
        'description' => 'Dues, initiation, and late fees for the current membership year.',
        'columns'     => $full['columns'],
        'rows'        => $rows,
        'totals'      => $rows !== [] ? [
            'year'       => 'Total',
            'payments'   => $rows[0]['payments'] ?? 0,
            'dues'       => $rows[0]['dues'] ?? 0,
            'initiation' => $rows[0]['initiation'] ?? 0,
            'late_fee'   => $rows[0]['late_fee'] ?? 0,
            'total'      => $rows[0]['total'] ?? 0,
        ] : null,
        'note'        => 'Current membership year only. Revenue is attributed to the year recorded on each payment.',
    ];

    if ($rows === []) {
        $report['note'] = 'No payments recorded for ' . $year . ' yet.';
    }

    return reportAppendAccuracyNote($pdo, 'revenue_by_year', $year, $report);
}

/**
 * Open and under-review incidents. Gracefully empty when the table is absent.
 *
 * @return array<string, mixed>
 */
function boardPacketOpenIncidents(PDO $pdo): array
{
    $rows = [];
    $note = null;

    try {
        $sql = "SELECT i.incident_date, i.incident_type, i.severity, i.status, i.location,
                       m.first_name, m.last_name, i.description
                FROM incidents i
                LEFT JOIN members m ON m.id = i.member_id
                WHERE i.status IN ('open', 'under_review')
                ORDER BY i.incident_date DESC, i.id DESC";
        $stmt = $pdo->query($sql);
        if ($stmt) {
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $member = trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? ''));
                $rows[] = [
                    'incident_date' => $r['incident_date'],
                    'type'          => board_packet_incident_type_label((string) $r['incident_type']),
                    'severity'      => ucfirst((string) $r['severity']),
                    'status'        => board_packet_incident_status_label((string) $r['status']),
                    'location'      => $r['location'] ?: null,
                    'member'        => $member !== '' ? $member : null,
                    'description'   => board_packet_short_text($r['description'] ?? ''),
                ];
            }
        }
    } catch (Throwable $e) {
        $note = 'Incident log is not available on this installation.';
    }

    return [
        'slug'        => 'board_open_incidents',
        'title'       => 'Open incidents',
        'description' => 'Safety incidents that are open or under review (not closed).',
        'columns'     => [
            ['key' => 'incident_date', 'label' => 'Date',        'format' => 'date', 'align' => 'start'],
            ['key' => 'type',          'label' => 'Type',        'format' => 'text', 'align' => 'start'],
            ['key' => 'severity',      'label' => 'Severity',    'format' => 'text', 'align' => 'start'],
            ['key' => 'status',        'label' => 'Status',      'format' => 'text', 'align' => 'start'],
            ['key' => 'location',      'label' => 'Location',    'format' => 'text', 'align' => 'start'],
            ['key' => 'member',        'label' => 'Member',      'format' => 'text', 'align' => 'start'],
            ['key' => 'description',   'label' => 'Description', 'format' => 'text', 'align' => 'start'],
        ],
        'rows'   => $rows,
        'totals' => null,
        'note'   => $note ?? ($rows === []
            ? 'No open or under-review incidents.'
            : count($rows) . ' incident' . (count($rows) !== 1 ? 's' : '') . ' open or under review.'),
    ];
}

/**
 * Build the full monthly board packet.
 *
 * @param  array{when?:DateTimeInterface, note?:string}  $opts
 * @return array<string, mixed>
 */
function buildBoardPacket(PDO $pdo, array $opts = []): array
{
    $when = $opts['when'] ?? new DateTimeImmutable('now');
    $renewal = boardPacketRenewalPipeline($pdo);

    $sections = [
        [
            'key'    => 'roster_summary',
            'title'  => 'Roster snapshot',
            'report' => boardPacketRosterSummary($pdo),
        ],
        [
            'key'         => 'renewal_pipeline',
            'title'       => 'Renewal pipeline',
            'report'      => $renewal['report'],
            'count'       => $renewal['count'],
            'report_path' => $renewal['report_path'],
            'report_url'  => $renewal['report_url'],
        ],
        [
            'key'    => 'revenue',
            'title'  => 'Revenue',
            'report' => boardPacketRevenueCurrentYear($pdo),
        ],
        [
            'key'    => 'incidents',
            'title'  => 'Safety incidents',
            'report' => boardPacketOpenIncidents($pdo),
        ],
    ];

    $clubName = board_packet_club_name($pdo);

    return [
        'title'           => 'Board packet',
        'club_name'       => $clubName,
        'period_label'    => board_packet_period_label($when),
        'month_key'       => board_packet_month_key($when),
        'generated_at'    => $when->format('M j, Y g:i a'),
        'generated_date'  => $when->format('M j, Y'),
        'membership_year' => membershipStatusYear(),
        'renewal_year'    => defaultRenewalYear($pdo),
        'sections'        => $sections,
        'intro_note'      => trim((string) ($opts['note'] ?? '')),
    ];
}

function board_packet_club_name(PDO $pdo): string
{
    try {
        $name = (string) ($pdo->query('SELECT name FROM club WHERE id = 1 LIMIT 1')->fetchColumn() ?: '');
        if (trim($name) !== '') {
            return trim($name);
        }
    } catch (Throwable $e) {
    }

    return 'RC Flight Operations';
}

/**
 * Branded HTML body for email (not wrapped). Caller passes to emailWrap().
 */
function boardPacketHtmlBody(array $packet, string $primary, string $primaryDark, string $onPrimary): string
{
    $title = h($packet['title'] ?? 'Board packet');
    $period = h($packet['period_label'] ?? '');
    $generated = h($packet['generated_at'] ?? '');

    $html = '<h1 style="margin:0 0 2px;font-size:20px;">' . $title . '</h1>'
        . '<p style="color:#665e52;font-size:12px;margin:0 0 4px;"><strong>' . $period . '</strong></p>'
        . '<p style="color:#665e52;font-size:12px;margin:0 0 16px;">Generated ' . $generated . '</p>';

    $intro = trim((string) ($packet['intro_note'] ?? ''));
    if ($intro !== '') {
        $html .= '<p style="font-size:14px;margin:0 0 16px;">' . nl2br(h($intro)) . '</p>';
    }

    foreach ($packet['sections'] as $section) {
        $report = $section['report'] ?? [];
        $html .= '<h2 style="font-size:16px;margin:24px 0 8px;color:' . $primaryDark . ';">'
            . h($section['title'] ?? ($report['title'] ?? 'Section')) . '</h2>';
        $html .= report_email_table_html($report, $primary, $primaryDark, $onPrimary);
        if (!empty($report['note'])) {
            $html .= '<p style="color:#665e52;font-size:11px;font-style:italic;margin-top:8px;">'
                . h($report['note']) . '</p>';
        }
        if (!empty($report['accuracy_note'])) {
            $html .= '<p style="color:#7a5c00;background:#fdf6e3;border:1px solid #f0e2b8;border-radius:6px;padding:8px 10px;font-size:11px;margin-top:8px;"><strong>Note:</strong> '
                . h($report['accuracy_note']) . '</p>';
        }

        $linkHref = trim((string) ($report['report_url'] ?? ($section['report_url'] ?? '')));
        $linkPath = trim((string) ($report['report_path'] ?? ($section['report_path'] ?? '')));
        $linkLabel = trim((string) ($report['report_link_label'] ?? 'View full not-yet-renewed report'));
        if ($linkHref !== '' || $linkPath !== '') {
            $href = $linkHref !== '' ? $linkHref : $linkPath;
            $html .= '<p style="font-size:12px;margin-top:10px;">'
                . '<a href="' . h($href) . '" style="color:' . $primaryDark . ';font-weight:600;">'
                . h($linkLabel) . '</a></p>';
        }
    }

    return $html;
}

/**
 * Subject line for a board packet email.
 */
function boardPacketEmailSubject(array $packet): string
{
    $club = (string) ($packet['club_name'] ?? 'RC Flight Operations');
    $period = (string) ($packet['period_label'] ?? '');

    return $club . ' — Board packet · ' . $period;
}

/**
 * Send board packet HTML to recipients. Returns [sent, failed].
 *
 * @param  array<int, string>  $recipients
 * @return array{sent:int, failed:int, error:?string}
 */
function board_packet_send_email(PDO $pdo, array $packet, array $recipients, array $mailCfg): array
{
    require_once __DIR__ . '/mail.php';
    require_once __DIR__ . '/../templates/email/email_layout.php';

    $recipients = array_values(array_unique(array_map('strtolower', $recipients)));
    if ($recipients === []) {
        return ['sent' => 0, 'failed' => 0, 'error' => 'No recipients.'];
    }

    $clubName = (string) ($packet['club_name'] ?? board_packet_club_name($pdo));
    $theme    = emailTheme(['club_name' => $clubName], $pdo);
    $subject  = boardPacketEmailSubject($packet);
    $content  = boardPacketHtmlBody(
        $packet,
        $theme['color_primary'],
        $theme['color_primary_dark'],
        $theme['on_primary']
    );
    $html = emailWrap($content, [
        'club_name'         => $clubName,
        'eyebrow'           => 'Board packet',
        'footer_note'       => 'This is an internal club report for the board. Generated '
            . h($packet['generated_date'] ?? date('M j, Y')) . ' from ' . h($clubName) . '.',
        'precomputed_theme' => $theme,
    ], $pdo);

    $sent = 0;
    $failed = 0;
    if (send_mail_to_many($recipients, $subject, $html, null, $mailCfg)) {
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

// ── Delivery log (idempotent automatic sends) ───────────────────────────────

function board_packet_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `board_packet_deliveries` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `month` varchar(7) NOT NULL COMMENT 'YYYY-MM calendar month',
              `recipients` text NOT NULL,
              `status` enum('claimed','sending','sent','failed') NOT NULL DEFAULT 'claimed',
              `error_message` text DEFAULT NULL,
              `sent_at` datetime DEFAULT NULL,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uniq_board_packet_month` (`month`),
              KEY `idx_board_packet_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
    }
}

function board_packet_lock_name(string $month): string
{
    return 'board_packet_' . preg_replace('/[^0-9-]/', '', $month);
}

function board_packet_acquire_lock(PDO $pdo, string $month): bool
{
    $name = board_packet_lock_name($month);
    $stmt = $pdo->query('SELECT GET_LOCK(' . $pdo->quote($name) . ', 30)');

    return $stmt && (int) $stmt->fetchColumn() === 1;
}

function board_packet_release_lock(PDO $pdo, string $month): void
{
    $name = board_packet_lock_name($month);
    try {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($name) . ')');
    } catch (Throwable $e) {
    }
}

/**
 * Whether a successful automatic delivery already exists for this month.
 */
function board_packet_month_already_sent(PDO $pdo, string $month): bool
{
    board_packet_ensure_schema($pdo);
    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM board_packet_deliveries WHERE month = ? AND status = 'sent' LIMIT 1"
        );
        $stmt->execute([$month]);

        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Atomically claim the month's automatic send slot.
 * Returns delivery row id when send should proceed, null when skipped.
 */
function board_packet_try_claim(PDO $pdo, string $month, string $recipientsCsv, bool $force = false): ?int
{
    board_packet_ensure_schema($pdo);

    if (!board_packet_acquire_lock($pdo, $month)) {
        return null;
    }

    $deliveryId = null;
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT id, status, updated_at FROM board_packet_deliveries WHERE month = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$month]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $status = (string) ($row['status'] ?? '');
            $deliveryId = (int) $row['id'];

            if ($status === 'sent' && !$force) {
                $pdo->commit();
                board_packet_release_lock($pdo, $month);

                return null;
            }

            if ($status === 'sending' && !$force) {
                $updated = strtotime((string) ($row['updated_at'] ?? ''));
                $claimActive = $updated === false
                    || (time() - $updated) < BOARD_PACKET_STALE_CLAIM_SECONDS;
                if ($claimActive) {
                    $pdo->commit();
                    board_packet_release_lock($pdo, $month);

                    return null;
                }
            }

            $pdo->prepare(
                "UPDATE board_packet_deliveries
                 SET recipients = ?, status = 'sending', error_message = NULL, sent_at = NULL
                 WHERE id = ?"
            )->execute([$recipientsCsv, $deliveryId]);
        } else {
            $pdo->prepare(
                "INSERT INTO board_packet_deliveries (month, recipients, status)
                 VALUES (?, ?, 'sending')"
            )->execute([$month, $recipientsCsv]);
            $deliveryId = (int) $pdo->lastInsertId();
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        board_packet_release_lock($pdo, $month);
        error_log('board_packet_try_claim failed: ' . $e->getMessage());

        return null;
    }

    board_packet_release_lock($pdo, $month);

    return $deliveryId > 0 ? $deliveryId : null;
}

function board_packet_mark_result(PDO $pdo, int $deliveryId, bool $success, ?string $error = null): void
{
    if ($deliveryId <= 0) {
        return;
    }

    board_packet_ensure_schema($pdo);
    try {
        if ($success) {
            $pdo->prepare(
                "UPDATE board_packet_deliveries
                 SET status = 'sent', error_message = NULL, sent_at = NOW()
                 WHERE id = ?"
            )->execute([$deliveryId]);
        } else {
            $pdo->prepare(
                "UPDATE board_packet_deliveries
                 SET status = 'failed', error_message = ?
                 WHERE id = ?"
            )->execute([$error, $deliveryId]);
        }
    } catch (Throwable $e) {
        error_log('board_packet_mark_result failed: ' . $e->getMessage());
    }
}

/**
 * Remove a claimed delivery row (e.g. when send is aborted before attempting).
 */
function board_packet_release_claim(PDO $pdo, int $deliveryId): void
{
    if ($deliveryId <= 0) {
        return;
    }
    board_packet_ensure_schema($pdo);
    try {
        $pdo->prepare(
            "DELETE FROM board_packet_deliveries WHERE id = ? AND status = 'sending'"
        )->execute([$deliveryId]);
    } catch (Throwable $e) {
    }
}

/**
 * Build branded multi-section HTML for board packet PDF export.
 *
 * @param  array<string, mixed>  $packet
 * @param  array<string, mixed>  $club
 */
function boardPacketPdfHtml(array $packet, array $club): string
{
    require_once __DIR__ . '/report_pdf.php';

    $clubName  = (string) ($club['name'] ?? ($packet['club_name'] ?? ''));
    $club_     = h($clubName !== '' ? $clubName : 'RC Flight Operations');
    $primary   = reportPdfColor($club['color_primary'] ?? null, '#6f7c3d');
    $primaryDk = reportPdfColor($club['color_primary_dark'] ?? null, '#556030');
    $title     = h($packet['title'] ?? 'Board packet');
    $period    = h($packet['period_label'] ?? '');
    $generated = h($packet['generated_at'] ?? date('M j, Y g:i a'));
    $logoUri   = reportPdfLogoDataUri($club['logo_path'] ?? null);

    $sectionsHtml = '';
    foreach ($packet['sections'] as $section) {
        $report = $section['report'] ?? [];
        $sectionsHtml .= '<h2 class="section-title">' . h($section['title'] ?? ($report['title'] ?? 'Section')) . '</h2>';

        $head = '';
        foreach ($report['columns'] as $col) {
            $align = ($col['align'] ?? 'start') === 'end' ? 'right' : 'left';
            $style = 'text-align:' . $align;
            $compact = reportColumnClass($col);
            if (in_array($compact, ['col-num', 'col-date', 'col-id'], true)) {
                $style .= ';white-space:nowrap;width:1%';
            }
            $head .= '<th style="' . $style . '">' . h($col['label']) . '</th>';
        }

        $body = '';
        $i = 0;
        foreach ($report['rows'] as $row) {
            $stripe = ($i++ % 2 === 1) ? ' class="alt"' : '';
            $body .= '<tr' . $stripe . '>';
            foreach ($report['columns'] as $col) {
                $align = ($col['align'] ?? 'start') === 'end' ? 'right' : 'left';
                $style = 'text-align:' . $align;
                $compact = reportColumnClass($col);
                if (in_array($compact, ['col-num', 'col-date', 'col-id'], true)) {
                    $style .= ';white-space:nowrap;width:1%';
                }
                $cell = reportFormatCell($row[$col['key']] ?? null, $col['format'], false);
                $body .= '<td style="' . $style . '">' . h($cell) . '</td>';
            }
            $body .= '</tr>';
        }
        if ($body === '') {
            $span = max(1, count($report['columns']));
            $body = '<tr><td colspan="' . $span . '" class="empty">No data for this section.</td></tr>';
        }

        $foot = '';
        if (!empty($report['totals'])) {
            $foot .= '<tr class="totals">';
            foreach ($report['columns'] as $col) {
                $align = ($col['align'] ?? 'start') === 'end' ? 'right' : 'left';
                $style = 'text-align:' . $align;
                $compact = reportColumnClass($col);
                if (in_array($compact, ['col-num', 'col-date', 'col-id'], true)) {
                    $style .= ';white-space:nowrap;width:1%';
                }
                $cell = reportFormatCell($report['totals'][$col['key']] ?? null, $col['format'], false);
                $foot .= '<td style="' . $style . '">' . h($cell) . '</td>';
            }
            $foot .= '</tr>';
        }

        $sectionsHtml .= '<table class="data"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody>';
        if ($foot !== '') {
            $sectionsHtml .= '<tfoot>' . $foot . '</tfoot>';
        }
        $sectionsHtml .= '</table>';

        if (!empty($report['note'])) {
            $sectionsHtml .= '<p class="note">' . h($report['note']) . '</p>';
        }
        if (!empty($report['accuracy_note'])) {
            $sectionsHtml .= '<p class="accuracy-note"><strong>Note:</strong> ' . h($report['accuracy_note']) . '</p>';
        }

        $linkHref = trim((string) ($report['report_url'] ?? ($section['report_url'] ?? '')));
        $linkPath = trim((string) ($report['report_path'] ?? ($section['report_path'] ?? '')));
        $linkLabel = trim((string) ($report['report_link_label'] ?? 'View full not-yet-renewed report'));
        if ($linkHref !== '' || $linkPath !== '') {
            $display = $linkHref !== '' ? $linkHref : $linkPath;
            $sectionsHtml .= '<p class="report-link"><strong>' . h($linkLabel) . ':</strong> '
                . h($display) . '</p>';
        }
    }

    $logoCell = $logoUri !== null
        ? '<td class="logo-cell"><img src="' . $logoUri . '" class="logo" alt=""></td>'
        : '';

    return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
        @page { margin: 1.5cm 1.3cm 1.9cm; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #252018; font-size: 10px; }
        .banner { width: 100%; border-collapse: collapse; background: ' . $primary . '; }
        .banner td { padding: 12px 14px; vertical-align: middle; }
        .banner .logo-cell { width: 54px; }
        .banner .logo { height: 40px; width: auto; }
        .banner .club { color: #ffffff; font-size: 12px; font-weight: bold; letter-spacing: .02em; }
        .banner .title { color: #ffffff; font-size: 18px; font-weight: bold; margin-top: 2px; }
        .accent { height: 4px; background: ' . $primaryDk . '; font-size: 0; line-height: 0; }
        .meta { color: #665e52; font-size: 9px; margin: 10px 0 12px; }
        .chip { display: inline-block; background: ' . $primary . '; color: #fff; font-weight: bold;
                padding: 1px 7px; border-radius: 8px; margin-left: 6px; }
        h2.section-title { font-size: 12px; color: ' . $primaryDk . '; margin: 18px 0 6px; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        table.data th, table.data td { padding: 5px 7px; border-bottom: 1px solid #e3e0d7; }
        table.data thead th { background: ' . $primary . '; color: #ffffff; font-size: 9px;
                text-transform: uppercase; letter-spacing: .03em; border-bottom: none; }
        table.data tbody tr.alt td { background: #f6f5ef; }
        table.data tbody td.empty { text-align: center; color: #8a8276; padding: 16px; }
        table.data tr.totals td { border-top: 2px solid ' . $primaryDk . '; border-bottom: none;
                font-weight: bold; color: ' . $primaryDk . '; }
        .note { color: #665e52; font-size: 8px; margin-top: 4px; font-style: italic; }
        .accuracy-note { color: #7a5c00; background: #fdf6e3; border: 1px solid #f0e2b8;
                padding: 6px 8px; font-size: 8px; margin-top: 6px; }
        .report-link { color: ' . $primaryDk . '; font-size: 8px; margin-top: 6px; }
    </style></head><body>
        <table class="banner"><tr>
            ' . $logoCell . '
            <td>
                <div class="club">' . $club_ . '</div>
                <div class="title">' . $title . '</div>
            </td>
        </tr></table>
        <div class="accent">&nbsp;</div>
        <p class="meta">Generated ' . $generated . ' <span class="chip">' . $period . '</span></p>
        ' . $sectionsHtml . '
    </body></html>';
}

/**
 * Stream board packet as branded PDF. Caller must verify reportPdfAvailable() first.
 *
 * @param  array<string, mixed>  $packet
 * @param  array<string, mixed>  $club
 */
function renderBoardPacketPdf(array $packet, array $club): void
{
    require_once __DIR__ . '/report_pdf.php';
    if (!reportPdfAvailable()) {
        return;
    }

    $limit = (string) ini_get('memory_limit');
    if ($limit !== '-1') {
        $bytes = (int) $limit;
        if (stripos($limit, 'g') !== false)      { $bytes *= 1024 * 1024 * 1024; }
        elseif (stripos($limit, 'm') !== false)   { $bytes *= 1024 * 1024; }
        elseif (stripos($limit, 'k') !== false)   { $bytes *= 1024; }
        if ($bytes < 512 * 1024 * 1024) {
            @ini_set('memory_limit', '512M');
        }
    }

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml(boardPacketPdfHtml($packet, $club));
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();

    $canvas    = $dompdf->getCanvas();
    $clubName  = (string) ($club['name'] ?? ($packet['club_name'] ?? ''));
    $footLabel = ($clubName !== '' ? $clubName : 'RC Flight Operations');
    $primaryDk = reportPdfColor($club['color_primary_dark'] ?? null, '#556030');
    [$r, $g, $b] = sscanf($primaryDk, '#%02x%02x%02x');
    $rgb  = [$r / 255, $g / 255, $b / 255];
    $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
    $w    = $canvas->get_width();
    $h    = $canvas->get_height();
    $canvas->page_text(40, $h - 28, $footLabel, $font, 8, $rgb);
    $canvas->page_text($w - 120, $h - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, $rgb);

    $month = preg_replace('/[^0-9-]/', '', (string) ($packet['month_key'] ?? date('Y-m')));
    $filename = 'board_packet_' . $month . '_' . date('Y-m-d') . '.pdf';

    if (ob_get_level()) {
        ob_end_clean();
    }
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}
