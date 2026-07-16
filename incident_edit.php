<?php
/**
 * incident_edit.php — Add or edit a safety incident.
 *
 * GET  ?id=N   — Edit existing incident (N = incidents.id); read-only for report viewers
 * GET  (no id) — New incident form (editors only)
 * GET  ?member_id=N — Pre-populate the member field (linked from member record)
 *
 * POST         — Save (insert or update), upload/delete photos
 *
 * Access: Membership Manager and Administrator can edit.
 * Club Staff and Report Viewer can view existing incidents read-only.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit_log.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/flash.php';
require_once __DIR__ . '/includes/incident_photos.php';

requireLogin();
$canEdit = canEditMembers();
$canView = canViewReports() || $canEdit;
if (!$canView) {
    header('Location: index.php');
    exit;
}

$userId = currentUserId();

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
            header('Location: incidents.php');
            exit;
        }
        $isNew = false;
    } catch (Throwable $e) {
        header('Location: incidents.php');
        exit;
    }
} elseif (!$canEdit) {
    header('Location: incidents.php');
    exit;
}

$readOnly = !$canEdit;

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

$fields = $isNew ? $defaults : array_merge($defaults, $incident);

// ── POST: Save / photo actions ───────────────────────────────────────────────
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    if ($readOnly) {
        flash('You do not have permission to edit incidents.', 'warning');
        header('Location: incident_edit.php?id=' . (int) $incidentId);
        exit;
    }

    $action = (string) ($_POST['action'] ?? 'save');

    // Delete a single photo
    if ($action === 'delete_photo' && !$isNew && $incidentId) {
        $photoId = (int) ($_POST['photo_id'] ?? 0);
        $resolved = incident_photo_resolve($pdo, $photoId);
        if (!$resolved['ok'] || (int) ($resolved['incident_id'] ?? 0) !== (int) $incidentId) {
            flash('Photo not found for this incident.', 'warning');
            header('Location: incident_edit.php?id=' . (int) $incidentId . '#photos');
            exit;
        }
        $result = incident_photo_delete($pdo, $photoId);
        if ($result['ok']) {
            audit_log($pdo, $userId, 'incident_photo_delete', 'incident_photo', $photoId, json_encode([
                'incident_id' => $incidentId,
            ]));
            flash('Photo deleted.', 'success');
        } else {
            flash($result['error'] ?? 'Could not delete photo.', 'warning');
        }
        header('Location: incident_edit.php?id=' . (int) $incidentId . '#photos');
        exit;
    }

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

    $rawMemberId         = trim($_POST['member_id'] ?? '');
    $fields['member_id'] = $rawMemberId !== '' ? (int) $rawMemberId : null;

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
            $fields['member_id'] = null;
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

                $photoMsgs = incident_edit_handle_photo_uploads($pdo, $userId, $newId);
                if ($photoMsgs !== []) {
                    flash('Incident logged. ' . implode(' ', $photoMsgs), count(array_filter($photoMsgs, static fn ($m) => str_contains($m, 'could not'))) > 0 ? 'warning' : 'success');
                } else {
                    flash('Incident logged. You can add photos below.', 'success');
                }
                header('Location: incident_edit.php?id=' . $newId . '#photos');
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

            $photoMsgs = incident_edit_handle_photo_uploads($pdo, $userId, (int) $incidentId);
            if ($photoMsgs !== []) {
                flash('Incident updated. ' . implode(' ', $photoMsgs), count(array_filter($photoMsgs, static fn ($m) => str_contains($m, 'could not'))) > 0 ? 'warning' : 'success');
            } else {
                flash('Incident updated.', 'success');
            }
            header('Location: incident_edit.php?id=' . (int) $incidentId);
            exit;
        } catch (Throwable $e) {
            $errors['db'] = 'Database is missing the incidents table. Please run the latest `schema_full.sql`.';
        }
    }
}

/**
 * Process $_FILES['photos'] for an incident. Returns human-readable status messages.
 *
 * @return list<string>
 */
function incident_edit_handle_photo_uploads(PDO $pdo, int $userId, int $incidentId): array
{
    if (empty($_FILES['photos'])) {
        return [];
    }

    $files = incident_photos_normalize_uploads($_FILES['photos']);
    $uploaded = 0;
    $messages = [];

    foreach ($files as $file) {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $result = incident_photo_save_upload($pdo, $incidentId, $file);
        if ($result['ok']) {
            $uploaded++;
            audit_log($pdo, $userId, 'incident_photo_add', 'incident_photo', (int) $result['photo_id'], json_encode([
                'incident_id' => $incidentId,
            ]));
        } else {
            $messages[] = $result['error'] ?? 'A photo could not be saved.';
        }
    }

    if ($uploaded > 0) {
        array_unshift($messages, 'Added ' . $uploaded . ' photo' . ($uploaded !== 1 ? 's' : '') . '.');
    }

    return $messages;
}

