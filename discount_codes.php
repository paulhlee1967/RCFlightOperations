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

    if ($action === 'update') {
        $codeId = (int) ($_POST['code_id'] ?? 0);
        $result = membership_discount_code_update($pdo, $codeId, [
            'code'          => $_POST['code'] ?? '',
            'discount_type' => $_POST['discount_type'] ?? 'amount',
            'amount'        => $_POST['amount'] ?? 0,
            'applies_to'    => $_POST['applies_to'] ?? 'both',
            'notes'         => $_POST['notes'] ?? '',
            'expires_at'    => trim((string) ($_POST['expires_date'] ?? '')),
            'enable'        => $_POST['enable'] ?? '',
        ]);
        if ($result['ok']) {
            $updated = membership_discount_code_find($pdo, $codeId);
            $dest = $filter;
            if ($filter !== 'all' && $updated) {
                $dest = match (membership_discount_code_status_label($updated)) {
                    'Active' => 'active',
                    'Expired' => 'expired',
                    default => 'disabled',
                };
            }
            $message = 'Discount code updated.';
            if ($filter !== 'all' && $dest !== $filter) {
                $message .= match ($dest) {
                    'active' => ' It is on the Active list.',
                    'expired' => ' It is on the Expired list.',
                    default => ' It is on the Disabled list.',
                };
            }
            flash($message, 'success');
            header('Location: discount_codes.php?filter=' . urlencode($dest));
            exit;
        }
        flash($result['error'] ?? 'Could not update that discount code.', 'warning');
        header('Location: discount_codes.php?filter=' . urlencode($filter) . '&edit=' . $codeId);
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

$editing = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editing = membership_discount_code_find($pdo, $editId);
    if ($editing === null) {
        flash('That discount code was not found.', 'warning');
        header('Location: discount_codes.php?filter=' . urlencode($filter));
        exit;
    }
}

$codes = $filter === 'all'
    ? membership_discount_code_list($pdo, 'all')
    : membership_discount_code_list($pdo, $filter);

$defaultExpires = (new DateTimeImmutable('last day of December this year'))->format('Y-m-d');
$editStatus = $editing ? membership_discount_code_status_label($editing) : '';
$selectedType = $editing
    ? membership_discount_normalize_type((string) ($editing['discount_type'] ?? ''))
    : 'amount';
$selectedApplies = $editing
    ? membership_discount_normalize_applies_to((string) ($editing['applies_to'] ?? ''))
    : 'dues';
$expiresValue = $defaultExpires;
if ($editing) {
    $expiresValue = !empty($editing['expires_at'])
        ? substr((string) $editing['expires_at'], 0, 10)
        : '';
}
$editDateIsPast = $editing
    && $expiresValue !== ''
    && $expiresValue < (new DateTimeImmutable('today'))->format('Y-m-d');
