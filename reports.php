<?php
/**
 * Reports — polished v1.0.
 *
 * Chart email helpers: includes/report_helpers.php.
 * Report SQL / row building: includes/run_report.php (`runReport()`).
 *
 * Changes from original:
 *  - runReport() now returns a $summary array alongside $headers/$rows.
 *  - Each report type defines its own summary stats (shown as stat cards
 *    above the table before anyone reads a row).
 *  - Chart.js charts added for revenue_by_year and member_type_breakdown.
 *  - AMA/FAA report split into labelled sections instead of one flat table.
 *  - Birthday callout added above the birthday report table.
 *  - Export buttons moved to a clean top-right toolbar.
 *  - Print stylesheet hides chrome; only title + stats + table print.
 *  - All original query logic and CSV/PDF export preserved unchanged.
 */
ob_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/report_helpers.php';
require_once __DIR__ . '/includes/run_report.php';
requireLogin();
if (!canViewReports()) {
    header('Location: index.php');
    exit;
}
$currentYear = (int) date('Y');
$membershipTypeLabels = enabledMembershipTypeLabels($pdo);
$emailReportError = '';

$reportTypes = [
    'membership_snapshot'   => 'Membership Snapshot',
    'birthdays_this_month'  => 'Birthdays This Month',
    'renewal_not_renewed'   => 'Not Yet Renewed',
    'revenue_by_year'       => 'Revenue by Year',
    'ama_faa_expiring'      => 'AMA/FAA Compliance',
    'gate_key_compliance'   => 'Gate Key Compliance',
    'member_type_breakdown' => 'Member Type Breakdown',
    'waived_analysis'       => 'Waived / Free Memberships',
];

// Report groupings for sidebar display
$reportGroups = [
    'Membership' => [
        'membership_snapshot',
        'member_type_breakdown',
        'renewal_not_renewed',
    ],
    'Compliance' => [
        'ama_faa_expiring',
        'gate_key_compliance',
    ],
    'Finance' => [
        'revenue_by_year',
        'waived_analysis',
    ],
    'People' => [
        'birthdays_this_month',
    ],
];

$report = isset($_GET['report']) && isset($reportTypes[$_GET['report']]) ? $_GET['report'] : 'membership_snapshot';
$year   = isset($_GET['year'])   ? max(2000, min(2100, (int) $_GET['year'])) : $currentYear;
$memberTypeFilter = (string) ($_GET['member_type'] ?? '');
$memberTypeSlotFilter = null;
if ($memberTypeFilter !== '') {
    $slot = is_numeric($memberTypeFilter) ? (int) $memberTypeFilter : 0;
    $memberTypeSlotFilter = ($slot >= 1 && $slot <= 4) ? $slot : null;
    if ($memberTypeSlotFilter === null) $memberTypeFilter = '';
}
$export  = isset($_GET['export']) && in_array($_GET['export'], ['csv', 'pdf', 'email_csv'], true) ? $_GET['export'] : null;
$options = ['member_type_slot' => $memberTypeSlotFilter];

$result = runReport($pdo, $report, $year, $membershipTypeLabels, $options);

