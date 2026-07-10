<?php
/**
 * apply_confirm.php — Public confirmation after membership application submit.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/member_applications.php';
require_once __DIR__ . '/includes/membership_application.php';
require_once __DIR__ . '/includes/csp_nonce.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$noNav = true;
$baseHref = '/';
$pageTitle = 'Application received';
$applicationId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$token = trim((string) ($_GET['token'] ?? ''));
$secret = membership_application_signing_secret($pdo);

if ($applicationId <= 0 || !membership_application_verify_confirmation_token($applicationId, $token, $secret)) {
    http_response_code(404);
    $noNav = true;
    $baseHref = '/';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="alert alert-warning">Confirmation link is invalid or expired.</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$app = application_fetch($pdo, $applicationId);
if ($app === null) {
    http_response_code(404);
    $baseHref = '/';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="alert alert-warning">Application not found.</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

membership_application_try_finalize_from_stripe($pdo, $applicationId);
$app = application_fetch($pdo, $applicationId);

require_once __DIR__ . '/includes/header.php';

$clubName = $theme['name'] ?? 'PVMAC';
$clubWebsite = 'https://www.pvmac.com';
$firstName = (string) ($app['first_name'] ?? '');
$status = (string) ($app['status'] ?? '');
$paymentStatus = (string) ($app['payment_status'] ?? '');
$payment = application_payment_breakdown($app, $pdo);
$paymentWaived = $payment['coupon_applied'] || $paymentStatus === 'waived';
$paymentPending = $status === 'pending_payment' || $paymentStatus === 'pending';
$address = trim(implode("\n", array_filter([
    $app['address_street'] ?? '',
    $app['address_street2'] ?? '',
    trim(($app['address_city'] ?? '') . ', ' . ($app['address_state'] ?? '') . ' ' . ($app['address_postal_code'] ?? '')),
])));
$hasClubLogo = !empty($theme['logo_path'])
    && is_readable(__DIR__ . '/' . $theme['logo_path']);
?>

<style<?= csp_nonce_attr() ?>>
.apply-confirm-shell {
    max-width: 720px;
    margin: 0 auto 3rem;
}
.apply-confirm-card {
    border: 0;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 18px 48px rgba(0, 0, 0, 0.08);
}
.apply-confirm-hero {
    background: linear-gradient(
        145deg,
        var(--club-primary) 0%,
        color-mix(in srgb, var(--club-primary) 70%, #000) 100%
    );
    color: var(--club-on-primary);
    padding: 2.25rem 2rem 2rem;
    text-align: center;
}
.apply-confirm-hero img {
    max-height: 80px;
    width: auto;
    margin-bottom: 1rem;
}
.apply-confirm-icon {
    width: 4rem;
    height: 4rem;
    margin: 0 auto 1rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    font-weight: 700;
    background: rgba(255, 255, 255, 0.16);
    border: 2px solid rgba(255, 255, 255, 0.42);
}
.apply-confirm-body {
    padding: 2rem;
    background: #fff;
    color: var(--club-text);
}
.apply-confirm-lead {
    color: var(--club-text);
    opacity: 0.92;
}
.apply-confirm-panel {
    background: color-mix(in srgb, var(--club-primary) 7%, #fff);
    border: 1px solid color-mix(in srgb, var(--club-primary) 18%, transparent);
    border-radius: 14px;
    padding: 1.15rem 1.25rem;
    color: var(--club-text);
}
.apply-confirm-panel dt {
    color: color-mix(in srgb, var(--club-text) 70%, var(--club-muted));
    font-size: 0.78rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    margin-bottom: 0.1rem;
}
.apply-confirm-panel dd {
    margin: 0 0 0.9rem;
    font-weight: 600;
}
.apply-confirm-panel dd:last-child {
    margin-bottom: 0;
}
.apply-confirm-fees .row + .row {
    margin-top: 0.35rem;
}
.apply-confirm-fees .fee-label {
    color: var(--club-text);
    opacity: 0.88;
}
.apply-confirm-next {
    border-left: 4px solid var(--club-primary);
    padding-left: 1rem;
    color: var(--club-text);
}
.apply-confirm-fees .fee-total {
    border-top: 1px solid color-mix(in srgb, var(--club-primary) 20%, transparent);
    margin-top: 0.65rem;
    padding-top: 0.65rem;
    font-size: 1.05rem;
}
.apply-confirm-next ul {
    color: color-mix(in srgb, var(--club-text) 82%, var(--club-muted));
}
.apply-confirm-footnote {
    color: color-mix(in srgb, var(--club-text) 72%, var(--club-muted));
}
</style>

<div class="apply-confirm-shell">
    <div class="card apply-confirm-card">
        <div class="apply-confirm-hero">
            <?php if ($hasClubLogo): ?>
            <img src="<?= htmlspecialchars($theme['logo_path']) ?>?t=<?= filemtime(__DIR__ . '/' . $theme['logo_path']) ?>"
                 alt="<?= htmlspecialchars($clubName) ?>">
            <?php endif; ?>
            <div class="apply-confirm-icon" aria-hidden="true">✓</div>
            <h1 class="h3 fw-bold mb-2">Application received</h1>
            <p class="mb-0 fs-5">Thanks, <?= htmlspecialchars($firstName) ?> — we&apos;re on it.</p>
        </div>

        <div class="apply-confirm-body">
            <?php if ($paymentPending && !$paymentWaived): ?>
            <div class="alert alert-warning py-2 small mb-4" role="status">
                Your payment is still processing. Refresh this page in a moment if the amount below does not update.
            </div>
            <?php endif; ?>

            <p class="apply-confirm-lead mb-4">
                Your <?= htmlspecialchars($clubName) ?> membership application has been submitted.
                Staff will review your documents and follow up if anything else is needed.
            </p>

            <div class="row g-3 mb-4">
                <div class="col-md-5">
                    <dl class="apply-confirm-panel mb-0">
                        <dt>Confirmation #</dt>
                        <dd><?= (int) $applicationId ?></dd>
                        <?php if ($address !== ''): ?>
                        <dt>Badge mailing address</dt>
                        <dd class="fw-normal lh-sm"><?= nl2br(htmlspecialchars($address)) ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
                <div class="col-md-7">
                    <div class="apply-confirm-panel h-100">
                        <div class="fw-semibold mb-2">Payment summary</div>
                        <div class="apply-confirm-fees small">
                            <?php if ($payment['membership_dues'] !== null): ?>
                            <div class="row">
                                <div class="col-7 fee-label">Membership dues</div>
                                <div class="col-5 text-end"><?= htmlspecialchars(formatMoney($payment['membership_dues'])) ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if ($payment['initiation'] !== null): ?>
                            <div class="row">
                                <div class="col-7 fee-label">Initiation</div>
                                <div class="col-5 text-end"><?= htmlspecialchars(formatMoney($payment['initiation'])) ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if ($payment['processing'] !== null): ?>
                            <div class="row">
                                <div class="col-7 fee-label">Processing fee</div>
                                <div class="col-5 text-end"><?= htmlspecialchars(formatMoney($payment['processing'])) ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if ($payment['subtotal'] !== null): ?>
                            <div class="row">
                                <div class="col-7 fee-label">Subtotal</div>
                                <div class="col-5 text-end"><?= htmlspecialchars(formatMoney($payment['subtotal'])) ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($payment['complimentary_label'])): ?>
                            <div class="row text-success">
                                <div class="col-7">Complimentary — <?= htmlspecialchars($payment['complimentary_label']) ?></div>
                                <div class="col-5 text-end">Payment waived</div>
                            </div>
                            <?php elseif ($payment['coupon_applied'] && $payment['special_code'] !== null): ?>
                            <div class="row text-success">
                                <div class="col-7">Coupon <code><?= htmlspecialchars($payment['special_code']) ?></code></div>
                                <div class="col-5 text-end">Payment waived</div>
                            </div>
                            <?php endif; ?>
                            <div class="row fee-total fw-bold">
                                <div class="col-7">Total paid</div>
                                <div class="col-5 text-end">
                                    <?php if ($paymentPending && !$paymentWaived): ?>
                                    Processing…
                                    <?php elseif ($payment['total_paid'] !== null): ?>
                                    <?= htmlspecialchars(formatMoney($payment['total_paid'])) ?>
                                    <?php else: ?>
                                    —
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="apply-confirm-next mb-4">
                <p class="fw-semibold mb-2">What happens next?</p>
                <ul class="small mb-0 ps-3">
                    <li>Staff review your application and uploaded documents.</li>
                    <li>Your membership badge will be mailed to the address above.</li>
                    <li>Field access details are shared once your application is approved.</li>
                </ul>
            </div>

            <div class="d-grid d-sm-flex gap-2 justify-content-sm-center">
                <a href="<?= htmlspecialchars($clubWebsite) ?>" class="btn btn-danger btn-lg fw-semibold px-4">
                    Return to club website
                </a>
            </div>

            <p class="small apply-confirm-footnote text-center mt-4 mb-0">
                Bookmark this page for your records.
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
