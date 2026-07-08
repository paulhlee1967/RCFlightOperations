<?php
/**
 * member_wizard.php — Guided new-member workflow.
 *
 * Steps 1–3: contact, compliance, membership (this page).
 * Steps 4–5: record signup and fulfillment (member_process.php?wizard=1).
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/flash.php';
require_once __DIR__ . '/includes/member_save.php';
require_once __DIR__ . '/includes/member_wizard_nav.php';

requireLogin();
if (!canEditMembers()) {
    header('Location: index.php');
    exit;
}

$memberId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$initialStep = (string) ($_GET['step'] ?? 'contact');
if (!in_array($initialStep, MEMBER_WIZARD_FORM_STEPS, true)) {
    $initialStep = 'contact';
}
$returnToProcess = isset($_GET['return']) && $_GET['return'] === 'process';
$focusField = trim((string) ($_GET['field'] ?? ''));
$membershipTypeLabels = enabledMembershipTypeLabels($pdo);
$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_wizard') {
    $resumeId = isset($_POST['member_id']) ? (int) $_POST['member_id'] : null;

    [$valErrors, $clean] = validate_member_input($_POST);
    if (empty($clean['membership_type_slot'])) {
        $valErrors['membership_type_slot'] = 'Membership type is required.';
    }
    if ($valErrors !== []) {
        flash(implode(' ', array_values($valErrors)), 'warning');
        header('Location: member_wizard.php' . ($resumeId ? '?id=' . $resumeId : ''));
        exit;
    }

    $result = save_member_from_post($pdo, $resumeId ?: null, $_POST, $_FILES);
    if (!$result['ok']) {
        flash(implode(' ', array_values($result['errors'])), 'warning');
        header('Location: member_wizard.php' . ($resumeId ? '?id=' . $resumeId : ''));
        exit;
    }

    $memberId = (int) $result['member_id'];
    $workYear = defaultRenewalYear($pdo);
    header('Location: member_process.php?id=' . $memberId . '&wizard=1&year=' . $workYear . '&renewal_type=new#record');
    exit;
}

$member = [];
$isResume = false;

if ($memberId) {
    $stmt = $pdo->prepare('SELECT * FROM members WHERE id = ?');
    $stmt->execute([$memberId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        header('Location: member_wizard.php');
        exit;
    }
    $isResume = true;
}

if (!$isResume) {
    $member = [
        'date_joined' => $today,
    ];
}

$pageTitle = $isResume
    ? 'Continue signup: ' . trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''))
    : 'New member wizard';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/member_wizard_styles.php';
?>

<nav class="d-flex flex-wrap align-items-center gap-2 mb-3 pb-2 border-bottom">
    <a href="members.php" class="btn btn-outline-secondary btn-sm">← Back to Members</a>
    <?php if ($isResume): ?>
    <a href="member_edit.php?id=<?= (int) $memberId ?>" class="btn btn-outline-secondary btn-sm ms-auto">Full edit form</a>
    <?php endif; ?>
</nav>

<div class="mb-2">
    <h1 class="h2 mb-1"><?= $isResume ? 'Continue new member signup' : 'New member wizard' ?></h1>
    <p class="text-muted mb-0">
        Enter contact and compliance details, then record the first signup payment and print materials — all in one flow.
    </p>
</div>

<?php render_member_wizard_nav($initialStep); ?>

<?php if ($returnToProcess): ?>
<div class="alert alert-info py-2 mb-3">
    Update the highlighted field, then click <strong>Save &amp; return to signup</strong> to continue where you left off.
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body p-3 p-md-4">
        <form method="post" action="member_wizard.php<?= $memberId ? '?id=' . (int) $memberId : '' ?>" enctype="multipart/form-data" id="wizard-form" novalidate
              data-initial-step="<?= h($initialStep) ?>"
              data-focus-field="<?= h($focusField) ?>"
              data-return-process="<?= $returnToProcess ? '1' : '0' ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_wizard">
            <?php if ($returnToProcess): ?>
            <input type="hidden" name="return_to_process" value="1">
            <?php endif; ?>
            <?php if ($memberId): ?>
            <input type="hidden" name="member_id" value="<?= (int) $memberId ?>">
            <?php endif; ?>
            <input type="hidden" id="page_csrf_token" value="<?= h(csrf_token()) ?>">

            <!-- Step 1: Contact -->
            <div class="wizard-step-panel<?= $initialStep === 'contact' ? ' is-active' : '' ?>" data-wizard-step="1" id="wizard-step-contact">
                <h2 class="h5 mb-3">Contact information</h2>
                <div class="row g-3">
                    <div class="col-12 col-md-6 col-lg-4">
                        <label class="form-label">Photo</label>
                        <?php if (!empty($member['photo_path']) && is_readable(__DIR__ . '/' . $member['photo_path'])): ?>
                        <p class="mb-2"><img src="<?= h($member['photo_path']) ?>?t=<?= time() ?>" alt="Member photo" class="img-thumbnail rounded d-block" style="max-width:160px;max-height:160px;object-fit:cover;"></p>
                        <?php else: ?>
                        <p class="mb-2 text-muted small">Optional — used on the ID card.</p>
                        <?php endif; ?>
                        <input type="file" class="form-control form-control-sm" name="photo" accept="image/jpeg,image/png,image/gif">
                        <small class="text-muted">Max 5MB</small>
                    </div>
                    <div class="col-12 col-md-6 col-lg-8">
                        <div class="row g-2 g-md-3">
                            <div class="col-4 col-md-2">
                                <label class="form-label">Title</label>
                                <input type="text" class="form-control" name="title" value="<?= h($member['title'] ?? '') ?>" placeholder="Mr. / Ms.">
                            </div>
                            <div class="col-8 col-md-5">
                                <label class="form-label">First name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="first_name" id="first_name" value="<?= h($member['first_name'] ?? '') ?>" required>
                            </div>
                            <div class="col-12 col-md-5">
                                <label class="form-label">Last name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="last_name" id="last_name" value="<?= h($member['last_name'] ?? '') ?>" required>
                            </div>
                            <div class="col-12 col-md-8">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" value="<?= h($member['email'] ?? '') ?>">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Phone</label>
                                <input type="text" class="form-control" name="phone" value="<?= h($member['phone'] ?? '') ?>" placeholder="Phone number">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Birthday</label>
                                <input type="date" class="form-control" name="birthday" value="<?= h($member['birthday'] ?? '') ?>">
                            </div>
                            <div class="col-12 mt-2 pt-2 border-top">
                                <label class="form-label text-muted small text-uppercase fw-semibold">Emergency contact</label>
                                <div class="row g-2">
                                    <div class="col-12 col-md-5">
                                        <input type="text" class="form-control form-control-sm" name="emergency_contact_name" value="<?= h($member['emergency_contact_name'] ?? '') ?>" placeholder="Name">
                                    </div>
                                    <div class="col-12 col-md-3">
                                        <input type="text" class="form-control form-control-sm" name="emergency_contact_relationship" value="<?= h($member['emergency_contact_relationship'] ?? '') ?>" placeholder="Relationship">
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <input type="text" class="form-control form-control-sm" name="emergency_contact_phone" value="<?= h($member['emergency_contact_phone'] ?? '') ?>" placeholder="Phone">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
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
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="Optional internal notes"><?= h($member['notes'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Step 2: Compliance -->
            <div class="wizard-step-panel<?= $initialStep === 'compliance' ? ' is-active' : '' ?>" data-wizard-step="2" id="wizard-step-compliance">
                <h2 class="h5 mb-2">AMA &amp; FAA compliance</h2>
                <p class="text-muted small mb-3">Verify AMA membership before recording signup. FAA registration is recommended for mailer packets.</p>
                <?php require __DIR__ . '/includes/member_compliance_fields.php'; ?>
            </div>

            <!-- Step 3: Membership -->
            <div class="wizard-step-panel<?= $initialStep === 'membership' ? ' is-active' : '' ?>" data-wizard-step="3" id="wizard-step-membership">
                <h2 class="h5 mb-3">Membership details</h2>
                <div class="row g-3">
                    <div class="col-12 col-sm-6 col-md-4">
                        <label class="form-label">Date joined</label>
                        <input type="date" class="form-control" name="date_joined" value="<?= h($member['date_joined'] ?? $today) ?>">
                    </div>
                    <div class="col-12 col-sm-6 col-md-4">
                        <label class="form-label">Membership type <span class="text-danger">*</span></label>
                        <select name="membership_type_slot" class="form-select" id="membership_type_slot" required>
                            <option value="">— Select type —</option>
                            <?php $curSlot = (int) ($member['membership_type_slot'] ?? 0); ?>
                            <?php foreach ($membershipTypeLabels as $slot => $label): ?>
                            <option value="<?= (int) $slot ?>"<?= $curSlot === (int) $slot ? ' selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6 col-md-4">
                        <label class="form-label">Gate key number</label>
                        <input type="text" class="form-control" name="gate_key_number" value="<?= h($member['gate_key_number'] ?? '') ?>" placeholder="Optional">
                    </div>
                    <div class="col-12">
                        <p class="text-muted small mb-0">
                            Renewal year and payment are recorded on the next step. Leave renewal year blank for now.
                        </p>
                        <input type="hidden" name="membership_renewal_year" value="<?= h($member['membership_renewal_year'] ?? '') ?>">
                        <input type="hidden" name="inactive" value="<?= (int) ($member['inactive'] ?? 0) ?>">
                        <input type="hidden" name="suspended" value="<?= (int) ($member['suspended'] ?? 0) ?>">
                        <input type="hidden" name="life_member" value="<?= (int) ($member['life_member'] ?? 0) ?>">
                        <input type="hidden" name="free_membership" value="<?= (int) ($member['free_membership'] ?? 0) ?>">
                    </div>
                </div>
            </div>

            <div class="d-flex flex-wrap align-items-center gap-2 pt-4 mt-2 border-top">
                <button type="button" class="btn btn-outline-secondary" id="wizard-back" style="display:none;">← Back</button>
                <?php if ($returnToProcess && $memberId): ?>
                <button type="submit" class="btn btn-primary" id="wizard-return">Save &amp; return to signup →</button>
                <?php endif; ?>
                <button type="button" class="btn btn-primary ms-auto" id="wizard-next">Next →</button>
                <button type="submit" class="btn btn-primary ms-auto" id="wizard-submit" style="display:none;">Save &amp; record signup →</button>
                <?php if ($returnToProcess && $memberId): ?>
                <a href="member_process.php?id=<?= (int) $memberId ?>&wizard=1#record" class="btn btn-link text-muted" id="wizard-cancel-return">Cancel</a>
                <?php endif; ?>
                <a href="members.php" class="btn btn-link text-muted" id="wizard-cancel">Cancel</a>
            </div>
        </form>
    </div>
</div>

<p class="text-muted small">
    <?php if ($memberId): ?>
    Need every field? <a href="member_edit.php?id=<?= (int) $memberId ?>">Open full member edit</a>.
    <?php endif; ?>
</p>

<script src="js/member_edit.js" defer></script>
<script src="js/member_wizard.js" defer></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
