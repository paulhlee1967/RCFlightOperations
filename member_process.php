<?php
/**
 * member_process.php — Signup / Renewal Processing Workflow
 *
 * A focused, three-panel workflow page for processing a member's signup or
 * renewal. Replaces the cramped inline card on member_edit.php.
 *
 * Workflow panels:
 *   1. Review   — current member data at a glance; flags missing info
 *   2. Record   — record the renewal/signup type, year, dues
 *   3. Fulfill  — checklist: print ID card, print mailing packet
 *
 * The fulfillment state lives in member_fulfillments (one row per member/year).
 * Print actions are handled by their own dedicated pages (badge_print.php,
 * member_mailer.php) so this page has no hard dependency on print internals.
 * When badge printing is overhauled, only badge_print.php needs to change.
 *
 * GET  ?id=N              — load member, show current state
 * GET  ?id=N&year=Y       — jump to a specific renewal year's fulfillment
 * POST action=record_renewal — insert payment (if any), update member renewal year / notes /
 *     flags from complementary options, upsert member_fulfillments for the year.
 * POST action=mark_card — set card_printed_at / card_printed_by on member_fulfillments; set members.badge_printed_at.
 * POST action=mark_mailer — set mailer_printed_at / mailer_printed_by on member_fulfillments.
 * POST action=unmark — clear card or mailer fulfillment timestamps (task from POST).
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit_log.php';
require_once __DIR__ . '/includes/member_wizard_nav.php';

requireLogin();
if (!canEditMembers() && !canProcessMemberships()) {
    header('Location: index.php');
    exit;
}

$userId = currentUserId();

$membershipTypeLabels = enabledMembershipTypeLabels($pdo);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Fetch or create a fulfillment row for a given member + year.
 * Returns the row as an associative array.
 */
