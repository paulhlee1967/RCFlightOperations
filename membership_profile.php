<?php
/**
 * membership_profile.php — Member self-service profile (magic-link session).
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/flash.php';
require_once __DIR__ . '/includes/member_portal.php';
require_once __DIR__ . '/includes/member_compliance_helpers.php';
require_once __DIR__ . '/includes/dues_helpers.php';

$memberId = member_portal_require_member();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_profile') {
        $result = member_portal_save($pdo, $memberId, $_POST, $_FILES);
        if ($result['ok']) {
            flash(
                $result['changed'] === []
                    ? 'No changes to save.'
                    : 'Your membership profile has been updated.',
                $result['changed'] === [] ? 'info' : 'success'
            );
        } else {
            flash(implode(' ', array_values($result['errors'])), 'warning');
        }
        header('Location: membership_profile.php');
        exit;
    }
}

$stmt = $pdo->prepare('SELECT * FROM members WHERE id = ? LIMIT 1');
$stmt->execute([$memberId]);
$member = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$member || !empty($member['suspended'])) {
    member_portal_session_clear();
    header('Location: membership.php');
    exit;
}

$membershipTypeLabels = enabledMembershipTypeLabels($pdo);
$typeLabel = '';
$slot = (int) ($member['membership_type_slot'] ?? 0);
if ($slot > 0 && isset($membershipTypeLabels[$slot])) {
    $typeLabel = $membershipTypeLabels[$slot];
}

$payments = [];
try {
    $pStmt = $pdo->prepare(
        'SELECT paid_at, year, amount_dues, amount_initiation, amount_late_fee, comp
         FROM payments WHERE member_id = ? ORDER BY paid_at DESC, id DESC LIMIT 5'
    );
    $pStmt->execute([$memberId]);
    $payments = $pStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$hintYear = defaultRenewalYear($pdo);
$fulfillment = null;
try {
    $fStmt = $pdo->prepare(
        'SELECT processed_at, card_printed_at, mailer_printed_at
         FROM member_fulfillments WHERE member_id = ? AND year = ? LIMIT 1'
    );
    $fStmt->execute([$memberId, $hintYear]);
    $fulfillment = $fStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
}

$renewalOpen = membership_application_renewal_open(new DateTimeImmutable('now'), $pdo);
$hasPhoto = !empty($member['photo_path']) && is_readable(__DIR__ . '/' . ltrim((string) $member['photo_path'], '/'));
$hasFaaCard = member_faa_card_has_file($member);
$faaIsImage = $hasFaaCard && member_faa_card_is_image($member);
$faaIsPdf = $hasFaaCard && member_faa_card_is_pdf($member);
$clubName = member_portal_club_name($pdo);

$statusParts = [];
if (!empty($member['life_member'])) {
    $statusParts[] = 'Life member';
}
if (!empty($member['free_membership'])) {
    $statusParts[] = 'Complimentary';
}
if (!empty($member['inactive'])) {
    $statusParts[] = 'Inactive';
}
$statusLabel = $statusParts !== [] ? implode(' · ', $statusParts) : 'Active roster';

$noNav = true;
$pageTitle = 'My Membership Profile';
require_once __DIR__ . '/includes/header.php';

$flash = getFlash();
?>

<div class="container py-4 mb-5" style="max-width:920px;">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1"><?= h($clubName) ?></p>
            <h1 class="h3 mb-1">My membership</h1>
            <p class="text-muted mb-0">
                <?= h(trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''))) ?>
            </p>
        </div>
        <a href="membership_logout.php" class="btn btn-outline-secondary btn-sm">Sign out</a>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <section class="border rounded-3 p-3 p-md-4 mb-4 bg-light bg-opacity-50">
        <h2 class="h6 text-uppercase text-muted fw-semibold mb-3">Membership status</h2>
        <div class="row g-3 small">
            <div class="col-6 col-md-3">
                <div class="text-muted">Email</div>
                <div class="fw-semibold"><?= h($member['email'] ?? '') ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted">Type</div>
                <div class="fw-semibold"><?= h($typeLabel !== '' ? $typeLabel : '—') ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted">Renewal year</div>
                <div class="fw-semibold"><?= h((string) ($member['membership_renewal_year'] ?? '—')) ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted">Status</div>
                <div class="fw-semibold"><?= h($statusLabel) ?></div>
            </div>
            <?php if (!empty($member['birthday'])): ?>
            <div class="col-6 col-md-3">
                <div class="text-muted">Birthday</div>
                <div class="fw-semibold"><?= h(formatDate((string) $member['birthday'])) ?></div>
            </div>
            <?php endif; ?>
            <?php if (trim((string) ($member['gate_key_number'] ?? '')) !== ''): ?>
            <div class="col-6 col-md-3">
                <div class="text-muted">Gate key</div>
                <div class="fw-semibold"><?= h($member['gate_key_number']) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($fulfillment): ?>
            <div class="col-12 col-md-6">
                <div class="text-muted"><?= (int) $hintYear ?> fulfillment</div>
                <div class="fw-semibold">
                    Recorded: <?= !empty($fulfillment['processed_at']) ? 'Yes' : 'Pending' ?>
                    · Card: <?= !empty($fulfillment['card_printed_at']) ? 'Printed' : '—' ?>
                    · Mailer: <?= !empty($fulfillment['mailer_printed_at']) ? 'Printed' : '—' ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($renewalOpen): ?>
            <p class="small mb-0 mt-3">
                Renewal season is open.
                <a href="apply.php">Renew online</a> if you still need to submit dues for the upcoming year.
            </p>
        <?php endif; ?>
        <p class="small text-muted mb-0 mt-2">
            Name, email, and membership status are managed by the club. Contact the membership team to change them.
        </p>
    </section>

    <form method="post" action="membership_profile.php" enctype="multipart/form-data" id="member-portal-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_profile">
        <input type="hidden" id="page_csrf_token" value="<?= h(csrf_token()) ?>">

        <section class="border rounded-3 p-3 p-md-4 mb-4">
            <h2 class="h6 text-uppercase text-muted fw-semibold mb-3">Contact</h2>
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label">Badge photo</label>
                    <?php if ($hasPhoto): ?>
                        <p class="mb-2">
                            <img src="membership_media.php?type=photo&amp;t=<?= time() ?>" alt="Badge photo"
                                 class="img-thumbnail rounded d-block" style="max-width:140px;max-height:140px;object-fit:cover;">
                        </p>
                    <?php else: ?>
                        <p class="mb-2 text-muted small">No photo on file</p>
                    <?php endif; ?>
                    <input type="file" class="form-control form-control-sm" name="photo" accept="image/jpeg,image/png,image/gif">
                    <small class="text-muted">JPEG, PNG, or GIF · max 5 MB</small>
                </div>
                <div class="col-12 col-md-8">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="phone">Phone</label>
                            <input type="text" class="form-control" name="phone" id="phone" value="<?= h($member['phone'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label text-muted small text-uppercase fw-semibold">Emergency contact</label>
                            <div class="row g-2">
                                <div class="col-md-5">
                                    <input type="text" class="form-control form-control-sm" name="emergency_contact_name"
                                           value="<?= h($member['emergency_contact_name'] ?? '') ?>" placeholder="Name">
                                </div>
                                <div class="col-md-3">
                                    <input type="text" class="form-control form-control-sm" name="emergency_contact_relationship"
                                           value="<?= h($member['emergency_contact_relationship'] ?? '') ?>" placeholder="Relationship">
                                </div>
                                <div class="col-md-4">
                                    <input type="text" class="form-control form-control-sm" name="emergency_contact_phone"
                                           value="<?= h($member['emergency_contact_phone'] ?? '') ?>" placeholder="Phone">
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Mailing address</label>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <input type="text" class="form-control form-control-sm" name="address_street"
                                           placeholder="Street" value="<?= h($member['address_street'] ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <input type="text" class="form-control form-control-sm" name="address_street2"
                                           placeholder="Suite / Apt" value="<?= h($member['address_street2'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <input type="text" class="form-control form-control-sm" name="address_city"
                                           placeholder="City" value="<?= h($member['address_city'] ?? '') ?>">
                                </div>
                                <div class="col-6 col-md-2">
                                    <input type="text" class="form-control form-control-sm" name="address_state"
                                           placeholder="State" value="<?= h($member['address_state'] ?? '') ?>">
                                </div>
                                <div class="col-6 col-md-3">
                                    <input type="text" class="form-control form-control-sm" name="address_postal_code"
                                           placeholder="Postal code" value="<?= h($member['address_postal_code'] ?? '') ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="border rounded-3 p-3 p-md-4 mb-4">
            <h2 class="h6 text-uppercase text-muted fw-semibold mb-3">Compliance</h2>
            <div class="row g-4">
                <div class="col-12 col-lg-6">
                    <div class="border rounded p-3 h-100 bg-light bg-opacity-50">
                        <h3 class="h6 mb-3">AMA membership</h3>
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label class="form-label" for="ama_number">AMA number</label>
                                <input type="text" class="form-control" name="ama_number" id="ama_number"
                                       value="<?= h($member['ama_number'] ?? '') ?>">
                            </div>
                            <div class="col-sm-6" id="ama-expiration-wrap">
                                <label class="form-label" for="ama_expiration">AMA expiration</label>
                                <input type="date" class="form-control" name="ama_expiration" id="ama_expiration"
                                       value="<?= h($member['ama_expiration'] ?? '') ?>">
                                <span id="ama-status-badge" class="ama-status-badge" aria-live="polite"></span>
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="ama_life_member" id="ama_life_member"
                                           value="1"<?= checked($member['ama_life_member'] ?? 0) ?>>
                                    <label class="form-check-label" for="ama_life_member">AMA life member</label>
                                </div>
                            </div>
                            <div class="col-12 d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center gap-2 pt-1 border-top">
                                <button type="button" class="btn btn-primary btn-sm flex-shrink-0" id="verify-ama-btn">
                                    Verify AMA membership
                                </button>
                                <span id="verify-ama-status" class="small flex-grow-1 border rounded px-2 py-2 bg-white"
                                      role="status" aria-live="polite"></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-6">
                    <div class="border rounded p-3 h-100 bg-light bg-opacity-50">
                        <h3 class="h6 mb-3">FAA registration</h3>
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label class="form-label" for="faa_number">FAA number</label>
                                <input type="text" class="form-control" name="faa_number" id="faa_number"
                                       value="<?= h($member['faa_number'] ?? '') ?>">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="faa_expiration">FAA expiration</label>
                                <input type="date" class="form-control" name="faa_expiration" id="faa_expiration"
                                       value="<?= h($member['faa_expiration'] ?? '') ?>">
                            </div>
                            <div class="col-12 pt-1 border-top">
                                <label class="form-label mb-2">FAA registration card</label>
                                <div class="row g-3">
                                    <div class="col-md-7">
                                        <?php if ($hasFaaCard): ?>
                                            <div class="bg-white border rounded p-2">
                                                <?php if ($faaIsImage): ?>
                                                    <img src="membership_media.php?type=faa&amp;t=<?= time() ?>" alt="FAA card"
                                                         class="img-fluid rounded d-block mx-auto" style="max-height:220px;object-fit:contain;">
                                                <?php elseif ($faaIsPdf): ?>
                                                    <iframe src="membership_media.php?type=faa#toolbar=0" title="FAA card"
                                                            class="w-100 rounded border-0" style="height:220px;"></iframe>
                                                <?php else: ?>
                                                    <p class="text-muted small mb-0 py-3 text-center">Card on file</p>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="bg-white border rounded text-center text-muted small py-4">No FAA card on file</div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-5">
                                        <input type="file" class="form-control form-control-sm" name="faa_card"
                                               accept="application/pdf,image/jpeg,image/png">
                                        <small class="text-muted d-block mt-1">PDF, JPG, or PNG · max 5 MB</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="border rounded-3 p-3 p-md-4 mb-4">
            <h2 class="h6 text-uppercase text-muted fw-semibold mb-3">Email preferences</h2>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="email_opt_in_club_events" id="email_opt_in_club_events"
                       value="1"<?= checked($member['email_opt_in_club_events'] ?? 1) ?>>
                <label class="form-check-label" for="email_opt_in_club_events">
                    Club event and announcement emails
                </label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="email_opt_in_expiry_reminders" id="email_opt_in_expiry_reminders"
                       value="1"<?= checked($member['email_opt_in_expiry_reminders'] ?? 1) ?>>
                <label class="form-check-label" for="email_opt_in_expiry_reminders">
                    AMA / FAA expiry reminder emails
                </label>
            </div>
        </section>

        <div class="d-flex flex-wrap gap-2 mb-4">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="membership_logout.php" class="btn btn-outline-secondary">Sign out</a>
        </div>
    </form>

    <?php if ($payments !== []): ?>
    <section class="border rounded-3 p-3 p-md-4">
        <h2 class="h6 text-uppercase text-muted fw-semibold mb-3">Recent payments</h2>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr><th>Date</th><th>Year</th><th>Dues</th><th>Initiation</th><th>Late fee</th><th>Comp</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $p): ?>
                    <tr>
                        <td><?= h($p['paid_at']) ?></td>
                        <td><?= h($p['year']) ?></td>
                        <td><?= h(formatMoney((float) $p['amount_dues'])) ?></td>
                        <td><?= h(formatMoney((float) $p['amount_initiation'])) ?></td>
                        <td><?= h(formatMoney((float) $p['amount_late_fee'])) ?></td>
                        <td><?= !empty($p['comp']) ? 'Yes' : '' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>
</div>

<script src="js/membership_profile.js" defer></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
