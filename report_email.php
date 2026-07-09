<?php
/**
 * report_email.php — send a report by email.
 *
 * Two POST actions:
 *   action=snapshot : email the rendered report (table) to one or more addresses
 *                     (e.g. a board or treasurer address). Any report.
 *   action=members  : email a per-member message to everyone in a cohort report
 *                     (e.g. "not yet renewed"). Only recipients with
 *                     a non-empty email are contacted.
 *
 * Message tokens for action=members: {first_name}, {last_name}, {club_name}.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/flash.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/installation_config.php';
require_once __DIR__ . '/includes/run_report.php';
require_once __DIR__ . '/templates/email/email_layout.php';

requireLogin();
if (!canViewReports()) {
    header('Location: index.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: reports.php');
    exit;
}
csrf_validate();

$action = (string) ($_POST['action'] ?? '');
$slug   = (string) ($_POST['report'] ?? '');
if (!reportExists($slug) || !reportVisibleToUser($slug)) {
    flash('Unknown report.', 'danger');
    header('Location: reports.php');
    exit;
}

// Resolve year (only meaningful for year-based reports).
$needsYear = reportNeedsYear($slug);
$minYear   = reportEarliestYear($pdo);
$maxYear   = reportMaxSelectableYear($pdo);
$year      = reportDefaultYear($pdo, $slug);
if ($needsYear) {
    $yearRaw = (string) ($_POST['year'] ?? '');
    if (preg_match('/^\d{4}$/', $yearRaw)) {
        $candidate = (int) $yearRaw;
        if ($candidate >= $minYear && $candidate <= $maxYear) {
            $year = $candidate;
        }
    }
}

$redirect = 'reports.php?report=' . urlencode($slug) . ($needsYear ? '&year=' . (int) $year : '');

$clubName = 'RC Flight Operations';
try {
    $clubName = (string) ($pdo->query('SELECT name FROM club WHERE id = 1 LIMIT 1')->fetchColumn() ?: 'RC Flight Operations');
} catch (Throwable $e) {
    // keep default
}
$mailCfg = installation_mail_config($pdo);
$theme   = emailTheme(['club_name' => $clubName], $pdo);

/** Parse a comma/semicolon-separated address list into validated emails. */
function report_email_parse_addresses(string $raw): array
{
    $parts = preg_split('/[,;]+/', $raw) ?: [];
    $out   = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) {
            $out[$p] = true; // dedupe
        }
    }
    return array_keys($out);
}

function report_email_column_style(array $col, string $align): string
{
    $style = 'text-align:' . $align;
    $compact = reportColumnClass($col);
    if (in_array($compact, ['col-num', 'col-date', 'col-id'], true)) {
        $style .= ';white-space:nowrap;width:1%';
    }

    return $style;
}

/**
 * Inline-styled HTML table for an emailed report (no <style> dependency).
 * Colors come from the club theme so the table matches the branded wrapper.
 */
function report_email_table_html(array $report, string $primary, string $primaryDark, string $onPrimary): string
{
    $th = 'style="text-align:%s;padding:8px 10px;background:' . $primary . ';color:' . $onPrimary
        . ';font-size:11px;text-transform:uppercase;letter-spacing:.03em;%s"';
    $td = 'style="text-align:%s;padding:7px 10px;border-bottom:1px solid #e3e0d7;font-size:13px;%s"';

    $head = '';
    foreach ($report['columns'] as $col) {
        $align = ($col['align'] ?? 'start') === 'end' ? 'right' : 'left';
        $extra = report_email_column_style($col, $align);
        $extra = str_replace('text-align:' . $align . ';', '', $extra);
        $head .= '<th ' . sprintf($th, $align, $extra) . '>' . h($col['label']) . '</th>';
    }

    $body = '';
    $i = 0;
    foreach ($report['rows'] as $row) {
        $bg = ($i++ % 2 === 1) ? ' background:#f6f5ef;' : '';
        $body .= '<tr>';
        foreach ($report['columns'] as $col) {
            $align = ($col['align'] ?? 'start') === 'end' ? 'right' : 'left';
            $extra = report_email_column_style($col, $align);
            $extra = str_replace('text-align:' . $align . ';', '', $extra);
            $cell  = reportFormatCell($row[$col['key']] ?? null, $col['format'], false);
            $body .= '<td style="text-align:' . $align . ';padding:7px 10px;border-bottom:1px solid #e3e0d7;font-size:13px;' . $extra . $bg . '">' . h($cell) . '</td>';
        }
        $body .= '</tr>';
    }
    if ($body === '') {
        $span = max(1, count($report['columns']));
        $body = '<tr><td colspan="' . $span . '" style="text-align:center;padding:16px;color:#8a8276;font-size:13px;">No data for this report.</td></tr>';
    }

    $foot = '';
    if (!empty($report['totals'])) {
        $foot .= '<tr>';
        foreach ($report['columns'] as $col) {
            $align = ($col['align'] ?? 'start') === 'end' ? 'right' : 'left';
            $extra = report_email_column_style($col, $align);
            $extra = str_replace('text-align:' . $align . ';', '', $extra);
            $cell  = reportFormatCell($report['totals'][$col['key']] ?? null, $col['format'], false);
            $foot .= '<td style="text-align:' . $align . ';padding:8px 10px;border-top:2px solid ' . $primaryDark . ';font-weight:bold;font-size:13px;color:' . $primaryDark . ';' . $extra . '">' . h($cell) . '</td>';
        }
        $foot .= '</tr>';
    }

    return '<table style="border-collapse:collapse;width:100%;">'
        . '<thead><tr>' . $head . '</tr></thead>'
        . '<tbody>' . $body . '</tbody>'
        . ($foot !== '' ? '<tfoot>' . $foot . '</tfoot>' : '')
        . '</table>';
}

