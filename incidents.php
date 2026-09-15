<?php
/**
 * incidents.php — Incident log list
 *
 * Shows all safety incidents for this club, newest first.
 * Filterable by type, severity, status, and year.
 *
 * Access: Administrator and Membership Manager can add/edit. Club Staff and Report Viewer: read-only.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

requireLogin();

if (!canViewReports() && !canEditMembers()) {
    header('Location: index.php');
    exit;
}
// ── Filter / search params ────────────────────────────────────────────────────
$filterType     = trim($_GET['type']     ?? '');
$filterSeverity = trim($_GET['severity'] ?? '');
$filterStatus   = trim($_GET['status']   ?? '');
$filterYear     = (int) ($_GET['year']   ?? 0);
$searchQ        = trim($_GET['q']        ?? '');

$perPage = 25;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// ── Build query ───────────────────────────────────────────────────────────────
$where  = ['1=1'];
$params = [];

if ($filterType !== '') {
    $where[]  = 'i.incident_type = ?';
    $params[] = $filterType;
}
if ($filterSeverity !== '') {
    $where[]  = 'i.severity = ?';
    $params[] = $filterSeverity;
}
if ($filterStatus !== '') {
    $where[]  = 'i.status = ?';
    $params[] = $filterStatus;
}
if ($filterYear > 0) {
    $where[]  = 'YEAR(i.incident_date) = ?';
    $params[] = $filterYear;
}
if ($searchQ !== '') {
    $where[]  = '(i.description LIKE ? OR i.location LIKE ? OR m.first_name LIKE ? OR m.last_name LIKE ?)';
    $like     = '%' . $searchQ . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereClause = implode(' AND ', $where);

$countSql = "SELECT COUNT(*) FROM incidents i
             LEFT JOIN members m ON m.id = i.member_id
             WHERE {$whereClause}";

$listSql = "SELECT i.*,
                   m.first_name, m.last_name,
                   u.name AS reporter_name
            FROM incidents i
            LEFT JOIN members m ON m.id = i.member_id
            LEFT JOIN users   u ON u.id = i.reported_by
            WHERE {$whereClause}
            ORDER BY i.incident_date DESC, i.id DESC
            LIMIT {$perPage} OFFSET {$offset}";

$totalCount = 0;
$incidents  = [];
try {
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $totalCount = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare($listSql);
    $stmt->execute($params);
    $incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Table may not yet exist — show empty state.
}

$totalPages = max(1, (int) ceil($totalCount / $perPage));

// ── Summary counts for the filter chips ──────────────────────────────────────
$openCount = 0;
try {
    $stmt = $pdo->query('SELECT COUNT(*) FROM incidents WHERE status = "open"');
    $openCount = (int) $stmt->fetchColumn();
} catch (Throwable $e) {}

// ── Available years for the year filter ──────────────────────────────────────
$years = [];
try {
    $stmt = $pdo->query('SELECT DISTINCT YEAR(incident_date) AS yr FROM incidents ORDER BY yr DESC');
    $years = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

// ── Type / severity / status label helpers ───────────────────────────────────
/**
 * Human-readable label for an incident type slug.
 */
function incidentTypeLabel(string $type): string {
    return match ($type) {
        'near_miss'       => 'Near Miss',
        'crash'           => 'Crash',
        'injury'          => 'Injury',
        'property_damage' => 'Property Damage',
        'airspace'        => 'Airspace Violation',
        default           => 'Other',
    };
}

/**
 * Bootstrap color class for an incident type.
 */
function incidentTypeColor(string $type): string {
    return match ($type) {
        'near_miss'       => 'warning',
        'crash'           => 'danger',
        'injury'          => 'danger',
        'property_damage' => 'warning',
        'airspace'        => 'info',
        default           => 'secondary',
    };
}

/**
 * Bootstrap color class for severity.
 */
function severityColor(string $severity): string {
    return match ($severity) {
        'serious'  => 'danger',
        'moderate' => 'warning',
        default    => 'secondary',
    };
}

/**
 * Bootstrap color class for status.
 */
function statusColor(string $status): string {
    return match ($status) {
        'open'         => 'danger',
        'under_review' => 'warning',
        'closed'       => 'success',
        default        => 'secondary',
    };
}

/**
 * Helper: build a URL back to this page preserving current active filters,
 * with optional overrides.
 *
 * @param array $overrides  Key-value pairs to override in the current filter set.
 */