$formAction = $editing
    ? 'discount_codes.php?filter=' . urlencode($filter) . '&edit=' . (int) $editing['id']
    : 'discount_codes.php';

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
        <div class="card shadow-sm" id="edit-code">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center gap-2">
                <span><?= $editing ? 'Edit code' : 'New code' ?></span>
                <?php if ($editing): ?>
                <?php
                    $editStatusClass = match ($editStatus) {
                        'Active' => 'text-bg-success',
                        'Expired' => 'text-bg-warning',
                        default => 'text-bg-secondary',
                    };
                ?>
                <span class="badge <?= h($editStatusClass) ?>"><?= h($editStatus) ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($editStatus === 'Expired'): ?>
                <div class="alert alert-warning py-2 small">
                    This code is expired. Set a future expiration date, or clear the date, and applicants can use it again.
                </div>
                <?php elseif ($editStatus === 'Disabled'): ?>
                <div class="alert alert-secondary py-2 small">
                    This code is disabled. You can change it here and turn it back on when you are ready.
                </div>
                <?php endif; ?>
                <form method="post" action="<?= h($formAction) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
                    <?php if ($editing): ?>
                    <input type="hidden" name="code_id" value="<?= (int) $editing['id'] ?>">
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label" for="code">Code</label>
                        <input type="text" class="form-control text-uppercase" id="code" name="code" autocomplete="off" required maxlength="32" placeholder="EARLY50" value="<?= h($editing ? (string) $editing['code'] : '') ?>">
                        <div class="form-text">Letters, numbers, and hyphens. Anyone with the code can use it until it expires or you disable it.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Discount type</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="discount_type" id="discount_type_amount" value="amount"<?= checked($selectedType === 'amount') ?>>
                            <label class="form-check-label" for="discount_type_amount">Dollar off</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="discount_type" id="discount_type_percent" value="percent"<?= checked($selectedType === 'percent') ?>>
                            <label class="form-check-label" for="discount_type_percent">Percent off</label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="amount">Amount</label>
                        <input type="number" class="form-control" id="amount" name="amount" min="0.01" step="0.01" required value="<?= h($editing ? (string) $editing['amount'] : '') ?>">
                        <div class="form-text">Dollars, or a percent less than 100. Campaign codes cannot waive the entire fee.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Applies to</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="applies_to" id="applies_to_dues" value="dues"<?= checked($selectedApplies === 'dues') ?>>
                            <label class="form-check-label" for="applies_to_dues">Membership dues</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="applies_to" id="applies_to_initiation" value="initiation"<?= checked($selectedApplies === 'initiation') ?>>
                            <label class="form-check-label" for="applies_to_initiation">Initiation fee</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="applies_to" id="applies_to_both" value="both"<?= checked($selectedApplies === 'both') ?>>
                            <label class="form-check-label" for="applies_to_both">Dues and initiation</label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="expires_date">Expires</label>
                        <input type="date" class="form-control" id="expires_date" name="expires_date" value="<?= h($expiresValue) ?>">
                        <div class="form-text">The code works through the end of this date. Clear the date for no expiration.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="notes">Notes (staff only)</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="e.g. 2027 early-bird flyer"><?= h($editing ? (string) ($editing['notes'] ?? '') : '') ?></textarea>
                    </div>
                    <?php if ($editStatus === 'Disabled'): ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="enable" id="enable" value="1">
                        <label class="form-check-label" for="enable">Enable this code</label>
                        <div class="form-text">
                            <?php if ($editDateIsPast): ?>
                            The expiration date is still in the past. Set a new date or clear it, or the code stays expired after you enable it.
                            <?php else: ?>
                            Turn the code back on with this save. Leave unchecked to keep it disabled.
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-outline-primary flex-grow-1"><?= $editing ? 'Save changes' : 'Create code' ?></button>
                        <?php if ($editing): ?>
                        <a href="discount_codes.php?filter=<?= urlencode($filter) ?>" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
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
                        <?php $isRowEdit = $editing && (int) $editing['id'] === (int) $row['id']; ?>
                        <tr<?= $isRowEdit ? ' class="table-active"' : '' ?>>
                            <td><code><?= h((string) $row['code']) ?></code></td>
                            <td class="small"><?= h(membership_discount_code_summary($row)) ?></td>
                            <td class="small"><?= !empty($row['expires_at']) ? h(formatDate(substr((string) $row['expires_at'], 0, 10))) : '—' ?></td>
                            <td><span class="badge <?= h($statusClass) ?>"><?= h($status) ?></span></td>
                            <td class="text-end text-nowrap">
                                <a href="discount_codes.php?filter=<?= urlencode($filter) ?>&amp;edit=<?= (int) $row['id'] ?>#edit-code" class="btn btn-sm <?= $isRowEdit ? 'btn-secondary' : 'btn-outline-secondary' ?>"<?= $isRowEdit ? ' aria-current="page"' : '' ?>>Edit</a>
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
