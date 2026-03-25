<?php
/**
 * forgot_password.php
 *
 * Request a password reset link by email.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/mail.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$sent  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    $email = trim($_POST['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $clientIp = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($clientIp === '') {
            $clientIp = '0';
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_ip_events (
                id int unsigned NOT NULL AUTO_INCREMENT,
                ip varchar(45) NOT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY ip_created (ip, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
        }
        try {
            $pdo->exec('DELETE FROM password_reset_ip_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 25 HOUR)');
        } catch (Throwable $e) {
        }
        try {
            $ipRate = $pdo->prepare(
                'SELECT COUNT(*) FROM password_reset_ip_events
                 WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
            );
            $ipRate->execute([$clientIp]);
            if ((int) $ipRate->fetchColumn() >= 12) {
                $sent = true;
                goto show_form;
            }
            $pdo->prepare('INSERT INTO password_reset_ip_events (ip) VALUES (?)')->execute([$clientIp]);
        } catch (Throwable $e) {
        }

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

        $userStmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ? AND password_hash != \'\' LIMIT 1');
        $userStmt->execute([$email]);
        if (!$userStmt->fetch()) {
            $sent = true;
        } else {
            $cooldownStmt = $pdo->prepare('
                SELECT COUNT(*) FROM password_reset_tokens
                WHERE email = ?
                  AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                  AND expires_at > NOW()
            ');
            $cooldownStmt->execute([$email]);
            if ((int) $cooldownStmt->fetchColumn() > 0) {
                $sent = true;
                goto show_form;
            }

            $token     = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', time() + 3600);

            $pdo->prepare('DELETE FROM password_reset_tokens WHERE email = ?')->execute([$email]);

            try {
                $pdo->prepare(
                    'INSERT INTO password_reset_tokens (token_hash, email, expires_at) VALUES (?, ?, ?)'
                )->execute([$tokenHash, $email, $expiresAt]);
            } catch (Throwable $e) {
                $sent = true;
                goto show_form;
            }

            global $config;
            $scheme = flightops_request_scheme($config);
            $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
            $resetLink = $scheme . '://' . $host . $basePath . '/reset_password.php?token=' . $token;

            $subject = 'Password reset — RC Flight Operations';
            $body    = '<p>You (or someone else) requested a password reset for your account.</p>'
                     . '<p><a href="' . htmlspecialchars($resetLink) . '">Click here to set a new password</a>'
                     . ' — this link expires in 1 hour.</p>'
                     . '<p>If you did not request this, you can safely ignore this email. '
                     . 'Your password has not been changed.</p>';

            $bodyText = strip_tags($body);
            if (!send_mail($email, $subject, $body, $bodyText)) {
                $error = 'We could not send the email. Please try again later or contact your club admin.';
            } else {
                $sent = true;
            }
        }
    }
}
show_form:

$pageTitle = 'Forgot password';
$noNav     = true;
require_once __DIR__ . '/includes/header.php';
?>
<div class="row justify-content-center mt-5">
    <div class="col-md-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-3">Forgot password</h1>

                <?php if ($sent): ?>
                    <p class="text-success mb-0">
                        If that email is registered, we've sent a reset link.
                        Check your inbox and spam folder — the link expires in 1 hour.
                    </p>
                    <a href="login.php" class="btn btn-primary mt-3">Back to login</a>
                <?php else: ?>
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    <p class="text-muted small mb-3">
                        Enter your account email and we'll send a link to set a new password.
                    </p>
                    <form method="post" action="forgot_password.php">
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email"
                                   required autocomplete="email"
                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Send reset link</button>
                    </form>
                    <p class="mt-3 mb-0 small text-muted">
                        <a href="login.php">← Back to login</a>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