// ── POST: Email report to an ad-hoc address ───────────────────────────────────
// Builds a formatted HTML email: stat summary + optional chart image + data
// table. Chart images are fetched from QuickChart.io (free, open-source) and
// embedded as base64 data URIs so they display in any email client without
// hotlinking. Falls back gracefully to a "view online" link if the fetch fails
// (e.g. server has outbound HTTP blocked).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'email_report') {
    csrf_validate();
    require_once __DIR__ . '/includes/mail.php';

    $toEmail = trim($_POST['to_email'] ?? '');
    $note    = trim($_POST['note'] ?? '');

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $emailReportError = 'Please enter a valid email address.';
    } elseif (
        empty($result['rows'])
        && empty($result['extra']['sections'])
    ) {
        $emailReportError = 'This report has no data to send.';
    } else {
        $tStmt = $pdo->query('SELECT name FROM club WHERE id = 1 LIMIT 1');
        $tRow     = $tStmt->fetch(PDO::FETCH_ASSOC);
        $clubName = $tRow['name'] ?? 'RC Flight Operations';

        // ── Chart image (QuickChart.io) ───────────────────────────────────
        // Converts the Chart.js config stored in $result['extra']['chart']
        // into a server-fetched PNG, embedded as a base64 data URI so it
        // renders in all email clients without external image blocking.
        // Falls back to a plain text link if the HTTP fetch fails.
        $chartHtml = '';
        if (!empty($result['extra']['chart'])) {
            // Build an absolute URL back to this report page for the fallback link
            $scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host        = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $path        = dirname($_SERVER['SCRIPT_NAME'] ?? '/') . '/reports.php';
            $reportUrl   = $scheme . '://' . $host . $path
                         . '?report=' . urlencode($report) . '&year=' . $year;
            $chartHtml = buildChartHtml(
                $result['extra']['chart'],
                $result['title'],
                $reportUrl
            );
        }

        // ── Stat summary cards ────────────────────────────────────────────
        $summaryText = '';
        foreach ($result['summary'] as $s) {
            $summaryText .= htmlspecialchars($s['label']) . ': <strong>' . htmlspecialchars((string) $s['value']) . '</strong>';
            if (!empty($s['sub'])) {
                $summaryText .= ' <span style="color:#888;font-size:0.85em;">(' . htmlspecialchars($s['sub']) . ')</span>';
            }
            $summaryText .= '<br>';
        }

        // ── Data table(s) ─────────────────────────────────────────────────
        $tableHtml = '';
        $sections = $result['extra']['sections'] ?? [];
        if ($sections !== []) {
            foreach ($sections as $sec) {
                $tableHtml .= '<h3 style="font-size:14px;margin:16px 0 8px;">' . htmlspecialchars($sec['title'] ?? '') . '</h3>';
                $tableHtml .= '<table style="border-collapse:collapse;width:100%;font-size:12px;">';
                $tableHtml .= '<thead><tr>';
                foreach ($sec['headers'] ?? [] as $h) {
                    $tableHtml .= '<th style="background:#f0f0f0;border:1px solid #ccc;padding:6px 8px;text-align:left;">'
                                . htmlspecialchars((string) $h) . '</th>';
                }
                $tableHtml .= '</tr></thead><tbody>';
                foreach ($sec['rows'] ?? [] as $i => $row) {
                    $bg = $i % 2 === 1 ? 'background:#fafafa;' : '';
                    $tableHtml .= '<tr style="' . $bg . '">';
                    foreach ($row as $cell) {
                        $tableHtml .= '<td style="border:1px solid #ddd;padding:5px 8px;">'
                                    . htmlspecialchars((string) $cell) . '</td>';
                    }
                    $tableHtml .= '</tr>';
                }
                $tableHtml .= '</tbody></table>';
            }
        } else {
            $tableHtml  = '<table style="border-collapse:collapse;width:100%;font-size:12px;">';
            $tableHtml .= '<thead><tr>';
            foreach ($result['headers'] as $h) {
                $tableHtml .= '<th style="background:#f0f0f0;border:1px solid #ccc;padding:6px 8px;text-align:left;">'
                            . htmlspecialchars($h) . '</th>';
            }
            $tableHtml .= '</tr></thead><tbody>';
            foreach ($result['rows'] as $i => $row) {
                $bg = $i % 2 === 1 ? 'background:#fafafa;' : '';
                $tableHtml .= '<tr style="' . $bg . '">';
                foreach ($row as $cell) {
                    $tableHtml .= '<td style="border:1px solid #ddd;padding:5px 8px;">'
                                . htmlspecialchars((string) $cell) . '</td>';
                }
                $tableHtml .= '</tr>';
            }
            $tableHtml .= '</tbody></table>';
        }

        // ── Assemble full email body ───────────────────────────────────────
        $sentBy    = $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'RC Flight Operations admin';
        $rowCount  = count($result['rows']);
        if (!empty($result['extra']['sections'])) {
            $rowCount = 0;
            foreach ($result['extra']['sections'] as $sec) {
                $rowCount += count($sec['rows'] ?? []);
            }
        }

        $bodyHtml  = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>';
        $bodyHtml .= '<body style="font-family:sans-serif;color:#222;max-width:800px;margin:0 auto;padding:20px;">';

        // Title + dateline
        $bodyHtml .= '<h2 style="font-size:18px;margin-bottom:4px;">' . htmlspecialchars($result['title']) . '</h2>';
        $bodyHtml .= '<p style="color:#888;font-size:12px;margin-top:0;">'
                   . 'Generated ' . date('F j, Y') . ' &nbsp;·&nbsp; '
                   . $rowCount . ' row' . ($rowCount !== 1 ? 's' : '')
                   . '</p>';

        // Stat summary box
        if ($summaryText !== '') {
            $bodyHtml .= '<div style="background:#f8f8f8;border:1px solid #e0e0e0;border-radius:4px;'
                       . 'padding:12px 16px;margin-bottom:20px;font-size:13px;line-height:2;">'
                       . $summaryText . '</div>';
        }

        // Optional admin note
        if ($note !== '') {
            $bodyHtml .= '<p style="font-size:13px;margin-bottom:20px;">' . nl2br(htmlspecialchars($note)) . '</p>';
        }

        // Chart (embedded PNG or fallback link)
        if ($chartHtml !== '') {
            $bodyHtml .= $chartHtml;
        }

        // Data table
        $bodyHtml .= $tableHtml;

        // Footer
        $bodyHtml .= '<p style="margin-top:24px;color:#aaa;font-size:11px;border-top:1px solid #eee;padding-top:12px;">'
                   . 'Sent by ' . htmlspecialchars($sentBy)
                   . ' via RC Flight Operations.</p>';
        $bodyHtml .= '</body></html>';

        // ── Send ──────────────────────────────────────────────────────────
        $subject = $clubName . ' — ' . $result['title'];
        $ok = send_mail($toEmail, $subject, $bodyHtml);

        if ($ok) {
            require_once __DIR__ . '/includes/flash.php';
            flash('Report emailed to ' . $toEmail . '.', 'success');
        } else {
            $emailReportError = 'Failed to send — check your email settings in config.php.';
        }

        if (empty($emailReportError)) {
            header('Location: reports.php?report=' . urlencode($report) . '&year=' . $year
                 . ($memberTypeFilter ? '&member_type=' . urlencode($memberTypeFilter) : ''));
            exit;
        }
    }
}