function incidentsUrl(array $overrides = []): string {
    global $filterType, $filterSeverity, $filterStatus, $filterYear, $searchQ, $page;
    $p = array_filter([
        'type'     => $overrides['type']     ?? ($filterType     !== '' ? $filterType     : null),
        'severity' => $overrides['severity'] ?? ($filterSeverity !== '' ? $filterSeverity : null),
        'status'   => $overrides['status']   ?? ($filterStatus   !== '' ? $filterStatus   : null),
        'year'     => $overrides['year']     ?? ($filterYear      > 0   ? $filterYear     : null),
        'q'        => $overrides['q']        ?? ($searchQ         !== '' ? $searchQ        : null),
        'page'     => $overrides['page']     ?? ($page            > 1   ? $page           : null),
    ], fn($v) => $v !== null && $v !== '' && $v !== 0);
    return 'incidents.php' . ($p ? '?' . http_build_query($p) : '');
}

$pageTitle   = 'Incident Log';
$breadcrumbs = [['label' => 'Incident Log', 'url' => '']];
require_once __DIR__ . '/includes/page_header.php';

ob_start();
if (canEditMembers()) {
    ?>
    <a href="incident_edit.php" class="btn btn-primary btn-sm">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="me-1" viewBox="0 0 16 16">
            <path d="M8 2a.5.5 0 0 1 .5.5v5h5a.5.5 0 0 1 0 1h-5v5a.5.5 0 0 1-1 0v-5h-5a.5.5 0 0 1 0-1h5v-5A.5.5 0 0 1 8 2"/>
        </svg>
        Log incident
    </a>
    <?php
}
$incidentsHeaderActions = ob_get_clean();

require_once __DIR__ . '/includes/header.php';

render_page_header([
    'title'    => 'Incident Log',
    'subtitle' => 'Safety incidents and field events. AMA-reportable events are flagged.',
    'border'   => true,
    'actions'  => $incidentsHeaderActions,
]);
?>

