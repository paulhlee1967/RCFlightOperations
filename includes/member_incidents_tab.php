<?php
/**
 * includes/member_incidents_tab.php
 *
 * Read-only incident summary for embedding in the member edit/detail page.
 *
 * Required variables (must be set by the calling page before including):
 *   $pdo        PDO       Database connection
 *   $memberId   int       The member's ID
 */

if (empty($memberId) || !isset($pdo)) {
    return;
}

$memberIncidents = [];
$memberIncidentCount = 0;

try {
    $cStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM incidents WHERE member_id = ?'
    );
    $cStmt->execute([$memberId]);
    $memberIncidentCount = (int) $cStmt->fetchColumn();

    $iStmt = $pdo->prepare('
        SELECT id, incident_date, incident_type, severity, status, description, ama_reported
        FROM incidents
        WHERE member_id = ?
        ORDER BY incident_date DESC, id DESC
        LIMIT 10
    ');
    $iStmt->execute([$memberId]);
    $memberIncidents = $iStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

function memberIncidentTypeColor(string $type): string {
    return match ($type) {
        'crash', 'injury'    => 'danger',
        'near_miss',
        'property_damage'    => 'warning',
        'airspace'           => 'info',
        default              => 'secondary',
    };
}

function memberIncidentTypeLabel(string $type): string {
    return match ($type) {
        'near_miss'       => 'Near Miss',
        'crash'           => 'Crash',
        'injury'          => 'Injury',
        'property_damage' => 'Property Damage',
        'airspace'        => 'Airspace',
        default           => 'Other',
    };
}
?>

<!-- ── Incidents mini-panel ─────────────────────────────────────────────────── -->
<div class="d-flex align-items-center justify-content-between mb-3">
    <h2 class="h5 mb-0">
        Incident History
        <?php if ($memberIncidentCount > 0): ?>
        <span class="badge bg-<?= $memberIncidentCount > 0 ? 'warning text-dark' : 'secondary' ?> ms-1"
              style="font-size:.7rem;">
            <?= $memberIncidentCount ?>
        </span>
        <?php endif; ?>
    </h2>
    <div class="d-flex gap-2">
        <?php if ($memberIncidentCount > 0): ?>
        <a href="incidents.php?member_id=<?= (int) $memberId ?>"
           class="btn btn-sm btn-outline-secondary">View all</a>
        <?php endif; ?>
        <?php if (function_exists('canEditMembers') && canEditMembers()): ?>
        <a href="incident_edit.php?member_id=<?= (int) $memberId ?>"
           class="btn btn-sm btn-outline-primary">+ Log incident</a>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($memberIncidents)): ?>
<p class="text-muted small mb-0">No incidents on record for this member.</p>

<?php else: ?>
<div class="table-responsive">
    <table class="table table-sm mb-0">
        <thead class="table-light">
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Severity</th>
                <th>Status</th>
                <th>AMA</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($memberIncidents as $inc): ?>
        <tr>
            <td class="text-nowrap small">
                <?= htmlspecialchars(date('M j, Y', strtotime($inc['incident_date']))) ?>
            </td>
            <td>
                <span class="badge bg-<?= memberIncidentTypeColor($inc['incident_type']) ?>"
                      style="font-size:.7rem;">
                    <?= htmlspecialchars(memberIncidentTypeLabel($inc['incident_type'])) ?>
                </span>
            </td>
            <td class="small text-muted"><?= htmlspecialchars(ucfirst($inc['severity'])) ?></td>
            <td>
                <?php
                $statusColor = match($inc['status']) {
                    'open'         => 'danger',
                    'under_review' => 'warning',
                    default        => 'success',
                };
                ?>
                <span class="badge bg-<?= $statusColor ?>" style="font-size:.7rem;">
                    <?= htmlspecialchars(str_replace('_', ' ', ucfirst($inc['status']))) ?>
                </span>
            </td>
            <td class="text-center">
                <?php if ($inc['ama_reported']): ?>
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="#198754" viewBox="0 0 16 16">
                    <path d="M13.854 3.646a.5.5 0 0 1 0 .708l-7 7a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L6.5 10.293l6.646-6.647a.5.5 0 0 1 .708 0z"/>
                </svg>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td class="text-end">
                <a href="incident_edit.php?id=<?= (int) $inc['id'] ?>"
                   class="btn btn-sm btn-outline-secondary py-0 px-2"
                   style="font-size:.7rem;">
                    <?= (function_exists('canEditMembers') && canEditMembers()) ? 'Edit' : 'View' ?>
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($memberIncidentCount > 10): ?>
<p class="text-muted small mt-2 mb-0">
    Showing 10 most recent.
    <a href="incidents.php?member_id=<?= (int) $memberId ?>">View all <?= $memberIncidentCount ?></a>
</p>
<?php endif; ?>
<?php endif; ?>
