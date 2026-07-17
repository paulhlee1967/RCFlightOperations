<?php
/**
 * Edit a system user: name, role, active, set new password. Admin only.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/password_policy.php';
require_once __DIR__ . '/includes/audit_log.php';

requireAdmin();
$userId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($userId < 1) {
    header('Location: users.php');
    exit;
}

$stmt = $pdo->prepare('SELECT id, email, name, role, COALESCE(active, 1) AS active FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user) {
    header('Location: users.php');
    exit;
}

$saved = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_validate();
        $email = trim($_POST['email'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $role = normalizeUserRole(trim($_POST['role'] ?? 'manager'));
        $active = isset($_POST['active']) ? 1 : 0;
        $newPassword = $_POST['new_password'] ?? '';

        $roles = array_keys(getSystemUserRoles());
        if (!in_array($role, $roles, true)) $role = 'manager';

        if ($email === '') {
            $error = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
            $stmt->execute([$email, $userId]);
            if ($stmt->fetch()) {
                $error = 'That email is already used by another user.';
            } elseif ($userId === currentUserId() && $active === 0) {
                $error = 'You cannot deactivate your own account.';
            } else {
                if ($newPassword !== '') {
                    list($pwOk, $pwError) = validate_password_policy($newPassword);
                    if (!$pwOk) {
                        $error = $pwError;
                    } else {
                        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare('UPDATE users SET email = ?, name = ?, role = ?, active = ?, password_hash = ? WHERE id = ?');
                        $stmt->execute([$email, $name ?: $email, $role, $active, $hash, $userId]);
                        audit_log($pdo, currentUserId(), 'user_edit', 'user', $userId, json_encode(['password_changed' => true]));
                        $saved = true;
                        $user['email'] = $email;
                        $user['name'] = $name ?: $email;
                        $user['role'] = $role;
                        $user['active'] = $active;
                        if ($userId === currentUserId()) $_SESSION['user_email'] = $email;
                    }
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET email = ?, name = ?, role = ?, active = ? WHERE id = ?');
                    $stmt->execute([$email, $name ?: $email, $role, $active, $userId]);
                    audit_log($pdo, currentUserId(), 'user_edit', 'user', $userId, '');
                    $saved = true;
                    $user['email'] = $email;
                    $user['name'] = $name ?: $email;
                    $user['role'] = $role;
                    $user['active'] = $active;
                    if ($userId === currentUserId()) $_SESSION['user_email'] = $email;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('user_edit.php save failed: ' . $e->getMessage());
        $error = 'Save failed. Please try again or check that your database schema is up to date.';
        $showErrors = !empty($config['show_save_errors']) || !empty($config['db']['show_save_errors']);
        if ($showErrors) {
            $error .= ' [' . htmlspecialchars($e->getMessage()) . ']';
        }
    }
}

$pageTitle = 'Edit user';
$breadcrumbs = [
    ['label' => 'Administration', 'url' => 'users.php'],
    ['label' => 'Users', 'url' => 'users.php'],
    ['label' => $user['name'] ?: $user['email'], 'url' => ''],
];
require_once __DIR__ . '/includes/page_header.php';
require_once __DIR__ . '/includes/header.php';

render_page_header(['title' => 'Edit user', 'class' => 'mb-3']);

if ($error): ?>
<div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>
<?php if ($saved): ?>
<div class="alert alert-success">Saved.</div>
<?php endif; ?>

<form method="post" action="user_edit.php?id=<?= $userId ?>">
    <?= csrf_field() ?>
    <div class="card mb-3">
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">Email (login)</label>
                <input type="email" class="form-control" name="email" required value="<?= h($user['email']) ?>" placeholder="user@club.org">
                <small class="text-muted">Used to log in. Must be unique.</small>
            </div>
            <div class="mb-3">
                <label class="form-label">Display name</label>
                <input type="text" class="form-control" name="name" value="<?= h($user['name']) ?>" placeholder="Jane Doe">
            </div>
            <div class="mb-3">
                <label class="form-label">Role</label>
                <select name="role" class="form-select">
                    <?php foreach (getSystemUserRoles() as $value => $label): ?>
                    <option value="<?= h($value) ?>" <?= $user['role'] === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-check mb-3">
                <input type="checkbox" class="form-check-input" name="active" id="active" value="1" <?= (int) $user['active'] === 1 ? ' checked' : '' ?> <?= $userId === currentUserId() ? ' disabled' : '' ?>>
                <label class="form-check-label" for="active">Active (can log in)</label>
                <?php if ($userId === currentUserId()): ?>
                <small class="text-muted d-block">You cannot deactivate your own account.</small>
                <?php endif; ?>
            </div>
            <?php if ($userId === currentUserId()): ?>
            <input type="hidden" name="active" value="1">
            <?php endif; ?>
            <div class="mb-0">
                <label class="form-label">New password (leave blank to keep current)</label>
                <input type="password" class="form-control password-strength-input" name="new_password" minlength="8" placeholder="10+ chars or 8+ with number &amp; symbol" autocomplete="new-password">
                <div class="password-strength small mt-1" aria-live="polite"></div>
            </div>
        </div>
    </div>
    <button type="submit" class="btn btn-primary">Save</button>
    <a href="users.php" class="btn btn-outline-secondary">Cancel</a>
</form>
<?php require_once __DIR__ . '/includes/password_strength_ui.php'; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
