<?php
/**
 * reports.php — Reports module.
 *
 * Renders a selected report (membership/revenue/etc.) as a table and offers a
 * CSV download. All report data comes from includes/run_report.php so the page
 * itself stays presentation-only.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/run_report.php';

requireLogin();
if (!canViewReports()) {
    header('Location: index.php');
    exit;
}

$registry = reportRegistry();
$slug     = (string) ($_GET['report'] ?? reportDefaultSlug());
if (!reportExists($slug) || !reportVisibleToUser($slug)) {
    $slug = reportDefaultSlug();
    foreach ($registry as $rSlug => $_meta) {
        if (reportVisibleToUser($rSlug)) {
            $slug = $rSlug;
            break;
        }
    }
}

// Resolve year for reports that take one (clamped to available range).
$needsYear = reportNeedsYear($slug);
$minYear   = reportEarliestYear($pdo);
$maxYear   = reportMaxSelectableYear($pdo);
$year      = reportDefaultYear($pdo, $slug);
if ($needsYear) {
    $yearRaw = (string) ($_GET['year'] ?? '');
    if (preg_match('/^\d{4}$/', $yearRaw)) {
        $candidate = (int) $yearRaw;
        if ($candidate >= $minYear && $candidate <= $maxYear) {
            $year = $candidate;
        }
    }
}

$report = runReport($pdo, $slug, ['year' => $year]);

// ── PDF export ───────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'pdf') {
    require_once __DIR__ . '/includes/report_pdf.php';
    require_once __DIR__ . '/includes/flash.php';

    if (!reportPdfAvailable()) {
        flash('PDF export is unavailable on this server (Dompdf is not installed). Run "composer install".', 'warning');
        header('Location: reports.php?report=' . urlencode($slug) . ($needsYear ? '&year=' . (int) $year : ''));
        exit;
    }

    $club = [];
    try {
        $club = $pdo->query(
            'SELECT name, logo_path, color_primary, color_primary_dark FROM club WHERE id = 1 LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $club = [];
    }

    renderReportPdf($report, $club, $needsYear ? $year : null);
    exit;
}

// ── CSV export ───────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $filename = 'report_' . $slug . ($needsYear ? '_' . $year : '') . '_' . date('Y-m-d') . '.csv';
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, array_map(static fn($c) => $c['label'], $report['columns']), ',', '"', '\\');
    foreach ($report['rows'] as $row) {
        $line = [];
        foreach ($report['columns'] as $col) {
            $line[] = reportFormatCell($row[$col['key']] ?? null, $col['format'], true);
        }
        fputcsv($out, $line, ',', '"', '\\');
    }
    if (!empty($report['totals'])) {
        $line = [];
        foreach ($report['columns'] as $col) {
            $line[] = reportFormatCell($report['totals'][$col['key']] ?? null, $col['format'], true);
        }
        fputcsv($out, $line, ',', '"', '\\');
    }
    if (!empty($report['note']) || !empty($report['accuracy_note'])) {
        fputcsv($out, [], ',', '"', '\\');
        if (!empty($report['note'])) {
            fputcsv($out, [$report['note']], ',', '"', '\\');
        }
        if (!empty($report['accuracy_note'])) {
            fputcsv($out, ['Note: ' . $report['accuracy_note']], ',', '"', '\\');
        }
    }
    fclose($out);
    exit;
}

$pageTitle   = 'Reports';
$breadcrumbs = [['label' => 'Reports', 'url' => '']];
require_once __DIR__ . '/includes/page_header.php';

/** Map a column alignment keyword to a Bootstrap text utility. */
$alignClass = static fn(string $a): string => $a === 'end' ? 'text-end' : 'text-start';

$canEmailReport  = !empty($report['rows']);
$canEmailMembers = $canEmailReport
    && reportSupportsCohortEmail($slug)
    && (canEditMembers() || canProcessMemberships());

