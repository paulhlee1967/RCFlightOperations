<?php
/**
 * Member delete — polished v1.0.
 *
 * Single delete:  GET  ?id=N         → confirmation page (used as fallback /
 *                                       direct link; primary UX is a Bootstrap
 *                                       modal in member_edit.php)
 *                 POST ?id=N confirm  → deletes, redirects with flash
 *
 * Bulk delete:    POST member_ids[]   → confirmation page (from members.php bulk form)
 *                 POST confirm + ids  → deletes, redirects with flash
 *
 * Flash messages are set via includes/flash.php and rendered by header.php toasts.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/flash.php';
require_once __DIR__ . '/includes/audit_log.php';

requireLogin();
if (!canEditMembers()) {
    header('Location: index.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
}
$bulkIds  = isset($_POST['member_ids']) && is_array($_POST['member_ids'])
    ? array_map('intval', array_filter($_POST['member_ids']))
    : [];
$singleId = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;
$isBulk   = count($bulkIds) > 0;

/**
 * Remove a member photo file only if it resolves under project uploads/ (blocks path traversal).
 */
function flightops_safe_unlink_member_photo(string $relativePhotoPath): void {
    $relativePhotoPath = ltrim($relativePhotoPath, '/');
    if ($relativePhotoPath === '' || str_contains($relativePhotoPath, '..')) {
        return;
    }
    $full  = realpath(__DIR__ . '/' . $relativePhotoPath);
    $base  = realpath(__DIR__ . '/uploads');
    if ($full === false || $base === false || !is_file($full)) {
        return;
    }
    if (!str_starts_with($full, $base . DIRECTORY_SEPARATOR) && $full !== $base) {
        return;
    }
    @unlink($full);
}

// ── Helper: delete a set of member IDs and clean up photo files ──────────────
function deleteMembers(PDO $pdo, array $ids, int $userId = 0): int {
    if (empty($ids)) return 0;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare("SELECT photo_path FROM members WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $photoPaths = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['photo_path'])) {
            $photoPaths[] = (string) $row['photo_path'];
        }
    }

    $delStmt = $pdo->prepare('DELETE FROM members WHERE id = ?');
    $deleted  = 0;
    foreach ($ids as $mid) {
        if ($mid > 0) {
            $delStmt->execute([$mid]);
            if ($delStmt->rowCount()) {
                $deleted++;
                if (function_exists('audit_log')) {
                    audit_log($pdo, $userId, 'member_delete', 'member', $mid, '');
                }
            }
        }
    }

    foreach ($photoPaths as $rel) {
        flightops_safe_unlink_member_photo($rel);
    }

    return $deleted;
}

// ════════════════════════════════════════════════════════════════════════════
// BULK: confirmed delete
// ════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isBulk && !empty($_POST['confirm'])) {
    $count = deleteMembers($pdo, $bulkIds, currentUserId());
    if ($count === 1) {
        flash('1 member deleted.', 'success');
    } elseif ($count > 1) {
        flash("$count members deleted.", 'success');
    }
    header('Location: members.php');
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// SINGLE: confirmed delete (POST with confirm flag)
// ════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $singleId > 0 && !empty($_POST['confirm']) && !$isBulk) {
    $count = deleteMembers($pdo, [$singleId], currentUserId());
    if ($count > 0) {
        flash('Member deleted.', 'success');
    }
    header('Location: members.php');
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// BULK: show confirmation page
// ════════════════════════════════════════════════════════════════════════════
if ($isBulk) {
    // Load names for the confirmation list
    $placeholders = implode(',', array_fill(0, count($bulkIds), '?'));
    $stmt = $pdo->prepare("
        SELECT id, first_name, last_name
        FROM members
        WHERE id IN ($placeholders)
        ORDER BY last_name, first_name
    ");
    $stmt->execute($bulkIds);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($members) === 0) {
        header('Location: members.php');
        exit;
    }

    $breadcrumbs = [
        ['label' => 'Members', 'url' => 'members.php'],
        ['label' => 'Delete ' . count($members) . ' member' . (count($members) !== 1 ? 's' : '')],
    ];
    $pageTitle = 'Delete members';
    require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card border-danger shadow-sm">
            <div class="card-header bg-danger text-white d-flex align-items-center gap-2">
                <!-- Warning icon -->
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5m.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2"/>
                </svg>
                <span class="fw-semibold">Delete <?= count($members) ?> member<?= count($members) !== 1 ? 's' : '' ?>?</span>
            </div>
            <div class="card-body">
                <p class="mb-1">You're about to permanently delete the following members:</p>
                <p class="text-muted small mb-3">
                    All contact info, payment history, and addresses will also be removed.
                    <strong>This cannot be undone.</strong>
                </p>

                <!-- Member name list -->
                <div class="border rounded p-2 mb-3 bg-light" style="max-height:200px; overflow-y:auto;">
                    <ul class="list-unstyled mb-0 small">
                        <?php foreach (array_slice($members, 0, 30) as $m): ?>
                        <li class="py-1 border-bottom">
                            <?= htmlspecialchars(trim($m['last_name'] . ', ' . $m['first_name']) ?: 'Member #' . $m['id']) ?>
                        </li>
                        <?php endforeach; ?>
                        <?php if (count($members) > 30): ?>
                        <li class="py-1 text-muted fst-italic">…and <?= count($members) - 30 ?> more</li>
                        <?php endif; ?>
                    </ul>
                </div>

                <form method="post" action="member_delete.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="confirm" value="1">
                    <?php foreach ($bulkIds as $mid): ?>
                    <input type="hidden" name="member_ids[]" value="<?= (int) $mid ?>">
                    <?php endforeach; ?>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-danger">
                            Delete <?= count($members) ?> member<?= count($members) !== 1 ? 's' : '' ?>
                        </button>
                        <a href="members.php" class="btn btn-outline-secondary">Cancel — go back</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// SINGLE: show confirmation page (fallback for direct links / non-JS)
// ════════════════════════════════════════════════════════════════════════════
if ($singleId <= 0) {
    header('Location: members.php');
    exit;
}

$stmt = $pdo->prepare('SELECT id, first_name, last_name FROM members WHERE id = ?');
$stmt->execute([$singleId]);
$member = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$member) {
    header('Location: members.php');
    exit;
}

$name = trim($member['last_name'] . ', ' . $member['first_name']);
if ($name === ',') $name = 'Member #' . $singleId;

$breadcrumbs = [
    ['label' => 'Members',           'url' => 'members.php'],
    ['label' => htmlspecialchars($name), 'url' => 'member_edit.php?id=' . $singleId],
    ['label' => 'Delete'],
];
$pageTitle = 'Delete member';
require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card border-danger shadow-sm">
            <div class="card-header bg-danger text-white d-flex align-items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5m.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2"/>
                </svg>
                <span class="fw-semibold">Delete member?</span>
            </div>
            <div class="card-body">
                <p class="mb-1">Permanently delete <strong><?= htmlspecialchars($name) ?></strong>?</p>
                <p class="text-muted small mb-4">
                    All contact info, phone numbers, addresses, and payment history will be removed.
                    <strong>This cannot be undone.</strong>
                </p>
                <form method="post" action="member_delete.php?id=<?= (int) $singleId ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="confirm" value="1">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-danger">Delete member</button>
                        <a href="member_edit.php?id=<?= (int) $singleId ?>" class="btn btn-outline-secondary">Cancel — go back</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>