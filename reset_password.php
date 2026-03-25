<?php
/**
 * Reset password: validate token from email link and set new password.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/password_policy.php';
require_once __DIR__ . '/includes/mail.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$token = trim($_REQUEST['token'] ?? '');
$error = '';
$success = false;

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
        token_hash varchar(64) NOT NULL,
        email varchar(255) NOT NULL,
        expires_at datetime NOT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (token_hash),
        KEY email_expires (email, expires_at)
    )");
} catch (Throwable $e) {
}

if ($token === '' || strlen($token) !== 64 || !ctype_xdigit($token)) {
    $error = 'Invalid or missing reset link. Request a new link from the forgot password page.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare('
        SELECT email
        FROM password_reset_tokens
        WHERE token_hash = ?
          AND expires_at > NOW()
        LIMIT 1
    ');
    try {
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $error = 'This reset link is invalid. Request a new link from the forgot password page.';
        $row = null;
    }
    if (!$row) {
        $error = 'This reset link has expired or was already used. Request a new link from the forgot password page.';
    } else {
        $email = $row['email'];
        $newPassword = $_POST['new_password'] ?? '';
        $confirm = $_POST['new_password_confirm'] ?? '';
        if ($newPassword !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            list($pwOk, $pwError) = validate_password_policy($newPassword);
            if (!$pwOk) {
                $error = $pwError;
            } else {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                try {
                    $pdo->prepare('UPDATE users SET password_hash = ? WHERE email = ? LIMIT 1')->execute([$hash, $email]);
                    $pdo->prepare('DELETE FROM password_reset_tokens WHERE token_hash = ?')->execute([$tokenHash]);
                    $success = true;
                    session_regenerate_id(true);

                    try {
                        $clubName = 'RC Flight Operations';
                        try {
                            $cstmt = $pdo->query('SELECT name FROM club WHERE id = 1 LIMIT 1');
                            $cRow = $cstmt ? $cstmt->fetch(PDO::FETCH_ASSOC) : false;
                            if (!empty($cRow['name'])) {
                                $clubName = $cRow['name'];
                            }
                        } catch (Throwable $e) {
                        }

                        $subject = ($clubName ?: 'RC Flight Operations') . ' – Your password was changed';
                        $loginUrl = 'login.php';

                        $bodyHtml =
                            '<p>Hello,</p>' .
                            '<p>This is a confirmation that your password was changed successfully.</p>' .
                            '<p>If you did not request a password reset, please contact your club admin as soon as possible.</p>' .
                            '<p>You can log in here: <a href="' . htmlspecialchars($loginUrl) . '">' . htmlspecialchars($loginUrl) . '</a></p>' .
                            '<p>— ' . htmlspecialchars($clubName ?: 'RC Flight Operations') . '</p>';

                        $bodyText =
                            "Hello,\n\n" .
                            "This is a confirmation that your password was changed successfully.\n" .
                            "If you did not request a password reset, please contact your club admin as soon as possible.\n" .
                            "You can log in here: {$loginUrl}\n\n" .
                            "— " . ($clubName ?: 'RC Flight Operations');

                        send_mail($email, $subject, $bodyHtml, $bodyText);
                    } catch (Throwable $e) {
                    }
                } catch (Throwable $e) {
                    $error = 'Unable to reset password. Please try again or request a new reset link.';
                }
            }
        }
    }
}

$pageTitle = 'Reset password';
$noNav = true;
require_once __DIR__ . '/includes/header.php';
?>
<div class="row justify-content-center mt-5">
    <div class="col-md-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-3">Set new password</h1>
                <?php if ($success): ?>
                    <p class="text-success mb-0">
                        Your password has been updated. If email sending is configured, you should also receive a confirmation email.
                    </p>
                    <a href="login.php" class="btn btn-primary mt-3">Log in</a>
                <?php elseif ($error && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
                    <p class="text-danger"><?= htmlspecialchars($error) ?></p>
                    <a href="forgot_password.php" class="btn btn-outline-primary">Request new link</a>
                <?php else: ?>
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    <form method="post" action="reset_password.php?token=<?= htmlspecialchars($token) ?>">
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label for="new_password" class="form-label">New password</label>
                            <input type="password" class="form-control password-strength-input" id="new_password" name="new_password" required minlength="8" autocomplete="new-password" placeholder="10+ chars or 8+ with number &amp; symbol">
                            <div class="password-strength small mt-1" aria-live="polite"></div>
                        </div>
                        <div class="mb-3">
                            <label for="new_password_confirm" class="form-label">Confirm new password</label>
                            <input type="password" class="form-control" id="new_password_confirm" name="new_password_confirm" required minlength="8" autocomplete="new-password">
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Update password</button>
                    </form>
                    <p class="mt-3 mb-0 small text-muted"><a href="login.php">← Back to login</a></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/password_strength_ui.php'; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
