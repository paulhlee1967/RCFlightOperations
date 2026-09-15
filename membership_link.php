<?php
/**
 * membership_link.php — Redeem a one-time magic link and open the member profile.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/member_portal.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$token = trim((string) ($_GET['token'] ?? ''));
$result = member_portal_redeem_token(
    $pdo,
    $token,
    rate_limit_get_client_ip(isset($config) && is_array($config) ? $config : null)
);

if ($result['ok']) {
    header('Location: membership_profile.php');
    exit;
}

$noNav = true;
$pageTitle = 'My Membership';
$error = $result['error'] ?? 'This access link is invalid or has expired.';

require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center mt-5 mb-5">
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-3">Access link problem</h1>
                <div class="alert alert-danger"><?= h($error) ?></div>
                <p class="mb-0">
                    <a href="membership.php" class="btn btn-primary">Request a new link</a>
                </p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
