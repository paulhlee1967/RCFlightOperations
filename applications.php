<?php
/**
 * applications.php — Review WPForms membership applications (pending queue).
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/flash.php';
require_once __DIR__ . '/includes/wpforms_application.php';
require_once __DIR__ . '/includes/membership_application.php';
require_once __DIR__ . '/includes/dues_helpers.php';

requireLogin();
if (!canEditMembers() && !canProcessMemberships()) {
    header('Location: index.php');
    exit;
}

$membershipTypeLabels = enabledMembershipTypeLabels($pdo);
$userId = currentUserId();
$viewId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$listFilters = application_parse_list_filters($pdo, $_GET);
$statusFilter = $listFilters['status'];
$yearFilter   = $listFilters['year'];
$searchQ      = $listFilters['search'];
$defaultRenewalYear = defaultRenewalYear($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $action = (string) ($_POST['action'] ?? '');
    $appId  = (int) ($_POST['application_id'] ?? 0);

    if ($action === 'approve' && $appId > 0) {
        $overrideMemberId = isset($_POST['matched_member_id']) && $_POST['matched_member_id'] !== ''
            ? (int) $_POST['matched_member_id']
            : null;
        $renewalType = trim((string) ($_POST['renewal_type'] ?? ''));
        $renewalYear = isset($_POST['renewal_year']) && $_POST['renewal_year'] !== ''
            ? (int) $_POST['renewal_year']
            : null;
        if ($renewalType === '') {
            $renewalType = null;
        }

        $result = application_approve(
            $pdo,
            $appId,
            $userId,
            $overrideMemberId,
            $renewalType,
            $renewalYear,
            is_array($_POST['field_choice'] ?? null) ? $_POST['field_choice'] : []
        );
        if (!$result['ok']) {
            flash($result['error'] ?? 'Could not approve application.', 'warning');
            header('Location: applications.php?id=' . $appId);
            exit;
        }

        $memberId = (int) $result['member_id'];
        $rtype    = urlencode((string) ($result['renewal_type'] ?? 'new'));
        $ryear    = (int) ($result['renewal_year'] ?? defaultRenewalYear($pdo));
        flash('Application approved. Continue with signup/renewal recording.', 'success');
        if (array_key_exists('photo_imported', $result) && $result['photo_imported'] === false) {
            flash('Badge photo could not be copied from the website — upload it on the member record before printing.', 'warning');
        }
        if (array_key_exists('faa_card_imported', $result) && $result['faa_card_imported'] === false) {
            flash('FAA registration card could not be copied from the website — upload it on the member Compliance tab.', 'warning');
        }
        header('Location: member_process.php?id=' . $memberId . '&year=' . $ryear . '&renewal_type=' . $rtype . '&application_id=' . $appId . '#record');
        exit;
    }

    if ($action === 'reject' && $appId > 0) {
        $reason = trim((string) ($_POST['rejection_reason'] ?? ''));
        $result = application_reject($pdo, $appId, $userId, $reason);
        if (!$result['ok']) {
            flash($result['error'] ?? 'Could not reject application.', 'warning');
        } else {
            flash('Application rejected.', 'success');
        }
        header('Location: ' . application_list_page_url('rejected', $yearFilter, $searchQ, $defaultRenewalYear, ['id' => $appId]));
        exit;
    }
}

$pageTitle = 'Applications';
$breadcrumbs = [
    ['label' => 'Members', 'url' => 'members.php'],
    ['label' => 'Applications', 'url' => 'applications.php'],
];

$application = null;
$candidates  = [];
$diff        = [];

if ($viewId > 0) {
    $application = application_fetch($pdo, $viewId);
    if ($application === null) {
        flash('Application not found.', 'warning');
        header('Location: ' . application_list_page_url($statusFilter, $yearFilter, $searchQ, $defaultRenewalYear));
        exit;
    }
    $diff = application_member_diff($pdo, $application);
    if ($application['match_confidence'] === 'ambiguous') {
        $match = member_match_find(
            $pdo,
            $application['ama_number'],
            $application['first_name'],
            $application['last_name'],
            $application['email'],
            $application['birthday']
        );
        if ($match['candidate_ids'] !== []) {
            $in = implode(',', array_fill(0, count($match['candidate_ids']), '?'));
            $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, ama_number FROM members WHERE id IN ($in) ORDER BY last_name, first_name");
            $stmt->execute($match['candidate_ids']);
            $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

$whereClause = application_list_where_clause([
    'status' => $statusFilter,
    'year'   => $yearFilter,
    'search' => $searchQ,
]);
$where = $whereClause['where'];
$params = $whereClause['params'];

$perPage = application_list_per_page();
$page    = max(1, (int) ($_GET['page'] ?? 1));

$totalCount = 0;
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM member_applications WHERE {$where}");
    $countStmt->execute($params);
    $totalCount = (int) $countStmt->fetchColumn();
} catch (Throwable $e) {
    $totalCount = 0;
}

$totalPages = max(1, (int) ceil($totalCount / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$showingFrom = $totalCount === 0 ? 0 : $offset + 1;
$showingTo   = min($offset + $perPage, $totalCount);

$filterYears = application_list_filter_years($pdo);
if ($filterYears === [] && $defaultRenewalYear > 0) {
    $filterYears = [$defaultRenewalYear];
}

$otherPendingCount = 0;
if ($yearFilter > 0) {
    try {
        $expr = application_list_renewal_year_sql();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM member_applications WHERE status = 'pending' AND {$expr} <> ?");
        $stmt->execute([$yearFilter]);
        $otherPendingCount = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $otherPendingCount = 0;
    }
}

$listStmt = $pdo->prepare("
    SELECT id, status, first_name, last_name, application_kind, form_season,
           submitted_at, suggested_renewal_year, payment_total, payment_initiation, payment_processing_fee,
           matched_member_id, match_confidence, wpforms_entry_id, notes, raw_payload
    FROM member_applications
    WHERE {$where}
    ORDER BY submitted_at DESC, id DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$listStmt->execute($params);
$applications = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$listPageExtra = $page > 1 ? ['page' => $page] : [];

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h2 mb-1">Membership applications</h1>
        <p class="text-muted small mb-0">WPForms submissions awaiting review before members are created or updated.</p>
    </div>
    <?php $pendingCount = application_pending_count($pdo); ?>
    <?php if ($pendingCount > 0): ?>
    <span class="badge text-bg-warning"><?= (int) $pendingCount ?> pending</span>
    <?php endif; ?>
</div>

<ul class="nav nav-tabs mb-3">
    <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $key => $label): ?>
    <li class="nav-item">
        <a class="nav-link<?= $statusFilter === $key ? ' active' : '' ?>" href="<?= h(application_list_page_url($key, $yearFilter, $searchQ, $defaultRenewalYear)) ?>"><?= h($label) ?></a>
    </li>
    <?php endforeach; ?>
</ul>

<div class="card shadow-sm mb-4">
    <div class="card-body py-3">
        <form method="get" action="applications.php" class="row g-2 align-items-end">
            <?php if ($statusFilter !== 'pending'): ?>
            <input type="hidden" name="status" value="<?= h($statusFilter) ?>">
            <?php endif; ?>

            <div class="col-12 col-sm-6 col-md-4">
                <label class="form-label small fw-semibold mb-1" for="app_search">Search</label>
                <input type="text" class="form-control form-control-sm" id="app_search" name="q"
                       placeholder="Name, email, entry #…" value="<?= h($searchQ) ?>">
            </div>

            <div class="col-6 col-sm-4 col-md-3">
                <label class="form-label small fw-semibold mb-1" for="app_year">Renewal year</label>
                <select class="form-select form-select-sm" id="app_year" name="year">
                    <option value="all"<?= $yearFilter === 0 ? ' selected' : '' ?>>All years</option>
                    <?php foreach ($filterYears as $yr): ?>
                    <option value="<?= (int) $yr ?>"<?= $yearFilter === (int) $yr ? ' selected' : '' ?>><?= (int) $yr ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-auto">
                <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
                <?php if ($searchQ !== '' || $yearFilter !== $defaultRenewalYear): ?>
                <a href="<?= h(application_list_page_url($statusFilter, $defaultRenewalYear, '', $defaultRenewalYear)) ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if ($otherPendingCount > 0): ?>
<div class="alert alert-warning py-2 small mb-4">
    <?= (int) $otherPendingCount ?> pending application<?= $otherPendingCount === 1 ? '' : 's' ?> from other renewal years.
    <a href="<?= h(application_list_page_url('pending', 0, $searchQ, $defaultRenewalYear)) ?>">Show all years</a>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-<?= $application ? '5' : '12' ?>">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>Applications</span>
                <span class="small text-muted">
                    <?php if ($totalCount === 0): ?>
                    No applications
                    <?php else: ?>
                    <?= number_format($showingFrom) ?>–<?= number_format($showingTo) ?> of <?= number_format($totalCount) ?>
                    <?php if ($yearFilter > 0): ?>
                    · <?= (int) $yearFilter ?> renewal
                    <?php endif; ?>
                    <?php endif; ?>
                </span>
            </div>
            <div class="list-group list-group-flush">
                <?php if ($applications === []): ?>
                <div class="list-group-item text-muted">No applications in this view.</div>
                <?php else: ?>
                <?php foreach ($applications as $row): ?>
                <?php
                $rowPayment = application_payment_breakdown($row, $pdo);
                $rowVerification = application_renewal_verification($pdo, $row);
                $rowRenewalWarning = ($row['application_kind'] ?? '') === 'renewal'
                    && $rowVerification['status'] !== 'verified';
                ?>
                <a href="<?= h(application_list_page_url($statusFilter, $yearFilter, $searchQ, $defaultRenewalYear, array_merge($listPageExtra, ['id' => (int) $row['id']]))) ?>"
                   class="list-group-item list-group-item-action<?= $viewId === (int) $row['id'] ? ' active' : '' ?>">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <strong><?= h(trim($row['first_name'] . ' ' . $row['last_name'])) ?></strong>
                            <div class="small opacity-75">
                                <?= h(application_kind_label($row['application_kind'])) ?>
                                · <?= h(application_season_label($row['form_season'])) ?>
                                <?php if ($rowRenewalWarning): ?>
                                · <span class="text-warning" title="<?= h(implode(' ', $rowVerification['warnings'])) ?>">⚠ Verify renewal</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="text-end small">
                            <?php if ($row['status'] === 'pending' || $row['status'] === 'pending_payment'): ?>
                            <span class="badge text-bg-warning"><?= $row['status'] === 'pending_payment' ? 'Awaiting payment' : 'Pending' ?></span>
                            <?php elseif ($row['status'] === 'approved'): ?>
                            <span class="badge text-bg-success">Approved</span>
                            <?php else: ?>
                            <span class="badge text-bg-secondary">Rejected</span>
                            <?php endif; ?>
                            <?php if ($rowPayment['total_paid'] !== null): ?>
                            <div><?= h(formatMoney($rowPayment['total_paid'])) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="small mt-1 opacity-75">
                        Entry #<?= h((string) $row['wpforms_entry_id']) ?>
                        <?php if (!empty($row['submitted_at'])): ?>
                        · <?= h(date('M j, Y', strtotime((string) $row['submitted_at']))) ?>
                        <?php endif; ?>
                        <?php if ($row['matched_member_id']): ?>
                        · Match #<?= (int) $row['matched_member_id'] ?> (<?= h((string) $row['match_confidence']) ?>)
                        <?php elseif ($row['match_confidence'] === 'ambiguous'): ?>
                        · <span class="text-warning">Ambiguous match</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($row['status'] === 'rejected' && !empty($row['rejection_reason'])): ?>
                    <div class="small mt-1"><em><?= h(mb_strimwidth((string) $row['rejection_reason'], 0, 100, '…')) ?></em></div>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($totalPages > 1): ?>
        <nav class="mt-3" aria-label="Applications pagination">
            <ul class="pagination pagination-sm justify-content-center mb-0">
                <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>">
                    <a class="page-link" href="<?= h(application_list_page_url($statusFilter, $yearFilter, $searchQ, $defaultRenewalYear, ['page' => $page - 1])) ?>">‹ Prev</a>
                </li>
                <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                <li class="page-item<?= $p === $page ? ' active' : '' ?>">
                    <a class="page-link" href="<?= h(application_list_page_url($statusFilter, $yearFilter, $searchQ, $defaultRenewalYear, ['page' => $p])) ?>"><?= (int) $p ?></a>
                </li>
                <?php endfor; ?>
                <li class="page-item<?= $page >= $totalPages ? ' disabled' : '' ?>">
                    <a class="page-link" href="<?= h(application_list_page_url($statusFilter, $yearFilter, $searchQ, $defaultRenewalYear, ['page' => $page + 1])) ?>">Next ›</a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>

    <?php if ($application): ?>
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Application #<?= (int) $application['id'] ?></span>
                <span class="badge text-bg-<?= $application['status'] === 'pending' || $application['status'] === 'pending_payment' ? 'warning' : ($application['status'] === 'approved' ? 'success' : 'secondary') ?>">
                    <?= $application['status'] === 'pending_payment' ? 'Awaiting payment' : h(ucfirst((string) $application['status'])) ?>
                </span>
            </div>
            <div class="card-body">
                <?php if (($application['status'] ?? '') === 'pending_payment'): ?>
                <div class="alert alert-warning mb-3 py-2">
                    <strong>Awaiting payment.</strong> The applicant has not finished Stripe checkout.
                    Approve is unavailable until payment is confirmed. You can reject this submission to clear the queue.
                </div>
                <?php endif; ?>
                <?php if ($application['status'] === 'rejected'): ?>
                <div class="alert alert-danger mb-4">
                    <div class="fw-semibold mb-1">Application rejected</div>
                    <?php if (!empty($application['rejection_reason'])): ?>
                    <div class="mb-0"><?= nl2br(h((string) $application['rejection_reason'])) ?></div>
                    <?php else: ?>
                    <div class="mb-0">No reason was recorded.</div>
                    <?php endif; ?>
                    <?php if (!empty($application['reviewed_at'])): ?>
                    <div class="small mt-2 mb-0 opacity-75">
                        Reviewed <?= h(date('M j, Y g:i A', strtotime((string) $application['reviewed_at']))) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <div class="text-muted small">Applicant</div>
                        <div class="fw-semibold"><?= h(trim($application['first_name'] . ' ' . $application['last_name'])) ?></div>
                        <?php if (!empty($application['middle_name'])): ?>
                        <div class="small">Middle: <?= h($application['middle_name']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Type / season</div>
                        <div><?= h(application_kind_label($application['application_kind'])) ?></div>
                        <div class="small"><?= h(application_season_label($application['form_season'])) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Contact</div>
                        <div><?= h((string) ($application['email'] ?: '—')) ?></div>
                        <div><?= h((string) ($application['phone'] ?: '')) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Birthday</div>
                        <div><?= !empty($application['birthday']) ? h(formatDate($application['birthday'])) : '—' ?></div>
                    </div>
                </div>

                <h2 class="h6">Address</h2>
                <p class="mb-3">
                    <?= h(trim(implode(', ', array_filter([
                        $application['address_street'] ?? '',
                        $application['address_street2'] ?? '',
                        $application['address_city'] ?? '',
                        $application['address_state'] ?? '',
                        $application['address_postal_code'] ?? '',
                    ])))) ?: '—' ?>
                </p>

                <h2 class="h6">Compliance</h2>
                <dl class="row small mb-3">
                    <dt class="col-sm-4">AMA</dt>
                    <dd class="col-sm-8"><?= h((string) ($application['ama_number'] ?: '—')) ?><?php if (!empty($application['ama_expiration'])): ?> (exp <?= h(formatDate($application['ama_expiration'])) ?>)<?php endif; ?></dd>
                    <dt class="col-sm-4">FAA</dt>
                    <dd class="col-sm-8"><?= h((string) ($application['faa_number'] ?: '—')) ?><?php if (!empty($application['faa_expiration'])): ?> (exp <?= h(formatDate($application['faa_expiration'])) ?>)<?php endif; ?></dd>
                    <dt class="col-sm-4">Membership type</dt>
                    <?php $resolvedSlot = application_resolve_membership_type_slot($application, $pdo); ?>
                    <dd class="col-sm-8"><?= h($membershipTypeLabels[$resolvedSlot ?? 0] ?? '—') ?></dd>
                </dl>

                <?php if (!empty($application['emergency_contact_name']) || !empty($application['emergency_contact_phone'])): ?>
                <h2 class="h6">Emergency contact</h2>
                <p class="small mb-3">
                    <?= h((string) $application['emergency_contact_name']) ?>
                    <?php if (!empty($application['emergency_contact_relationship'])): ?>
                    (<?= h((string) $application['emergency_contact_relationship']) ?>)
                    <?php endif; ?>
                    <?php if (!empty($application['emergency_contact_phone'])): ?>
                    · <?= h((string) $application['emergency_contact_phone']) ?>
                    <?php endif; ?>
                </p>
                <?php endif; ?>

                <?php
                $renewalVerification = application_renewal_verification($pdo, $application);
                $paymentUnderpaid = application_payment_underpaid_check($pdo, $application, $renewalVerification);
                $effectiveRenewalType = application_effective_renewal_type($pdo, $application);
                $allVerificationWarnings = array_merge(
                    $renewalVerification['warnings'],
                    $paymentUnderpaid['warnings']
                );
                ?>
                <?php if ($allVerificationWarnings !== []): ?>
                <div class="alert alert-warning py-2 small mb-3">
                    <strong>Renewal verification</strong>
                    <?php if (application_renewal_verification_label($renewalVerification['status']) !== ''): ?>
                    — <?= h(application_renewal_verification_label($renewalVerification['status'])) ?>
                    <?php endif; ?>
                    <ul class="mb-0 mt-1">
                        <?php foreach ($allVerificationWarnings as $warn): ?>
                        <li><?= h($warn) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($renewalVerification['adjusted_renewal_type'] === 'late'): ?>
                    <p class="mb-0 mt-2"><strong>Suggested action:</strong> Use renewal type <em>Late / new with initiation</em> and collect any balance before recording.</p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <h2 class="h6">Payment (from website)</h2>
                <?php $payment = application_payment_breakdown($application, $pdo); ?>
                <dl class="row small mb-3">
                    <?php if ($payment['membership_dues'] !== null): ?>
                    <dt class="col-sm-4">Membership dues</dt>
                    <dd class="col-sm-8"><?= h(formatMoney($payment['membership_dues'])) ?></dd>
                    <?php endif; ?>
                    <?php if ($payment['initiation'] !== null): ?>
                    <dt class="col-sm-4">Initiation</dt>
                    <dd class="col-sm-8"><?= h(formatMoney($payment['initiation'])) ?></dd>
                    <?php endif; ?>
                    <?php if ($payment['processing'] !== null): ?>
                    <dt class="col-sm-4">Processing fee</dt>
                    <dd class="col-sm-8"><?= h(formatMoney($payment['processing'])) ?></dd>
                    <?php endif; ?>
                    <?php if ($payment['subtotal'] !== null): ?>
                    <dt class="col-sm-4">Subtotal</dt>
                    <dd class="col-sm-8"><?= h(formatMoney($payment['subtotal'])) ?></dd>
                    <?php endif; ?>
                    <?php if ($paymentUnderpaid['expected_subtotal'] !== null): ?>
                    <dt class="col-sm-4">Expected (new/late)</dt>
                    <dd class="col-sm-8"><?= h(formatMoney($paymentUnderpaid['expected_subtotal'])) ?></dd>
                    <?php endif; ?>
                    <?php if ($paymentUnderpaid['underpaid'] && $paymentUnderpaid['shortfall'] !== null): ?>
                    <dt class="col-sm-4">Shortfall</dt>
                    <dd class="col-sm-8 text-danger"><strong><?= h(formatMoney($paymentUnderpaid['shortfall'])) ?></strong></dd>
                    <?php endif; ?>
                    <?php if ($payment['coupon_applied'] && $payment['special_code'] !== null): ?>
                    <dt class="col-sm-4">Coupon</dt>
                    <dd class="col-sm-8">
                        <code><?= h($payment['special_code']) ?></code>
                        <span class="badge bg-success ms-1">Payment waived</span>
                    </dd>
                    <?php elseif ($payment['special_code'] !== null): ?>
                    <dt class="col-sm-4">Special code</dt>
                    <dd class="col-sm-8"><code><?= h($payment['special_code']) ?></code></dd>
                    <?php endif; ?>
                    <dt class="col-sm-4">Total paid</dt>
                    <dd class="col-sm-8"><strong><?= $payment['total_paid'] !== null ? h(formatMoney($payment['total_paid'])) : '—' ?></strong></dd>
                    <dt class="col-sm-4">Gateway</dt>
                    <dd class="col-sm-8"><?= h((string) ($application['payment_gateway'] ?: '—')) ?></dd>
                    <dt class="col-sm-4">Transaction</dt>
                    <dd class="col-sm-8"><code class="small"><?= h((string) ($application['payment_transaction_id'] ?: '—')) ?></code></dd>
                </dl>

                <?php
                $uploadKinds = [
                    'Badge photo'      => 'badge',
                    'AMA verification' => 'ama',
                    'FAA registration' => 'faa',
                    'Signature'        => 'signature',
                ];
                $uploadedFiles = [];
                foreach ($uploadKinds as $label => $kind) {
                    $href = application_file_href($application, $kind);
                    if ($href !== '') {
                        $uploadedFiles[$label] = ['href' => $href, 'kind' => $kind];
                    }
                }
                ?>
                <?php if ($uploadedFiles !== []): ?>
                <h2 class="h6">Uploaded files</h2>
                <ul class="small mb-3">
                    <?php foreach ($uploadedFiles as $label => $meta): ?>
                    <li><a href="<?= h($meta['href']) ?>" target="_blank" rel="noopener"><?= h($label) ?></a></li>
                    <?php endforeach; ?>
                </ul>
                <?php
                $signatureHref = application_file_href($application, 'signature');
                if ($signatureHref !== ''): ?>
                <div class="mb-3">
                    <div class="text-muted small mb-1">Signature on file</div>
                    <img src="<?= h($signatureHref) ?>" alt="Applicant signature" class="img-fluid border rounded bg-white" style="max-height:120px;">
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ($application['matched_member_id']): ?>
                <div class="alert alert-info small">
                    Suggested match:
                    <a href="member_view.php?id=<?= (int) $application['matched_member_id'] ?>">Member #<?= (int) $application['matched_member_id'] ?></a>
                    (<?= h((string) $application['match_confidence']) ?> via <?= h((string) ($application['match_method'] ?: 'unknown')) ?>)
                </div>
                <?php elseif ($application['match_confidence'] === 'ambiguous'): ?>
                <div class="alert alert-warning small">Multiple members match this name. Choose the correct member below before approving.</div>
                <?php endif; ?>

                <?php if ($diff !== [] && !application_is_reviewable_status($application['status'] ?? null)): ?>
                <h2 class="h6">Changes vs matched member</h2>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered small mb-0">
                        <thead><tr><th>Field</th><th>Current</th><th>From application</th></tr></thead>
                        <tbody>
                        <?php foreach ($diff as $d): ?>
                        <tr>
                            <td><?= h($d['field']) ?></td>
                            <td><?= h($d['current']) ?></td>
                            <td class="table-warning"><?= h($d['incoming']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <?php if (application_is_reviewable_status($application['status'] ?? null) && (canEditMembers() || canProcessMemberships())): ?>
                <form method="post" class="border-top pt-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="application_id" value="<?= (int) $application['id'] ?>">

                    <?php if ($diff !== []): ?>
                    <h2 class="h6">Changes vs matched member</h2>
                    <p class="small text-muted mb-2">Choose which value to keep on the member record when you approve.</p>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-bordered small mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th style="width:18%">Field</th>
                                    <th style="width:41%">Keep current member value</th>
                                    <th style="width:41%">Use application value</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($diff as $d): ?>
                            <tr>
                                <td class="fw-semibold"><?= h($d['field']) ?></td>
                                <td>
                                    <label class="d-flex gap-2 mb-0">
                                        <input class="form-check-input mt-1 flex-shrink-0" type="radio"
                                               name="field_choice[<?= h($d['key']) ?>]" value="current">
                                        <span><?= h($d['current']) ?></span>
                                    </label>
                                </td>
                                <td class="table-warning">
                                    <label class="d-flex gap-2 mb-0">
                                        <input class="form-check-input mt-1 flex-shrink-0" type="radio"
                                               name="field_choice[<?= h($d['key']) ?>]" value="incoming" checked>
                                        <span><?= h($d['incoming']) ?></span>
                                    </label>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>

                    <?php if ($candidates !== []): ?>
                    <div class="mb-3">
                        <label class="form-label" for="matched_member_id">Match to member</label>
                        <select class="form-select" id="matched_member_id" name="matched_member_id">
                            <option value="">— Create as new member —</option>
                            <?php foreach ($candidates as $c): ?>
                            <option value="<?= (int) $c['id'] ?>">
                                #<?= (int) $c['id'] ?> — <?= h($c['last_name'] . ', ' . $c['first_name']) ?>
                                <?php if (!empty($c['email'])): ?>(<?= h($c['email']) ?>)<?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php elseif ($application['matched_member_id']): ?>
                    <input type="hidden" name="matched_member_id" value="<?= (int) $application['matched_member_id'] ?>">
                    <?php endif; ?>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label" for="renewal_type">Renewal type</label>
                            <select class="form-select" id="renewal_type" name="renewal_type">
                                <?php foreach (['new' => 'New', 'on_time' => 'On-time renewal', 'late' => 'Late / new with initiation'] as $val => $label): ?>
                                <option value="<?= h($val) ?>"<?= $effectiveRenewalType === $val ? ' selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="renewal_year">Renewal year</label>
                            <input type="number" class="form-control" id="renewal_year" name="renewal_year"
                                   value="<?= (int) ($application['suggested_renewal_year'] ?? defaultRenewalYear($pdo)) ?>" min="2000" max="2100">
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <?php if (application_can_approve($application['status'] ?? null)): ?>
                        <button type="submit" name="action" value="approve" class="btn btn-primary">Approve &amp; continue to recording</button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#rejectPanel">Reject</button>
                    </div>

                    <div class="collapse mt-3" id="rejectPanel">
                        <label class="form-label" for="rejection_reason">Rejection reason (optional)</label>
                        <textarea class="form-control mb-2" id="rejection_reason" name="rejection_reason" rows="2"></textarea>
                        <button type="submit" name="action" value="reject" class="btn btn-danger btn-sm">Confirm reject</button>
                    </div>
                </form>
                <?php elseif ($application['status'] === 'approved' && !empty($application['approved_member_id'])): ?>
                <a href="member_view.php?id=<?= (int) $application['approved_member_id'] ?>" class="btn btn-outline-primary btn-sm">View member #<?= (int) $application['approved_member_id'] ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
