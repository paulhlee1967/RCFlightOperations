<?php
/**
 * Report email sender.
 *
 * Displays a compose form (GET) or sends individual emails to every member
 * in the chosen report (POST), using the existing send_mail() + render_email_template()
 * framework from includes/mail.php and includes/email_templates.php.
 *
 * URL: report_email.php?report=renewal_not_renewed&year=2025
 *
 * Recipients come from reportEmailRecipientRows() in includes/run_report.php
 * (same data as the on-screen report). Only reports that have
 * email addresses are supported; others redirect back.
 *
 * Auth: requires canViewReports() (editor, treasurer, admin, viewer).
 *       Only admin/editor can actually send; viewers see a read-only preview.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/flash.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/email_templates.php';
require_once __DIR__ . '/includes/run_report.php';

requireLogin();
if (!canViewReports()) {
    header('Location: index.php');
    exit;
}

requireFeature('report_email');
$currentYear = (int) date('Y');

// ── Report types that expose an email list ────────────────────────────────────
// Keys must match reports.php $reportTypes.
$emailableReports = [
    'renewal_not_renewed' => 'Not Yet Renewed',
    'birthdays_this_month'=> 'Birthdays This Month',
    'ama_faa_expiring'    => 'AMA/FAA Compliance Issues',
    'gate_key_compliance' => 'Gate Key Compliance',
];

$report = $_REQUEST['report'] ?? '';
$year   = isset($_REQUEST['year']) ? max(2000, min(2100, (int) $_REQUEST['year'])) : $currentYear;

if (!isset($emailableReports[$report])) {
    flash('That report type does not have an email list.', 'warning');
    header('Location: reports.php');
    exit;
}

$reportLabel = $emailableReports[$report] . ($year ? " — $year" : '');

// ── Load club name for template ─────────────────────────────────────────────
$stmt = $pdo->query('SELECT name FROM club WHERE id = 1');
$clubRow  = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
$clubName = $clubRow['name'] ?? 'RC Flight Operations';

$membershipTypeLabels = enabledMembershipTypeLabels($pdo);
$recipients           = reportEmailRecipientRows($pdo, $report, $year, $membershipTypeLabels, []);

// ── POST: send emails ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    // Only editors and admins can actually send
    if (!canEditMembers()) {
        flash('You do not have permission to send emails.', 'danger');
        header('Location: reports.php?report=' . urlencode($report) . '&year=' . $year);
        exit;
    }

    $customMessage = trim($_POST['custom_message'] ?? '');
    if ($customMessage === '') {
        $error = 'Please enter a message before sending.';
    } else {
        $adminName = $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'RC Flight Operations admin';
        $sent      = 0;
        $failed    = 0;
        $skipped   = 0;

        foreach ($recipients as $member) {
            $email = trim($member['email'] ?? '');
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                continue;
            }

            $vars = [
                'first_name'     => $member['first_name'] ?? '',
                'last_name'      => $member['last_name']  ?? '',
                'club_name'      => $clubName,
                'report_label'   => $reportLabel,
                'custom_message' => $customMessage,
                'admin_name'     => $adminName,
            ];

            try {
                $rendered = render_email_template('report_list', $vars);
                $ok = send_mail($email, $rendered['subject'], $rendered['html'], $rendered['text']);
                $ok ? $sent++ : $failed++;
            } catch (Throwable $e) {
                $failed++;
            }
        }

        // Build result message
        $parts = [];
        if ($sent > 0)    $parts[] = "$sent sent";
        if ($failed > 0)  $parts[] = "$failed failed";
        if ($skipped > 0) $parts[] = "$skipped skipped (no valid email)";

        $flashType = $failed > 0 ? ($sent > 0 ? 'warning' : 'danger') : 'success';
        flash(implode(', ', $parts) . '.', $flashType);
        header('Location: reports.php?report=' . urlencode($report) . '&year=' . $year);
        exit;
    }
}

// ── Page render ───────────────────────────────────────────────────────────────
$breadcrumbs = [
    ['label' => 'Reports', 'url' => 'reports.php?report=' . urlencode($report) . '&year=' . $year],
    ['label' => 'Email list'],
];
$pageTitle = 'Email: ' . $emailableReports[$report];
require_once __DIR__ . '/includes/header.php';

?>

<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
    <div>
        <h1 class="h2 mb-0">Email list</h1>
        <p class="text-muted mb-0"><?= h($reportLabel) ?></p>
    </div>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<?php if (count($recipients) === 0): ?>
<!-- Empty state -->
<div class="card">
    <div class="card-body text-center py-5">
        <p class="text-muted mb-3">No members with email addresses in this report.</p>
        <a href="reports.php?report=<?= urlencode($report) ?>&year=<?= $year ?>" class="btn btn-outline-secondary">
            ← Back to report
        </a>
    </div>
</div>

<?php else: ?>

<div class="row g-4">
    <!-- ── Compose form ── -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header fw-semibold">Compose message</div>
            <div class="card-body">
                <?php if (!canEditMembers()): ?>
                <div class="alert alert-warning mb-3">
                    You have view-only access. You can preview the recipient list but cannot send emails.
                </div>
                <?php endif; ?>

                <form method="post"
                      action="report_email.php?report=<?= urlencode($report) ?>&year=<?= $year ?>"
                      id="composeForm">
                    <?= csrf_field() ?>

                    <!-- From / To summary (read-only) -->
                    <div class="mb-3">
                        <label class="form-label text-muted small">From</label>
                        <div class="form-control-plaintext">
                            <?php
                            global $mailFromName, $mailFromAddress;
                            echo h(($mailFromName ?? $clubName) . ' <' . ($mailFromAddress ?? '') . '>');
                            ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted small">To</label>
                        <div class="form-control-plaintext">
                            <?= count($recipients) ?> member<?= count($recipients) !== 1 ? 's' : '' ?>
                            <span class="text-muted">(individually addressed)</span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted small">Subject</label>
                        <div class="form-control-plaintext">
                            <?= h($clubName . ' – ' . $reportLabel) ?>
                        </div>
                    </div>

                    <!-- Greeting note -->
                    <div class="mb-3 p-2 border rounded bg-light small text-muted">
                        Each email will begin with <strong>"Hi [First Name],"</strong> and end with your name
                        and the club name. Write the body of your message below.
                    </div>

                    <!-- Message body -->
                    <div class="mb-4">
                        <label for="custom_message" class="form-label">Message</label>
                        <textarea class="form-control" id="custom_message" name="custom_message"
                                  rows="8" placeholder="Type your message here…"
                                  <?= !canEditMembers() ? 'disabled' : '' ?>
                                  required><?= h($_POST['custom_message'] ?? '') ?></textarea>
                    </div>

                    <div class="d-flex gap-2 flex-wrap">
                        <?php if (canEditMembers()): ?>
                        <button type="submit" class="btn btn-primary" id="sendBtn">
                            Send to <?= count($recipients) ?> member<?= count($recipients) !== 1 ? 's' : '' ?>
                        </button>
                        <?php endif; ?>
                        <a href="reports.php?report=<?= urlencode($report) ?>&year=<?= $year ?>"
                           class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ── Recipient list preview ── -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Recipients</span>
                <span class="badge bg-secondary"><?= count($recipients) ?></span>
            </div>
            <div class="card-body p-0">
                <div style="max-height: 420px; overflow-y: auto;">
                    <ul class="list-group list-group-flush">
                        <?php foreach ($recipients as $m): ?>
                        <li class="list-group-item py-2 px-3">
                            <div class="fw-medium small"><?= h(trim($m['last_name'] . ', ' . $m['first_name'])) ?></div>
                            <div class="text-muted" style="font-size: 0.8rem;"><?= h($m['email']) ?></div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Also offer email CSV download as a lightweight alternative -->
        <div class="mt-3 text-end">
            <a href="reports.php?report=<?= urlencode($report) ?>&year=<?= $year ?>&export=email_csv"
               class="btn btn-sm btn-outline-secondary">
                Download email CSV instead
            </a>
        </div>
    </div>
</div>

<!-- Confirm before sending to large lists -->
<script<?= csp_nonce_attr() ?>>
(function () {
    'use strict';
    var form   = document.getElementById('composeForm');
    var btn    = document.getElementById('sendBtn');
    var count  = <?= count($recipients) ?>;

    if (!form || !btn) return;

    form.addEventListener('submit', function (e) {
        // Warn for large sends (>20 recipients)
        if (count > 20) {
            if (!confirm('Send to ' + count + ' members? This cannot be undone.')) {
                e.preventDefault();
                return;
            }
        }
        // Disable button to prevent double-send
        btn.disabled = true;
        btn.textContent = 'Sending…';
    });
})();
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>