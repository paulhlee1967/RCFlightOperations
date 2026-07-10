<?php
/**
 * Installation settings — app name, maintenance mode, SMTP, test email, admin broadcast, health.
 * Admin only. Host-level settings (SMTP, maintenance, health) for single-club deployments.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/flash.php';
require_once __DIR__ . '/includes/installation_config.php';

requireAdmin();

require_once __DIR__ . '/includes/mail.php';

$configRows = installation_load_system_config($pdo);
$error      = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    $action = $_POST['action'] ?? 'save_config';

    if ($action === 'send_test_email') {
        $toEmail = trim((string) ($_POST['to_email'] ?? ''));
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            flash('Please enter a valid address for the test email.', 'warning');
        } else {
            $mailCfg = installation_mail_config($pdo, $configRows);
            $subject = 'RC Flight Operations — SMTP deliverability test';
            $bodyText = "This is a test email to verify SMTP.\n\nTime: " . date('c');
            $bodyHtml = '<p>This is a test email to verify <strong>SMTP deliverability</strong>.</p>'
                . '<p>Time: <code>' . htmlspecialchars(date('c'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></p>';
            $ok = send_mail($toEmail, $subject, $bodyHtml, $bodyText, $mailCfg);
            if ($ok) {
                flash('Test email sent to ' . $toEmail . '. Check inbox/spam.', 'success');
            } else {
                $err = function_exists('get_last_mail_error') ? get_last_mail_error() : null;
                $msg = 'Test email failed. Check SMTP settings.';
                if (!empty($err)) {
                    $msg .= ' (' . $err . ')';
                }
                flash($msg, 'warning');
            }
        }
        header('Location: installation.php');
        exit;
    }

    if ($action === 'broadcast_admins') {
        $subject = trim((string) ($_POST['broadcast_subject'] ?? ''));
        $body    = trim((string) ($_POST['broadcast_body'] ?? ''));
        if ($subject === '' || $body === '') {
            flash('Subject and message are required.', 'warning');
        } else {
            $clubRow = $pdo->query('SELECT name FROM club WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
            $clubName = $clubRow['name'] ?? 'Club';
            $stmt = $pdo->query('SELECT name, email FROM users WHERE role = "admin" AND COALESCE(active,1) = 1 AND email != "" ORDER BY name');
            $recipients = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $mailCfg = installation_mail_config($pdo, $configRows);
            $sent = $fails = 0;
            send_mail_batch_begin($mailCfg);
            try {
                foreach ($recipients as $r) {
                    $msg = str_replace(['{{name}}', '{{club_name}}'], [$r['name'], $clubName], $body);
                    $bodyHtml = nl2br(htmlspecialchars($msg));
                    $ok  = send_mail($r['email'], $subject, $bodyHtml, $msg, $mailCfg);
                    $ok ? $sent++ : $fails++;
                }
            } finally {
                send_mail_batch_end();
            }
            try {
                $pdo->prepare('INSERT INTO operator_messages (subject, body, sent_to_count, target, sent_at) VALUES (?, ?, ?, ?, NOW())')
                    ->execute([$subject, $body, $sent, 'admins']);
            } catch (Throwable $e) {
            }
            $msg2 = "Message sent to $sent admin" . ($sent !== 1 ? 's' : '') . '.';
            if ($fails > 0) {
                $msg2 .= ' ' . ($fails !== 1 ? "$fails failed" : '1 failed') . '.';
            }
            flash($msg2, $fails > 0 ? 'warning' : 'success');
        }
        header('Location: installation.php');
        exit;
    }

    // save_config
    $appName  = trim($_POST['app_name'] ?? '');
    $smtpPort = (int) ($_POST['smtp_port'] ?? 587);
    if ($appName === '') {
        $error = 'App name is required.';
    } elseif ($smtpPort < 1 || $smtpPort > 65535) {
        $error = 'SMTP port must be between 1 and 65535.';
    } else {
        try {
            $pdo->beginTransaction();
            $keys = ['app_name','support_email','membership_email','renewal_prebook_start_month','renewal_prebook_start_day','reports_accurate_from_year','app_secret','stripe_publishable_key','stripe_secret_key','stripe_webhook_secret','stripe_test_mode','smtp_host','smtp_port','smtp_encryption','smtp_username','smtp_password','smtp_from_email','smtp_from_name','sender_api_token','sender_group_id','maintenance_mode'];
            foreach ($keys as $key) {
                $val = match ($key) {
                    'smtp_port'        => (string) $smtpPort,
                    'maintenance_mode' => empty($_POST['maintenance_mode']) ? '0' : '1',
                    'stripe_test_mode' => empty($_POST['stripe_test_mode']) ? '0' : '1',
                    'renewal_prebook_start_month' => (string) max(1, min(12, (int) ($_POST['renewal_prebook_start_month'] ?? 10))),
                    'renewal_prebook_start_day'   => (string) max(1, min(31, (int) ($_POST['renewal_prebook_start_day'] ?? 15))),
                    'reports_accurate_from_year'  => (string) max(2000, min(2100, (int) ($_POST['reports_accurate_from_year'] ?? 2027))),
                    default            => trim($_POST[$key] ?? ''),
                };
                installation_save_config_key($pdo, $key, $val);
            }
            $pdo->commit();
            $configRows = installation_load_system_config($pdo);
            flash('Configuration saved.', 'success');
            header('Location: installation.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Could not save: ' . $e->getMessage();
        }
    }
}

// ── System health (read-only) ─────────────────────────────────────────────
$dbVersion = 'Unknown';
$dbOk      = false;
try {
    $dbVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $dbOk      = true;
} catch (Throwable $e) {
}

$tableMissing = [];
$expectedTables = ['club','users','members','payments','dues_rules','badge_templates','incidents','audit_log','login_attempts','password_reset_tokens','password_reset_ip_events','member_fulfillments','system_config','operator_messages'];
foreach ($expectedTables as $tbl) {
    try {
        $pdo->query("SELECT 1 FROM `$tbl` LIMIT 1");
    } catch (Throwable $e) {
        $tableMissing[] = $tbl;
    }
}

$uploadsDir = __DIR__ . '/uploads';
$uploadsOk  = is_dir($uploadsDir) && is_writable($uploadsDir);

$sentLog = [];
try {
    $sentLog = $pdo->query('SELECT id, subject, sent_to_count, target, sent_at FROM operator_messages ORDER BY sent_at DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$pageTitle   = 'Installation';
$breadcrumbs = [
    ['label' => 'Administration', 'url' => 'users.php'],
    ['label' => 'Installation', 'url' => ''],
];
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
    <div>
        <h1 class="h2 mb-0">Installation</h1>
        <p class="text-muted mb-0 small">App-wide email and maintenance settings. Club branding stays under <a href="config_site.php">Configuration</a>.</p>
    </div>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<form method="post" action="installation.php" id="install-main-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_config">

    <div class="card mb-4">
        <div class="card-header fw-semibold">General</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="app_name">App name</label>
                    <input type="text" class="form-control" id="app_name" name="app_name"
                           value="<?= h($configRows['app_name'] ?? 'RC Flight Operations') ?>" required>
                    <div class="form-text">Shown in the navbar and emails.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="support_email">Support email</label>
                    <input type="email" class="form-control" id="support_email" name="support_email"
                           value="<?= h($configRows['support_email'] ?? '') ?>">
                    <div class="form-text">General club support contact.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="renewal_prebook_start_month">Renewal year default — pre-book starts</label>
                    <div class="d-flex gap-2">
                        <select class="form-select" id="renewal_prebook_start_month" name="renewal_prebook_start_month">
                            <?php
                            $preMo = isset($configRows['renewal_prebook_start_month'])
                                ? max(1, min(12, (int) $configRows['renewal_prebook_start_month']))
                                : 10;
                            $monthNames = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
                            for ($m = 1; $m <= 12; $m++):
                            ?>
                            <option value="<?= $m ?>"<?= $preMo === $m ? ' selected' : '' ?>><?= h($monthNames[$m]) ?></option>
                            <?php endfor; ?>
                        </select>
                        <select class="form-select w-auto" id="renewal_prebook_start_day" name="renewal_prebook_start_day" aria-label="Pre-book start day">
                            <?php
                            $preDay = isset($configRows['renewal_prebook_start_day'])
                                ? max(1, min(31, (int) $configRows['renewal_prebook_start_day']))
                                : 15;
                            for ($d = 1; $d <= 31; $d++):
                            ?>
                            <option value="<?= $d ?>"<?= $preDay === $d ? ' selected' : '' ?>><?= $d ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-text">
                        On/after this day, the app’s default “renewal year” is the <strong>next</strong> calendar year
                        (e.g. October 15 → next year). Earlier dates use the current year. Default: October 15.
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="membership_email">Membership email</label>
                    <input type="email" class="form-control" id="membership_email" name="membership_email"
                           value="<?= h($configRows['membership_email'] ?? '') ?>">
                    <div class="form-text">New application notifications are sent here. If blank, support email is used.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="reports_accurate_from_year">Reports — complete data starting year</label>
                    <input type="number" class="form-control" id="reports_accurate_from_year" name="reports_accurate_from_year"
                           min="2000" max="2100" step="1"
                           value="<?= h((string) ($configRows['reports_accurate_from_year'] ?? '2027')) ?>">
                    <div class="form-text">
                        The first membership year with complete, trustworthy records. Reports add a footnote
                        warning that figures for <strong>earlier</strong> years are reconstructed from payment
                        history and may undercount members or revenue. Default: 2027.
                    </div>
                </div>
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" id="maintenance_mode" name="maintenance_mode" value="1"
                               <?= !empty($configRows['maintenance_mode']) && $configRows['maintenance_mode'] === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="maintenance_mode">Maintenance mode (banner for logged-in users)</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header fw-semibold">Membership application (Stripe)</div>
        <div class="card-body">
            <p class="text-muted small">Public form at <code>/apply.php</code>. Create a Stripe webhook for <code>payment_intent.succeeded</code> pointing to <code>api_stripe_webhook.php</code>.</p>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="stripe_publishable_key">Publishable key</label>
                    <input type="text" class="form-control" id="stripe_publishable_key" name="stripe_publishable_key" autocomplete="off"
                           value="<?= h($configRows['stripe_publishable_key'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="stripe_secret_key">Secret key</label>
                    <input type="password" class="form-control" id="stripe_secret_key" name="stripe_secret_key" autocomplete="new-password"
                           value="<?= h($configRows['stripe_secret_key'] ?? '') ?>">
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="stripe_webhook_secret">Stripe webhook signing secret</label>
                    <input type="password" class="form-control" id="stripe_webhook_secret" name="stripe_webhook_secret" autocomplete="new-password"
                           value="<?= h($configRows['stripe_webhook_secret'] ?? '') ?>">
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="app_secret">Application signing secret</label>
                    <input type="password" class="form-control" id="app_secret" name="app_secret" autocomplete="new-password"
                           value="<?= h($configRows['app_secret'] ?? ($configRows['application_webhook_secret'] ?? '')) ?>">
                    <div class="form-text">Long random string for signed confirmation links and reminder unsubscribe URLs. Can also be set as <code>app_secret</code> in <code>config.php</code>.</div>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" id="stripe_test_mode" name="stripe_test_mode" value="1"
                               <?= !empty($configRows['stripe_test_mode']) && $configRows['stripe_test_mode'] === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="stripe_test_mode">Test mode keys</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header fw-semibold">Sender.net (reminder opt-out)</div>
        <div class="card-body">
            <p class="text-muted small">
                AMA/FAA expiry reminders check each recipient’s <strong>transactional (reminder)</strong> status in Sender.net
                before sending. Missing subscribers are added automatically (lowercase email) so case variants like
                <code>Email@domain.com</code> and <code>email@domain.com</code> do not create duplicates.
                Reminders are sent through Sender’s transactional API. Each message includes a signed
                <strong>reminder-only unsubscribe link</strong> on this site (newsletters are unaffected).
                Requires <code>canonical_host</code> in <code>config.php</code> for logo and unsubscribe URLs.
                Create an API token under Sender → Settings → API access tokens.
            </p>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="sender_api_token">API access token</label>
                    <input type="password" class="form-control" id="sender_api_token" name="sender_api_token" autocomplete="new-password"
                           value="<?= h($configRows['sender_api_token'] ?? '') ?>">
                    <div class="form-text">Required for opt-out checks and reminder delivery. Leave blank to use SMTP only (no Sender opt-out).</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="sender_group_id">Members group ID</label>
                    <input type="text" class="form-control" id="sender_group_id" name="sender_group_id"
                           placeholder="e.g. eZVD4w"
                           value="<?= h($configRows['sender_group_id'] ?? '') ?>">
                    <div class="form-text">Sender group for auto-added subscribers (Subscribers → your members list → group settings). Required for reminders.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header fw-semibold">Outbound email (SMTP)</div>
        <div class="card-body">
            <p class="text-muted small">Overrides the <code>email</code> block in <code>config.php</code>. Leave host blank to use PHP <code>mail()</code>.</p>
            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label">SMTP host</label>
                    <input type="text" class="form-control" name="smtp_host"
                           value="<?= h($configRows['smtp_host'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Port</label>
                    <input type="number" class="form-control" name="smtp_port"
                           value="<?= h($configRows['smtp_port'] ?? '587') ?>" min="1" max="65535">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Encryption</label>
                    <select class="form-select" name="smtp_encryption">
                        <?php foreach (['tls' => 'TLS (STARTTLS)', 'ssl' => 'SSL', '' => 'None'] as $val => $lbl): ?>
                        <option value="<?= h($val) ?>" <?= ($configRows['smtp_encryption'] ?? 'tls') === $val ? 'selected' : '' ?>><?= h($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Username</label>
                    <input type="text" class="form-control" name="smtp_username" autocomplete="off"
                           value="<?= h($configRows['smtp_username'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Password / API key</label>
                    <input type="password" class="form-control" name="smtp_password" autocomplete="new-password"
                           value="<?= h($configRows['smtp_password'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">From address</label>
                    <input type="email" class="form-control" name="smtp_from_email"
                           value="<?= h($configRows['smtp_from_email'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">From name</label>
                    <input type="text" class="form-control" name="smtp_from_name"
                           value="<?= h($configRows['smtp_from_name'] ?? '') ?>">
                </div>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary">Save configuration</button>
</form>

<div class="card mb-4 mt-4">
    <div class="card-header fw-semibold">Send test email</div>
    <div class="card-body">
        <form method="post" action="installation.php" class="row g-3 align-items-end"
              data-email-sending data-email-sending-title="Sending test email">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send_test_email">
            <div class="col-md-8">
                <label class="form-label" for="to_email">Send to</label>
                <input type="email" class="form-control" id="to_email" name="to_email" required
                       value="<?= h($_SESSION['user_email'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-outline-primary w-100">Send test</button>
            </div>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header fw-semibold">Message all admin users</div>
    <div class="card-body">
        <p class="text-muted small">Sends to every active user with role <strong>admin</strong>. Use <code>{{name}}</code> and <code>{{club_name}}</code>.</p>
        <form method="post" action="installation.php"
              data-email-sending data-email-sending-title="Broadcasting to admins"
              data-confirm-submit="Send this message to every active admin user?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="broadcast_admins">
            <div class="mb-3">
                <label class="form-label" for="broadcast_subject">Subject</label>
                <input type="text" class="form-control" id="broadcast_subject" name="broadcast_subject" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="broadcast_body">Message</label>
                <textarea class="form-control" id="broadcast_body" name="broadcast_body" rows="5" required></textarea>
            </div>
            <button type="submit" class="btn btn-outline-secondary">Send to admins</button>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header fw-semibold">System status</div>
    <div class="card-body">
        <dl class="row small mb-0">
            <dt class="col-sm-3">Database</dt>
            <dd class="col-sm-9"><?= $dbOk ? '<span class="text-success">Connected</span>' : '<span class="text-danger">Error</span>' ?> — <code><?= h($dbVersion) ?></code></dd>
            <dt class="col-sm-3">Uploads folder</dt>
            <dd class="col-sm-9"><?= $uploadsOk ? '<span class="text-success">Writable</span>' : '<span class="text-danger">Missing or not writable</span>' ?> <code>uploads/</code></dd>
            <dt class="col-sm-3">Schema tables</dt>
            <dd class="col-sm-9">
                <?php if (empty($tableMissing)): ?>
                <span class="text-success">All expected tables present</span>
                <?php else: ?>
                <span class="text-danger">Missing: <?= h(implode(', ', $tableMissing)) ?></span> — import <code>schema_full.sql</code> if this is a new install.
                <?php endif; ?>
            </dd>
            <dt class="col-sm-3">PHP</dt>
            <dd class="col-sm-9"><code><?= h(PHP_VERSION) ?></code></dd>
        </dl>
    </div>
</div>

<?php if (!empty($sentLog)): ?>
<div class="card mb-4">
    <div class="card-header fw-semibold">Recent broadcast log</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Subject</th><th>Recipients</th><th>When</th></tr></thead>
                <tbody>
                <?php foreach ($sentLog as $msg): ?>
                <tr>
                    <td><?= h($msg['subject']) ?></td>
                    <td><?= (int) $msg['sent_to_count'] ?></td>
                    <td class="text-muted"><?= h(date('M j, Y g:ia', strtotime($msg['sent_at']))) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script<?= csp_nonce_attr() ?>>
(function () {
    var monthSel = document.getElementById('renewal_prebook_start_month');
    var daySel   = document.getElementById('renewal_prebook_start_day');
    if (!monthSel || !daySel) { return; }
    // Days per month (February allows 29 for leap years).
    var daysInMonth = [31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

    function syncDays() {
        var max = daysInMonth[(parseInt(monthSel.value, 10) || 1) - 1];
        var want = parseInt(daySel.value, 10) || 1;
        daySel.innerHTML = '';
        for (var d = 1; d <= max; d++) {
            var opt = document.createElement('option');
            opt.value = String(d);
            opt.textContent = String(d);
            if (d === Math.min(want, max)) { opt.selected = true; }
            daySel.appendChild(opt);
        }
    }

    monthSel.addEventListener('change', syncDays);
    syncDays();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
