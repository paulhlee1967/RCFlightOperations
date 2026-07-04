<?php
/**
 * unsubscribe.php
 *
 * Opt-out from AMA/FAA expiry reminders only. Updates transactional email status in Sender.net.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/app_log.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/sender_net.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$email = sender_net_normalize_email((string) ($_REQUEST['email'] ?? ''));
$token = trim((string) ($_REQUEST['token'] ?? ''));

$senderCfg = sender_net_load_config($pdo);
$secret    = sender_net_unsubscribe_signing_secret($pdo, $senderCfg);
$tokenOk   = sender_net_unsubscribe_verify_token($email, $token, $secret);

$clubName = 'RC Flight Operations';
try {
    $row = $pdo->query('SELECT name FROM club WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if ($row && trim((string) ($row['name'] ?? '')) !== '') {
        $clubName = trim((string) $row['name']);
    }
} catch (Throwable $e) {
}

$done    = false;
$error   = '';
$message = '';

if (!$tokenOk || $email === '') {
    $error = 'This unsubscribe link is invalid or has expired. Contact the club treasurer if you need help.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    if (!sender_net_is_configured($senderCfg)) {
        $error = 'Email opt-out is not configured on the server. Please contact the club treasurer.';
    } else {
        $result = sender_net_unsubscribe_subscriber($email, $senderCfg);
        if ($result['ok']) {
            $done    = true;
            $message = 'You have been unsubscribed from AMA/FAA expiry reminders at ' . htmlspecialchars($email) . '.';
            flightops_log('INFO', 'unsubscribe: reminder opt-out', ['email' => $email], 'web');
        } else {
            $error = 'We could not process your request. Please try again or contact the club treasurer.';
            flightops_log('WARN', 'unsubscribe: Sender API failed', [
                'email' => $email,
                'error' => $result['error'],
            ], 'web');
        }
    }
}

$pageTitle = 'Unsubscribe';
$noNav     = true;
require_once __DIR__ . '/includes/header.php';
?>
<div class="row justify-content-center mt-5">
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-3">Unsubscribe from expiry reminders</h1>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger mb-0"><?= htmlspecialchars($error) ?></div>
                <?php elseif ($done): ?>
                    <div class="alert alert-success mb-0"><?= $message ?></div>
                    <p class="text-muted small mt-3 mb-0">
                        You will no longer receive AMA/FAA expiry reminders at this address.
                        Newsletters and general club notices are separate; use the unsubscribe link
                        in those emails if you want to stop those too. You remain a club member.
                    </p>
                <?php else: ?>
                    <p class="mb-3">
                        Stop receiving <strong>AMA/FAA expiry reminders</strong> at
                        <strong><?= htmlspecialchars($email) ?></strong> from <?= htmlspecialchars($clubName) ?>?
                    </p>
                    <p class="text-muted small">
                        This does not affect newsletters or general club notices. Those use a separate
                        mailing list; unsubscribe from them using the link in those emails.
                    </p>
                    <form method="post" action="unsubscribe.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                        <button type="submit" class="btn btn-danger">Unsubscribe from reminders</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
