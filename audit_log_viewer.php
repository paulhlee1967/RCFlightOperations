<?php
/**
 * audit_log_viewer.php
 *
 * Admin-only paginated audit log viewer with filtering.
 * Shows who did what and when for actions logged via includes/audit_log.php.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

requireAdmin();

$filterAction   = trim($_GET['action'] ?? '');
$filterUser     = trim($_GET['user'] ?? '');
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo   = trim($_GET['date_to'] ?? '');
$searchQ        = trim($_GET['q'] ?? '');

$perPage = 20;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($filterAction !== '') {
    $where[]  = 'al.action = ?';
    $params[] = $filterAction;
}
if ($filterUser === 'system') {
    $where[] = 'al.user_id = 0';
} elseif ($filterUser !== '' && ctype_digit($filterUser)) {
    $where[]  = 'al.user_id = ?';
    $params[] = (int) $filterUser;
}
if ($filterDateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateFrom)) {
    $where[]  = 'DATE(al.created_at) >= ?';
    $params[] = $filterDateFrom;
}
if ($filterDateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateTo)) {
    $where[]  = 'DATE(al.created_at) <= ?';
    $params[] = $filterDateTo;
}
if ($searchQ !== '') {
    $where[]  = '(al.detail LIKE ? OR al.target_type LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
    $like     = '%' . $searchQ . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereClause = implode(' AND ', $where);

/**
 * Build a URL preserving current filters.
 *
 * @param array<string, mixed> $overrides
 */
function auditLogUrl(array $overrides = []): string
{
    global $filterAction, $filterUser, $filterDateFrom, $filterDateTo, $searchQ, $page;

    $p = array_filter([
        'action'    => $overrides['action']    ?? ($filterAction   !== '' ? $filterAction   : null),
        'user'      => $overrides['user']      ?? ($filterUser     !== '' ? $filterUser     : null),
        'date_from' => $overrides['date_from'] ?? ($filterDateFrom !== '' ? $filterDateFrom : null),
        'date_to'   => $overrides['date_to']   ?? ($filterDateTo   !== '' ? $filterDateTo   : null),
        'q'         => $overrides['q']         ?? ($searchQ        !== '' ? $searchQ        : null),
        'page'      => $overrides['page']      ?? ($page           > 1   ? $page           : null),
    ], static fn ($v) => $v !== null && $v !== '');

    return 'audit_log_viewer.php' . ($p ? '?' . http_build_query($p) : '');
}

$total = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log al LEFT JOIN users u ON u.id = al.user_id WHERE {$whereClause}");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();
} catch (Throwable $e) {
    // Table might not exist in early installs; render empty UI.
}