function getFulfillment(PDO $pdo, int $memberId, int $year): array {
    $stmt = $pdo->prepare('
        SELECT * FROM member_fulfillments
        WHERE member_id = ? AND year = ?
    ');
    $stmt->execute([$memberId, $year]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }
    return [
        'id'                => null,
        'member_id'         => $memberId,
        'year'              => $year,
        'processed_at'      => null,
        'processed_by'      => null,
        'renewal_type'      => null,
        'card_printed_at'   => null,
        'card_printed_by'   => null,
        'mailer_printed_at' => null,
        'mailer_printed_by' => null,
    ];
}

/**
 * Ensure a fulfillment row exists (INSERT IGNORE), then UPDATE a specific task.
 *
 * @param string $field   Column name to set (card_printed_at, mailer_printed_at, etc.)
 * @param string $byField Corresponding _by column (card_printed_by, etc.)
 */
function markFulfillmentTask(
    PDO $pdo,
    int $memberId,
    int $year,
    int $userId,
    string $field,
    string $byField
): void {
    $pdo->prepare('
        INSERT INTO member_fulfillments (member_id, year)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE id = id
    ')->execute([$memberId, $year]);

    $pdo->prepare("
        UPDATE member_fulfillments
        SET `{$field}` = NOW(), `{$byField}` = ?
        WHERE member_id = ? AND year = ?
    ")->execute([$userId, $memberId, $year]);
}

/**
 * Clear a fulfillment task (set back to NULL).
 *
 * @param string $field   Column to clear
 * @param string $byField Corresponding _by column
 */
function unmarkFulfillmentTask(
    PDO $pdo,
    int $memberId,
    int $year,
    string $field,
    string $byField
): void {
    $pdo->prepare("
        UPDATE member_fulfillments
        SET `{$field}` = NULL, `{$byField}` = NULL
        WHERE member_id = ? AND year = ?
    ")->execute([$memberId, $year]);
}

// ---------------------------------------------------------------------------
// Load member
// ---------------------------------------------------------------------------
$memberId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($memberId <= 0) {
    header('Location: members.php');
    exit;
}

$fromWizard = !empty($_GET['wizard']) || !empty($_POST['wizard']);
$wizardRenewalType = $_GET['renewal_type'] ?? $_POST['renewal_type'] ?? '';
if (!in_array($wizardRenewalType, ['new', 'on_time', 'late'], true)) {
    $wizardRenewalType = '';
}

$stmt = $pdo->prepare('
    SELECT m.*,
           (SELECT street FROM member_addresses WHERE member_id = m.id ORDER BY FIELD(type,"Home","Work","Other") LIMIT 1) AS addr_street,
           (SELECT street2 FROM member_addresses WHERE member_id = m.id ORDER BY FIELD(type,"Home","Work","Other") LIMIT 1) AS addr_street2,
           (SELECT city FROM member_addresses WHERE member_id = m.id ORDER BY FIELD(type,"Home","Work","Other") LIMIT 1) AS addr_city,
           (SELECT state FROM member_addresses WHERE member_id = m.id ORDER BY FIELD(type,"Home","Work","Other") LIMIT 1) AS addr_state,
           (SELECT postal_code FROM member_addresses WHERE member_id = m.id ORDER BY FIELD(type,"Home","Work","Other") LIMIT 1) AS addr_postal
    FROM members m
    WHERE m.id = ?
');
$stmt->execute([$memberId]);
$member = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$member) {
    header('Location: members.php');
    exit;
}

/** Prefetch dues rules once for renewal POST + preview UI. */
$prefetchedRules = duesRules($pdo);

// Which year are we working on?
$workYear = isset($_GET['year']) ? (int) $_GET['year'] : defaultRenewalYear($pdo);

// ---------------------------------------------------------------------------
// POST: Record renewal
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_renewal') {
    if (!canEditMembers()) {
        header('Location: member_process.php?id=' . $memberId . '&error=permission');
        exit;
    }

    $renewalYear  = (int) ($_POST['renewal_year'] ?? defaultRenewalYear($pdo));
    $renewalType  = $_POST['renewal_type'] ?? ''; // new | on_time | late
    $complementary     = !empty($_POST['complementary']);
    $complementaryStatus = $_POST['complementary_status'] ?? '';
    if (!in_array($complementaryStatus, ['', 'free_membership', 'life_member'], true)) {
        $complementaryStatus = '';
    }

    // AMA validation (life members exempt)
    $amaLifeMember = !empty($member['ama_life_member']);
    $minAmaExp     = $renewalYear . '-12-31';
    $amaExpRecord  = trim($member['ama_expiration'] ?? '');

    if (!$amaLifeMember) {
        if ($amaExpRecord === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $amaExpRecord)) {
            header('Location: member_process.php?id=' . $memberId . '&year=' . $renewalYear . '&renewal_error=no_ama' . ($fromWizard ? '&wizard=1' : ''));
            exit;
        }
        if ($amaExpRecord < $minAmaExp) {
            header('Location: member_process.php?id=' . $memberId . '&year=' . $renewalYear . '&renewal_error=ama_before' . ($fromWizard ? '&wizard=1' : ''));
            exit;
        }
    } elseif ($amaLifeMember && $amaExpRecord !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $amaExpRecord) && $amaExpRecord < $minAmaExp) {
        error_log(sprintf(
            'member_process: recording renewal for member %d (%d) — AMA life member with stale expiration %s (ignored for gate)',
            $memberId,
            $renewalYear,
            $amaExpRecord
        ));
    }

    // Calculate dues — fetch rules once and pass in to avoid extra DB queries
    $paidAt          = date('Y-m-d');
    $typeSlot        = (int) ($member['membership_type_slot'] ?? 0);
    $calc            = calculateDues($pdo, $typeSlot, $renewalType, $prefetchedRules);
    $dues            = (float) $calc['dues'];
    $init            = (float) $calc['init'];

    $typeLabel = match ($renewalType) {
        'new'      => 'New Member (Prorated)',
        'on_time'  => 'On-Time Renewal',
        'late'     => 'New/Late Renewal',
        default    => 'Renewal',
    };

    if ($complementary) {
        $dues = 0.0;
        $init = 0.0;
        $typeLabel .= ' (Complementary)';
    }

    // Ensure dependent tables exist BEFORE opening the transaction. The table
    // creation issues DDL (CREATE TABLE), and in MySQL any DDL triggers an
    // implicit COMMIT — which would silently end the transaction mid-way and
    // make the later commit()/rollBack() fail with "no active transaction".
    ensureMembershipYearsTable($pdo);

    // Payment + member update must be atomic (prevents orphaned payment records).
    $pdo->beginTransaction();
    try {
        // For "late" renewals, store the additional fee in `amount_late_fee`
        // (reports aggregate late fees from this column).
        $lateFee       = $renewalType === 'late' ? $init : 0.0;
        $initiationFee = $renewalType === 'late' ? 0.0 : $init;

        // Insert payment record
        if ($dues > 0 || $init > 0 || $complementary) {
            $pdo->prepare('
                INSERT INTO payments (member_id, paid_at, year, amount_dues, amount_initiation, amount_late_fee, comp)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ')->execute([$memberId, $paidAt, $renewalYear, $dues, $initiationFee, $lateFee, $complementary ? 1 : 0]);
            audit_log($pdo, $userId, 'payment_add', 'payment', (int) $pdo->lastInsertId(), json_encode(['member_id' => $memberId, 'year' => $renewalYear]));
        }

        // Update member renewal year + optional status + notes
        $noteSuffix = "$typeLabel for $renewalYear recorded on $paidAt";
        $existingNotes = trim($member['notes'] ?? '');
        $newNotes = $existingNotes !== '' ? $existingNotes . "\n" . $noteSuffix : $noteSuffix;

        if ($complementaryStatus === 'free_membership') {
            $pdo->prepare('UPDATE members SET membership_renewal_year = ?, notes = ?, free_membership = 1 WHERE id = ?')
                ->execute([$renewalYear, $newNotes, $memberId]);
        } elseif ($complementaryStatus === 'life_member') {
            $pdo->prepare('UPDATE members SET membership_renewal_year = ?, notes = ?, life_member = 1 WHERE id = ?')
                ->execute([$renewalYear, $newNotes, $memberId]);
        } else {
            $pdo->prepare('UPDATE members SET membership_renewal_year = ?, notes = ? WHERE id = ?')
                ->execute([$renewalYear, $newNotes, $memberId]);
        }

        $pdo->prepare('
            INSERT INTO member_fulfillments (member_id, year, processed_at, processed_by, renewal_type)
            VALUES (?, ?, NOW(), ?, ?)
            ON DUPLICATE KEY UPDATE processed_at = NOW(), processed_by = ?, renewal_type = ?
        ')->execute([$memberId, $renewalYear, $userId, $renewalType, $userId, $renewalType]);

        recordMemberMembershipYear($pdo, $memberId, $renewalYear, 'renewal');

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('member_process record_renewal failed: ' . $e->getMessage());
        header('Location: member_process.php?id=' . $memberId . '&year=' . $renewalYear . '&recorded=0&error=record_failed' . ($fromWizard ? '&wizard=1' : ''));
        exit;
    }

    $wizardQs = $fromWizard ? '&wizard=1' : '';
    header('Location: member_process.php?id=' . $memberId . '&year=' . $renewalYear . '&recorded=1' . $wizardQs . '#fulfill');
    exit;
}

// ---------------------------------------------------------------------------
// POST: Mark card printed (called from this page's checklist)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_card') {
    $year = (int) ($_POST['year'] ?? $workYear);
    markFulfillmentTask($pdo, $memberId, $year, $userId, 'card_printed_at', 'card_printed_by');
    $pdo->prepare('UPDATE members SET badge_printed_at = NOW() WHERE id = ?')
        ->execute([$memberId]);
    header('Location: member_process.php?id=' . $memberId . '&year=' . $year . '#fulfill');
    exit;
}

// ---------------------------------------------------------------------------
// POST: Mark mailer printed
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_mailer') {
    $year = (int) ($_POST['year'] ?? $workYear);
    markFulfillmentTask($pdo, $memberId, $year, $userId, 'mailer_printed_at', 'mailer_printed_by');
    header('Location: member_process.php?id=' . $memberId . '&year=' . $year . '#fulfill');
    exit;
}

// ---------------------------------------------------------------------------
// POST: Unmark a task
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unmark') {
    $year = (int) ($_POST['year'] ?? $workYear);
    $task = $_POST['task'] ?? '';
    $allowed = ['card_printed_at' => 'card_printed_by', 'mailer_printed_at' => 'mailer_printed_by'];
    if (isset($allowed[$task])) {
        unmarkFulfillmentTask($pdo, $memberId, $year, $task, $allowed[$task]);
    }
    header('Location: member_process.php?id=' . $memberId . '&year=' . $year . '#fulfill');
    exit;
}