if ($export === 'csv') {
    while (ob_get_level()) ob_end_clean();
    $prevError = error_reporting(E_ALL & ~E_DEPRECATED);
    $filename  = preg_replace('/[^a-z0-9_-]/i', '_', $report) . '_' . $year;
    if ($memberTypeFilter !== '') {
        $filename .= '_' . $memberTypeFilter;
    }
    $filename .= '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    $esc = '\\';
    $sections = $result['extra']['sections'] ?? [];
    if ($sections !== []) {
        fputcsv($out, [$result['title']], ',', '"', $esc);
        fputcsv($out, [], ',', '"', $esc);
        foreach ($sections as $sec) {
            fputcsv($out, [$sec['title'] ?? ''], ',', '"', $esc);
            fputcsv($out, $sec['headers'] ?? [], ',', '"', $esc);
            foreach ($sec['rows'] ?? [] as $row) {
                fputcsv($out, $row, ',', '"', $esc);
            }
            fputcsv($out, [], ',', '"', $esc);
        }
    } else {
        fputcsv($out, array_merge([$result['title']], array_fill(0, max(0, count($result['headers']) - 1), '')), ',', '"', $esc);
        fputcsv($out, $result['headers'], ',', '"', $esc);
        foreach ($result['rows'] as $row) {
            fputcsv($out, $row, ',', '"', $esc);
        }
    }
    fclose($out);
    error_reporting($prevError);
    exit;
}

