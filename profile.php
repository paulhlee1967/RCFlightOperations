<?php
/**
 * Profile: change your own password.
 * Any logged-in user (admin, editor, treasurer, viewer) can set a new password
 * after verifying their current one.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/password_policy.php';
require_once __DIR__ . '/includes/audit_log.php';

requireLogin();

$userId   = currentUserId();
$error    = '';
$saved    = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword    = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['new_password_confirm'] ?? '';

    if ($currentPassword === '') {
        $error = 'Enter your current password.';
    } else {
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($currentPassword, $row['password_hash'] ?? '')) {
            $error = 'Current password is incorrect.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'New password and confirmation do not match.';
        } else {
            list($pwOk, $pwError) = validate_password_policy($newPassword);
            if (!$pwOk) {
                $error = $pwError;
            } else {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([$hash, $userId]);
                audit_log($pdo, $userId, 'user_password_change', 'user', $userId, '');
                $saved = true;
            }
        }
    }
}

$pageTitle = 'Profile';
$breadcrumbs = [['label' => 'Profile', 'url' => '']];
require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-8 col-lg-5">
        <h1 class="h2 mb-3">Change password</h1>
        <p class="text-muted mb-4">Set a new password for your account. You’ll need to enter your current password to confirm.</p>

        <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
        <?php endif; ?>
        <?php if ($saved): ?>
        <div class="alert alert-success">Your password has been updated.</div>
        <?php endif; ?>

        <form method="post" action="profile.php" class="card">
            <?= csrf_field() ?>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="current_password">Current password <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" id="current_password" name="current_password"
                           required autocomplete="current-password" placeholder="Your current password">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="new_password">New password <span class="text-danger">*</span></label>
                    <input type="password" class="form-control password-strength-input" id="new_password" name="new_password"
                           required minlength="8" placeholder="10+ chars or 8+ with number &amp; symbol"
                           autocomplete="new-password">
                    <div class="password-strength small mt-1" aria-live="polite"></div>
                </div>
                <div class="mb-0">
                    <label class="form-label" for="new_password_confirm">Confirm new password <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" id="new_password_confirm" name="new_password_confirm"
                           required minlength="8" autocomplete="new-password" placeholder="Repeat new password">
                </div>
            </div>
            <div class="card-footer bg-transparent">
                <button type="submit" class="btn btn-primary">Update password</button>
                <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/includes/password_strength_ui.php'; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