<!-- ── Filter bar ────────────────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
    <div class="card-body py-3">
        <form method="get" action="incidents.php" class="row g-2 align-items-end">

            <div class="col-12 col-sm-6 col-md-3">
                <label class="form-label small fw-semibold mb-1">Search</label>
                <input type="text" name="q" class="form-control form-control-sm"
                       placeholder="Description, location, member…"
                       value="<?= h($searchQ) ?>">
            </div>

            <div class="col-6 col-sm-3 col-md-2">
                <label class="form-label small fw-semibold mb-1">Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All types</option>
                    <?php foreach (['near_miss' => 'Near Miss', 'crash' => 'Crash', 'injury' => 'Injury',
                                    'property_damage' => 'Property Damage', 'airspace' => 'Airspace', 'other' => 'Other'] as $v => $l): ?>
                    <option value="<?= h($v) ?>" <?= $filterType === $v ? 'selected' : '' ?>><?= h($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-sm-3 col-md-2">
                <label class="form-label small fw-semibold mb-1">Severity</label>
                <select name="severity" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="minor"    <?= $filterSeverity === 'minor'    ? 'selected' : '' ?>>Minor</option>
                    <option value="moderate" <?= $filterSeverity === 'moderate' ? 'selected' : '' ?>>Moderate</option>
                    <option value="serious"  <?= $filterSeverity === 'serious'  ? 'selected' : '' ?>>Serious</option>
                </select>
            </div>

            <div class="col-6 col-sm-3 col-md-2">
                <label class="form-label small fw-semibold mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="open"         <?= $filterStatus === 'open'         ? 'selected' : '' ?>>Open</option>
                    <option value="under_review" <?= $filterStatus === 'under_review' ? 'selected' : '' ?>>Under review</option>
                    <option value="closed"       <?= $filterStatus === 'closed'       ? 'selected' : '' ?>>Closed</option>
                </select>
            </div>

            <div class="col-6 col-sm-3 col-md-2">
                <label class="form-label small fw-semibold mb-1">Year</label>
                <select name="year" class="form-select form-select-sm">
                    <option value="">All years</option>
                    <?php foreach ($years as $yr): ?>
                    <option value="<?= (int) $yr ?>" <?= $filterYear === (int) $yr ? 'selected' : '' ?>><?= (int) $yr ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-auto">
                <button type="submit" class="btn btn-outline-secondary btn-sm">Filter</button>
                <?php if ($filterType || $filterSeverity || $filterStatus || $filterYear || $searchQ): ?>
                <a href="incidents.php" class="btn btn-outline-secondary btn-sm">Clear</a>
                <?php endif; ?>
            </div>

        </form>
    </div>
</div>

<!-- ── Results header ────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <span class="text-muted small">
        <?php if ($totalCount === 0): ?>
            No incidents found.
        <?php else: ?>
            Showing <?= number_format(min($perPage, max(0, $totalCount - $offset))) ?> of <?= number_format($totalCount) ?> incident<?= $totalCount !== 1 ? 's' : '' ?>
            <?php if ($openCount > 0 && !$filterStatus): ?>
            — <span class="text-danger fw-semibold"><?= $openCount ?> open</span>
            <?php endif; ?>
        <?php endif; ?>
    </span>
</div>

<!-- ── Incidents table ───────────────────────────────────────────────────────── -->
<?php if (empty($incidents)): ?>
<div class="card shadow-sm">
    <div class="card-body text-center text-muted py-5">
        <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" fill="currentColor" class="mb-3 opacity-50" viewBox="0 0 16 16">
            <path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5m.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2"/>
        </svg>
        <p class="mb-2 fw-semibold">No incidents logged yet</p>
        <p class="small mb-0">
            <?php if ($filterType || $filterSeverity || $filterStatus || $filterYear || $searchQ): ?>
            No results match your current filters. <a href="incidents.php">Clear filters</a>.
            <?php elseif (canEditMembers()): ?>
            <a href="incident_edit.php">Log your first incident</a> to start building a safety record.
            <?php else: ?>
            No incidents have been logged for this club.
            <?php endif; ?>
        </p>
    </div>
</div>

<?php else: ?>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th style="min-width:100px;">Date</th>
                    <th>Type</th>
                    <th class="d-none d-md-table-cell">Severity</th>
                    <th class="d-none d-lg-table-cell">Location</th>
                    <th>Member involved</th>
                    <th class="d-none d-md-table-cell">Status</th>
                    <th class="d-none d-md-table-cell">AMA</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($incidents as $inc): ?>
            <tr>
                <td class="text-nowrap">
                    <span class="fw-semibold"><?= h(date('M j, Y', strtotime($inc['incident_date']))) ?></span>
                </td>
                <td>
                    <span class="badge bg-<?= incidentTypeColor($inc['incident_type']) ?>">
                        <?= h(incidentTypeLabel($inc['incident_type'])) ?>
                    </span>
                </td>
                <td class="d-none d-md-table-cell">
                    <span class="badge bg-<?= severityColor($inc['severity']) ?>-subtle text-<?= severityColor($inc['severity']) ?> border border-<?= severityColor($inc['severity']) ?>-subtle">
                        <?= h(ucfirst($inc['severity'])) ?>
                    </span>
                </td>
                <td class="d-none d-lg-table-cell text-muted small">
                    <?= $inc['location'] !== '' ? h($inc['location']) : '—' ?>
                </td>
                <td>
                    <?php if ($inc['member_id'] && ($inc['first_name'] || $inc['last_name'])): ?>
                    <a href="member_edit.php?id=<?= (int) $inc['member_id'] ?>" class="text-decoration-none">
                        <?= h(trim($inc['last_name'] . ', ' . $inc['first_name'])) ?>
                    </a>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="d-none d-md-table-cell">
                    <span class="badge bg-<?= statusColor($inc['status']) ?>">
                        <?= h(str_replace('_', ' ', ucfirst($inc['status']))) ?>
                    </span>
                </td>
                <td class="d-none d-md-table-cell text-center">
                    <?php if ($inc['ama_reported']): ?>
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="#198754" viewBox="0 0 16 16" title="Reported to AMA">
                        <path d="M13.854 3.646a.5.5 0 0 1 0 .708l-7 7a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L6.5 10.293l6.646-6.647a.5.5 0 0 1 .708 0z"/>
                    </svg>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-end text-nowrap">
                    <a href="incident_edit.php?id=<?= (int) $inc['id'] ?>"
                       class="btn btn-sm btn-outline-secondary py-0 px-2"
                       style="font-size:.75rem;">
                        <?= canEditMembers() ? 'Edit' : 'View' ?>
                    </a>
                </td>
            </tr>
            <!-- Expandable description row -->
            <tr class="table-light border-top-0">
                <td colspan="8" class="py-1 px-3" style="font-size:.82rem; color:#555;">
                    <?= h(mb_strimwidth($inc['description'], 0, 180, '…')) ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Pagination ─────────────────────────────────────────────────────────── -->
<?php if ($totalPages > 1): ?>
<nav class="mt-3" aria-label="Incidents pagination">
    <ul class="pagination pagination-sm justify-content-center">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= h(incidentsUrl(['page' => $page - 1])) ?>">‹ Prev</a>
        </li>
        <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
            <a class="page-link" href="<?= h(incidentsUrl(['page' => $p])) ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= h(incidentsUrl(['page' => $page + 1])) ?>">Next ›</a>
        </li>
    </ul>
</nav>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>