// ── Load member list for the member picker ────────────────────────────────────
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
} catch (Throwable $e) {
}

$photos = (!$isNew && $incidentId) ? incident_photos_fetch($pdo, (int) $incidentId) : [];
$photoCount = count($photos);

// ── Page metadata ─────────────────────────────────────────────────────────────
$pageTitle = $isNew ? 'Log Incident' : ($readOnly ? 'View Incident' : 'Edit Incident');
$breadcrumbs = [
    ['label' => 'Incident Log', 'url' => 'incidents.php'],
    ['label' => $pageTitle, 'url' => ''],
];
require_once __DIR__ . '/includes/header.php';

$typeLabels = [
    'near_miss'       => 'Near Miss',
    'crash'           => 'Crash',
    'injury'          => 'Injury',
    'property_damage' => 'Property Damage',
    'airspace'        => 'Airspace Violation',
    'other'           => 'Other',
];
$statusLabels = [
    'open'         => 'Open',
    'under_review' => 'Under review',
    'closed'       => 'Closed',
];
?>

<div class="row justify-content-center">
<div class="col-lg-8 col-xl-7">

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="incidents.php" class="btn btn-outline-secondary btn-sm">← Back</a>
    <h1 class="h2 mb-0"><?= h($pageTitle) ?></h1>
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

<?php if ($readOnly): ?>
<div class="alert alert-info py-2 small">You have read-only access to this incident.</div>
<?php endif; ?>

