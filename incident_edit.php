<?php
/**
 * incident_edit.php — Add or edit a safety incident.
 *
 * GET  ?id=N   — Edit existing incident (N = incidents.id)
 * GET  (no id) — New incident form
 * GET  ?member_id=N — Pre-populate the member field (linked from member record)
 *
 * POST         — Save (insert or update), redirect to incidents.php
 *
 * Access: Membership Manager and Administrator only. Club Staff and Report Viewer see read-only via incidents.php.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit_log.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/flash.php';

requireLogin();
if (!canEditMembers()) {
    header('Location: incidents.php');
    exit;
}
$userId   = currentUserId();

// ── Load existing incident (edit mode) or initialise defaults (new mode) ─────
$incidentId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$incident   = null;
$isNew      = true;

if ($incidentId) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM incidents WHERE id = ?');
        $stmt->execute([$incidentId]);
        $incident = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$incident) {
            // Not found
            header('Location: incidents.php');
            exit;
        }
        $isNew = false;
    } catch (Throwable $e) {
        // Table missing (pre-migration) or DB issue.
        header('Location: incidents.php');
        exit;
    }
}

// Default field values (new form or pre-population from query string)
$defaults = [
    'incident_date'  => date('Y-m-d'),
    'location'       => '',
    'incident_type'  => 'other',
    'severity'       => 'minor',
    'status'         => 'open',
    'member_id'      => isset($_GET['member_id']) ? (int) $_GET['member_id'] : null,
    'description'    => '',
    'action_taken'   => '',
    'ama_reported'   => 0,
    'ama_report_ref' => '',
];

// Merge existing record over defaults for edit mode
$fields = $isNew ? $defaults : array_merge($defaults, $incident);

// ── POST: Save ────────────────────────────────────────────────────────────────
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    // Collect and sanitise inputs
    $fields['incident_date']  = trim($_POST['incident_date']  ?? '');
    $fields['location']       = trim($_POST['location']       ?? '');
    $fields['incident_type']  = trim($_POST['incident_type']  ?? 'other');
    $fields['severity']       = trim($_POST['severity']       ?? 'minor');
    $fields['status']         = trim($_POST['status']         ?? 'open');
    $fields['description']    = trim($_POST['description']    ?? '');
    $fields['action_taken']   = trim($_POST['action_taken']   ?? '');
    $fields['ama_reported']   = !empty($_POST['ama_reported']) ? 1 : 0;
    $fields['ama_report_ref'] = trim($_POST['ama_report_ref'] ?? '');

    $rawMemberId          = trim($_POST['member_id'] ?? '');
    $fields['member_id']  = $rawMemberId !== '' ? (int) $rawMemberId : null;

    // ── Validation ────────────────────────────────────────────────────────
    if ($fields['incident_date'] === '') {
        $errors['incident_date'] = 'Incident date is required.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fields['incident_date'])) {
        $errors['incident_date'] = 'Date must be in YYYY-MM-DD format.';
    } elseif ($fields['incident_date'] > date('Y-m-d')) {
        $errors['incident_date'] = 'Incident date cannot be in the future.';
    }

    if ($fields['description'] === '') {
        $errors['description'] = 'A description is required.';
    }

    // Validate allowed enum values to avoid junk in the DB
    $validTypes    = ['near_miss', 'crash', 'injury', 'property_damage', 'airspace', 'other'];
    $validSeverity = ['minor', 'moderate', 'serious'];
    $validStatus   = ['open', 'under_review', 'closed'];

    if (!in_array($fields['incident_type'], $validTypes, true)) {
        $fields['incident_type'] = 'other';
    }
    if (!in_array($fields['severity'], $validSeverity, true)) {
        $fields['severity'] = 'minor';
    }
    if (!in_array($fields['status'], $validStatus, true)) {
        $fields['status'] = 'open';
    }

    if ($fields['member_id'] !== null) {
        $chk = $pdo->prepare('SELECT id FROM members WHERE id = ?');
        $chk->execute([$fields['member_id']]);
        if (!$chk->fetch()) {
            $fields['member_id'] = null; // Silently clear invalid member link
        }
    }

    // ── Persist ───────────────────────────────────────────────────────────
    if (empty($errors)) {
        try {
            if ($isNew) {
                $stmt = $pdo->prepare('
                    INSERT INTO incidents
                        (incident_date, location, incident_type, severity, status,
                         member_id, description, action_taken, ama_reported, ama_report_ref, reported_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([
                    $fields['incident_date'],
                    $fields['location'],
                    $fields['incident_type'],
                    $fields['severity'],
                    $fields['status'],
                    $fields['member_id'],
                    $fields['description'],
                    $fields['action_taken'],
                    $fields['ama_reported'],
                    $fields['ama_report_ref'] !== '' ? $fields['ama_report_ref'] : null,
                    $userId,
                ]);
                $newId = (int) $pdo->lastInsertId();
                audit_log($pdo, $userId, 'incident_add', 'incident', $newId,
                          json_encode(['type' => $fields['incident_type'], 'date' => $fields['incident_date']]));
                flash('Incident logged.', 'success');
                header('Location: incidents.php');
                exit;
            }

            $stmt = $pdo->prepare('
                UPDATE incidents SET
                    incident_date  = ?,
                    location       = ?,
                    incident_type  = ?,
                    severity       = ?,
                    status         = ?,
                    member_id      = ?,
                    description    = ?,
                    action_taken   = ?,
                    ama_reported   = ?,
                    ama_report_ref = ?
                WHERE id = ?
            ');
            $stmt->execute([
                $fields['incident_date'],
                $fields['location'],
                $fields['incident_type'],
                $fields['severity'],
                $fields['status'],
                $fields['member_id'],
                $fields['description'],
                $fields['action_taken'],
                $fields['ama_reported'],
                $fields['ama_report_ref'] !== '' ? $fields['ama_report_ref'] : null,
                $incidentId,
            ]);
            audit_log($pdo, $userId, 'incident_edit', 'incident', $incidentId,
                      json_encode(['type' => $fields['incident_type'], 'date' => $fields['incident_date']]));
            flash('Incident updated.', 'success');
            header('Location: incidents.php');
            exit;
        } catch (Throwable $e) {
            $errors['db'] = 'Database is missing the incidents table. Please run the latest `schema_full.sql`.';
        }
    }
}

// ── Load member list for the member picker ────────────────────────────────────
// We only need active members for the dropdown. For clubs with many members,
// this is still fast — we're just loading name + id.
$members = [];
try {
    $incidentMemberYear = membershipStatusYear();
    $stmt = $pdo->prepare('
        SELECT m.id, m.first_name, m.last_name
        FROM members m
        WHERE ' . currentMemberWhereSql('m', $incidentMemberYear) . '
        ORDER BY m.last_name, m.first_name
    ');
    $stmt->execute(currentMemberWhereParams($incidentMemberYear));
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ── Page metadata ─────────────────────────────────────────────────────────────
$pageTitle   = $isNew ? 'Log Incident' : 'Edit Incident';
$breadcrumbs = [
    ['label' => 'Incident Log', 'url' => 'incidents.php'],
    ['label' => $isNew ? 'Log Incident' : 'Edit Incident', 'url' => ''],
];
require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-lg-8 col-xl-7">

<!-- ── Page heading ──────────────────────────────────────────────────────── -->
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="incidents.php" class="btn btn-outline-secondary btn-sm">← Back</a>
    <h1 class="h2 mb-0"><?= $isNew ? 'Log Incident' : 'Edit Incident' ?></h1>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <strong>Please fix the following:</strong>
    <ul class="mb-0 mt-1">
    <?php foreach ($errors as $err): ?>
        <li><?= h($err) ?></li>
    <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="post" action="incident_edit.php<?= $incidentId ? '?id=' . $incidentId : '' ?>">
    <?= csrf_field() ?>

    <!-- ── What happened? ────────────────────────────────────────────── -->
    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">Incident Details</div>
        <div class="card-body">

            <div class="row g-3">

                <div class="col-sm-6">
                    <label class="form-label" for="incident_date">
                        Date of incident <span class="text-danger">*</span>
                    </label>
                    <input type="date" class="form-control <?= isset($errors['incident_date']) ? 'is-invalid' : '' ?>"
                           id="incident_date" name="incident_date"
                           value="<?= h($fields['incident_date']) ?>"
                           max="<?= date('Y-m-d') ?>" required>
                    <?php if (isset($errors['incident_date'])): ?>
                    <div class="invalid-feedback"><?= h($errors['incident_date']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="col-sm-6">
                    <label class="form-label" for="location">Location</label>
                    <input type="text" class="form-control" id="location" name="location"
                           value="<?= h($fields['location']) ?>"
                           placeholder="e.g. Main runway, North parking area">
                    <div class="form-text">Where at the field did this happen?</div>
                </div>

                <div class="col-sm-6">
                    <label class="form-label" for="incident_type">Incident type <span class="text-danger">*</span></label>
                    <select class="form-select" id="incident_type" name="incident_type" required>
                        <option value="near_miss"       <?= $fields['incident_type'] === 'near_miss'       ? 'selected' : '' ?>>Near Miss</option>
                        <option value="crash"           <?= $fields['incident_type'] === 'crash'           ? 'selected' : '' ?>>Crash</option>
                        <option value="injury"          <?= $fields['incident_type'] === 'injury'          ? 'selected' : '' ?>>Injury</option>
                        <option value="property_damage" <?= $fields['incident_type'] === 'property_damage' ? 'selected' : '' ?>>Property Damage</option>
                        <option value="airspace"        <?= $fields['incident_type'] === 'airspace'        ? 'selected' : '' ?>>Airspace Violation</option>
                        <option value="other"           <?= $fields['incident_type'] === 'other'           ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>

                <div class="col-sm-6">
                    <label class="form-label" for="severity">Severity <span class="text-danger">*</span></label>
                    <select class="form-select" id="severity" name="severity" required>
                        <option value="minor"    <?= $fields['severity'] === 'minor'    ? 'selected' : '' ?>>Minor</option>
                        <option value="moderate" <?= $fields['severity'] === 'moderate' ? 'selected' : '' ?>>Moderate</option>
                        <option value="serious"  <?= $fields['severity'] === 'serious'  ? 'selected' : '' ?>>Serious</option>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label" for="description">
                        Description <span class="text-danger">*</span>
                    </label>
                    <textarea class="form-control <?= isset($errors['description']) ? 'is-invalid' : '' ?>"
                              id="description" name="description"
                              rows="5" required
                              placeholder="Describe what happened, the sequence of events, aircraft/equipment involved, and any witnesses."><?= h($fields['description']) ?></textarea>
                    <?php if (isset($errors['description'])): ?>
                    <div class="invalid-feedback"><?= h($errors['description']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="col-12">
                    <label class="form-label" for="action_taken">Action taken</label>
                    <textarea class="form-control" id="action_taken" name="action_taken"
                              rows="3"
                              placeholder="What did the club do in response? Safety briefing, equipment check, member follow-up, etc."><?= h($fields['action_taken']) ?></textarea>
                </div>

            </div><!-- /.row -->
        </div><!-- /.card-body -->
    </div>

    <!-- ── Member involvement ─────────────────────────────────────────── -->
    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">Member Involved <span class="text-muted fw-normal">(optional)</span></div>
        <div class="card-body">
            <label class="form-label" for="member_id">Member</label>
            <select class="form-select" id="member_id" name="member_id">
                <option value="">— No specific member / visitor / unknown —</option>
                <?php foreach ($members as $m): ?>
                <option value="<?= (int) $m['id'] ?>"
                    <?= (int) $fields['member_id'] === (int) $m['id'] ? 'selected' : '' ?>>
                    <?= h(trim($m['last_name'] . ', ' . $m['first_name'])) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">
                If a visitor or non-member was involved, leave this blank and note details in the description.
            </div>
        </div>
    </div>

    <!-- ── AMA reporting ─────────────────────────────────────────────── -->
    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">AMA Reporting</div>
        <div class="card-body">

            <div class="form-check mb-3">
                <input type="checkbox" class="form-check-input" id="ama_reported"
                       name="ama_reported" value="1"
                       <?= $fields['ama_reported'] ? 'checked' : '' ?>
                       data-show-target="ama_ref_wrap">
                <label class="form-check-label" for="ama_reported">
                    This incident has been reported to the AMA
                </label>
            </div>

            <div id="ama_ref_wrap" <?= $fields['ama_reported'] ? '' : 'style="display:none"' ?>>
                <label class="form-label" for="ama_report_ref">AMA reference / case number</label>
                <input type="text" class="form-control" id="ama_report_ref" name="ama_report_ref"
                       value="<?= h($fields['ama_report_ref'] ?? '') ?>"
                       placeholder="e.g. AMA-2024-12345"
                       style="max-width:300px;">
                <div class="form-text">Record the AMA case number for your club's records.</div>
            </div>

        </div>
    </div>

    <!-- ── Status ────────────────────────────────────────────────────── -->
    <?php if (!$isNew): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">Status</div>
        <div class="card-body">
            <div class="d-flex gap-3 flex-wrap">
                <?php foreach (['open' => 'Open', 'under_review' => 'Under review', 'closed' => 'Closed'] as $v => $l): ?>
                <div class="form-check">
                    <input type="radio" class="form-check-input" id="status_<?= $v ?>"
                           name="status" value="<?= $v ?>"
                           <?= $fields['status'] === $v ? 'checked' : '' ?>>
                    <label class="form-check-label" for="status_<?= $v ?>"><?= h($l) ?></label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php else: ?>
    <!-- New incidents always start as 'open' -->
    <input type="hidden" name="status" value="open">
    <?php endif; ?>

    <!-- ── Actions ───────────────────────────────────────────────────── -->
    <div class="d-flex gap-2 flex-wrap mb-5">
        <button type="submit" class="btn btn-primary">
            <?= $isNew ? 'Log incident' : 'Save changes' ?>
        </button>
        <a href="incidents.php" class="btn btn-outline-secondary">Cancel</a>

        <?php if (!$isNew): ?>
        <div class="ms-auto">
            <a href="incident_delete.php?id=<?= $incidentId ?>"
               class="btn btn-outline-danger btn-sm"
               data-confirm="Delete this incident? This cannot be undone.">
                Delete
            </a>
        </div>
        <?php endif; ?>
    </div>

</form>
</div><!-- /.col -->
</div><!-- /.row -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>