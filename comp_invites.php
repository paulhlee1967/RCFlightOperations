<?php
/**
 * comp_invites.php — Staff management of complimentary membership invites.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/flash.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/membership_comp_invites.php';

requireLogin();
if (!canEditMembers() && !canProcessMemberships()) {
    header('Location: index.php');
    exit;
}

$userId = currentUserId();
$filter = (string) ($_GET['filter'] ?? 'open');
if (!in_array($filter, ['open', 'redeemed', 'expired', 'cancelled', 'all'], true)) {
    $filter = 'open';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $expiresDate = trim((string) ($_POST['expires_date'] ?? ''));
        $expiresAt = '';
        if ($expiresDate !== '') {
            $expiresAt = $expiresDate . ' 23:59:59';
        }
        $result = membership_comp_invite_create($pdo, [
            'email'           => $_POST['email'] ?? '',
            'ama_number'      => $_POST['ama_number'] ?? '',
            'membership_type' => $_POST['membership_type'] ?? 'free_membership',
            'notes'           => $_POST['notes'] ?? '',
            'expires_at'      => $expiresAt,
        ], $userId);
        if ($result['ok']) {
            flash('Complimentary invite created.', 'success');
        } else {
            flash($result['error'] ?? 'Could not create invite.', 'warning');
        }
        header('Location: comp_invites.php');
        exit;
    }

    if ($action === 'cancel') {
        $inviteId = (int) ($_POST['invite_id'] ?? 0);
        if ($inviteId > 0 && membership_comp_invite_cancel($pdo, $inviteId)) {
            flash('Invite cancelled.', 'success');
        } else {
            flash('Could not cancel invite — it may already be used.', 'warning');
        }
        header('Location: comp_invites.php?filter=' . urlencode($filter));
        exit;
    }
}

$listFilter = $filter === 'all' ? 'open' : $filter;
$invites = membership_comp_invite_list($pdo, $listFilter);
if ($filter === 'all') {
    $invites = membership_comp_invite_list($pdo, 'open');
    $invites = array_merge(
        $invites,
        membership_comp_invite_list($pdo, 'redeemed'),
        membership_comp_invite_list($pdo, 'expired'),
        membership_comp_invite_list($pdo, 'cancelled')
    );
    usort($invites, static fn ($a, $b) => (int) $b['id'] <=> (int) $a['id']);
    $invites = array_slice($invites, 0, 200);
}

$defaultExpires = (new DateTimeImmutable('+90 days'))->format('Y-m-d');

$pageTitle = 'Comp invites';
$breadcrumbs = [
    ['label' => 'Applications', 'url' => 'applications.php'],
    ['label' => 'Comp invites', 'url' => ''],
];
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h2 mb-1">Complimentary membership invites</h1>
        <p class="text-muted small mb-0">
            Pre-authorize complimentary online applications for people not yet in the club database.
            Matching is by email and/or AMA # — no shareable coupon code.
        </p>
    </div>
    <a href="applications.php" class="btn btn-outline-primary btn-sm">← Applications</a>
</div>

<ul class="nav nav-tabs mb-3">
    <?php foreach (['open' => 'Open', 'redeemed' => 'Redeemed', 'expired' => 'Expired', 'cancelled' => 'Cancelled', 'all' => 'All'] as $key => $label): ?>
    <li class="nav-item">
        <a class="nav-link<?= $filter === $key ? ' active' : '' ?>" href="comp_invites.php?filter=<?= urlencode($key) ?>"><?= h($label) ?></a>
    </li>
    <?php endforeach; ?>
</ul>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">New invite</div>
            <div class="card-body">
                <form method="post" action="comp_invites.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3">
                        <label class="form-label" for="email">Email</label>
                        <input type="email" class="form-control" id="email" name="email" autocomplete="off">
                        <div class="form-text">Required if AMA # is blank. Must match the application email.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="ama_number">AMA #</label>
                        <input type="text" class="form-control" id="ama_number" name="ama_number" autocomplete="off">
                        <div class="form-text">Required if email is blank. Must match verified AMA on the form.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Membership type</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="membership_type" id="membership_type_free" value="free_membership" checked>
                            <label class="form-check-label" for="membership_type_free">Free membership</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="membership_type" id="membership_type_life" value="life_member">
                            <label class="form-check-label" for="membership_type_life">Life member</label>
                        </div>
                        <div class="form-text">Applied automatically when the application is approved. Do not select both on the member record.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="expires_date">Expires</label>
                        <input type="date" class="form-control" id="expires_date" name="expires_date" value="<?= h($defaultExpires) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="notes">Notes (staff only)</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="e.g. 2026 scholarship — board approved"></textarea>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-outline-primary btn-sm">Create invite</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Invites</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Email</th>
                            <th>AMA #</th>
                            <th>Type</th>
                            <th>Expires</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($invites === []): ?>
                        <tr><td colspan="7" class="text-muted p-3">No invites in this list.</td></tr>
                    <?php else: ?>
                        <?php foreach ($invites as $invite): ?>
                        <?php
                            $status = 'Open';
                            $statusClass = 'text-bg-success';
                            if (!empty($invite['cancelled_at'])) {
                                $status = 'Cancelled';
                                $statusClass = 'text-bg-secondary';
                            } elseif (!empty($invite['redeemed_at'])) {
                                $status = 'Redeemed';
                                $statusClass = 'text-bg-primary';
                            } elseif (!empty($invite['expires_at']) && strtotime((string) $invite['expires_at']) <= time()) {
                                $status = 'Expired';
                                $statusClass = 'text-bg-warning';
                            }
                        ?>
                        <tr>
                            <td><?= (int) $invite['id'] ?></td>
                            <td><?= h((string) ($invite['email'] ?? '—')) ?></td>
                            <td><?= h((string) ($invite['ama_number'] ?? '—')) ?></td>
                            <td class="small"><?= h(membership_comp_invite_type_label($invite['membership_type'] ?? '')) ?></td>
                            <td class="small"><?= !empty($invite['expires_at']) ? h(formatDate(substr((string) $invite['expires_at'], 0, 10))) : '—' ?></td>
                            <td><span class="badge <?= h($statusClass) ?>"><?= h($status) ?></span></td>
                            <td class="text-end">
                                <?php if ($status === 'Open'): ?>
                                <form method="post" action="comp_invites.php?filter=<?= urlencode($filter) ?>" class="d-inline" onsubmit="return confirm('Cancel this invite?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="invite_id" value="<?= (int) $invite['id'] ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm">Cancel</button>
                                </form>
                                <?php elseif ($status === 'Redeemed' && !empty($invite['redeemed_application_id'])): ?>
                                <a href="applications.php?id=<?= (int) $invite['redeemed_application_id'] ?>" class="btn btn-outline-primary btn-sm">Application</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if (!empty($invite['notes'])): ?>
                        <tr>
                            <td></td>
                            <td colspan="6" class="small text-muted border-0 pt-0"><?= h((string) $invite['notes']) ?></td>
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
