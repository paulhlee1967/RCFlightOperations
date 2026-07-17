<?php
/**
 * membership.php — Request a magic link to the member self-service profile.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/member_portal.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (member_portal_current_member_id() > 0) {
    header('Location: membership_profile.php');
    exit;
}

$sent = false;
$error = '';
$genericMessage = 'If we find a matching membership for that email, we will send a one-time access link. Check your inbox (and spam folder). The link expires in one hour.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    $email = normalize_email((string) ($_POST['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        global $config;
        $clientIp = rate_limit_get_client_ip(is_array($config ?? null) ? $config : null);
        if (!member_portal_rate_limit_ok($pdo, $clientIp)) {
            // Still show success to avoid enumeration / probing.
            $sent = true;
        } else {
            $result = member_portal_send_magic_link_email($pdo, $email, $clientIp, is_array($config ?? null) ? $config : null);
            if ($result['error'] !== null && $result['matched']) {
                $error = $result['error'];
            } else {
                $sent = true;
            }
        }
    }
}

$noNav = true;
$pageTitle = 'Membership profile';
$clubName = member_portal_club_name($pdo);
$prefillEmail = normalize_email((string) ($_POST['email'] ?? $_GET['email'] ?? ''));

require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center mt-4 mt-md-5 mb-5">
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h1 class="h4 mb-2">Update your membership</h1>
                <p class="text-muted small mb-4">
                    Enter the email on your <?= h($clubName) ?> membership record.
                    We will email you a one-time link to view and update your profile.
                </p>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?= h($error) ?></div>
                <?php elseif ($sent): ?>
                    <div class="alert alert-success"><?= h($genericMessage) ?></div>
                    <p class="small text-muted mb-0">
                        Already have an open link? <a href="membership_profile.php">Continue to your profile</a>
                        (only works after you have opened a valid link).
                    </p>
                <?php else: ?>
                    <form method="post" action="membership.php" novalidate>
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label class="form-label" for="email">Email on file</label>
                            <input type="email" class="form-control" name="email" id="email"
                                   value="<?= h($prefillEmail) ?>" required autocomplete="email" autofocus>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Email me an access link</button>
                    </form>
                <?php endif; ?>

                <hr class="my-4">
                <p class="small text-muted mb-0">
                    New or renewing members: use the
                    <a href="apply.php">membership application</a> form instead.
                    Club officers sign in at <a href="login.php">staff login</a>.
                </p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
