<?php
/**
 * login.php
 *
 * Login: email + password. Sets session (user_id, …) and redirects.
 * Brute-force protection: lock 15 minutes after 5 failed attempts per email.
 * Redirect parameter is validated to prevent open redirects.
 *
 * Branding: hero shows RC Flight Operations; after sign-in, the app uses the club theme.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/safe_redirect.php';
require_once __DIR__ . '/includes/flightops_logo.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';

// Brute-force: max attempts and lock duration (minutes)
$login_max_attempts = 5;
$login_lock_minutes = 15;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    $email    = trim($_POST['email']    ?? '');
    $password =      $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Email and password are required.';
    } else {
        // Check if this email is currently locked
        $stmt = $pdo->prepare('SELECT failed_count, locked_until FROM login_attempts WHERE email = ?');
        $stmt->execute([$email]);
        $attempt = $stmt->fetch();
        $lockedUntil = $attempt['locked_until'] ?? null;
        if ($lockedUntil && strtotime($lockedUntil) > time()) {
            $error = 'Too many failed attempts. This account is locked for ' . $login_lock_minutes . ' minutes. Please try again later.';
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, email, password_hash, name, role,
                        COALESCE(active, 1) AS active
                 FROM   users
                 WHERE  email = ?
                 LIMIT  1'
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user
                && (int) ($user['active'] ?? 1) === 1
                && $user['password_hash'] !== ''
                && password_verify($password, $user['password_hash'])
            ) {
                // Success: clear attempts for this email
                $pdo->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);

                // Prevent session fixation: regenerate session ID before elevating privileges
                session_regenerate_id(true);

                $_SESSION['user_id']    = (int) $user['id'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_name']  = $user['name'];
                $_SESSION['user_role']  = $user['role'] ?? 'editor';

                $redirect = safe_redirect_url($_GET['redirect'] ?? 'index.php', 'index.php');
                header('Location: ' . $redirect);
                exit;
            }

            // Failed attempt: upsert login_attempts
            $failedCount = (int) ($attempt['failed_count'] ?? 0) + 1;
            $lockedUntil = null;
            if ($failedCount >= $login_max_attempts) {
                $lockedUntil = date('Y-m-d H:i:s', time() + $login_lock_minutes * 60);
            }
            $stmt = $pdo->prepare(
                'INSERT INTO login_attempts (email, failed_count, locked_until) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE failed_count = ?, locked_until = ?, updated_at = NOW()'
            );
            $stmt->execute([$email, $failedCount, $lockedUntil, $failedCount, $lockedUntil]);

            $error = 'Invalid email or password.';
            if ($lockedUntil) {
                $error = 'Too many failed attempts. This account is locked for ' . $login_lock_minutes . ' minutes. Please try again later.';
            }
        }
    }
}

$redirect   = safe_redirect_url($_GET['redirect'] ?? '', 'index.php');
$pageTitle  = 'Log in';
$noNav      = true;

require_once __DIR__ . '/includes/header.php';
?>

<!-- ══════════════════════════════════════════════════════════════════════════
     Login page — two-column on desktop, stacked on mobile
     ════════════════════════════════════════════════════════════════════════ -->

<style<?= csp_nonce_attr() ?>>
/* ── Login layout ─────────────────────────────────────────────────────── */
.login-wrap {
    min-height: calc(100vh - 3rem);
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Hero panel (left) — neutral surface so the PNG logo (often red) stays readable */
.login-hero {
    background: var(--club-bg);
    border-radius: 16px 0 0 16px;
    border-right: 1px solid rgba(var(--club-primary-rgb), 0.14);
    box-shadow: inset 4px 0 0 var(--club-primary);
    padding: 2.5rem 1.75rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    color: var(--club-text);
    min-height: 460px;
}
.login-hero .fo-brand img {
    filter: drop-shadow(0 2px 10px rgba(0,0,0,.1));
}
/* Wordmark uses --club-on-primary-muted (for coloured navbars); override on this light panel */
.login-hero .fo-wordmark-sub {
    color: var(--club-muted) !important;
}

/* Form panel (right) */
.login-form-panel {
    background: #fff;
    border-radius: 0 16px 16px 0;
    padding: 3rem 2.5rem;
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-height: 420px;
}

/* Card wrapper shadow */
.login-card {
    box-shadow: 0 8px 40px rgba(0,0,0,.14);
    border-radius: 16px;
    overflow: hidden;
    width: 100%;
    max-width: 820px;
}

/* ── Powered-by strip ─────────────────────────────────────────────────── */
.login-powered-by {
    margin-top: 2rem;
    padding-top: 1.25rem;
    border-top: 1px solid #e9ecef;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-wrap: wrap;
    gap: 0.5rem 0.65rem;
}
.login-powered-by-label {
    font-size: 0.7rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #adb5bd;
    white-space: nowrap;
}

/* Responsive: stack on mobile */
@media (max-width: 575.98px) {
    .login-hero       { border-radius: 16px 16px 0 0; min-height: auto; padding: 2rem 1.5rem; }
    .login-form-panel { border-radius: 0 0 16px 16px; padding: 2rem 1.5rem; }
    .login-hero .fo-brand img { height: 136px !important; }
}
</style>

<div class="login-wrap">
    <div class="login-card">
        <div class="row g-0">

            <!-- ── Hero / branding ───────── -->
            <div class="col-sm-5 login-hero">
                <?php flightops_logo(168, true); ?>
            </div>

            <!-- ── Form panel ──────────────────────────────────────────── -->
            <div class="col-sm-7 login-form-panel">

                <h1 class="h4 fw-bold mb-1" style="color:#1e2a36;">Welcome back</h1>
                <p class="text-muted small mb-4">Sign in to manage your club.</p>

                <?php if ($error): ?>
                <div class="alert alert-danger py-2 small" role="alert">
                    <?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>

                <form method="post"
                      action="login.php<?= $redirect !== 'index.php'
                          ? '?redirect=' . htmlspecialchars(urlencode($redirect))
                          : '' ?>">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label for="email" class="form-label fw-semibold small">Email</label>
                        <input type="email"
                               class="form-control"
                               id="email" name="email"
                               required
                               autocomplete="email"
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>

                    <div class="mb-4">
                        <label for="password" class="form-label fw-semibold small">Password</label>
                        <input type="password"
                               class="form-control"
                               id="password" name="password"
                               required
                               autocomplete="current-password">
                    </div>

                    <button type="submit" class="btn btn-primary w-100 fw-semibold">
                        Sign In
                    </button>
                    <p class="mt-3 mb-0 small text-muted text-center">
                        <a href="forgot_password.php">Forgot password?</a>
                    </p>
                </form>

                <!-- ── Powered by RC Flight Operations ──────────────── -->
                <div class="login-powered-by">
                    <span class="login-powered-by-label">Powered by</span>
                    <?php flightops_logo(42, false); ?>
                </div>

            </div><!-- /.col -->
        </div><!-- /.row -->
    </div><!-- /.login-card -->
</div><!-- /.login-wrap -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>