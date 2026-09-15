<?php
/**
 * user_delete.php — Confirm and permanently delete a system user.
 *
 * Deleting removes the user row from `users` (they will no longer be able
 * to log in). Audit history is retained.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/flash.php';
require_once __DIR__ . '/includes/audit_log.php';

requireAdmin();
$targetId = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;
if ($targetId < 1) {
    header('Location: users.php');
    exit;
}

$stmt = $pdo->prepare(
    'SELECT id, email, name, role, COALESCE(active, 1) AS active
     FROM users
     WHERE id = ?'
);
$stmt->execute([$targetId]);
$user = $stmt->fetch();
if (!$user) {
    header('Location: users.php');
    exit;
}

$currentUserId = currentUserId();
$isSelf        = ((int) $user['id'] === $currentUserId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    // Require an explicit confirm for this handler.
    if (empty($_POST['confirm'])) {
        header('Location: user_delete.php?id=' . (int) $targetId);
        exit;
    }

    if ($isSelf) {
        flash('You cannot delete your own account.', 'warning');
        header('Location: users.php');
        exit;
    }

    // Prevent accidental lockout of the club by ensuring at least one
    // active admin remains after deleting an active admin user.
    $isActiveAdminBeingDeleted = ((string) ($user['role'] ?? '') === 'admin') && ((int) ($user['active'] ?? 1) === 1);
    if ($isActiveAdminBeingDeleted) {
        $adminCountStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM users
             WHERE role = \'admin\' AND active = 1 AND id != ?'
        );
        $adminCountStmt->execute([$targetId]);
        $remainingActiveAdmins = (int) $adminCountStmt->fetchColumn();

        if ($remainingActiveAdmins < 1) {
            flash('You must keep at least one other active Admin account.', 'warning');
            header('Location: users.php');
            exit;
        }
    }

    $delStmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $delStmt->execute([$targetId]);

    if ($delStmt->rowCount() > 0) {
        audit_log($pdo, $currentUserId, 'user_delete', 'user', $targetId, '');
        flash('User deleted.', 'success');
    } else {
        flash('User was not deleted (it may have already been removed).', 'warning');
    }

    header('Location: users.php');
    exit;
}

$displayName = trim((string) ($user['name'] ?? ''));
$displayName = $displayName !== '' ? $displayName : (string) ($user['email'] ?? '');

$breadcrumbs = [
    ['label' => 'Users', 'url' => 'users.php'],
    ['label' => 'Delete', 'url' => ''],
];

$pageTitle = 'Delete user';
require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card border-danger shadow-sm">
            <div class="card-header bg-danger text-white fw-semibold">
                Delete user?
            </div>
            <div class="card-body">
                <p class="mb-1">Permanently delete <strong><?= h($displayName) ?></strong>?</p>
                <p class="text-muted small mb-4">
                    They will no longer be able to log in. This cannot be undone.
                </p>

                <form method="post" action="user_delete.php?id=<?= (int) $targetId ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="confirm" value="1">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-danger" <?= $isSelf ? 'disabled' : '' ?>>
                            Delete user
                        </button>
                        <a href="users.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>

                    <?php if ($isSelf): ?>
                        <div class="text-muted small mt-2">
                            You cannot delete your own account.
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