$yearQs = $needsYear ? '&amp;year=' . (int) $year : '';
ob_start();
?>
        <a class="btn btn-primary btn-sm" href="board_packet.php">Board packet</a>
        <?php if ($needsYear): ?>
        <form method="get" action="reports.php" class="d-flex align-items-center gap-1">
            <input type="hidden" name="report" value="<?= h($slug) ?>">
            <label for="reportYear" class="form-label mb-0 small text-muted">Year</label>
            <select id="reportYear" name="year" class="form-select form-select-sm w-auto js-submit-on-change">
                <?php for ($y = $maxYear; $y >= $minYear; $y--): ?>
                <option value="<?= $y ?>"<?= $y === $year ? ' selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </form>
        <?php endif; ?>
        <a class="btn btn-outline-primary btn-sm"
           href="reports.php?report=<?= h($slug) ?><?= $yearQs ?>&amp;export=csv">
            Export CSV
        </a>
        <a class="btn btn-outline-primary btn-sm"
           href="reports.php?report=<?= h($slug) ?><?= $yearQs ?>&amp;export=pdf">
            Export PDF
        </a>
        <?php if ($canEmailReport): ?>
        <button type="button" class="btn btn-outline-primary btn-sm"
                data-bs-toggle="modal" data-bs-target="#reportEmailModal">
            Email report
        </button>
        <?php endif; ?>
        <?php if ($canEmailMembers): ?>
        <button type="button" class="btn btn-outline-primary btn-sm"
                data-bs-toggle="modal" data-bs-target="#reportMembersEmailModal">
            Email members
        </button>
        <?php endif; ?>
<?php
$reportsHeaderActions = ob_get_clean();

require_once __DIR__ . '/includes/header.php';

render_page_header([
    'title'   => 'Reports',
    'actions' => $reportsHeaderActions,
]);
?>

<div class="row g-3">
    <!-- Report picker -->
    <div class="col-12 col-lg-3">
        <nav class="card mb-3 shadow-sm" aria-label="Report types">
            <div class="card-body p-0">
                <?php foreach ($registry as $rSlug => $meta):
                    if (!reportVisibleToUser($rSlug)) {
                        continue;
                    }
                ?>
                <a href="reports.php?report=<?= h($rSlug) ?>"
                   class="sidebar-nav-link<?= $rSlug === $slug ? ' active' : '' ?>">
                    <div class="fw-semibold"><?= h($meta['label']) ?></div>
                    <div class="small <?= $rSlug === $slug ? '' : 'text-muted' ?>"><?= h($meta['description']) ?></div>
                </a>
                <?php endforeach; ?>
                <div class="border-top">
                    <a href="board_packet.php"
                       class="sidebar-nav-link<?= navActive('board_packet.php') ?>">
                        <div class="fw-semibold">Board packet</div>
                        <div class="small text-muted">Monthly summary for the board — roster, renewals, revenue, incidents.</div>
                    </a>
                </div>
            </div>
        </nav>
    </div>

    <!-- Report body -->
    <div class="col-12 col-lg-9">
        <?php if (!empty($report['accuracy_note'])): ?>
        <div class="alert alert-warning d-flex align-items-start gap-2 py-2 px-3 mb-3" role="alert">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" class="flex-shrink-0 mt-1" viewBox="0 0 16 16">
                <path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767L8.982 1.566zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5zm.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/>
            </svg>
            <div class="small"><?= h($report['accuracy_note']) ?></div>
        </div>
        <?php endif; ?>
        <div class="card">
            <div class="card-header py-2">
                <span class="fw-semibold"><?= h($report['title']) ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($report['rows'])): ?>
                <p class="text-muted m-3 mb-0">No data available for this report yet.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 report-table">
                        <thead class="table-light">
                            <tr>
                                <?php foreach ($report['columns'] as $col):
                                    $colClass = reportColumnClass($col);
                                ?>
                                <th class="<?= $alignClass($col['align'] ?? 'start') ?><?= $colClass !== '' ? ' ' . $colClass : '' ?>"><?= h($col['label']) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report['rows'] as $row): ?>
                            <tr>
                                <?php foreach ($report['columns'] as $col):
                                    $key   = $col['key'];
                                    $fmt   = $col['format'];
                                    $raw   = $row[$key] ?? null;
                                    $cls   = $alignClass($col['align'] ?? 'start');
                                    $colClass = reportColumnClass($col);
                                    if ($colClass !== '') {
                                        $cls .= ' ' . $colClass;
                                    }
                                    if ($fmt === 'signed' && $raw !== null && $raw !== '') {
                                        $n   = (int) $raw;
                                        $cls .= $n > 0 ? ' text-success' : ($n < 0 ? ' text-danger' : ' text-muted');
                                    }
                                    $cellHtml = h(reportFormatCell($raw, $fmt, false));
                                    if ($slug === 'possible_duplicates' && $key === 'members') {
                                        $parts = [];
                                        foreach (explode(';', (string) ($row['members'] ?? '')) as $chunk) {
                                            $chunk = trim($chunk);
                                            if ($chunk === '') {
                                                continue;
                                            }
                                            if (preg_match('/^(.*)\(#(\d+)\)$/', $chunk, $m)) {
                                                $parts[] = '<a href="member_edit.php?id=' . (int) $m[2] . '" class="text-decoration-none">'
                                                    . h(trim($m[1])) . ' <span class="text-muted">(#' . (int) $m[2] . ')</span></a>';
                                            } else {
                                                $parts[] = h($chunk);
                                            }
                                        }
                                        $cellHtml = implode('<br>', $parts);
                                    }
                                ?>
                                <td class="<?= $cls ?>"><?= $cellHtml ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if (!empty($report['totals'])): ?>
                        <tfoot>
                            <tr class="fw-semibold border-top">
                                <?php foreach ($report['columns'] as $col):
                                    $colClass = reportColumnClass($col);
                                    $footCls  = $alignClass($col['align'] ?? 'start');
                                    if ($colClass !== '') {
                                        $footCls .= ' ' . $colClass;
                                    }
                                ?>
                                <td class="<?= $footCls ?>">
                                    <?= h(reportFormatCell($report['totals'][$col['key']] ?? null, $col['format'], false)) ?>
                                </td>
                                <?php endforeach; ?>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            <?php if (!empty($report['note'])): ?>
            <div class="card-footer text-muted small py-2"><?= h($report['note']) ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($canEmailReport): ?>
