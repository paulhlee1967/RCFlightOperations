<?php
/**
 * incident_delete.php — Confirm and delete a single incident.
 *
 * GET  ?id=N  — Show confirmation screen
 * POST ?id=N  — Delete and redirect to incidents.php
 *
 * Admin only. Editors can create/edit incidents but only admins can delete.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit_log.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/flash.php';

requireLogin();
requireAdmin(); // Delete is admin-only to protect the safety record
$userId     = currentUserId();
$incidentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($incidentId < 1) {
    header('Location: incidents.php');
    exit;
}

// Load the incident
try {
    $stmt = $pdo->prepare('SELECT * FROM incidents WHERE id = ?');
    $stmt->execute([$incidentId]);
    $incident = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    header('Location: incidents.php');
    exit;
}

if (!$incident) {
    header('Location: incidents.php');
    exit;
}

// POST: confirmed delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === '1') {
    csrf_validate();
    $pdo->prepare('DELETE FROM incidents WHERE id = ?')
        ->execute([$incidentId]);
    audit_log($pdo, $userId, 'incident_delete', 'incident', $incidentId,
              json_encode(['type' => $incident['incident_type'], 'date' => $incident['incident_date']]));
    flash('Incident deleted.', 'success');
    header('Location: incidents.php');
    exit;
}

$pageTitle   = 'Delete Incident';
$breadcrumbs = [
    ['label' => 'Incident Log', 'url' => 'incidents.php'],
    ['label' => 'Delete Incident', 'url' => ''],
];
require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card border-danger shadow-sm">
            <div class="card-header bg-danger text-white d-flex align-items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5m.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2"/>
                </svg>
                <span class="fw-semibold">Delete incident?</span>
            </div>
            <div class="card-body">
                <p class="mb-1">
                    Permanently delete this incident from <strong><?= h(date('M j, Y', strtotime($incident['incident_date']))) ?></strong>?
                </p>
                <p class="text-muted small mb-1">
                    Type: <?= h(ucwords(str_replace('_', ' ', $incident['incident_type']))) ?> &nbsp;·&nbsp;
                    Severity: <?= h(ucfirst($incident['severity'])) ?>
                </p>
                <p class="text-muted small mb-4 fst-italic">
                    "<?= h(mb_strimwidth($incident['description'], 0, 120, '…')) ?>"
                </p>
                <p class="text-danger small mb-4">
                    <strong>This cannot be undone.</strong>
                    Deleting incidents removes them from your safety record permanently.
                    Consider closing the incident instead if you want to keep the record.
                </p>
                <form method="post" action="incident_delete.php?id=<?= $incidentId ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="confirm" value="1">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-danger">Delete incident</button>
                        <a href="incident_edit.php?id=<?= $incidentId ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>