// ── Email CSV export ──────────────────────────────────────────────────────────
// Downloads a simple Name, Email CSV suitable for mail-merge or importing into
// a mailing tool. Only available for reports that return email addresses.
if ($export === 'email_csv') {
    while (ob_get_level()) ob_end_clean();
    $filename = preg_replace('/[^a-z0-9_-]/i', '_', $report) . '_' . $year . '_emails.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Name', 'Email'], ',', '"', '\\');
    foreach ($result['emails'] as $e) {
        fputcsv($out, [$e['name'], $e['email']], ',', '"', '\\');
    }
    fclose($out);
    exit;
}


if ($export === 'pdf') {
    $pdfHtml  = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>';
    $pdfHtml .= 'body{font-family:sans-serif;font-size:11px;margin:20px;}';
    $pdfHtml .= 'h1{font-size:16px;margin-bottom:12px;}';
    $pdfHtml .= 'table{border-collapse:collapse;width:100%;}';
    $pdfHtml .= 'th,td{border:1px solid #333;padding:6px 8px;text-align:left;}';
    $pdfHtml .= 'th{background:#eee;}tr:nth-child(even){background:#f9f9f9;}';
    $pdfHtml .= '</style></head><body>';
    $pdfHtml .= '<h1>' . htmlspecialchars($result['title']) . '</h1>';
    $sections = $result['extra']['sections'] ?? [];
    if ($sections !== []) {
        foreach ($sections as $sec) {
            $pdfHtml .= '<h2 style="font-size:13px;margin:16px 0 8px;">' . htmlspecialchars($sec['title'] ?? '') . '</h2>';
            $pdfHtml .= '<table><thead><tr>';
            foreach ($sec['headers'] ?? [] as $h) {
                $pdfHtml .= '<th>' . htmlspecialchars((string) $h) . '</th>';
            }
            $pdfHtml .= '</tr></thead><tbody>';
            foreach ($sec['rows'] ?? [] as $row) {
                $pdfHtml .= '<tr>';
                foreach ($row as $cell) {
                    $pdfHtml .= '<td>' . htmlspecialchars((string) $cell) . '</td>';
                }
                $pdfHtml .= '</tr>';
            }
            $pdfHtml .= '</tbody></table>';
        }
    } else {
        $pdfHtml .= '<table><thead><tr>';
        foreach ($result['headers'] as $h) {
            $pdfHtml .= '<th>' . htmlspecialchars($h) . '</th>';
        }
        $pdfHtml .= '</tr></thead><tbody>';
        foreach ($result['rows'] as $row) {
            $pdfHtml .= '<tr>';
            foreach ($row as $cell) {
                $pdfHtml .= '<td>' . htmlspecialchars((string) $cell) . '</td>';
            }
            $pdfHtml .= '</tr>';
        }
        $pdfHtml .= '</tbody></table>';
    }
    $pdfHtml .= '</body></html>';

    $dompdfPath = __DIR__ . '/vendor/autoload.php';
    if (is_file($dompdfPath)) {
        $prevErrorLevel = error_reporting(E_ALL & ~E_DEPRECATED);
        try {
            require_once $dompdfPath;
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
            $dompdf->loadHtml($pdfHtml);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            while (ob_get_level()) ob_end_clean();
            $dompdf->stream(
                preg_replace('/[^a-z0-9_-]/i', '_', $report) . '_' . $year . '.pdf',
                ['Attachment' => true]
            );
            exit;
        } catch (Throwable $e) {
            error_reporting($prevErrorLevel);
        } finally {
            error_reporting($prevErrorLevel);
        }
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>PDF export</title></head><body>';
    echo '<p>PDF export requires Dompdf. Run: <code>composer install</code>.</p>';
    echo '<p><a href="reports.php?report=' . urlencode($report) . '&year=' . $year . '">Back to report</a></p>';
    echo '</body></html>';
    exit;
}

// ── Page render ───────────────────────────────────────────────────────────────
$pageTitle = 'Reports — ' . ($reportTypes[$report] ?? 'Report');
require_once __DIR__ . '/includes/header.php';

/** Render a single data table from headers + rows arrays */
function renderTable(array $headers, array $rows, string $tableClass = ''): void {
    if (empty($rows)) {
        echo '<p class="text-muted small py-2 mb-0 ps-1">No data for this selection.</p>';
        return;
    }
    echo '<div class="table-responsive"><table class="table table-hover table-sm mb-0 ' . htmlspecialchars($tableClass) . '">';
    echo '<thead class="table-light"><tr>';
    foreach ($headers as $h) {
        echo '<th>' . htmlspecialchars($h) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $cell) {
            echo '<td>' . htmlspecialchars((string) $cell) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
?>

<!-- ── Layout: sidebar + main ────────────────────────────────────────────────── -->
<div class="row g-4">

    <!-- ── Left sidebar: report picker ──────────────────────────────────── -->
    <div class="col-lg-3 col-md-4">
        <div class="card shadow-sm reports-sidebar">
            <div class="card-header fw-semibold small">Reports</div>
            <div class="card-body p-0">
                <?php foreach ($reportGroups as $groupName => $groupReports): ?>
                <div class="report-group">
                    <div class="report-group-label"><?= htmlspecialchars($groupName) ?></div>
                    <?php foreach ($groupReports as $rKey): ?>
                    <a href="reports.php?report=<?= urlencode($rKey) ?>&year=<?= $year ?>"
                       class="report-nav-link<?= $report === $rKey ? ' active' : '' ?>">
                        <?= htmlspecialchars($reportTypes[$rKey]) ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Year + type filter (below sidebar) -->
        <div class="card shadow-sm mt-3">
            <div class="card-header fw-semibold small">Options</div>
            <div class="card-body">
                <form method="get" action="reports.php">
                    <input type="hidden" name="report" value="<?= htmlspecialchars($report) ?>">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Year</label>
                        <select name="year" class="form-select form-select-sm">
                            <?php for ($y = $currentYear + 1; $y >= $currentYear - 10; $y--): ?>
                            <option value="<?= $y ?>"<?= $year === $y ? ' selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <?php if ($report === 'renewal_not_renewed'): ?>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Member type</label>
                        <select name="member_type" class="form-select form-select-sm">
                            <option value="">All types</option>
                            <?php foreach (['Adult','Senior','Youth','Spouse'] as $t): ?>
                            <option value="<?= $t ?>"<?= $memberTypeFilter === $t ? ' selected' : '' ?>><?= $t ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary btn-sm w-100">Run report</button>
                </form>
            </div>
        </div>
    </div>

    <!-- ── Main content area ─────────────────────────────────────────────── -->
    <div class="col-lg-9 col-md-8">

        <!-- Report title + export toolbar -->
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
            <div>
                <h1 class="h3 mb-0"><?= htmlspecialchars($result['title']) ?></h1>
                <?php if (!empty($result['rows'])): ?>
                <p class="text-muted small mb-0"><?= count($result['rows']) ?> row<?= count($result['rows']) !== 1 ? 's' : '' ?></p>
                <?php endif; ?>
            </div>
            <?php if (!empty($result['rows'])): ?>
            <div class="d-flex gap-2 no-print flex-wrap">
                <?php if (!empty($result['emails'])): ?>
                <!-- ── Email members button — routes through report_email.php ── -->
                <a href="report_email.php?report=<?= urlencode($report) ?>&year=<?= $year ?>"
                   class="btn btn-sm btn-outline-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" class="me-1" viewBox="0 0 16 16">
                        <path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v.217l7 4.2 7-4.2V4a1 1 0 0 0-1-1zm13 2.383-4.708 2.825L15 11.105zm-.034 6.876-5.64-3.471L8 9.583l-1.326-.795-5.64 3.47A1 1 0 0 0 2 13h12a1 1 0 0 0 .966-.741M1 11.105l4.708-2.897L1 5.383z"/>
                    </svg>
                    Email members (<?= count($result['emails']) ?>)
                </a>
                <?php endif; ?>

                <!-- ── Email report button — triggers modal ── -->
                <button type="button" class="btn btn-sm btn-outline-primary"
                        data-bs-toggle="modal" data-bs-target="#emailReportModal">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" class="me-1" viewBox="0 0 16 16">
                        <path d="M15 14s1 0 1-1-1-4-5-4-5 3-5 4 1 1 1 1zm-7.978-1A.261.261 0 0 1 7 12.996c.001-.264.167-1.03.76-1.72C8.312 10.629 9.282 10 11 10c1.717 0 2.687.63 3.24 1.276.593.69.758 1.457.76 1.72l-.008.002a.274.274 0 0 1-.014.002H7.022zM11 7a2 2 0 1 0 0-4 2 2 0 0 0 0 4m3-2a3 3 0 1 1-6 0 3 3 0 0 1 6 0M6.936 9.28a5.88 5.88 0 0 0-1.23-.247A7.35 7.35 0 0 0 5 9c-4 0-5 3-5 4 0 .667.333 1 1 1h4.216A2.238 2.238 0 0 1 5 13c0-1.01.377-2.042 1.09-2.904.243-.294.526-.569.846-.816M4.92 10A5.493 5.493 0 0 0 4 13H1c0-.26.164-1.03.76-1.724.545-.636 1.492-1.256 3.16-1.275ZM1.5 5.5a3 3 0 1 1 6 0 3 3 0 0 1-6 0m3-2a2 2 0 1 0 0 4 2 2 0 0 0 0-4"/>
                    </svg>
                    Email report
                </button>

                <!-- ── Standard export buttons ── -->
                <a href="reports.php?report=<?= urlencode($report) ?>&year=<?= $year ?>&member_type=<?= urlencode($memberTypeFilter) ?>&export=csv"
                   class="btn btn-sm btn-outline-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" class="me-1" viewBox="0 0 16 16">
                        <path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/>
                        <path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/>
                    </svg>CSV
                </a>
                <a href="reports.php?report=<?= urlencode($report) ?>&year=<?= $year ?>&member_type=<?= urlencode($memberTypeFilter) ?>&export=pdf"
                   class="btn btn-sm btn-outline-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" class="me-1" viewBox="0 0 16 16">
                        <path d="M14 14V4.5L9.5 0H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2zM9.5 3A1.5 1.5 0 0 0 11 4.5h2V14a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1h5.5z"/>
                    </svg>PDF
                </a>
                <button type="button" data-action="print" class="btn btn-sm btn-outline-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" class="me-1" viewBox="0 0 16 16">
                        <path d="M2.5 8a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1z"/>
                        <path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2zm6 14H5a1 1 0 0 1-1-1v-1h8v1a1 1 0 0 1-1 1M4 3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2H4zm1 5a2 2 0 0 0-2 2v1H2a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v-1a2 2 0 0 0-2-2z"/>
                    </svg>Print
                </button>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Stat summary cards ──────────────────────────────────────── -->
        <?php if (!empty($result['summary'])): ?>
        <div class="row g-3 mb-4 report-stats">
            <?php foreach ($result['summary'] as $stat):
                $colour = $stat['colour'] ?? 'secondary';
                $isLight = $colour === 'light' || $colour === 'secondary';
            ?>
            <div class="col-6 col-sm-4 col-xl-3">
                <div class="card stat-card h-100">
                    <div class="card-body p-3">
                        <div class="stat-value text-<?= $isLight ? 'body' : $colour ?>"><?= htmlspecialchars((string) $stat['value']) ?></div>
                        <div class="stat-label"><?= htmlspecialchars($stat['label']) ?></div>
                        <?php if (!empty($stat['sub'])): ?>
                        <div class="stat-sub text-muted"><?= htmlspecialchars($stat['sub']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ── Chart (revenue_by_year + member_type_breakdown) ─────────── -->
        <?php if (!empty($result['extra']['chart'])): ?>
        <div class="card shadow-sm mb-4 no-print">
            <div class="card-body">
                <canvas id="reportChart"
                        style="max-height:260px;"
                        aria-label="<?= htmlspecialchars($result['title']) ?> chart"></canvas>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── AMA/FAA: sectioned sub-tables ──────────────────────────── -->
        <?php if (!empty($result['extra']['sections'])): ?>
        <div class="report-sections">
            <?php foreach ($result['extra']['sections'] as $section):
                if (empty($section['rows'])) continue;
            ?>
            <div class="card shadow-sm mb-3">
                <div class="card-header d-flex align-items-center gap-2">
                    <span class="badge bg-<?= htmlspecialchars($section['colour']) ?>">&nbsp;</span>
                    <span class="fw-semibold"><?= htmlspecialchars($section['title']) ?></span>
                    <span class="badge bg-light text-dark border ms-1"><?= count($section['rows']) ?></span>
                </div>
                <div class="card-body p-0">
                    <?php renderTable($section['headers'], $section['rows']); ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php
            // If every section is empty, show a nice all-clear message
            $anyData = false;
            foreach ($result['extra']['sections'] as $s) {
                if (!empty($s['rows'])) { $anyData = true; break; }
            }
            if (!$anyData): ?>
            <div class="card shadow-sm">
                <div class="card-body text-center py-5">
                    <div class="text-success mb-2" style="font-size:2rem;">✓</div>
                    <p class="fw-semibold mb-1 text-success">All clear!</p>
                    <p class="text-muted small mb-0">No compliance issues found for active members.</p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <!-- ── Standard single table (plus optional waived-members detail) ─ -->
        <?php if (!empty($result['rows'])): ?>
        <div class="card shadow-sm mb-3">
            <div class="card-body p-0">
                <?php renderTable($result['headers'], $result['rows']); ?>
            </div>
        </div>

        <?php if ($report === 'waived_analysis' && !empty($result['extra']['waived_members']['rows'])): ?>
        <div class="card shadow-sm">
            <div class="card-header fw-semibold small">
                Free / Life members — <?= $year ?>
                <span class="badge bg-light text-dark border ms-1">
                    <?= count($result['extra']['waived_members']['rows']) ?>
                </span>
            </div>
            <div class="card-body p-0">
                <?php renderTable(
                    $result['extra']['waived_members']['headers'],
                    $result['extra']['waived_members']['rows']
                ); ?>
            </div>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <p class="fw-semibold mb-1">No data</p>
                <p class="text-muted small mb-0">
                    No results for this report and year.
                    Try selecting a different year using the options panel.
                </p>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

    </div><!-- /.col main -->
</div><!-- /.row -->

<!-- ── Styles ────────────────────────────────────────────────────────────── -->
<style<?= csp_nonce_attr() ?>>
/* Sidebar navigation */
.reports-sidebar .report-group { padding: 0.5rem 0; border-bottom: 1px solid #eee; }
.reports-sidebar .report-group:last-child { border-bottom: none; }
.report-group-label {
    font-size: 10px; font-weight: 700; letter-spacing: 0.12em;
    text-transform: uppercase; color: #888;
    padding: 0.4rem 1rem 0.2rem;
}
.report-nav-link {
    display: block; padding: 0.35rem 1rem;
    font-size: 0.875rem; text-decoration: none;
    color: var(--bs-body-color);
    border-left: 3px solid transparent;
    transition: background 0.1s, color 0.1s;
}
.report-nav-link:hover { background: rgba(var(--club-primary-rgb), 0.06); color: var(--club-primary); }
.report-nav-link.active {
    background: rgba(var(--club-primary-rgb), 0.10);
    color: var(--club-primary);
    border-left-color: var(--club-primary);
    font-weight: 600;
}

/* Stat cards */
.stat-card { border: 1px solid #e9ecef; }
.stat-value { font-size: 1.75rem; font-weight: 700; line-height: 1.1; }
.stat-label { font-size: 0.8rem; font-weight: 600; color: #555; margin-top: 0.2rem; }
.stat-sub   { font-size: 0.75rem; margin-top: 0.1rem; }

/* Table inside card */
.report-sections .card-header { background: #fafafa; padding: 0.6rem 1rem; }

/* Print styles */
@media print {
    .no-print, nav.navbar, .reports-sidebar, .card.shadow-sm.mt-3,
    .col-lg-3, .col-md-4, canvas { display: none !important; }
    .col-lg-9, .col-md-8 { flex: 0 0 100%; max-width: 100%; }
    .stat-card { border: 1px solid #ccc !important; break-inside: avoid; }
    .report-stats .col-6 { flex: 0 0 25%; max-width: 25%; }
    table { font-size: 10px; }
    th, td { padding: 4px 6px !important; }
}
</style>

<!-- ── Chart.js (lazy-loaded only when a chart is requested) ─────────────── -->
<?php if (!empty($result['extra']['chart'])): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script<?= csp_nonce_attr() ?>>
(function () {
    'use strict';
    /** @type {Object} */
    var chartData = <?= json_encode($result['extra']['chart']) ?>;
    var ctx = document.getElementById('reportChart');
    if (!ctx || !chartData) return;

    var type = chartData.type; // 'bar' or 'doughnut'

    if (type === 'bar') {
        // Stacked bar for revenue
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: chartData.labels,
                datasets: chartData.datasets.map(function (ds) {
                    return {
                        label: ds.label,
                        data: ds.data,
                        backgroundColor: ds.color,
                        borderRadius: 3,
                    };
                }),
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ctx.dataset.label + ': $' + ctx.parsed.y.toLocaleString('en-US', { minimumFractionDigits: 2 });
                            },
                        },
                    },
                },
                scales: {
                    x: { stacked: true },
                    y: {
                        stacked: true,
                        ticks: {
                            callback: function (v) { return '$' + v.toLocaleString(); },
                        },
                    },
                },
            },
        });
    } else if (type === 'doughnut') {
        // Donut for member type breakdown
        var ds = chartData.datasets[0];
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: chartData.labels,
                datasets: [{
                    data: ds.data,
                    backgroundColor: ds.colors,
                    borderWidth: 2,
                    borderColor: '#fff',
                }],
            },
            options: {
                responsive: true,
                cutout: '60%',
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                var pct   = total > 0 ? Math.round(ctx.parsed / total * 100) : 0;
                                return ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                            },
                        },
                    },
                },
            },
        });
    }
})();
</script>
<?php endif; ?>

