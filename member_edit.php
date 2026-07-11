<?php
/**
 * Member add/edit. Contact (phones, addresses), Membership, AMA/FAA, Payment history.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit_log.php';
require_once __DIR__ . '/includes/flash.php';
require_once __DIR__ . '/includes/validation.php';
require_once __DIR__ . '/includes/member_save.php';

requireLogin();
if (!canEditMembers()) {
    header('Location: index.php');
    exit;
}
$memberId = isset($_GET['id']) ? (int) $_GET['id'] : null;

if (!$memberId && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: member_wizard.php');
    exit;
}

// Membership type slots for this club (1–4, enabled + labeled)
$membershipTypeLabels = enabledMembershipTypeLabels($pdo);

// Validate CSRF on POST actions (member edits + any future actions).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
}

// ---------------------------------------------------------------------------
// POST: Save member (and phones, addresses)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_member') {
    $result = save_member_from_post($pdo, $memberId, $_POST, $_FILES);
    if (!$result['ok']) {
        flash(implode(' ', array_values($result['errors'])), 'warning');
        header('Location: member_edit.php' . ($memberId ? '?id=' . (int) $memberId : ''));
        exit;
    }
    $memberId = (int) $result['member_id'];

    header('Location: member_edit.php?id=' . $memberId);
    exit;
}

// ---------------------------------------------------------------------------
// Load member, payments
// ---------------------------------------------------------------------------
$member = null;
$payments = [];

if ($memberId) {
    $stmt = $pdo->prepare('SELECT * FROM members WHERE id = ?');
    $stmt->execute([$memberId]);
    $member = $stmt->fetch();
    if (!$member) {
        header('Location: members.php');
        exit;
    }
    $stmt = $pdo->prepare('SELECT * FROM payments WHERE member_id = ? ORDER BY paid_at DESC, id DESC');
    $stmt->execute([$memberId]);
    $payments = $stmt->fetchAll();
}
if (!$member) {
    $member = [];
}

$isNew = !$memberId;
$memberDisplayName = $isNew ? 'New member' : trim(($member['last_name'] ?? '') . ', ' . ($member['first_name'] ?? ''));
$pageTitle = $isNew ? 'New member' : htmlspecialchars($member['last_name'] . ', ' . $member['first_name']);

$breadcrumbs = [['label' => 'Members', 'url' => 'members.php']];
if ($memberId) {
    $breadcrumbs[] = ['label' => $memberDisplayName, 'url' => ''];
}

$prevMemberId = null;
$nextMemberId = null;
if ($memberId) {
    $stmt = $pdo->prepare('SELECT id FROM members WHERE (last_name < ? OR (last_name = ? AND first_name < ?) OR (last_name = ? AND first_name = ? AND id < ?)) ORDER BY last_name DESC, first_name DESC, id DESC LIMIT 1');
    $stmt->execute([$member['last_name'], $member['last_name'], $member['first_name'], $member['last_name'], $member['first_name'], $memberId]);
    $row = $stmt->fetch();
    if ($row) $prevMemberId = (int) $row['id'];
    $stmt = $pdo->prepare('SELECT id FROM members WHERE (last_name > ? OR (last_name = ? AND first_name > ?) OR (last_name = ? AND first_name = ? AND id > ?)) ORDER BY last_name ASC, first_name ASC, id ASC LIMIT 1');
    $stmt->execute([$member['last_name'], $member['last_name'], $member['first_name'], $member['last_name'], $member['first_name'], $memberId]);
    $row = $stmt->fetch();
    if ($row) $nextMemberId = (int) $row['id'];
}

require_once __DIR__ . '/includes/header.php';
?>
<?php if ($memberId): ?>
<nav class="d-flex flex-wrap align-items-center gap-2 gap-md-3 mb-3">
    <?php if ($prevMemberId): ?><a href="member_edit.php?id=<?= $prevMemberId ?>" class="btn btn-outline-secondary btn-sm">← Previous</a><?php endif; ?>
    <?php if ($nextMemberId): ?><a href="member_edit.php?id=<?= $nextMemberId ?>" class="btn btn-outline-secondary btn-sm">Next →</a><?php endif; ?>
</nav>
<?php endif; ?>
<h1 class="h2 mb-3 mb-md-4"><?= $isNew ? 'New member' : 'Edit: ' . $pageTitle ?></h1>

<?php if ($memberId): ?>

<?php /* ── Process / renewal action bar ─────────────────────────── */ ?>
<div class="d-flex flex-wrap align-items-center gap-3 mb-3 p-3 rounded border bg-light">

    <?php /* Primary CTA: launches the dedicated workflow page */ ?>
    <a href="member_process.php?id=<?= $memberId ?>"
       class="btn btn-primary btn-sm"
       title="Record dues, renewal year, and fulfillment tasks in one place">
        Process Signup / Renewal →
    </a>
    <p class="small text-muted mb-0 w-100">
        Use <strong>Process Signup / Renewal</strong> to record payments and renewal year &mdash; don&rsquo;t change renewal year by hand unless you mean to correct data.
    </p>

    <?php /* ── Print shortcuts (outside workflow) ────────────────────── */ ?>
    <div class="vr d-none d-sm-block mx-1"></div>
    <a href="badge_print.php?id=<?= $memberId ?>"
       class="btn btn-outline-primary btn-sm" title="Print this member's ID card">
        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor"
             class="me-1" viewBox="0 0 16 16" aria-hidden="true">
            <path d="M2.5 8a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1"/>
            <path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2
                     2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2zm6
                     14H5a1 1 0 0 1-1-1v-1h8v1a1 1 0 0 1-1 1M4 3a1 1 0 0 1 1-1h6a1 1 0 0 1
                     1 1v2H4zm1 5a2 2 0 0 0-2 2v1H2a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h12a1 1 0
                     0 1 1 1v3a1 1 0 0 1-1 1h-1v-1a2 2 0 0 0-2-2z"/>
        </svg>
        Print ID Card
    </a>
    <a href="member_envelope.php?id=<?= $memberId ?>&from=edit"
       class="btn btn-outline-primary btn-sm" title="Print a mailing envelope for this member">
        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor"
             class="me-1" viewBox="0 0 16 16" aria-hidden="true">
            <path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm2-1a1 1 0
                     0 0-1 1v.217l7 4.2 7-4.2V4a1 1 0 0 0-1-1zm13 2.383-4.708 2.825L15 11.105zm-.034
                     6.876-5.64-3.471L8 9.583l-1.326-.795-5.64 3.47A1 1 0 0 0 2 13h12a1 1 0 0 0
                     .966-.741M1 11.105l4.708-2.897L1 5.383z"/>
        </svg>
        Print Envelope
    </a>

    <?php /* Show fulfillment status for the current/next renewal year as a lightweight hint */ ?>
    <?php
    $hintYear = (int) date('n') >= 10 ? (int) date('Y') + 1 : (int) date('Y');
    $fHint = null;
    try {
        $fHintStmt = $pdo->prepare('
            SELECT processed_at, card_printed_at, mailer_printed_at
            FROM member_fulfillments
            WHERE member_id = ? AND year = ?
        ');
        $fHintStmt->execute([$memberId, $hintYear]);
        $fHint = $fHintStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Table may not exist yet if migration hasn't run — silently ignore
    }
    ?>
    <?php if ($fHint): ?>
    <div class="small text-muted ms-auto">
        <?= $hintYear ?> status:
        <?php if ($fHint['processed_at']): ?>
        <span class="text-success">✓ Recorded</span>
        <?php else: ?>
        <span class="text-warning">Pending</span>
        <?php endif; ?>
        &middot;
        Card: <?= $fHint['card_printed_at'] ? '<span class="text-success">✓</span>' : '<span class="text-muted">–</span>' ?>
        &middot;
        Mailer: <?= $fHint['mailer_printed_at'] ? '<span class="text-success">✓</span>' : '<span class="text-muted">–</span>' ?>
    </div>
    <?php elseif (!empty($member['badge_printed_at'])): ?>
    <div class="small text-muted ms-auto">
        Card last printed <?= date('M j, Y', strtotime($member['badge_printed_at'])) ?>
    </div>
    <?php endif; ?>

</div>
<?php endif; ?>




<div class="card mb-3 mb-md-4">
    <div class="card-body p-0 p-md-3">
        <!-- Tabs: responsive nav (scroll on small screens) -->
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
            <?php if ($memberId): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link rounded-top" id="tab-incidents" data-bs-toggle="tab" data-bs-target="#pane-incidents" type="button" role="tab">Incidents</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link rounded-top" id="tab-payment" data-bs-toggle="tab" data-bs-target="#pane-payment" type="button" role="tab">Payment history</button>
            </li>
            <?php endif; ?>
        </ul>

        <div class="tab-content p-3 p-md-4" id="memberTabContent">
            <!-- Contact tab -->
            <div class="tab-pane fade show active" id="pane-contact" role="tabpanel">
                <form method="post" action="member_edit.php<?= $memberId ? '?id=' . $memberId : '' ?>" enctype="multipart/form-data" id="member-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_member">
                    <div class="row g-3">
                        <div class="col-12 col-md-6 col-lg-4 order-2 order-md-1">
                            <label class="form-label">Photo</label>
                            <?php if (!empty($member['photo_path']) && is_readable(__DIR__ . '/' . $member['photo_path'])): ?>
                            <p class="mb-2"><img src="<?= h($member['photo_path']) ?>?t=<?= time() ?>" alt="Member photo" class="img-thumbnail rounded d-block" style="max-width:160px;max-height:160px;object-fit:cover;"></p>
                            <?php else: ?>
                            <p class="mb-2 text-muted small">No photo</p>
                            <?php endif; ?>
                            <input type="file" class="form-control form-control-sm" name="photo" accept="image/jpeg,image/png,image/gif"> <small class="text-muted">Optional, max 5MB</small>
                        </div>
                        <div class="col-12 col-md-6 col-lg-8 order-1 order-md-2">
                            <div class="row g-2 g-md-3">
                                <div class="col-4 col-md-2"><label class="form-label">Title</label><input type="text" class="form-control" name="title" value="<?= h($member['title'] ?? '') ?>" placeholder="Mr. / Ms."></div>
                                <div class="col-8 col-md-5"><label class="form-label">First name</label><input type="text" class="form-control" name="first_name" value="<?= h($member['first_name'] ?? '') ?>" required></div>
                                <div class="col-12 col-md-5"><label class="form-label">Last name</label><input type="text" class="form-control" name="last_name" id="last_name" value="<?= h($member['last_name'] ?? '') ?>" required></div>
                                <div class="col-12 col-md-8"><label class="form-label">Email</label><input type="email" class="form-control" name="email" value="<?= h($member['email'] ?? '') ?>"></div>
                                <div class="col-12 col-md-4"><label class="form-label">Phone</label><input type="text" class="form-control" name="phone" value="<?= h($member['phone'] ?? '') ?>" placeholder="Phone number"></div>
                                <div class="col-12 col-md-4"><label class="form-label">Birthday</label><input type="date" class="form-control" name="birthday" value="<?= h($member['birthday'] ?? '') ?>"></div>
                                <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2"><?= h($member['notes'] ?? '') ?></textarea></div>
                                <div class="col-12 mt-2 pt-2 border-top">
                                    <label class="form-label text-muted small text-uppercase fw-semibold">Emergency contact</label>
                                    <div class="row g-2">
                                        <div class="col-12 col-md-5"><input type="text" class="form-control form-control-sm" name="emergency_contact_name" value="<?= h($member['emergency_contact_name'] ?? '') ?>" placeholder="Name"></div>
                                        <div class="col-12 col-md-3"><input type="text" class="form-control form-control-sm" name="emergency_contact_relationship" value="<?= h($member['emergency_contact_relationship'] ?? '') ?>" placeholder="Relationship (e.g. Spouse, Parent)"></div>
                                        <div class="col-12 col-md-4"><input type="text" class="form-control form-control-sm" name="emergency_contact_phone" value="<?= h($member['emergency_contact_phone'] ?? '') ?>" placeholder="Phone"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php require __DIR__ . '/includes/member_sender_status.php'; ?>
                        <div class="col-12 order-4">
                            <label class="form-label">Mailing address</label>
                            <div class="row g-2">
                                <div class="col-12 col-md-6"><input type="text" class="form-control form-control-sm" name="address_street" placeholder="Street" value="<?= h($member['address_street'] ?? '') ?>"></div>
                                <div class="col-12 col-md-6"><input type="text" class="form-control form-control-sm" name="address_street2" placeholder="Suite / Apt" value="<?= h($member['address_street2'] ?? '') ?>"></div>
                            </div>
                            <div class="row g-2 mt-1">
                                <div class="col-12 col-md-4"><input type="text" class="form-control form-control-sm" name="address_city" placeholder="City" value="<?= h($member['address_city'] ?? '') ?>"></div>
                                <div class="col-6 col-md-2"><input type="text" class="form-control form-control-sm" name="address_state" placeholder="State" value="<?= h($member['address_state'] ?? '') ?>"></div>
                                <div class="col-6 col-md-3"><input type="text" class="form-control form-control-sm" name="address_postal_code" placeholder="Postal code" value="<?= h($member['address_postal_code'] ?? '') ?>"></div>
                            </div>
                        </div>
                    </div>
            </div>

            <!-- Compliance tab (AMA/FAA) -->
            <div class="tab-pane fade" id="pane-compliance" role="tabpanel">
                    <?php require __DIR__ . '/includes/member_compliance_fields.php'; ?>
            </div>

            <!-- Membership tab -->
            <div class="tab-pane fade" id="pane-membership" role="tabpanel">
                    <div class="row g-3">
                        <div class="col-12 col-sm-6 col-md-3">
                            <label class="form-label">Date joined</label>
                            <input type="date" class="form-control" name="date_joined" value="<?= h($member['date_joined'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <label class="form-label">Membership type</label>
                            <select name="membership_type_slot" class="form-select">
                                <option value="">—</option>
                                <?php $curSlot = (int) ($member['membership_type_slot'] ?? 0); ?>
                                <?php foreach ($membershipTypeLabels as $slot => $label): ?>
                                <option value="<?= (int) $slot ?>"<?= $curSlot === (int) $slot ? ' selected' : '' ?>>
                                    <?= h($label) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-sm-6 col-md-2">
                            <label class="form-label">Renewal year</label>
                            <input type="number" class="form-control" name="membership_renewal_year" value="<?= h($member['membership_renewal_year'] ?? '') ?>" min="2000" max="2100" placeholder="e.g. 2026">
                        </div>
                        <div class="col-12 col-sm-6 col-md-2">
                            <label class="form-label">Gate key number</label>
                            <input type="text" class="form-control" name="gate_key_number" value="<?= h($member['gate_key_number'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <div class="d-flex flex-wrap gap-3">
                                <div class="form-check"><input class="form-check-input" type="checkbox" name="inactive" value="1"<?= checked($member['inactive'] ?? 0) ?>><label class="form-check-label">Inactive (archived — not a current member)</label></div>
                                <div class="form-check"><input class="form-check-input" type="checkbox" name="life_member" value="1"<?= checked($member['life_member'] ?? 0) ?>><label class="form-check-label">Life member</label></div>
                                <div class="form-check"><input class="form-check-input" type="checkbox" name="free_membership" value="1"<?= checked($member['free_membership'] ?? 0) ?>><label class="form-check-label">Free membership</label></div>
                                <div class="form-check"><input class="form-check-input" type="checkbox" name="suspended" value="1"<?= checked($member['suspended'] ?? 0) ?>><label class="form-check-label">Suspended</label></div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <?php if ($memberId): ?>
            <!-- Incidents tab (read-only summary + "View all") -->
            <div class="tab-pane fade" id="pane-incidents" role="tabpanel">
                <?php require_once __DIR__ . '/includes/member_incidents_tab.php'; ?>
            </div>

            <!-- Payment history tab (read-only + add form; not inside main form) -->
            <div class="tab-pane fade" id="pane-payment" role="tabpanel">
                <?php if (count($payments) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead><tr><th>Date</th><th>Year</th><th>Dues</th><th>Initiation</th><th>Late fee</th><th>Comp</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($payments as $p): ?>
                            <tr>
                                <td><?= h($p['paid_at']) ?></td>
                                <td><?= h($p['year']) ?></td>
                                <td><?= h($p['amount_dues']) ?></td>
                                <td><?= h($p['amount_initiation']) ?></td>
                                <td><?= h($p['amount_late_fee']) ?></td>
                                <td><?= $p['comp'] ? 'Yes' : '' ?></td>
                                <td class="text-nowrap">
                                    <?php if (canManagePayments()): ?>
                                    <form method="post" action="payment_delete.php" class="d-inline"
                                          data-confirm-submit="Delete this payment? This permanently removes it from the record.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                                        <input type="hidden" name="member_id" value="<?= (int) $memberId ?>">
                                        <input type="hidden" name="return" value="edit">
                                        <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1">Delete</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p class="text-muted mb-0">No payments recorded.</p>
                <?php endif; ?>

                <div class="border rounded p-3 mt-3 bg-light border-primary border-opacity-25">
                    <strong>Add payment</strong>
                    <p class="small text-muted mb-2 mb-sm-3">This form posts separately from <strong>Save member</strong> below. After entering a payment, click <strong>Add payment</strong> here (not Save member).</p>
                    <form method="post" action="payment_add.php?id=<?= $memberId ?>" class="mt-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="member_id" value="<?= $memberId ?>">
                        <div class="row g-2 align-items-end flex-wrap">
                            <div class="col-6 col-sm-auto"><label class="form-label small mb-0">Date</label><input type="date" class="form-control form-control-sm" name="paid_at" value="<?= date('Y-m-d') ?>" required></div>
                            <div class="col-6 col-sm-auto"><label class="form-label small mb-0">Year</label><input type="number" class="form-control form-control-sm" name="year" value="<?= date('Y') ?>" min="2000" max="2100" required></div>
                            <div class="col-6 col-sm-auto"><label class="form-label small mb-0">Dues</label><input type="number" class="form-control form-control-sm" name="amount_dues" step="0.01" value="0"></div>
                            <div class="col-6 col-sm-auto"><label class="form-label small mb-0">Initiation</label><input type="number" class="form-control form-control-sm" name="amount_initiation" step="0.01" value="0"></div>
                            <div class="col-6 col-sm-auto"><label class="form-label small mb-0">Late fee</label><input type="number" class="form-control form-control-sm" name="amount_late_fee" step="0.01" value="0"></div>
                            <div class="col-6 col-sm-auto"><div class="form-check"><input class="form-check-input" type="checkbox" name="comp" value="1"><label class="form-check-label small">Comp</label></div></div>
                            <div class="col-12 col-sm-auto"><button type="submit" class="btn btn-primary btn-sm">Add payment</button></div>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="p-3 border-top bg-light">
            <button type="submit" form="member-form" class="btn btn-primary">Save member</button>
            <a href="members.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>
</div>

<?php if ($memberId): ?>
<div class="d-flex flex-wrap gap-2 mb-4">
    <form method="post" action="member_delete.php" class="d-inline" data-confirm-submit="Delete this member? This cannot be undone.">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $memberId ?>">
        <button type="submit" class="btn btn-danger">Delete member</button>
    </form>
</div>
<?php endif; ?>

<script src="js/member_edit.js" defer></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
