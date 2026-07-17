<?php
/**
 * About — app information, version, and links to documentation.
 * Any logged-in user can view this page.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

$appVersion = defined('FLIGHT_OPS_VERSION') ? FLIGHT_OPS_VERSION : '2.0.0';

$clubName = 'RC Flight Operations';
try {
    $row = $pdo->query('SELECT name FROM club WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if ($row && ($row['name'] ?? '') !== '') {
        $clubName = $row['name'];
    }
} catch (Throwable $e) {
}

$userRole = $_SESSION['user_role'] ?? '';
$userName = $_SESSION['user_name'] ?? ($_SESSION['user_email'] ?? '');

$pageTitle   = 'About';
$breadcrumbs = [
    ['label' => 'Home', 'url' => 'index.php'],
    ['label' => 'About', 'url' => ''],
];
require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8 col-xl-7">

        <div class="text-center mb-4">
            <?php flightops_logo(56, false); ?>
            <h1 class="h2 mt-3 mb-1">RC Flight Operations</h1>
            <p class="text-muted mb-2"><?= h($clubName) ?></p>
            <span class="badge rounded-pill text-bg-primary px-3 py-2">Version <?= h($appVersion) ?></span>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h5 card-title">What this app does</h2>
                <p class="mb-2">
                    Open-source membership management for AMA-affiliated RC flying clubs — members,
                    renewals, AMA/FAA compliance, badge printing, reports, and more.
                </p>
                <ul class="mb-0 small text-muted">
                    <li>Member roster with contact info, photos, and addresses</li>
                    <li>Renewal and payment recording (dues collected outside the app)</li>
                    <li>AMA/FAA compliance tracking and verification</li>
                    <li>CR80 badge design and printing</li>
                    <li>Seven built-in reports with CSV and PDF export</li>
                    <li>CSV import/export and optional incident log</li>
                </ul>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h5 card-title">Your session</h2>
                <dl class="row mb-0 small">
                    <dt class="col-sm-4">Signed in as</dt>
                    <dd class="col-sm-8"><?= h($userName) ?></dd>
                    <dt class="col-sm-4">Role</dt>
                    <dd class="col-sm-8"><?= h($userRole !== '' ? ucfirst($userRole) : '—') ?></dd>
                </dl>
            </div>
        </div>

        <div class="card mb-3 border-warning-subtle">
            <div class="card-body">
                <h2 class="h5 card-title">About payments</h2>
                <p class="mb-0 small">
                    RC Flight Operations <strong>does not process payments</strong>. It records dues
                    you have already collected through your club's normal channels (cash, check, etc.).
                </p>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h5 card-title">License &amp; help</h2>
                <p class="small text-muted mb-3">
                    Released under the <a href="https://opensource.org/licenses/MIT" target="_blank" rel="noopener noreferrer">MIT License</a>.
                    Third-party libraries are listed in <code>THIRD_PARTY_LICENSES.md</code> in the project repository.
                </p>
                <div class="d-flex flex-wrap gap-2">
                    <a href="docs/index.html" class="btn btn-primary btn-sm">Help &amp; documentation</a>
                    <?php if (isAdmin()): ?>
                    <a href="installation.php" class="btn btn-outline-primary btn-sm">Installation &amp; health</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
