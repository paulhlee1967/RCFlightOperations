<?php
/**
 * board_packet.php — Monthly board packet preview, PDF download, and email.
 *
 * Access: any role with canViewReports().
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/flash.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/audit_log.php';
require_once __DIR__ . '/includes/installation_config.php';
require_once __DIR__ . '/includes/board_packet.php';

requireLogin();
if (!canViewReports()) {
    header('Location: index.php');
    exit;
}

$packet = buildBoardPacket($pdo);

// ── PDF export ───────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'pdf') {
    require_once __DIR__ . '/includes/report_pdf.php';

    if (!reportPdfAvailable()) {
        flash('PDF export is unavailable on this server (Dompdf is not installed). Run "composer install".', 'warning');
        header('Location: board_packet.php');
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

    renderBoardPacketPdf($packet, $club);
    exit;
}

// ── Email packet ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    $action = (string) ($_POST['action'] ?? '');
    if ($action !== 'email') {
        flash('Unknown action.', 'danger');
        header('Location: board_packet.php');
        exit;
    }

    $addresses = board_packet_parse_addresses((string) ($_POST['to'] ?? ''));
    if ($addresses === []) {
        flash('Enter at least one valid email address.', 'warning');
        header('Location: board_packet.php');
        exit;
    }

    $note = trim((string) ($_POST['note'] ?? ''));
    if ($note !== '') {
        $packet['intro_note'] = $note;
    }

    $mailCfg = installation_mail_config($pdo);
    $result  = board_packet_send_email($pdo, $packet, $addresses, $mailCfg);

    $addrList = implode(', ', $addresses);
    audit_log(
        $pdo,
        currentUserId(),
        'board_packet_email',
        'board_packet',
        0,
        json_encode([
            'period'     => $packet['period_label'] ?? '',
            'recipients' => $addrList,
            'sent'       => $result['sent'],
            'failed'     => $result['failed'],
        ])
    );

    if ($result['sent'] > 0 && $result['failed'] === 0) {
        flash('Board packet emailed to ' . $result['sent'] . ' address' . ($result['sent'] !== 1 ? 'es' : '') . '.', 'success');
    } elseif ($result['sent'] > 0) {
        flash('Board packet emailed to ' . $result['sent'] . ' address(es); ' . $result['failed'] . ' failed.', 'warning');
    } else {
        flash('Could not send the board packet.' . ($result['error'] ? ' ' . $result['error'] : ''), 'danger');
    }
    header('Location: board_packet.php');
    exit;
}

$pageTitle   = 'Board packet';
$breadcrumbs = [
    ['label' => 'Reports', 'url' => 'reports.php'],
    ['label' => 'Board packet', 'url' => ''],
];
require_once __DIR__ . '/includes/page_header.php';

$alignClass = static fn(string $a): string => $a === 'end' ? 'text-end' : 'text-start';

ob_start();
?>
        <a class="btn btn-outline-secondary btn-sm" href="reports.php">← Reports</a>
        <a class="btn btn-outline-primary btn-sm" href="board_packet.php?export=pdf">
            Download PDF
        </a>
        <button type="button" class="btn btn-outline-primary btn-sm"
                data-bs-toggle="modal" data-bs-target="#boardPacketEmailModal">
            Email packet
        </button>
<?php
$headerActions = ob_get_clean();

require_once __DIR__ . '/includes/header.php';

render_page_header([
    'title'       => 'Board packet',
    'subtitle'    => h($packet['period_label'] ?? '') . ' · Generated ' . h($packet['generated_at'] ?? ''),
    'actions'     => $headerActions,
]);
?>

<p class="text-muted small mb-4">
    One-click monthly summary for the board: roster counts, renewal progress, current-year revenue,
    and open safety incidents. Renewal detail is summarized with a link to the full report.
</p>

<?php foreach ($packet['sections'] as $section):
    $report = $section['report'] ?? [];
    $reportPath = trim((string) ($report['report_path'] ?? ($section['report_path'] ?? '')));
    $reportLabel = trim((string) ($report['report_link_label'] ?? 'View full not-yet-renewed report'));
?>
<div class="card mb-3">
    <div class="card-header py-2 d-flex align-items-center justify-content-between">
        <span class="fw-semibold"><?= h($section['title'] ?? ($report['title'] ?? 'Section')) ?></span>
        <?php if (isset($section['count'])): ?>
        <span class="badge badge-club"><?= (int) $section['count'] ?> not yet renewed</span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($report['rows'])): ?>
        <p class="text-muted m-3 mb-0">No data for this section.</p>
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
                            $key = $col['key'];
                            $fmt = $col['format'];
                            $raw = $row[$key] ?? null;
                            $cls = $alignClass($col['align'] ?? 'start');
                            $colClass = reportColumnClass($col);
                            if ($colClass !== '') {
                                $cls .= ' ' . $colClass;
                            }
                            if ($fmt === 'signed' && $raw !== null && $raw !== '') {
                                $n = (int) $raw;
                                $cls .= $n > 0 ? ' text-success' : ($n < 0 ? ' text-danger' : ' text-muted');
                            }
                        ?>
                        <td class="<?= $cls ?>"><?= h(reportFormatCell($raw, $fmt, false)) ?></td>
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
    <?php if ($reportPath !== ''): ?>
    <div class="card-footer py-2">
        <a href="<?= h($reportPath) ?>" class="small fw-semibold"><?= h($reportLabel) ?> →</a>
    </div>
    <?php endif; ?>
    <?php if (!empty($report['note'])): ?>
    <div class="card-footer text-muted small py-2"><?= h($report['note']) ?></div>
    <?php endif; ?>
    <?php if (!empty($report['accuracy_note'])): ?>
    <div class="card-footer py-2">
        <div class="alert alert-warning small mb-0 py-2"><?= h($report['accuracy_note']) ?></div>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<div class="modal fade" id="boardPacketEmailModal" tabindex="-1" aria-labelledby="boardPacketEmailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="board_packet.php"
                  data-email-sending data-email-sending-title="Emailing board packet">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="email">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="boardPacketEmailModalLabel">Email board packet</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-3">
                        Sends <strong><?= h($packet['title'] ?? 'Board packet') ?></strong>
                        for <strong><?= h($packet['period_label'] ?? '') ?></strong> as branded HTML
                        (all sections). Multiple addresses receive one message.
                    </p>
                    <div class="mb-3">
                        <label class="form-label" for="boardPacketEmailTo">Send to</label>
                        <input type="text" id="boardPacketEmailTo" name="to" class="form-control"
                               placeholder="board@club.org, treasurer@club.org" required
                               autocomplete="email" autofocus>
                        <div class="form-text">Comma- or semicolon-separated addresses.</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="boardPacketEmailNote">Optional note</label>
                        <textarea id="boardPacketEmailNote" name="note" class="form-control" rows="3"
                                  placeholder="Add a short message for the board…"></textarea>
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>