$rows = [];
try {
    $stmt = $pdo->prepare(
        "SELECT al.id, al.created_at, al.user_id, al.action, al.target_type, al.target_id, al.detail,
                u.name AS actor_name, u.email AS actor_email
         FROM audit_log al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE {$whereClause}
         ORDER BY al.id DESC
         LIMIT ? OFFSET ?"
    );
    $bind = 1;
    foreach ($params as $param) {
        $stmt->bindValue($bind++, $param);
    }
    $stmt->bindValue($bind++, $perPage, PDO::PARAM_INT);
    $stmt->bindValue($bind, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // No-op
}

$actions = [];
$users   = [];
try {
    $actions = $pdo->query('SELECT DISTINCT action FROM audit_log ORDER BY action ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $users   = $pdo->query('SELECT id, name, email FROM users ORDER BY name ASC, email ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
}

function renderDetail(string|null $detail): string {
    $detail = $detail ?? '';
    $detail = trim($detail);
    if ($detail === '') return '';

    $decoded = json_decode($detail, true);
    if (is_array($decoded)) {
        return '<code class="text-muted">' . htmlspecialchars(json_encode($decoded, JSON_UNESCAPED_SLASHES), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
    }

    $escaped = htmlspecialchars($detail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if (mb_strlen($escaped) > 110) {
        $escaped = mb_substr($escaped, 0, 107) . '…';
    }
    return '<span class="text-muted small">' . $escaped . '</span>';
}

$pageTitle   = 'Audit log';
$breadcrumbs = [
    ['label' => 'Administration', 'url' => 'users.php'],
    ['label' => 'Audit log', 'url' => ''],
];

require_once __DIR__ . '/includes/page_header.php';

$hasFilters = $filterAction !== '' || $filterUser !== '' || $filterDateFrom !== '' || $filterDateTo !== '' || $searchQ !== '';
$totalPages = $perPage > 0 ? max(1, (int) ceil($total / $perPage)) : 1;

ob_start();
if ($total > 0) {
    echo 'Showing ' . (int) min($perPage, max(0, $total - $offset)) . ' of ' . (int) $total;
} else {
    echo 'No audit entries' . ($hasFilters ? ' match your filters' : ' yet') . '.';
}
$auditHeaderMeta = ob_get_clean();

require_once __DIR__ . '/includes/header.php';

render_page_header([
    'title'        => 'Audit log',
    'subtitle'     => 'Recent account & data changes for this club. Only Admin users can view this.',
    'border'       => true,
    'actions'      => '<div class="text-muted small">' . $auditHeaderMeta . '</div>',
]);
?>

<div class="card shadow-sm mb-4">
    <div class="card-body py-3">
        <form method="get" action="audit_log_viewer.php" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label small fw-semibold mb-1">Search</label>
                <input type="text" name="q" class="form-control form-control-sm"
                       placeholder="Detail, target, actor name or email…"
                       value="<?= h($searchQ) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold mb-1">Action</label>
                <select name="action" class="form-select form-select-sm">
                    <option value="">All actions</option>
                    <?php foreach ($actions as $action): ?>
                    <option value="<?= h((string) $action) ?>" <?= $filterAction === (string) $action ? 'selected' : '' ?>><?= h((string) $action) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold mb-1">User</label>
                <select name="user" class="form-select form-select-sm">
                    <option value="">All users</option>
                    <option value="system" <?= $filterUser === 'system' ? 'selected' : '' ?>>System</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?= (int) $u['id'] ?>" <?= $filterUser === (string) (int) $u['id'] ? 'selected' : '' ?>>
                        <?= h(trim((string) ($u['name'] ?: $u['email']))) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold mb-1">From</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($filterDateFrom) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold mb-1">To</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($filterDateTo) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-outline-secondary btn-sm">Filter</button>
                <?php if ($hasFilters): ?>
                <a href="audit_log_viewer.php" class="btn btn-outline-secondary btn-sm">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($rows)): ?>
            <div class="p-4 text-muted small text-center">
                <?php if ($hasFilters): ?>
                No audit log entries match your filters. <a href="audit_log_viewer.php">Clear filters</a>.
                <?php else: ?>
                No audit log entries found for this club.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:.875rem;">
                    <thead class="table-light">
                        <tr>
                            <th style="width:12rem;">When</th>
                            <th style="width:15rem;">Actor</th>
                            <th style="width:13rem;">Action</th>
                            <th style="width:12rem;">Target</th>
                            <th>Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <?php
                                $actorLabel = '';
                                if ((int) ($r['user_id'] ?? 0) === 0) {
                                    $actorLabel = 'System';
                                } else {
                                    $actorLabel = trim((string) ($r['actor_name'] ?? ''));
                                    if ($actorLabel === '') $actorLabel = trim((string) ($r['actor_email'] ?? ''));
                                    if ($actorLabel === '') $actorLabel = 'User #' . (int) ($r['user_id'] ?? 0);
                                }

                                $targetType = (string) ($r['target_type'] ?? '');
                                $targetId   = (int) ($r['target_id'] ?? 0);
                                $targetLabel = $targetType;
                                if ($targetId > 0) $targetLabel = $targetType . ' #' . $targetId;
                            ?>
                            <tr>
                                <td class="text-muted" style="white-space:nowrap;">
                                    <?= h(date('M j, Y g:ia', strtotime($r['created_at'] ?? 'now'))) ?>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?= h($actorLabel) ?></div>
                                    <?php if (!empty($r['actor_email'])): ?>
                                        <div class="text-muted small"><?= h($r['actor_email']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small"><?= h((string) ($r['action'] ?? '')) ?></td>
                                <td class="text-muted small"><?= h($targetLabel) ?></td>
                                <td><?= renderDetail($r['detail'] ?? null) ?: '<span class="text-muted small">—</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="p-3 border-top">
                    <nav aria-label="Audit log pagination" class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <?php if ($page > 1): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= h(auditLogUrl(['page' => $page - 1])) ?>">← Previous</a>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted small">
                            Page <?= (int) $page ?> of <?= (int) $totalPages ?>
                        </div>
                        <div>
                            <?php if ($page < $totalPages): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= h(auditLogUrl(['page' => $page + 1])) ?>">Next →</a>
                            <?php endif; ?>
                        </div>
                    </nav>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