<?php if (!empty($emailReportError)): ?>
<!-- Re-open modal automatically if there was a send error -->
<script<?= csp_nonce_attr() ?>>
document.addEventListener('DOMContentLoaded', function () {
    var modal = new bootstrap.Modal(document.getElementById('emailReportModal'));
    modal.show();
});
</script>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════════
     Email Report Modal — send the current report as a formatted email
     to any address. Available on all reports with data rows.
     ════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="emailReportModal" tabindex="-1" aria-labelledby="emailReportModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post"
                  action="reports.php?report=<?= urlencode($report) ?>&year=<?= $year ?><?= $memberTypeFilter ? '&member_type=' . urlencode($memberTypeFilter) : '' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="email_report">

                <div class="modal-header">
                    <h5 class="modal-title" id="emailReportModalLabel">Email report</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <?php if (!empty($emailReportError)): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($emailReportError) ?></div>
                    <?php endif; ?>

                    <p class="text-muted small mb-3">
                        Sends <strong><?= htmlspecialchars($result['title']) ?></strong>
                        (<?= count($result['rows']) ?> row<?= count($result['rows']) !== 1 ? 's' : '' ?>)
                        as a formatted HTML email — stat summary + full table.
                    </p>

                    <div class="mb-3">
                        <label for="emailReportTo" class="form-label">To <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="emailReportTo" name="to_email"
                               value="<?= htmlspecialchars($_POST['to_email'] ?? '') ?>"
                               placeholder="president@yourclub.org"
                               required autofocus>
                        <div class="form-text">Enter any email address — board member, yourself, etc.</div>
                    </div>

                    <div class="mb-0">
                        <label for="emailReportNote" class="form-label">Note <span class="text-muted">(optional)</span></label>
                        <textarea class="form-control" id="emailReportNote" name="note"
                                  rows="3"
                                  placeholder="Add a note that appears above the report table…"><?= htmlspecialchars($_POST['note'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Send report</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>