// ── Action: snapshot to address(es) ────────────────────────────────────────────
if ($action === 'snapshot') {
    $addresses = report_email_parse_addresses((string) ($_POST['to'] ?? ''));
    if (empty($addresses)) {
        flash('Enter at least one valid email address.', 'warning');
        header('Location: ' . $redirect);
        exit;
    }

    $report = runReport($pdo, $slug, ['year' => $year]);
    $intro  = trim((string) ($_POST['note'] ?? ''));

    $reportTitle = $report['title'] ?? 'Report';
    $yearLabel   = $needsYear ? (' &middot; ' . h((string) $year)) : '';
    $subject     = $clubName . ' — ' . $reportTitle;

    $content = '<h1 style="margin:0 0 2px;font-size:20px;color:' . $theme['color_text'] . ';">' . h($reportTitle) . '</h1>'
        . '<p style="color:#665e52;font-size:12px;margin:0 0 16px;">Generated ' . h(date('M j, Y g:i a')) . $yearLabel . '</p>'
        . ($intro !== '' ? '<p style="font-size:14px;margin:0 0 16px;">' . nl2br(h($intro)) . '</p>' : '')
        . report_email_table_html($report, $theme['color_primary'], $theme['color_primary_dark'], $theme['on_primary'])
        . (!empty($report['note']) ? '<p style="color:#665e52;font-size:11px;font-style:italic;margin-top:14px;">' . h($report['note']) . '</p>' : '')
        . (!empty($report['accuracy_note'])
            ? '<p style="color:#7a5c00;background:#fdf6e3;border:1px solid #f0e2b8;border-radius:6px;padding:8px 10px;font-size:11px;margin-top:12px;"><strong>Note:</strong> ' . h($report['accuracy_note']) . '</p>'
            : '');

    $html = emailWrap($content, [
        'club_name'          => $clubName,
        'eyebrow'            => 'Club Reports',
        'footer_note'        => 'This is an internal club report. '
            . 'Generated ' . h(date('M j, Y')) . ' from ' . h($clubName) . '.',
        'precomputed_theme'  => $theme,
    ], $pdo);

    $sent = 0;
    $failed = 0;
    if (send_mail_to_many($addresses, $subject, $html, null, $mailCfg)) {
        $sent = count($addresses);
    } else {
        $failed = count($addresses);
    }

    if ($sent > 0 && $failed === 0) {
        flash("Report emailed to {$sent} address" . ($sent !== 1 ? 'es' : '') . '.', 'success');
    } elseif ($sent > 0) {
        flash("Report emailed to {$sent} address(es); {$failed} failed.", 'warning');
    } else {
        $err = function_exists('get_last_mail_error') ? get_last_mail_error() : null;
        flash('Could not send the report.' . ($err ? ' ' . $err : ''), 'danger');
    }
    header('Location: ' . $redirect);
    exit;
}

// ── Action: email members in a cohort ──────────────────────────────────────────
if ($action === 'members') {
    if (!reportSupportsCohortEmail($slug)) {
        flash('This report does not support emailing members.', 'warning');
        header('Location: ' . $redirect);
        exit;
    }
    // Mass member email is more impactful than viewing — keep it to staff roles.
    if (!canEditMembers() && !canProcessMemberships()) {
        flash('You do not have permission to email members.', 'danger');
        header('Location: ' . $redirect);
        exit;
    }

    $subjectTpl = trim((string) ($_POST['subject'] ?? ''));
    $bodyTpl    = trim((string) ($_POST['message'] ?? ''));
    if ($subjectTpl === '' || $bodyTpl === '') {
        flash('Subject and message are both required.', 'warning');
        header('Location: ' . $redirect);
        exit;
    }

    $recipients = reportCohortRecipients($pdo, $slug, $year);
    if (empty($recipients)) {
        flash('No members in this cohort have a usable email address (allow-email on).', 'warning');
        header('Location: ' . $redirect);
        exit;
    }

    $sent = 0;
    $failed = 0;
    $wrapVars = ['club_name' => $clubName, 'precomputed_theme' => $theme];
    send_mail_batch_begin($mailCfg);
    try {
        foreach ($recipients as $r) {
            $tokens = [
                '{first_name}' => $r['first_name'],
                '{last_name}'  => $r['last_name'],
                '{club_name}'  => $clubName,
            ];
            $subject = strtr($subjectTpl, $tokens);
            $bodyText = strtr($bodyTpl, $tokens);
            $html = emailWrap(
                '<div style="font-size:14px;line-height:1.7;">' . nl2br(h($bodyText)) . '</div>',
                $wrapVars,
                $pdo
            );

            if (send_mail($r['email'], $subject, $html, $bodyText, $mailCfg)) {
                $sent++;
            } else {
                $failed++;
            }
        }
    } finally {
        send_mail_batch_end();
    }

    if ($sent > 0 && $failed === 0) {
        flash("Emailed {$sent} member" . ($sent !== 1 ? 's' : '') . '.', 'success');
    } elseif ($sent > 0) {
        flash("Emailed {$sent} member(s); {$failed} failed.", 'warning');
    } else {
        flash('Could not send member emails.', 'danger');
    }
    header('Location: ' . $redirect);
    exit;
}

flash('Unknown email action.', 'danger');
header('Location: ' . $redirect);
exit;
