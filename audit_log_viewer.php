<?php
/**
 * audit_log_viewer.php
 *
 * Admin-only paginated audit log viewer.
 * Shows who did what and when for actions logged via includes/audit_log.php.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

requireAdmin();
$perPage = 20;
$page    = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$offset  = ($page - 1) * $perPage;

$total = 0;
try {
    $stmt = $pdo->query('SELECT COUNT(*) FROM audit_log');
    $total = (int) $stmt->fetchColumn();
} catch (Throwable $e) {
    // Table might not exist in early installs; render empty UI.
}

$rows = [];
try {
    $stmt = $pdo->prepare(
        'SELECT al.id, al.created_at, al.user_id, al.action, al.target_type, al.target_id, al.detail,
                u.name AS actor_name, u.email AS actor_email
         FROM audit_log al
         LEFT JOIN users u ON u.id = al.user_id
         ORDER BY al.id DESC
         LIMIT ? OFFSET ?'
    );
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // No-op
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
$breadcrumbs = [['label' => 'Audit log', 'url' => '']];

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-4 pb-2 border-bottom">
    <div>
        <h1 class="h2 mb-1">Audit log</h1>
        <p class="text-muted mb-0">Recent account &amp; data changes for this club. Only Admin users can view this.</p>
    </div>
    <div class="text-muted small">
        <?php if ($total > 0): ?>
            Showing <?= (int) min($perPage, max(0, $total - $offset)) ?> of <?= (int) $total ?>
        <?php else: ?>
            No audit entries yet.
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($rows)): ?>
            <div class="p-4 text-muted small text-center">
                No audit log entries found for this club.
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
                                    <?= htmlspecialchars(date('M j, Y g:ia', strtotime($r['created_at'] ?? 'now')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($actorLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                    <?php if (!empty($r['actor_email'])): ?>
                                        <div class="text-muted small"><?= htmlspecialchars($r['actor_email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small"><?= htmlspecialchars((string) ($r['action'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                <td class="text-muted small"><?= htmlspecialchars($targetLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                <td><?= renderDetail($r['detail'] ?? null) ?: '<span class="text-muted small">—</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php
                $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;
            ?>
            <?php if ($totalPages > 1): ?>
                <div class="p-3 border-top">
                    <nav aria-label="Audit log pagination" class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <?php if ($page > 1): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="audit_log_viewer.php?page=<?= (int) ($page - 1) ?>">← Previous</a>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted small">
                            Page <?= (int) $page ?> of <?= (int) $totalPages ?>
                        </div>
                        <div>
                            <?php if ($page < $totalPages): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="audit_log_viewer.php?page=<?= (int) ($page + 1) ?>">Next →</a>
                            <?php endif; ?>
                        </div>
                    </nav>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

