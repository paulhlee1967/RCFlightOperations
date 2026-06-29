<?php
/**
 * member_view.php — Read-only member record (for viewer role).
 *
 * This page intentionally has no write actions. Editors should use member_edit.php.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();
if (!canViewMembers()) {
    header('Location: index.php');
    exit;
}

$memberId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($memberId <= 0) {
    header('Location: members.php');
    exit;
}

$membershipTypeLabels = enabledMembershipTypeLabels($pdo);

$stmt = $pdo->prepare('SELECT * FROM members WHERE id = ?');
$stmt->execute([$memberId]);
$member = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$member) {
    header('Location: members.php');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM member_phones WHERE member_id = ? ORDER BY id');
$stmt->execute([$memberId]);
$phones = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare('SELECT * FROM member_addresses WHERE member_id = ? ORDER BY id');
$stmt->execute([$memberId]);
$addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare('SELECT * FROM payments WHERE member_id = ? ORDER BY paid_at DESC, id DESC');
$stmt->execute([$memberId]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$memberName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) ?: 'Member';
$pageTitle  = 'Member: ' . $memberName;
$breadcrumbs = [
    ['label' => 'Members', 'url' => 'members.php'],
    ['label' => $memberName, 'url' => 'member_view.php?id=' . $memberId],
];

require_once __DIR__ . '/includes/header.php';
?>

<nav class="d-flex flex-wrap align-items-center gap-2 mb-3 mb-md-4 pb-2 border-bottom">
    <a href="members.php" class="btn btn-outline-secondary btn-sm">← Back to Members</a>
    <?php if (canEditMembers()): ?>
        <a href="member_edit.php?id=<?= (int) $memberId ?>" class="btn btn-outline-primary btn-sm">Edit member</a>
    <?php endif; ?>
</nav>

<div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h2 mb-0"><?= htmlspecialchars($memberName) ?></h1>
        <div class="text-muted small">
            <?= htmlspecialchars(($member['last_name'] ?? '') . ', ' . ($member['first_name'] ?? '')) ?>
            <?php if (!empty($member['email'])): ?>
                &mdash; <?= htmlspecialchars($member['email']) ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="text-muted small">
        <span class="badge bg-secondary"><?= htmlspecialchars(ucfirst($_SESSION['user_role'] ?? '')) ?> access</span>
    </div>
</div>

<div class="card mb-3 mb-md-4">
    <div class="card-body p-0 p-md-3">
        <ul class="nav nav-tabs nav-fill flex-nowrap flex-md-wrap overflow-auto border-0 px-2 pt-2 pb-0 gap-1" id="memberTabs" role="tablist" style="-webkit-overflow-scrolling:touch;">
            <li class="nav-item" role="presentation">
                <button class="nav-link active rounded-top" id="tab-contact" data-bs-toggle="tab" data-bs-target="#pane-contact" type="button" role="tab">Contact</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link rounded-top" id="tab-compliance" data-bs-toggle="tab" data-bs-target="#pane-compliance" type="button" role="tab">Compliance</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link rounded-top" id="tab-membership" data-bs-toggle="tab" data-bs-target="#pane-membership" type="button" role="tab">Membership</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link rounded-top" id="tab-incidents" data-bs-toggle="tab" data-bs-target="#pane-incidents" type="button" role="tab">Incidents</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link rounded-top" id="tab-payment" data-bs-toggle="tab" data-bs-target="#pane-payment" type="button" role="tab">Payment history</button>
            </li>
        </ul>

        <div class="tab-content p-3 p-md-4" id="memberTabContent">
            <div class="tab-pane fade show active" id="pane-contact" role="tabpanel">
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label text-muted small text-uppercase fw-semibold">Photo</label>
                        <?php if (!empty($member['photo_path']) && is_readable(__DIR__ . '/' . ltrim((string) $member['photo_path'], '/'))): ?>
                            <p class="mb-0">
                                <img src="<?= htmlspecialchars($member['photo_path']) ?>"
                                     alt="Member photo"
                                     class="img-thumbnail rounded d-block"
                                     style="max-width:180px;max-height:180px;object-fit:cover;"
                                     loading="lazy" decoding="async">
                            </p>
                        <?php else: ?>
                            <p class="text-muted small mb-0">No photo</p>
                        <?php endif; ?>
                    </div>
                    <div class="col-12 col-md-8">
                        <div class="row g-2 g-md-3">
                            <div class="col-12 col-md-3">
                                <label class="form-label">Title</label>
                                <div class="form-control bg-light"><?= htmlspecialchars($member['title'] ?? '') ?></div>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">First name</label>
                                <div class="form-control bg-light"><?= htmlspecialchars($member['first_name'] ?? '') ?></div>
                            </div>
                            <div class="col-12 col-md-5">
                                <label class="form-label">Last name</label>
                                <div class="form-control bg-light"><?= htmlspecialchars($member['last_name'] ?? '') ?></div>
                            </div>
                            <div class="col-12 col-md-8">
                                <label class="form-label">Email</label>
                                <div class="form-control bg-light"><?= htmlspecialchars($member['email'] ?? '') ?></div>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Birthday</label>
                                <div class="form-control bg-light"><?= htmlspecialchars($member['birthday'] ?? '') ?></div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <div class="form-control bg-light" style="min-height:80px;white-space:pre-wrap;"><?= htmlspecialchars($member['notes'] ?? '') ?></div>
                            </div>
                            <div class="col-12 mt-2 pt-2 border-top">
                                <label class="form-label text-muted small text-uppercase fw-semibold">Emergency contact</label>
                                <div class="row g-2">
                                    <div class="col-12 col-md-5">
                                        <div class="form-control bg-light"><?= htmlspecialchars($member['emergency_contact_name'] ?? '') ?></div>
                                    </div>
                                    <div class="col-12 col-md-3">
                                        <div class="form-control bg-light"><?= htmlspecialchars($member['emergency_contact_relationship'] ?? '') ?></div>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <div class="form-control bg-light"><?= htmlspecialchars($member['emergency_contact_phone'] ?? '') ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Phones</label>
                        <?php if (count($phones) === 0): ?>
                            <p class="text-muted small mb-0">No phone numbers on file.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead class="table-light"><tr><th>Type</th><th>Number</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($phones as $p): ?>
                                        <tr>
                                            <td class="text-muted small"><?= htmlspecialchars($p['type'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($p['number'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Addresses</label>
                        <?php if (count($addresses) === 0): ?>
                            <p class="text-muted small mb-0">No addresses on file.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead class="table-light"><tr><th>Type</th><th>Address</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($addresses as $a): ?>
                                        <tr>
                                            <td class="text-muted small"><?= htmlspecialchars($a['type'] ?? '') ?></td>
                                            <td style="white-space:pre-wrap;">
                                                <?= htmlspecialchars(trim(implode("\n", array_filter([
                                                    $a['street'] ?? '',
                                                    $a['street2'] ?? '',
                                                    trim(($a['city'] ?? '') . ', ' . ($a['state'] ?? '') . ' ' . ($a['postal_code'] ?? '')),
                                                ])))) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="pane-compliance" role="tabpanel">
                <div class="row g-3">
                    <div class="col-12 col-md-3">
                        <label class="form-label">AMA number</label>
                        <div class="form-control bg-light"><?= htmlspecialchars($member['ama_number'] ?? '') ?></div>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">AMA expiration</label>
                        <div class="form-control bg-light"><?= htmlspecialchars($member['ama_expiration'] ?? '') ?></div>
                        <?php if (!empty($member['ama_life_member'])): ?>
                            <div class="small text-muted mt-1">AMA life member</div>
                        <?php endif; ?>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">FAA number</label>
                        <div class="form-control bg-light"><?= htmlspecialchars($member['faa_number'] ?? '') ?></div>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">FAA expiration</label>
                        <div class="form-control bg-light"><?= htmlspecialchars($member['faa_expiration'] ?? '') ?></div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="pane-membership" role="tabpanel">
                <div class="row g-3">
                    <div class="col-12 col-md-3">
                        <label class="form-label">Date joined</label>
                        <div class="form-control bg-light"><?= htmlspecialchars($member['date_joined'] ?? '') ?></div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">Membership type</label>
                        <?php
                        $slot = (int) ($member['membership_type_slot'] ?? 0);
                        $typeLabel = $slot > 0 ? ($membershipTypeLabels[$slot] ?? ('Type ' . $slot)) : '';
                        ?>
                        <div class="form-control bg-light"><?= htmlspecialchars($typeLabel) ?></div>
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label">Renewal year</label>
                        <div class="form-control bg-light"><?= htmlspecialchars($member['membership_renewal_year'] ?? '') ?></div>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Gate key number</label>
                        <div class="form-control bg-light"><?= htmlspecialchars($member['gate_key_number'] ?? '') ?></div>
                    </div>
                    <div class="col-12">
                        <?php if (!empty($member['inactive'])): ?><span class="badge bg-secondary me-1">Inactive</span><?php endif; ?>
                        <?php if (!empty($member['suspended'])): ?><span class="badge bg-warning text-dark me-1">Suspended</span><?php endif; ?>
                        <?php if (!empty($member['life_member'])): ?><span class="badge bg-info text-dark me-1">Life member</span><?php endif; ?>
                        <?php if (!empty($member['free_membership'])): ?><span class="badge bg-secondary me-1">Free membership</span><?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="pane-incidents" role="tabpanel">
                <?php $memberIdForInclude = $memberId; /* keep local clarity */ ?>
                <?php $memberId = $memberIdForInclude; require __DIR__ . '/includes/member_incidents_tab.php'; ?>
            </div>

            <div class="tab-pane fade" id="pane-payment" role="tabpanel">
                <?php if (count($payments) === 0): ?>
                    <p class="text-muted mb-0">No payments recorded.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th><th>Year</th><th>Dues</th><th>Initiation</th><th>Late fee</th><th>Comp</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($payments as $p): ?>
                                <tr>
                                    <td><?= htmlspecialchars($p['paid_at'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($p['year'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($p['amount_dues'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($p['amount_initiation'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($p['amount_late_fee'] ?? '') ?></td>
                                    <td><?= !empty($p['comp']) ? 'Yes' : '' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