<div class="modal fade" id="reportEmailModal" tabindex="-1" aria-labelledby="reportEmailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="report_email.php"
                  data-email-sending data-email-sending-title="Emailing report">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="snapshot">
                <input type="hidden" name="report" value="<?= h($slug) ?>">
                <?php if ($needsYear): ?><input type="hidden" name="year" value="<?= (int) $year ?>"><?php endif; ?>
                <div class="modal-header">
                    <h2 class="modal-title h5" id="reportEmailModalLabel">Email this report</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-3">
                        Sends <strong><?= h($report['title']) ?></strong> as an HTML table.
                        Multiple addresses receive one message (all on the To line).
                    </p>
                    <div class="mb-3">
                        <label class="form-label" for="reportEmailTo">Send to</label>
                        <input type="text" id="reportEmailTo" name="to" class="form-control"
                               placeholder="board@club.org, treasurer@club.org" required
                               autocomplete="email" autofocus>
                        <div class="form-text">Comma- or semicolon-separated addresses.</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="reportEmailNote">Note <span class="text-muted fw-normal">(optional)</span></label>
                        <textarea id="reportEmailNote" name="note" class="form-control" rows="3"
                                  placeholder="Short message to include above the table"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Send email</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canEmailMembers): ?>
<div class="modal fade" id="reportMembersEmailModal" tabindex="-1" aria-labelledby="reportMembersEmailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="post" action="report_email.php"
                  data-email-sending data-email-sending-title="Emailing members">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="members">
                <input type="hidden" name="report" value="<?= h($slug) ?>">
                <?php if ($needsYear): ?><input type="hidden" name="year" value="<?= (int) $year ?>"><?php endif; ?>
                <div class="modal-header">
                    <h2 class="modal-title h5" id="reportMembersEmailModalLabel">Email these members</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-3">
                        Sends one personalized message to each member in this report who has email turned on.
                        Tokens: <code>{first_name}</code>, <code>{last_name}</code>, <code>{club_name}</code>.
                    </p>
                    <div class="mb-3">
                        <label class="form-label" for="reportMembersSubject">Subject</label>
                        <input type="text" id="reportMembersSubject" name="subject" class="form-control"
                               value="<?= h('Membership renewal reminder') ?>" required>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="reportMembersMessage">Message</label>
                        <textarea id="reportMembersMessage" name="message" rows="8" class="form-control" required>Hi {first_name},

Our records show you haven't renewed your {club_name} membership yet. We'd love to have you back for another season — please renew at your convenience.

Thank you!</textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Send to members</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