<form method="post" action="incident_edit.php<?= $incidentId ? '?id=' . $incidentId : '' ?>"
      <?= $readOnly ? '' : 'enctype="multipart/form-data"' ?>>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">

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
                           max="<?= date('Y-m-d') ?>" required
                           <?= $readOnly ? 'disabled' : '' ?>>
                    <?php if (isset($errors['incident_date'])): ?>
                    <div class="invalid-feedback"><?= h($errors['incident_date']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="col-sm-6">
                    <label class="form-label" for="location">Location</label>
                    <input type="text" class="form-control" id="location" name="location"
                           value="<?= h($fields['location']) ?>"
                           placeholder="e.g. Main runway, North parking area"
                           <?= $readOnly ? 'disabled' : '' ?>>
                    <div class="form-text">Where at the field did this happen?</div>
                </div>

                <div class="col-sm-6">
                    <label class="form-label" for="incident_type">Incident type <span class="text-danger">*</span></label>
                    <select class="form-select" id="incident_type" name="incident_type" required
                            <?= $readOnly ? 'disabled' : '' ?>>
                        <?php foreach ($typeLabels as $v => $l): ?>
                        <option value="<?= h($v) ?>"<?= $fields['incident_type'] === $v ? ' selected' : '' ?>><?= h($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-sm-6">
                    <label class="form-label" for="severity">Severity <span class="text-danger">*</span></label>
                    <select class="form-select" id="severity" name="severity" required
                            <?= $readOnly ? 'disabled' : '' ?>>
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
                              placeholder="Describe what happened, the sequence of events, aircraft/equipment involved, and any witnesses."
                              <?= $readOnly ? 'disabled' : '' ?>><?= h($fields['description']) ?></textarea>
                    <?php if (isset($errors['description'])): ?>
                    <div class="invalid-feedback"><?= h($errors['description']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="col-12">
                    <label class="form-label" for="action_taken">Action taken</label>
                    <textarea class="form-control" id="action_taken" name="action_taken"
                              rows="3"
                              placeholder="What did the club do in response? Safety briefing, equipment check, member follow-up, etc."
                              <?= $readOnly ? 'disabled' : '' ?>><?= h($fields['action_taken']) ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$isNew || !$readOnly): ?>
    <div class="card shadow-sm mb-4" id="photos">
        <div class="card-header fw-semibold d-flex align-items-center justify-content-between">
            <span>Photos</span>
            <?php if ($photoCount > 0): ?>
            <span class="badge text-bg-secondary"><?= $photoCount ?></span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($isNew): ?>
            <p class="text-muted small mb-3">
                Optional: choose photos now. They are saved after the incident is logged.
            </p>
            <?php elseif ($photos === []): ?>
            <p class="text-muted small mb-3">No photos attached yet.</p>
            <?php else: ?>
            <div class="row g-3 mb-3">
                <?php foreach ($photos as $photo): ?>
                <div class="col-6 col-md-4">
                    <div class="border rounded overflow-hidden bg-light">
                        <a href="<?= h(incident_photo_serve_url((int) $photo['id'])) ?>" target="_blank" rel="noopener">
                            <img src="<?= h(incident_photo_serve_url((int) $photo['id'])) ?>"
                                 alt="<?= h($photo['original_filename'] ?? 'Incident photo') ?>"
                                 class="w-100" style="height:140px;object-fit:cover;display:block;">
                        </a>
                        <div class="p-2 small">
                            <div class="text-truncate" title="<?= h($photo['original_filename'] ?? '') ?>">
                                <?= h($photo['original_filename'] ?: 'Photo #' . (int) $photo['id']) ?>
                            </div>
                            <?php if (!$readOnly): ?>
                            <button type="submit" form="delete-photo-<?= (int) $photo['id'] ?>"
                                    class="btn btn-link btn-sm text-danger p-0 mt-1"
                                    data-confirm="Delete this photo?">
                                Remove
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!$readOnly && $photoCount < INCIDENT_PHOTOS_MAX): ?>
            <label class="form-label" for="photos"><?= $isNew ? 'Photos' : 'Add photos' ?></label>
            <input type="file" class="form-control" id="photos" name="photos[]"
                   accept="image/jpeg,image/png,image/gif" multiple>
            <div class="form-text">
                JPEG, PNG, or GIF · max 5 MB each · up to <?= INCIDENT_PHOTOS_MAX ?> photos total
                <?php if (!$isNew): ?>
                (<?= INCIDENT_PHOTOS_MAX - $photoCount ?> remaining)
                <?php endif; ?>.
            </div>
            <?php elseif (!$readOnly): ?>
            <p class="text-muted small mb-0">This incident has the maximum of <?= INCIDENT_PHOTOS_MAX ?> photos.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">Member Involved <span class="text-muted fw-normal">(optional)</span></div>
        <div class="card-body">
            <label class="form-label" for="member_id">Member</label>
            <select class="form-select" id="member_id" name="member_id" <?= $readOnly ? 'disabled' : '' ?>>
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

    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">AMA Reporting</div>
        <div class="card-body">
            <div class="form-check mb-3">
                <input type="checkbox" class="form-check-input" id="ama_reported"
                       name="ama_reported" value="1"
                       <?= $fields['ama_reported'] ? 'checked' : '' ?>
                       data-show-target="ama_ref_wrap"
                       <?= $readOnly ? 'disabled' : '' ?>>
                <label class="form-check-label" for="ama_reported">
                    This incident has been reported to the AMA
                </label>
            </div>

            <div id="ama_ref_wrap" <?= $fields['ama_reported'] ? '' : 'style="display:none"' ?>>
                <label class="form-label" for="ama_report_ref">AMA reference / case number</label>
                <input type="text" class="form-control" id="ama_report_ref" name="ama_report_ref"
                       value="<?= h($fields['ama_report_ref'] ?? '') ?>"
                       placeholder="e.g. AMA-2024-12345"
                       style="max-width:300px;"
                       <?= $readOnly ? 'disabled' : '' ?>>
                <div class="form-text">Record the AMA case number for your club's records.</div>
            </div>
        </div>
    </div>

    <?php if (!$isNew): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">Status</div>
        <div class="card-body">
            <div class="d-flex gap-3 flex-wrap">
                <?php foreach ($statusLabels as $v => $l): ?>
                <div class="form-check">
                    <input type="radio" class="form-check-input" id="status_<?= $v ?>"
                           name="status" value="<?= $v ?>"
                           <?= $fields['status'] === $v ? 'checked' : '' ?>
                           <?= $readOnly ? 'disabled' : '' ?>>
                    <label class="form-check-label" for="status_<?= $v ?>"><?= h($l) ?></label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php else: ?>
    <input type="hidden" name="status" value="open">
    <?php endif; ?>

    <div class="d-flex gap-2 flex-wrap mb-5">
        <?php if (!$readOnly): ?>
        <button type="submit" class="btn btn-primary">
            <?= $isNew ? 'Log incident' : 'Save changes' ?>
        </button>
        <?php endif; ?>
        <a href="incidents.php" class="btn btn-outline-secondary"><?= $readOnly ? 'Back' : 'Cancel' ?></a>

        <?php if (!$isNew && !$readOnly && isAdmin()): ?>
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

<?php if (!$readOnly && !$isNew && $photos !== []): ?>
<?php foreach ($photos as $photo): ?>
<form id="delete-photo-<?= (int) $photo['id'] ?>" method="post" action="incident_edit.php?id=<?= (int) $incidentId ?>" class="d-none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_photo">
    <input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>">
</form>
<?php endforeach; ?>
<?php endif; ?>

</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
