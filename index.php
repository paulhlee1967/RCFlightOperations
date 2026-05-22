<?php
/**
 * index.php — Home / Dashboard
 *
 * Live stat cards + "Needs Attention" callout + navigation cards.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['user_role']) && isset($pdo)) {
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    if ($row) $_SESSION['user_role'] = $row['role'];
}

$currentYear = membershipStatusYear();

$currentMembers = countCurrentMembers($pdo, $currentYear);
$lastYear       = $currentYear - 1;
// Prior year: who had membership that year (payments/fulfillments), not who still show that renewal year on file.
$lastYearCount  = countMembersForMembershipYear($pdo, $lastYear);
$memberDelta    = $currentMembers - $lastYearCount;
$memberDeltaStr = ($memberDelta >= 0 ? '+' : '') . $memberDelta . ' vs ' . $lastYear;

// ── Stat: not yet renewed this year ─────────────────────────────────────────
$notRenewedWhere = notYetRenewedWhereSql('m', $currentYear);
$stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM members m WHERE {$notRenewedWhere}");
$stmt->execute(notYetRenewedWhereParams($currentYear));
$notRenewed = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

// ── Stat: unprinted badges ───────────────────────────────────────────────────
$currentWhere = currentMemberWhereSql('m', $currentYear);
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS cnt
    FROM members m
    WHERE {$currentWhere}
      AND (m.badge_printed_at IS NULL OR YEAR(m.badge_printed_at) < ?)
");
$stmt->execute(array_merge(currentMemberWhereParams($currentYear), [$currentYear]));
$unprintedBadges = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

// ── Stat: AMA/FAA compliance alerts ─────────────────────────────────────────
$in60  = date('Y-m-d', strtotime('+60 days'));
$today = date('Y-m-d');

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT m.id) AS cnt
    FROM members m
    WHERE {$currentWhere}
      AND (
        (m.ama_expiration IS NOT NULL AND m.ama_expiration != '' AND m.ama_expiration <= ?)
        OR (m.faa_expiration IS NOT NULL AND m.faa_expiration != '' AND m.faa_expiration <= ?)
      )
");
$stmt->execute(array_merge(currentMemberWhereParams($currentYear), [$in60, $in60]));
$complianceAlerts = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT m.id) AS cnt
    FROM members m
    WHERE {$currentWhere}
      AND (
        (m.ama_expiration IS NOT NULL AND m.ama_expiration != '' AND m.ama_expiration < ?)
        OR (m.faa_expiration IS NOT NULL AND m.faa_expiration != '' AND m.faa_expiration < ?)
      )
");
$stmt->execute(array_merge(currentMemberWhereParams($currentYear), [$today, $today]));
$expiredCount = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

// ── Stat: birthdays this week ────────────────────────────────────────────────
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd   = date('Y-m-d', strtotime('sunday this week'));
$startMd = date('m-d', strtotime($weekStart));
$endMd   = date('m-d', strtotime($weekEnd));

// Use a month-day range so weeks spanning months (e.g. Jan 29 – Feb 4)
// are handled correctly.
if ($startMd <= $endMd) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS cnt
        FROM members m
        WHERE {$currentWhere} AND m.birthday IS NOT NULL
          AND DATE_FORMAT(m.birthday, '%m-%d') BETWEEN ? AND ?
    ");
    $stmt->execute(array_merge(currentMemberWhereParams($currentYear), [$startMd, $endMd]));
} else {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS cnt
        FROM members m
        WHERE {$currentWhere} AND m.birthday IS NOT NULL
          AND (DATE_FORMAT(m.birthday, '%m-%d') >= ? OR DATE_FORMAT(m.birthday, '%m-%d') <= ?)
    ");
    $stmt->execute(array_merge(currentMemberWhereParams($currentYear), [$startMd, $endMd]));
}

$birthdaysThisWeek = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

// ── Stat: members with no email ──────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS cnt
    FROM members m
    WHERE {$currentWhere}
      AND (m.email IS NULL OR TRIM(m.email) = '')
");
$stmt->execute(currentMemberWhereParams($currentYear));
$noEmail = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

// ── Stat: current year members missing AMA number ────────────────────────────
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS cnt
    FROM members m
    WHERE {$currentWhere}
      AND (m.ama_number IS NULL OR TRIM(m.ama_number) = '')
      AND (m.ama_life_member IS NULL OR m.ama_life_member = 0)
");
$stmt->execute(currentMemberWhereParams($currentYear));
$missingAma = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

// ── Nav card counts ──────────────────────────────────────────────────────────
$totalMembersAll = 0;
if (canEditMembers() || canProcessMemberships()) {
    $totalMembersAll = $currentMembers;
}

$pageTitle = 'Home';
require_once __DIR__ . '/includes/header.php';
?>

<!-- ── Dashboard header ──────────────────────────────────────────────────────── -->
<div class="d-flex align-items-center justify-content-between mb-1">
    <h1 class="h2 mb-0">Dashboard</h1>
    <span class="text-muted small"><?= htmlspecialchars(date('l, F j, Y')) ?></span>
</div>
<p class="text-muted small mb-4">Welcome back. Here's what's happening with the club.</p>

<?php if ((int) date('n') >= 10): ?>
<div class="alert alert-info alert-dismissible fade show small mb-4" role="alert">
    <strong>Renewal season:</strong> Members who have already paid for next year may show <?= (int) date('Y') + 1 ?> as their renewal year.
    The <strong>Current members</strong> count above is for the <strong>calendar year <?= (int) date('Y') ?></strong> &mdash; that is expected and does not mean their payment was lost.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<!-- ── Stat cards ────────────────────────────────────────────────────────────── -->
<?php if (canViewReports() || canEditMembers() || canProcessMemberships()): ?>
<div class="row g-3 mb-4">

    <!-- Current members -->
    <div class="col-6 col-sm-4 col-xl">
        <a href="members.php?status=current" class="card stat-card text-decoration-none h-100">
            <div class="card-body p-3">
                <div class="stat-icon text-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M7 14s-1 0-1-1 1-4 5-4 5 3 5 4-1 1-1 1zm4-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6m-5.784 6A2.24 2.24 0 0 1 5 13c0-1.355.68-2.75 1.936-3.72A6.3 6.3 0 0 0 5 9c-4 0-5 3-5 4s1 1 1 1zM4.5 8a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5"/>
                    </svg>
                </div>
                <div class="stat-value text-primary"><?= $currentMembers ?></div>
                <div class="stat-label">Current members</div>
                <div class="stat-sub <?= $memberDelta >= 0 ? 'text-success' : 'text-danger' ?>"><?= h($memberDeltaStr) ?></div>
            </div>
        </a>
    </div>

    <!-- Not yet renewed -->
    <div class="col-6 col-sm-4 col-xl">
        <a href="reports.php?report=renewal_not_renewed&year=<?= $currentYear ?>" class="card stat-card text-decoration-none h-100">
            <div class="card-body p-3">
                <div class="stat-icon <?= $notRenewed > 0 ? 'text-warning' : 'text-success' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M8 3.5a.5.5 0 0 0-1 0V9a.5.5 0 0 0 .252.434l3.5 2a.5.5 0 0 0 .496-.868L8 8.71z"/>
                        <path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16m7-8A7 7 0 1 1 1 8a7 7 0 0 1 14 0"/>
                    </svg>
                </div>
                <div class="stat-value <?= $notRenewed > 0 ? 'text-warning' : 'text-success' ?>"><?= $notRenewed ?></div>
                <div class="stat-label">Not yet renewed</div>
                <div class="stat-sub text-muted">for <?= $currentYear ?></div>
            </div>
        </a>
    </div>

    <!-- Unprinted badges -->
    <div class="col-6 col-sm-4 col-xl">
        <?php if (canEditMembers() && featureEnabled('badge_designer')): ?>
        <a href="members.php?status=current" class="card stat-card text-decoration-none h-100">
        <?php else: ?><div class="card stat-card h-100"><?php endif; ?>
            <div class="card-body p-3">
                <div class="stat-icon <?= $unprintedBadges > 0 ? 'text-secondary' : 'text-success' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M2.5 8a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1"/>
                        <path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2zm6 14H5a1 1 0 0 1-1-1v-1h8v1a1 1 0 0 1-1 1M4 3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2H4zm1 5a2 2 0 0 0-2 2v1H2a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v-1a2 2 0 0 0-2-2z"/>
                    </svg>
                </div>
                <div class="stat-value <?= $unprintedBadges > 0 ? 'text-body' : 'text-success' ?>"><?= $unprintedBadges ?></div>
                <div class="stat-label">Badges unprinted</div>
                <div class="stat-sub text-muted"><?= $currentYear ?> renewals</div>
            </div>
        <?php if (canEditMembers() && featureEnabled('badge_designer')): ?></a><?php else: ?></div><?php endif; ?>
    </div>

    <!-- AMA/FAA compliance -->
    <div class="col-6 col-sm-4 col-xl">
        <a href="reports.php?report=ama_faa_expiring" class="card stat-card text-decoration-none h-100">
            <div class="card-body p-3">
                <div class="stat-icon <?= $complianceAlerts > 0 ? ($expiredCount > 0 ? 'text-danger' : 'text-warning') : 'text-success' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5m.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2"/>
                    </svg>
                </div>
                <div class="stat-value <?= $complianceAlerts > 0 ? ($expiredCount > 0 ? 'text-danger' : 'text-warning') : 'text-success' ?>"><?= $complianceAlerts ?></div>
                <div class="stat-label">AMA/FAA alerts</div>
                <div class="stat-sub text-muted"><?= $expiredCount > 0 ? $expiredCount . ' already expired' : 'within 60 days' ?></div>
            </div>
        </a>
    </div>

    <!-- Birthdays this week -->
    <div class="col-6 col-sm-4 col-xl">
        <a href="reports.php?report=birthdays_this_month" class="card stat-card text-decoration-none h-100">
            <div class="card-body p-3">
                <div class="stat-icon text-info">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M8 0a1 1 0 0 1 1 1v.083q.34.024.676.084C10.9 1.38 12 2.163 12 3.153v1c0 .828-.675 1.5-1.5 1.5h-5A1.5 1.5 0 0 1 4 4.153v-1c0-.99 1.1-1.773 2.324-1.986A7 7 0 0 1 7 1.083V1a1 1 0 0 1 1-1M2 6h12v1.5A2.5 2.5 0 0 1 11.5 10h-7A2.5 2.5 0 0 1 2 7.5zm0 3.5V14a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2v-4.5a3.5 3.5 0 0 1-3.5 3H5.5A3.5 3.5 0 0 1 2 9.5"/>
                    </svg>
                </div>
                <div class="stat-value text-info"><?= $birthdaysThisWeek ?></div>
                <div class="stat-label">Birthdays this week</div>
                <div class="stat-sub text-muted"><?= h(date('M j', strtotime($weekStart))) ?>–<?= h(date('M j', strtotime($weekEnd))) ?></div>
            </div>
        </a>
    </div>

</div><!-- /.row stat cards -->

<!-- ── Needs Attention callout ───────────────────────────────────────────────── -->
<?php
$attentionItems = [];

if ($notRenewed > 0) {
    $attentionItems[] = [
        'icon'  => 'bi-arrow-repeat',
        'color' => 'warning',
        'count' => $notRenewed,
        'label' => 'member' . ($notRenewed !== 1 ? 's' : '') . ' renewed last year but not yet this year',
        'link'  => 'reports.php?report=renewal_not_renewed&year=' . $currentYear,
        'cta'   => 'View list →',
    ];
}
if ($complianceAlerts > 0) {
    $attentionItems[] = [
        'icon'  => 'bi-shield-exclamation',
        'color' => $expiredCount > 0 ? 'danger' : 'warning',
        'count' => $complianceAlerts,
        'label' => 'member' . ($complianceAlerts !== 1 ? 's' : '') . ' with AMA/FAA expiring or expired',
        'link'  => 'reports.php?report=ama_faa_expiring',
        'cta'   => 'View compliance →',
    ];
}
if ($noEmail > 0) {
    $attentionItems[] = [
        'icon'  => 'bi-envelope-x',
        'color' => 'secondary',
        'count' => $noEmail,
        'label' => 'current member' . ($noEmail !== 1 ? 's' : '') . ' with no email address on file',
        'link'  => 'members.php?status=current',
        'cta'   => 'View members →',
    ];
}
if ($missingAma > 0) {
    $attentionItems[] = [
        'icon'  => 'bi-patch-question',
        'color' => 'secondary',
        'count' => $missingAma,
        'label' => $currentYear . ' member' . ($missingAma !== 1 ? 's' : '') . ' with no AMA number on file',
        'link'  => 'reports.php?report=ama_faa_expiring',
        'cta'   => 'View compliance →',
    ];
}
?>
<?php if (!empty($attentionItems) && (canEditMembers() || canProcessMemberships())): ?>
<div class="card mb-4" style="border-left: 4px solid #ffc107 !important;">
    <div class="card-header d-flex align-items-center gap-2 py-2"
         style="background:#fffbeb;border-bottom:1px solid #ffe8a1;">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="#6f7c3d" viewBox="0 0 16 16">
            <path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5m.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2"/>
        </svg>
        <span class="fw-semibold" style="font-size:.9rem;">Needs attention</span>
        <span class="badge bg-warning text-dark ms-1" style="font-size:.72rem;"><?= count($attentionItems) ?></span>
    </div>
    <ul class="list-group list-group-flush">
        <?php foreach ($attentionItems as $item): ?>
        <li class="list-group-item d-flex align-items-center justify-content-between py-2 px-3">
            <span style="font-size:.875rem;">
                <strong><?= $item['count'] ?></strong> <?= $item['label'] ?>
            </span>
            <a href="<?= h($item['link']) ?>"
               class="btn btn-sm btn-outline-secondary py-0 px-2 ms-3"
               style="font-size:.78rem;white-space:nowrap;">
                <?= $item['cta'] ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
<?php endif; // canViewReports check ?>

<!-- ── Navigation cards ──────────────────────────────────────────────────────── -->
<h2 class="h6 text-muted text-uppercase fw-semibold mb-3"
    style="letter-spacing:.06em;font-size:.75rem;">Quick access</h2>
<div class="row g-3">

    <?php if (canEditMembers() || canProcessMemberships()): ?>
    <div class="col-sm-6 col-lg-4">
        <a href="members.php" class="card nav-card text-decoration-none text-body h-100">
            <div class="card-body d-flex align-items-start gap-3">
                <div class="nav-card-icon bg-primary-subtle text-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M7 14s-1 0-1-1 1-4 5-4 5 3 5 4-1 1-1 1zm4-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6m-5.784 6A2.24 2.24 0 0 1 5 13c0-1.355.68-2.75 1.936-3.72A6.3 6.3 0 0 0 5 9c-4 0-5 3-5 4s1 1 1 1zM4.5 8a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5"/>
                    </svg>
                </div>
                <div>
                    <h2 class="h6 card-title mb-1">Members</h2>
                    <p class="card-text text-muted small mb-0"><?= $totalMembersAll ?> current member<?= $totalMembersAll !== 1 ? 's' : '' ?></p>
                </div>
            </div>
        </a>
    </div>
    <?php endif; ?>

    <?php if (canViewReports()): ?>
    <div class="col-sm-6 col-lg-4">
        <a href="reports.php" class="card nav-card text-decoration-none text-body h-100">
            <div class="card-body d-flex align-items-start gap-3">
                <div class="nav-card-icon bg-success-subtle text-success">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M0 0h1v15h15v1H0zm14.817 3.113a.5.5 0 0 1 .07.704l-4.5 5.5a.5.5 0 0 1-.74.037L7.06 6.767l-3.656 5.027a.5.5 0 0 1-.808-.588l4-5.5a.5.5 0 0 1 .758-.06l2.609 2.61 4.15-5.073a.5.5 0 0 1 .704-.07"/>
                    </svg>
                </div>
                <div>
                    <h2 class="h6 card-title mb-1">Reports</h2>
                    <p class="card-text text-muted small mb-0">Membership, compliance, revenue</p>
                </div>
            </div>
        </a>
    </div>
    <?php endif; ?>

    <?php if (canEditMembers() && featureEnabled('badge_designer')): ?>
    <div class="col-sm-6 col-lg-4">
        <a href="badge_design.php" class="card nav-card text-decoration-none text-body h-100">
            <div class="card-body d-flex align-items-start gap-3">
                <div class="nav-card-icon bg-info-subtle text-info">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M2 2a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2zm6 2.5a2 2 0 1 1 0 4 2 2 0 0 1 0-4M4 11c0-1 .895-1.5 2-1.5h4c1.105 0 2 .5 2 1.5v.5H4z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="h6 card-title mb-1">Badge design</h2>
                    <p class="card-text text-muted small mb-0">Design the CR80 member ID card layout</p>
                </div>
            </div>
        </a>
    </div>
    <?php endif; ?>

    <?php if (canManageUsers()): ?>
    <div class="col-sm-6 col-lg-4">
        <a href="users.php" class="card nav-card text-decoration-none text-body h-100">
            <div class="card-body d-flex align-items-start gap-3">
                <div class="nav-card-icon bg-secondary-subtle text-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M3 14s-1 0-1-1 1-4 6-4 6 3 6 4-1 1-1 1zm5-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6"/>
                    </svg>
                </div>
                <div>
                    <h2 class="h6 card-title mb-1">Users</h2>
                    <p class="card-text text-muted small mb-0">Manage system users &amp; roles</p>
                </div>
            </div>
        </a>
    </div>

    <div class="col-sm-6 col-lg-4">
        <a href="config_site.php" class="card nav-card text-decoration-none text-body h-100">
            <div class="card-body d-flex align-items-start gap-3">
                <div class="nav-card-icon bg-warning-subtle text-warning">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M9.405 1.05c-.413-1.4-2.397-1.4-2.81 0l-.1.34a1.464 1.464 0 0 1-2.105.872l-.31-.17c-1.283-.698-2.686.705-1.987 1.987l.169.311c.446.82.023 1.841-.872 2.105l-.34.1c-1.4.413-1.4 2.397 0 2.81l.34.1a1.464 1.464 0 0 1 .872 2.105l-.17.31c-.698 1.283.705 2.686 1.987 1.987l.311-.169a1.464 1.464 0 0 1 2.105.872l.1.34c.413 1.4 2.397 1.4 2.81 0l.1-.34a1.464 1.464 0 0 1 2.105-.872l.31.17c1.283.698 2.686-.705 1.987-1.987l-.169-.311a1.464 1.464 0 0 1 .872-2.105l.34-.1c1.4-.413 1.4-2.397 0-2.81l-.34-.1a1.464 1.464 0 0 1-.872-2.105l.17-.31c.698-1.283-.705-2.686-1.987-1.987l-.311.169a1.464 1.464 0 0 1-2.105-.872zM8 10.93a2.929 2.929 0 1 1 0-5.86 2.929 2.929 0 0 1 0 5.858z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="h6 card-title mb-1">Configuration</h2>
                    <p class="card-text text-muted small mb-0">Club name, logo, colours, dues</p>
                </div>
            </div>
        </a>
    </div>
    <?php endif; ?>

</div><!-- /.row nav cards -->

<style<?= csp_nonce_attr() ?>>
.stat-card { border: 1px solid #e9ecef; border-radius: 8px; transition: box-shadow .15s, transform .15s; color: inherit; }
.stat-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,.08); transform: translateY(-1px); text-decoration: none; color: inherit; }
.stat-icon { margin-bottom: .5rem; }
.stat-value { font-size: 2rem; font-weight: 700; line-height: 1.1; }
.stat-label { font-size: .8rem; font-weight: 600; color: #555; margin-top: .15rem; }
.stat-sub   { font-size: .72rem; margin-top: .1rem; }

.nav-card { border: 1px solid #e9ecef; border-radius: 8px; transition: box-shadow .15s, border-color .15s; }
.nav-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,.07); border-color: var(--club-primary); text-decoration: none; color: inherit; }
.nav-card-icon { width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }

.bg-primary-subtle   { background: rgba(13,110,253,.10) !important; }
.bg-success-subtle   { background: rgba(25,135,84,.10)  !important; }
.bg-info-subtle      { background: rgba(13,202,240,.10) !important; }
.bg-secondary-subtle { background: rgba(108,117,125,.10)!important; }
.bg-warning-subtle   { background: rgba(255,193,7,.10)  !important; }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>