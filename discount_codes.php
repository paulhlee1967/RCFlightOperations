<?php
/**
 * discount_codes.php — Staff management of reusable campaign discount codes.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/flash.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/membership_discount_codes.php';

requireLogin();
if (!canEditMembers() && !canProcessMemberships()) {
    header('Location: index.php');
    exit;
}

$userId = currentUserId();
$filter = (string) ($_GET['filter'] ?? 'active');
if (!in_array($filter, ['active', 'expired', 'disabled', 'all'], true)) {
    $filter = 'active';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $expiresDate = trim((string) ($_POST['expires_date'] ?? ''));
        $result = membership_discount_code_create($pdo, [
            'code'           => $_POST['code'] ?? '',
            'discount_type'  => $_POST['discount_type'] ?? 'amount',
            'amount'         => $_POST['amount'] ?? 0,
            'applies_to'     => $_POST['applies_to'] ?? 'both',
            'notes'          => $_POST['notes'] ?? '',
            'expires_at'     => $expiresDate,
        ], $userId);
        if ($result['ok']) {
            flash('Discount code created.', 'success');
        } else {
            flash($result['error'] ?? 'Could not create discount code.', 'warning');
        }
        header('Location: discount_codes.php');
        exit;
    }

    if ($action === 'disable' || $action === 'enable') {
        $codeId = (int) ($_POST['code_id'] ?? 0);
        $ok = $codeId > 0 && membership_discount_code_set_active($pdo, $codeId, $action === 'enable');
        if ($ok) {
            flash($action === 'enable' ? 'Discount code enabled.' : 'Discount code disabled.', 'success');
        } else {
            flash('Could not update that discount code.', 'warning');
        }
        header('Location: discount_codes.php?filter=' . urlencode($filter));
        exit;
    }
}

$codes = $filter === 'all'
    ? membership_discount_code_list($pdo, 'all')
    : membership_discount_code_list($pdo, $filter);

$defaultExpires = (new DateTimeImmutable('last day of December this year'))->format('Y-m-d');

$pageTitle = 'Discount codes';
$breadcrumbs = [
    ['label' => 'Applications', 'url' => 'applications.php'],
    ['label' => 'Discount codes', 'url' => ''],
];
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h2 mb-1">Campaign discount codes</h1>
        <p class="text-muted small mb-0">
            Reusable codes anyone can enter on the public application. They reduce dues, initiation, or both —
            they never make the application free. Use <a href="comp_invites.php">comp invites</a> for a named $0 membership.
        </p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <a href="comp_invites.php" class="btn btn-outline-primary btn-sm">Comp invites</a>
        <a href="applications.php" class="btn btn-outline-primary btn-sm">← Applications</a>
    </div>
</div>

<ul class="nav nav-tabs nav-tabs-club mb-3">
    <?php foreach (['active' => 'Active', 'expired' => 'Expired', 'disabled' => 'Disabled', 'all' => 'All'] as $key => $label): ?>
    <li class="nav-item">
        <a class="nav-link<?= $filter === $key ? ' active' : '' ?>" href="discount_codes.php?filter=<?= urlencode($key) ?>"><?= h($label) ?></a>
    </li>
    <?php endforeach; ?>
</ul>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">New code</div>
            <div class="card-body">
                <form method="post" action="discount_codes.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3">
                        <label class="form-label" for="code">Code</label>
                        <input type="text" class="form-control text-uppercase" id="code" name="code" autocomplete="off" required maxlength="32" placeholder="EARLY50">
                        <div class="form-text">Letters, numbers, and hyphens. Anyone with the code can use it until it expires or you disable it.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Discount type</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="discount_type" id="discount_type_amount" value="amount" checked>
                            <label class="form-check-label" for="discount_type_amount">Dollar off</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="discount_type" id="discount_type_percent" value="percent">
                            <label class="form-check-label" for="discount_type_percent">Percent off</label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="amount">Amount</label>
                        <input type="number" class="form-control" id="amount" name="amount" min="0.01" step="0.01" required>
                        <div class="form-text">Dollars, or a percent less than 100. Campaign codes cannot waive the entire fee.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Applies to</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="applies_to" id="applies_to_dues" value="dues" checked>
                            <label class="form-check-label" for="applies_to_dues">Membership dues</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="applies_to" id="applies_to_initiation" value="initiation">
                            <label class="form-check-label" for="applies_to_initiation">Initiation fee</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="applies_to" id="applies_to_both" value="both">
                            <label class="form-check-label" for="applies_to_both">Dues and initiation</label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="expires_date">Expires</label>
                        <input type="date" class="form-control" id="expires_date" name="expires_date" value="<?= h($defaultExpires) ?>">
                        <div class="form-text">The code works through the end of this date. Clear the date for no expiration.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="notes">Notes (staff only)</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="e.g. 2027 early-bird flyer"></textarea>
                    </div>
                    <div>
                        <button type="submit" class="btn btn-outline-primary w-100">Create code</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Codes</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Discount</th>
                            <th>Expires</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($codes === []): ?>
                        <tr><td colspan="5" class="text-muted p-3">No discount codes in this list.</td></tr>
                    <?php else: ?>
                        <?php foreach ($codes as $row): ?>
                        <?php
                            $status = membership_discount_code_status_label($row);
                            $statusClass = match ($status) {
                                'Active' => 'text-bg-success',
                                'Expired' => 'text-bg-warning',
                                default => 'text-bg-secondary',
                            };
                        ?>
                        <tr>
                            <td><code><?= h((string) $row['code']) ?></code></td>
                            <td class="small"><?= h(membership_discount_code_summary($row)) ?></td>
                            <td class="small"><?= !empty($row['expires_at']) ? h(formatDate(substr((string) $row['expires_at'], 0, 10))) : '—' ?></td>
                            <td><span class="badge <?= h($statusClass) ?>"><?= h($status) ?></span></td>
                            <td class="text-end">
                                <?php if ($status === 'Disabled'): ?>
                                <form method="post" action="discount_codes.php?filter=<?= urlencode($filter) ?>" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="enable">
                                    <input type="hidden" name="code_id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="btn btn-outline-primary btn-sm">Enable</button>
                                </form>
                                <?php else: ?>
                                <form method="post" action="discount_codes.php?filter=<?= urlencode($filter) ?>" class="d-inline" data-confirm-submit="Disable this discount code? Applicants will no longer be able to use it.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="disable">
                                    <input type="hidden" name="code_id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm">Disable</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if (!empty($row['notes'])): ?>
                        <tr>
                            <td></td>
                            <td colspan="4" class="small text-muted border-0 pt-0"><?= h((string) $row['notes']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