// ---------------------------------------------------------------------------
// Load fulfillment for display
// ---------------------------------------------------------------------------
$fulfillment = getFulfillment($pdo, $memberId, $workYear);

$payStmt = $pdo->prepare('
    SELECT * FROM payments
    WHERE member_id = ? AND year = ?
    ORDER BY created_at DESC LIMIT 1
');
$payStmt->execute([$memberId, $workYear]);
$latestPayment = $payStmt->fetch(PDO::FETCH_ASSOC);

// Validation flags for the Review panel
$warnings = [];
if (empty($member['ama_number']) && empty($member['ama_life_member'])) {
    $warnings[] = ['type' => 'warning', 'msg' => 'No AMA number on file.', 'tab' => 'compliance', 'field' => 'ama_number'];
}
if (!empty($member['ama_life_member']) && !empty($member['ama_expiration'])) {
    $minExpUi = $workYear . '-12-31';
    if ($member['ama_expiration'] < $minExpUi) {
        $warnings[] = ['type' => 'info', 'msg' => 'AMA expiration date is before year-end but member is marked AMA life member — renewal is still allowed; consider clearing or updating the date on the Compliance tab.', 'tab' => 'compliance', 'field' => 'ama_expiration'];
    }
}
if (empty($member['ama_life_member']) && !empty($member['ama_expiration'])) {
    $minExp = $workYear . '-12-31';
    if ($member['ama_expiration'] < $minExp) {
        $warnings[] = ['type' => 'danger', 'msg' => 'AMA expiration (' . date('M j, Y', strtotime($member['ama_expiration'])) . ') is before Dec 31 of ' . $workYear . '. Update before recording.', 'tab' => 'compliance', 'field' => 'ama_expiration'];
    }
}
if (empty($member['ama_expiration']) && empty($member['ama_life_member'])) {
    $warnings[] = ['type' => 'warning', 'msg' => 'No AMA expiration date on file.', 'tab' => 'compliance', 'field' => 'ama_expiration'];
}
if (empty($member['faa_number'])) {
    $warnings[] = ['type' => 'warning', 'msg' => 'No FAA registration number on file.', 'tab' => 'compliance', 'field' => 'faa_number'];
}
if (empty($member['addr_street']) || empty($member['addr_city'])) {
    $warnings[] = ['type' => 'warning', 'msg' => 'No mailing address on file — mailer packet will be incomplete.', 'tab' => 'contact', 'field' => 'addresses'];
}
if (empty($member['membership_type_slot'])) {
    $warnings[] = ['type' => 'danger', 'msg' => 'Membership type not set. Set it on the member record first.', 'tab' => 'membership', 'field' => 'membership_type_slot'];
}

// ---------------------------------------------------------------------------
// Dues preview — rules prefetched at page load ($prefetchedRules).
// ---------------------------------------------------------------------------
$typeSlot = (int) ($member['membership_type_slot'] ?? 0);

$previewCalc = calculateDues($pdo, $typeSlot, 'on_time', $prefetchedRules);
$regPreview  = (float) $previewCalc['regularDues'];
$proPreview  = (float) $previewCalc['proratedDues'];
$iniPreview  = (float) $previewCalc['initiationFee'];

$calcNew  = calculateDues($pdo, $typeSlot, 'new',  $prefetchedRules);
$calcLate = calculateDues($pdo, $typeSlot, 'late', $prefetchedRules);

$duesPreview = [
    'new'     => (float) $calcNew['dues']  + (float) $calcNew['init'],
    'on_time' => (float) $previewCalc['dues'],
    'late'    => (float) $calcLate['dues'] + (float) $calcLate['init'],
];

$memberName  = trim($member['first_name'] . ' ' . $member['last_name']);
$renewalError = $_GET['renewal_error'] ?? '';
$justRecorded = isset($_GET['recorded']);
$memberEditUrl = $fromWizard
    ? member_wizard_url($memberId)
    : 'member_edit.php?id=' . $memberId;
$wizardComplianceUrl = $fromWizard ? member_wizard_url($memberId, 'compliance', 'process') : 'member_edit.php?id=' . $memberId . '#pane-compliance';
$wizardNavStep = ($fulfillment['processed_at'] || $justRecorded) ? 'fulfill' : 'record';
$defaultRenewalType = $wizardRenewalType !== '' ? $wizardRenewalType : 'new';

$pageTitle = $fromWizard ? 'New member: ' . $memberName : 'Process: ' . $memberName;
require_once __DIR__ . '/includes/header.php';
if ($fromWizard) {
    require_once __DIR__ . '/includes/member_wizard_styles.php';
}
?>

<?php /* ── Breadcrumb ──────────────────────────────────────────────── */ ?>
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="members.php">Members</a></li>
        <?php if ($fromWizard): ?>
        <li class="breadcrumb-item"><a href="member_wizard.php?id=<?= $memberId ?>">New member wizard</a></li>
        <?php else: ?>
        <li class="breadcrumb-item"><a href="member_edit.php?id=<?= $memberId ?>"><?= h($memberName) ?></a></li>
        <?php endif; ?>
        <li class="breadcrumb-item active"><?= $fromWizard ? 'Record signup' : 'Process Signup / Renewal' ?></li>
    </ol>
</nav>

<?php if ($fromWizard): ?>
<?php render_member_wizard_nav($wizardNavStep); ?>
<?php endif; ?>

<div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-4">
    <div>
        <h1 class="h2 mb-0"><?= $fromWizard ? 'Record signup &amp; fulfillment' : 'Process Signup / Renewal' ?></h1>
        <?php $typeLabel = $typeSlot > 0 ? ($membershipTypeLabels[$typeSlot] ?? ('Type ' . $typeSlot)) : 'Unknown type'; ?>
        <p class="text-muted mb-0"><?= h($memberName) ?> &mdash; <?= h($typeLabel) ?></p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <label class="form-label small mb-0 text-muted">Year:</label>
        <div class="btn-group btn-group-sm" role="group">
            <?php for ($y = (int) date('Y') - 1; $y <= (int) date('Y') + 2; $y++): ?>
            <a href="member_process.php?id=<?= $memberId ?>&year=<?= $y ?><?= $fromWizard ? '&wizard=1' : '' ?>"
               class="btn <?= $y === $workYear ? 'btn-primary' : 'btn-outline-secondary' ?>">
                <?= $y ?>
            </a>
            <?php endfor; ?>
        </div>
    </div>
</div>

<?php /* ── AMA validation errors ─────────────────────────────────── */ ?>
<?php if ($renewalError === 'no_ama'): ?>
<div class="alert alert-warning alert-dismissible fade show">
    <strong>Cannot record:</strong> No AMA expiration on file. Update it on the
    <a href="<?= h($wizardComplianceUrl) ?>" class="alert-link">Compliance tab</a> and try again.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php elseif ($renewalError === 'ama_before'): ?>
<div class="alert alert-warning alert-dismissible fade show">
    <strong>Cannot record:</strong> AMA expiration must be on or after Dec 31 of <?= $workYear ?>. Update it on the
    <a href="<?= h($wizardComplianceUrl) ?>" class="alert-link">Compliance tab</a> first.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php /* ── Recorded success banner ──────────────────────────────── */ ?>
<?php if ($justRecorded): ?>
<div class="alert alert-success alert-dismissible fade show">
    <strong>Recorded!</strong> Renewal for <?= $workYear ?> has been saved.
    Proceed to the checklist below to print the ID card and mailing packet.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php /* ═══════════════════════════════════════════════════════════════════
       PANEL 1 — REVIEW
       ══════════════════════════════════════════════════════════════════ */ ?>
<div class="card mb-4" id="review">
    <div class="card-header d-flex align-items-center gap-2">
        <span class="badge bg-secondary rounded-pill">1</span>
        <strong>Review Member Info</strong>
    <a href="<?= h($memberEditUrl) ?>" class="btn btn-outline-secondary btn-sm ms-auto">Edit member</a>
    </div>
    <div class="card-body">

        <?php if (count($warnings) > 0): ?>
        <div class="mb-3">
            <?php foreach ($warnings as $w): ?>
            <div class="alert alert-<?= h($w['type']) ?> py-2 mb-2">
                <?= h($w['msg']) ?>
                <a href="<?= h(member_process_fix_url($fromWizard, $memberId, $w)) ?>" class="alert-link ms-2 small">Fix now →</a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-sm-6 col-md-4">
                <div class="text-muted small text-uppercase fw-semibold mb-1" style="letter-spacing:.05em;">Contact</div>
                <div><?= h($memberName) ?></div>
                <?php if ($member['email']): ?>
                <div class="small text-muted"><?= h($member['email']) ?></div>
                <?php endif; ?>
                <?php if ($member['addr_street']): ?>
                <div class="small mt-1">
                    <?= h($member['addr_street']) ?><br>
                    <?php if ($member['addr_street2']): ?><?= h($member['addr_street2']) ?><br><?php endif; ?>
                    <?= h($member['addr_city']) ?>, <?= h($member['addr_state']) ?> <?= h($member['addr_postal']) ?>
                </div>
                <?php else: ?>
                <div class="small text-warning mt-1">No address on file</div>
                <?php endif; ?>
                <?php if (!empty($member['emergency_contact_name']) || !empty($member['emergency_contact_phone'])): ?>
                <div class="small mt-2">
                    <span class="text-muted">Emergency:</span>
                    <?= h($member['emergency_contact_name'] ?? '') ?>
                    <?php if (!empty($member['emergency_contact_relationship'])): ?>
                    <span class="text-muted">(<?= h($member['emergency_contact_relationship']) ?>)</span>
                    <?php endif; ?>
                    <?php if (!empty($member['emergency_contact_phone'])): ?>
                    <br><?= h($member['emergency_contact_phone']) ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-sm-6 col-md-4">
                <div class="text-muted small text-uppercase fw-semibold mb-1" style="letter-spacing:.05em;">AMA / FAA</div>
                <div class="small">
                    <span class="text-muted">AMA #:</span>
                    <?php if ($member['ama_number']): ?>
                    <strong><?= h($member['ama_number']) ?></strong>
                    <?php elseif ($member['ama_life_member']): ?>
                    <span class="text-muted">Life member</span>
                    <?php else: ?>
                    <span class="text-danger">Not set</span>
                    <?php endif; ?>
                </div>
                <div class="small">
                    <span class="text-muted">AMA Exp:</span>
                    <?php if ($member['ama_life_member']): ?>
                    <span class="text-muted">Life member (no expiry)</span>
                    <?php elseif ($member['ama_expiration']): ?>
                    <?php $amaOk = $member['ama_expiration'] >= $workYear . '-12-31'; ?>
                    <span class="<?= $amaOk ? 'text-success' : 'text-danger' ?>">
                        <?= date('M j, Y', strtotime($member['ama_expiration'])) ?>
                        <?= $amaOk ? '✓' : '— EXPIRED for ' . $workYear ?>
                    </span>
                    <?php else: ?>
                    <span class="text-danger">Not set</span>
                    <?php endif; ?>
                </div>
                <div class="small mt-1">
                    <span class="text-muted">FAA #:</span>
                    <?= $member['faa_number'] ? ('<strong>' . h($member['faa_number']) . '</strong>') : '<span class="text-warning">Not set</span>' ?>
                </div>
                <?php if ($member['faa_expiration']): ?>
                <div class="small">
                    <span class="text-muted">FAA Exp:</span>
                    <?php $faaOk = $member['faa_expiration'] >= date('Y-m-d'); ?>
                    <span class="<?= $faaOk ? '' : 'text-danger' ?>"><?= date('M j, Y', strtotime($member['faa_expiration'])) ?></span>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-sm-6 col-md-4">
                <div class="text-muted small text-uppercase fw-semibold mb-1" style="letter-spacing:.05em;">Membership</div>
                <div class="small">
                    <span class="text-muted">Type:</span>
                    <?= $typeSlot > 0 ? h($typeLabel) : '<span class="text-danger">Not set</span>' ?>
                </div>
                <div class="small">
                    <span class="text-muted">Current renewal year:</span>
                    <?= $member['membership_renewal_year'] ? h($member['membership_renewal_year']) : '<span class="text-muted">None</span>' ?>
                </div>
                <?php if ($member['life_member']): ?>
                <div class="small"><span class="badge bg-info text-dark">Life Member</span></div>
                <?php endif; ?>
                <?php if ($member['free_membership']): ?>
                <div class="small"><span class="badge bg-secondary">Free Membership</span></div>
                <?php endif; ?>
                <?php if ($member['gate_key_number']): ?>
                <div class="small mt-1"><span class="text-muted">Gate key:</span> <?= h($member['gate_key_number']) ?></div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /.card-body -->
</div>

<?php /* ═══════════════════════════════════════════════════════════════════
       PANEL 2 — RECORD
       ══════════════════════════════════════════════════════════════════ */ ?>
<div class="card mb-4" id="record">
    <div class="card-header d-flex align-items-center gap-2">
        <span class="badge <?= $fulfillment['processed_at'] ? 'bg-success' : 'bg-secondary' ?> rounded-pill">2</span>
        <strong>Record Signup / Renewal</strong>
        <?php if ($fulfillment['processed_at']): ?>
        <span class="badge bg-success ms-auto">Recorded <?= date('M j, Y', strtotime($fulfillment['processed_at'])) ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body">

        <?php if ($fulfillment['processed_at'] && $latestPayment): ?>
        <?php /* Already processed — show summary + allow re-process */ ?>
        <div class="alert alert-success py-2 mb-3">
            <strong><?= h(ucwords(str_replace('_', ' ', $fulfillment['renewal_type'] ?? 'renewal'))) ?></strong>
            recorded on <?= date('M j, Y', strtotime($fulfillment['processed_at'])) ?>.
            Dues: <strong>$<?= number_format((float)$latestPayment['amount_dues'], 2) ?></strong>
            <?php if ((float)$latestPayment['amount_initiation'] > 0): ?>
            + Init: <strong>$<?= number_format((float)$latestPayment['amount_initiation'], 2) ?></strong>
            <?php endif; ?>
            <?php if ((float)$latestPayment['amount_late_fee'] > 0): ?>
            + Late fee: <strong>$<?= number_format((float)$latestPayment['amount_late_fee'], 2) ?></strong>
            <?php endif; ?>
            <?php if ($latestPayment['comp']): ?>
            <span class="badge bg-secondary ms-1">Complementary</span>
            <?php endif; ?>
            <?php if (canManagePayments()): ?>
            <form method="post" action="payment_delete.php" class="d-inline-block ms-2"
                  data-confirm-submit="Delete this payment? This permanently removes it from the record.">
                <?= csrf_field() ?>
                <input type="hidden" name="payment_id" value="<?= (int) $latestPayment['id'] ?>">
                <input type="hidden" name="member_id" value="<?= (int) $memberId ?>">
                <input type="hidden" name="return" value="process">
                <button type="submit" class="btn btn-sm btn-outline-danger">Delete this payment</button>
            </form>
            <?php endif; ?>
        </div>
        <details class="mb-0">
            <summary class="text-muted small" style="cursor:pointer;">Re-record / correct this year's renewal</summary>
            <div class="pt-3">
        <?php endif; ?>

        <?php if (!canEditMembers()): ?>
        <p class="text-muted">You have view access only and cannot record renewals.</p>
        <?php else: ?>
        <form method="post" action="member_process.php?id=<?= $memberId ?><?= $fromWizard ? '&wizard=1' : '' ?>" id="record-renewal-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="record_renewal">
            <?php if ($fromWizard): ?>
            <input type="hidden" name="wizard" value="1">
            <input type="hidden" name="renewal_type_pref" value="<?= h($defaultRenewalType) ?>">
            <?php endif; ?>

            <div class="row g-3 align-items-end mb-0">

                <div class="col-auto">
                    <label class="form-label mb-1">Renewal year</label>
                    <select name="renewal_year" class="form-select">
                        <?php for ($y = (int) date('Y'); $y <= (int) date('Y') + 2; $y++): ?>
                        <option value="<?= $y ?>" <?= $y === $workYear ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="col-auto">
                    <label class="form-label mb-1">Renewal type</label>
                    <select name="renewal_type" class="form-select" id="renewal-type-select">
                        <option value="late"<?= $defaultRenewalType === 'late' ? ' selected' : '' ?>>New / Late Renewal</option>
                        <option value="on_time"<?= $defaultRenewalType === 'on_time' ? ' selected' : '' ?>>On-Time Renewal</option>
                        <option value="new"<?= $defaultRenewalType === 'new' ? ' selected' : '' ?>>New Member (Prorated)</option>
                    </select>
                </div>

                <div class="col-auto">
                    <label class="form-label mb-1 d-block">&nbsp;</label>
                    <div class="form-check" style="padding-top: 0.375rem; padding-bottom: 0.375rem;">
                        <input class="form-check-input" type="checkbox" name="complementary" id="complementary" value="1">
                        <label class="form-check-label" for="complementary">Complementary (no charge)</label>
                    </div>
                </div>

                <div class="col-auto" id="comp-status-wrap">
                    <label class="form-label mb-1">If complementary, also set</label>
                    <select name="complementary_status" class="form-select">
                        <option value="">— no change —</option>
                        <option value="free_membership">Free Membership flag</option>
                        <option value="life_member">Life Member flag</option>
                    </select>
                </div>

            </div>

            <div class="d-flex align-items-center gap-3 mt-3 flex-wrap">
                <button type="submit" class="btn btn-primary">
                    Record Signup / Renewal for <?= $workYear ?>
                </button>
                <span id="dues-preview" class="text-muted small"></span>
            </div>

            <p class="text-muted small mt-2 mb-0">
                <strong>New (prorated):</strong> 2nd half of year — prorated dues + initiation fee ·
                <strong>On-time:</strong> Oct 1–Dec 31 — regular dues only ·
                <strong>New/Late:</strong> full dues + initiation fee.<br>
                <?= h($typeLabel) ?> rates —
                Regular: <strong>$<?= number_format($regPreview, 0) ?></strong>
                / Prorated: <strong>$<?= number_format($proPreview, 0) ?></strong>
                / Init: <strong>$<?= number_format($iniPreview, 0) ?></strong>
            </p>
        </form>
        <?php endif; ?>

        <?php if ($fulfillment['processed_at'] && $latestPayment): ?>
            </div>
        </details>
        <?php endif; ?>

    </div><!-- /.card-body -->
</div>

<?php /* ═══════════════════════════════════════════════════════════════════
       PANEL 3 — FULFILL (checklist)
       ══════════════════════════════════════════════════════════════════ */ ?>
<div class="card mb-4" id="fulfill">
    <div class="card-header d-flex align-items-center gap-2">
        <?php
        $tasksTotal = 2;
        $tasksDone  = ($fulfillment['card_printed_at'] ? 1 : 0) + ($fulfillment['mailer_printed_at'] ? 1 : 0);
        $allDone    = $tasksDone === $tasksTotal;
        ?>
        <span class="badge <?= $allDone ? 'bg-success' : 'bg-secondary' ?> rounded-pill">3</span>
        <strong>Print &amp; Mail Checklist</strong>
        <?php if ($allDone): ?>
        <span class="badge bg-success ms-auto">All done ✓</span>
        <?php else: ?>
        <span class="text-muted small ms-auto"><?= $tasksDone ?>/<?= $tasksTotal ?> complete</span>
        <?php endif; ?>
    </div>
    <div class="card-body">

        <?php if (!$fulfillment['processed_at']): ?>
        <p class="text-muted mb-0">Complete Step 2 first — record the renewal before printing.</p>
        <?php else: ?>

        <p class="text-muted small mb-3">
            Print each item below, then mark it done. Come back to this page anytime to check status.
        </p>

        <div class="list-group list-group-flush">

            <?php /* ── Task 1: ID Card ─────────────────────────────── */ ?>
            <div class="list-group-item px-0 py-3">
                <div class="d-flex align-items-start gap-3">
                    <div class="flex-shrink-0 mt-1">
                        <?php if ($fulfillment['card_printed_at']): ?>
                        <span class="badge bg-success p-2" title="Done">✓</span>
                        <?php else: ?>
                        <span class="badge bg-light text-secondary border p-2">○</span>
                        <?php endif; ?>
                    </div>
                    <div class="flex-grow-1">
                        <div class="fw-semibold">Print ID Card</div>
                        <div class="text-muted small mb-2">
                            Print the CR80 badge (front and/or back) for this member.
                            <?php if ($fulfillment['card_printed_at']): ?>
                            <span class="text-success">Printed <?= date('M j, Y', strtotime($fulfillment['card_printed_at'])) ?>.</span>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="badge_print.php?id=<?= $memberId ?>&from_process=1&year=<?= $workYear ?>"
                               class="btn btn-sm btn-outline-primary" target="_blank">
                                Open Print Card →
                            </a>
                            <?php if (!$fulfillment['card_printed_at']): ?>
                            <form method="post" action="member_process.php?id=<?= $memberId ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="mark_card">
                                <input type="hidden" name="year" value="<?= $workYear ?>">
                                <button type="submit" class="btn btn-sm btn-success">Mark as printed</button>
                            </form>
                            <?php else: ?>
                            <form method="post" action="member_process.php?id=<?= $memberId ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="unmark">
                                <input type="hidden" name="task" value="card_printed_at">
                                <input type="hidden" name="year" value="<?= $workYear ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary btn-sm">Undo</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php /* ── Task 2: Mailing Packet ────────────────────── */ ?>
            <div class="list-group-item px-0 py-3">
                <div class="d-flex align-items-start gap-3">
                    <div class="flex-shrink-0 mt-1">
                        <?php if ($fulfillment['mailer_printed_at']): ?>
                        <span class="badge bg-success p-2" title="Done">✓</span>
                        <?php else: ?>
                        <span class="badge bg-light text-secondary border p-2">○</span>
                        <?php endif; ?>
                    </div>
                    <div class="flex-grow-1">
                        <div class="fw-semibold">Print Mailing Packet</div>
                        <div class="text-muted small mb-2">
                            Print the welcome/renewal letter and mailing envelope for this member.
                            <?php if ($fulfillment['mailer_printed_at']): ?>
                            <span class="text-success">Printed <?= date('M j, Y', strtotime($fulfillment['mailer_printed_at'])) ?>.</span>
                            <?php endif; ?>
                            <?php if (empty($member['addr_street'])): ?>
                            <span class="text-warning">⚠ No address on file.</span>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="member_mailer.php?id=<?= $memberId ?>&year=<?= $workYear ?>"
                               class="btn btn-sm btn-outline-primary" target="_blank">
                                Open Mailing Packet →
                            </a>
                            <?php if (!$fulfillment['mailer_printed_at']): ?>
                            <form method="post" action="member_process.php?id=<?= $memberId ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="mark_mailer">
                                <input type="hidden" name="year" value="<?= $workYear ?>">
                                <button type="submit" class="btn btn-sm btn-success">Mark as printed</button>
                            </form>
                            <?php else: ?>
                            <form method="post" action="member_process.php?id=<?= $memberId ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="unmark">
                                <input type="hidden" name="task" value="mailer_printed_at">
                                <input type="hidden" name="year" value="<?= $workYear ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Undo</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /.list-group -->

        <?php if ($allDone): ?>
        <div class="alert alert-success mt-3 mb-0">
            <strong>All done!</strong> <?= h($memberName) ?>'s <?= $workYear ?> membership packet is complete.
            <a href="member_edit.php?id=<?= $memberId ?>" class="alert-link">View member record →</a>
            <?php if ($fromWizard): ?>
            <a href="members.php" class="alert-link ms-2">Back to members list →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php endif; /* processed_at check */ ?>

    </div><!-- /.card-body -->
</div>

<div class="mb-5">
    <a href="<?= h($memberEditUrl) ?>" class="btn btn-outline-secondary">← <?= $fromWizard ? 'Back to wizard' : 'Back to member record' ?></a>
    <a href="members.php" class="btn btn-outline-secondary ms-2">← Members list</a>
</div>

<script<?= csp_nonce_attr() ?>>
(function () {
    'use strict';

    // ── Dues preview ───────────────────────────────────────────────────────────
    var duesPreview = <?= json_encode([
        'new'     => '$' . number_format($duesPreview['new'],     2) . ' (prorated + init)',
        'on_time' => '$' . number_format($duesPreview['on_time'], 2) . ' (regular dues)',
        'late'    => '$' . number_format($duesPreview['late'],    2) . ' (full dues + init)',
    ]) ?>;

    var typeSelect   = document.getElementById('renewal-type-select');
    var previewEl    = document.getElementById('dues-preview');
    var compCheckbox = document.getElementById('complementary');
    var compWrap     = document.getElementById('comp-status-wrap');

    function updatePreview() {
        if (!typeSelect || !previewEl) return;
        var val  = typeSelect.value;
        var comp = compCheckbox && compCheckbox.checked;
        previewEl.textContent = comp ? 'Complementary — $0.00' : (duesPreview[val] || '');
    }

    if (typeSelect)   typeSelect.addEventListener('change', updatePreview);
    if (compCheckbox) {
        compCheckbox.addEventListener('change', function () {
            if (compWrap) compWrap.style.display = this.checked ? '' : 'none';
            updatePreview();
        });
    }

    // Init
    if (compWrap) compWrap.style.display = 'none';
    updatePreview();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>