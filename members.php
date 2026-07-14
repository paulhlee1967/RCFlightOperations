<?php
/**
 * Member list — polished v1.0.
 *
 * Quick-view offcanvas loads JSON from member_detail.php (same app, paired endpoint).
 *
 * Changes from original:
 *  - SELECT now fetches photo_path, inactive, suspended, life_member,
 *    free_membership, gate_key_number, badge_printed_at for display.
 *  - Filter-chip counts added via a single summary query.
 *  - Renders avatar (photo thumbnail or CSS initials), type pill,
 *    status/flag icons, and color-coded renewal year.
 *  - Inline Delete button removed; destructive action lives in edit form.
 *  - "Print badge" shortcut added to action column.
 *  - Bootstrap offcanvas quick-view panel (read-only detail fetch).
 *  - Flash message support via $_SESSION['flash'].
 *  - All existing filter/sort/pagination/bulk-select logic preserved.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

requireLogin();
if (!canViewMembers()) {
    header('Location: index.php');
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$currentYear = membershipStatusYear();
$membershipTypeLabels = enabledMembershipTypeLabels($pdo);
$renewedMemberIds = renewedMemberIdsForYear($pdo, $currentYear);

require_once __DIR__ . '/includes/members_list_query.php';
require_once __DIR__ . '/includes/members_list_helpers.php';

$filters = members_list_parse_request($_GET);
$searchQ              = $filters['searchQ'];
$perPage              = $filters['perPage'];
$page                 = $filters['page'];
$memberTypeFilter     = $filters['memberTypeFilter'];
$memberTypeSlotFilter = $filters['memberTypeSlotFilter'];
$statusFilter         = $filters['statusFilter'];
$flagFilters          = $filters['flagFilters'];
$badgeFilter          = $filters['badgeFilter'];
$fulfillmentFilter    = $filters['fulfillmentFilter'];
$sort                 = $filters['sort'];

$list = members_list_fetch($pdo, $filters, $currentYear);
$members              = $list['members'];
$totalCount           = $list['totalCount'];
$chipCounts           = $list['chipCounts'];
$flagChipCounts       = $list['flagChipCounts'];
$typeCounts           = $list['typeCounts'];
$typeCountsByStatus   = $list['typeCountsByStatus'];
$totalPages           = $list['totalPages'];
$from                 = $list['from'];
$to                   = $list['to'];
$queryParams          = $list['queryParams'];

$flagChipLabels = [
    'free'      => 'Free',
    'life'      => 'Life',
    'suspended' => 'Suspended',
];
$hasMembersInDatabase = ($chipCounts['all'] ?? 0) > 0;
$hasActiveListFilters = $searchQ !== ''
    || $statusFilter !== 'active'
    || $flagFilters !== []
    || $memberTypeFilter !== ''
    || $badgeFilter !== ''
    || $fulfillmentFilter !== '';

$pageTitle = 'Members';
$breadcrumbs = [['label' => 'Members', 'url' => '']];
require_once __DIR__ . '/includes/page_header.php';

$membersSubtitle = $totalCount . ' member' . ($totalCount !== 1 ? 's' : '');
if ($totalCount > 0 && $perPage > 0 && $totalPages > 1) {
    $membersSubtitle .= ' — showing ' . $from . '–' . $to;
}

ob_start();
?>
        <?php if (canEditMembers()): ?>
        <a href="member_wizard.php" class="btn btn-primary btn-sm">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="me-1" viewBox="0 0 16 16">
                <path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4z"/>
            </svg>New member
        </a>
        <a href="import.php" class="btn btn-outline-primary btn-sm">Import</a>
        <a href="applications.php" class="btn btn-outline-primary btn-sm">Applications</a>
        <?php endif; ?>
        <?php if (canEditMembers() || canProcessMemberships()): ?>
        <div class="dropdown">
            <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                Export CSV
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li>
                    <form method="post" action="export.php" class="m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="format" value="full">
                        <input type="hidden" name="filter" value="all">
                        <button type="submit" class="dropdown-item border-0 bg-transparent w-100 text-start rounded-0">All members</button>
                    </form>
                </li>
                <li>
                    <form method="post" action="export.php" class="m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="format" value="full">
                        <input type="hidden" name="filter" value="current">
                        <button type="submit" class="dropdown-item border-0 bg-transparent w-100 text-start rounded-0">Current year (<?= $currentYear ?>)</button>
                    </form>
                </li>
                <li>
                    <form method="post" action="export.php" class="m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="format" value="short">
                        <input type="hidden" name="filter" value="current">
                        <button type="submit" class="dropdown-item border-0 bg-transparent w-100 text-start rounded-0">Short list — <?= $currentYear ?></button>
                    </form>
                </li>
                <li>
                    <form method="post" action="export.php" class="m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="format" value="email">
                        <input type="hidden" name="filter" value="all">
                        <button type="submit" class="dropdown-item border-0 bg-transparent w-100 text-start rounded-0">Email list only</button>
                    </form>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="post" action="export.php" class="m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="format" value="short">
                        <input type="hidden" name="filter" value="not_renewed">
                        <input type="hidden" name="year" value="<?= (int) $currentYear ?>">
                        <button type="submit" class="dropdown-item border-0 bg-transparent w-100 text-start rounded-0">Not yet renewed — <?= $currentYear ?></button>
                    </form>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="export_options.php">More export options&hellip;</a></li>
            </ul>
        </div>
        <?php endif; ?>
<?php
$membersHeaderActions = ob_get_clean();

require_once __DIR__ . '/includes/header.php';
render_page_header([
    'title'    => 'Members',
    'subtitle' => $membersSubtitle,
    'actions'  => $membersHeaderActions,
]);
?>

<!-- ── Filter chips + search row ────────────────────────────────────────────── -->
<div class="members-filter-bar mb-3">

    <!-- Membership + flag chips -->
    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <span class="text-muted small me-1">Membership:</span>
        <?php
        $statusChipLabels = [
            'all'      => 'All',
            'active'   => 'Active',
            'inactive' => 'Inactive',
        ];
        foreach ($statusChipLabels as $val => $label):
            $isActive = $statusFilter === $val;
            $statusChipParams = array_merge($queryParams, ['status' => $val]);
            unset($statusChipParams['page']);
            $chipLabel = $label . ' (' . (int) ($chipCounts[$val] ?? 0) . ')';
        ?>
        <a href="<?= htmlspecialchars(membersUrl($statusChipParams)) ?>"
           class="btn btn-sm <?= $isActive ? 'btn-primary' : 'btn-outline-secondary' ?> filter-chip">
            <?= htmlspecialchars($chipLabel) ?>
        </a>
        <?php endforeach; ?>
    </div>

    <form method="get" action="members.php" id="members-flag-form" class="js-auto-submit-form d-flex flex-wrap gap-3 align-items-center mb-2">
        <span class="text-muted small me-1">Flags:</span>
        <?php
        $flagFormParams = $queryParams;
        unset($flagFormParams['page'], $flagFormParams['flag']);
        foreach ($flagFormParams as $paramKey => $paramVal):
            if ($paramVal === null || $paramVal === '') {
                continue;
            }
        ?>
        <input type="hidden" name="<?= htmlspecialchars((string) $paramKey) ?>" value="<?= htmlspecialchars((string) $paramVal) ?>">
        <?php endforeach; ?>
        <?php foreach ($flagChipLabels as $val => $label):
            $isChecked = in_array($val, $flagFilters, true);
            $chipLabel = $label . ' (' . (int) ($flagChipCounts[$val] ?? 0) . ')';
        ?>
        <div class="form-check form-check-inline mb-0">
            <input class="form-check-input" type="checkbox"
                   name="flag[]" value="<?= htmlspecialchars($val) ?>"
                   id="flag-<?= htmlspecialchars($val) ?>"
                   <?= $isChecked ? 'checked' : '' ?>>
            <label class="form-check-label small" for="flag-<?= htmlspecialchars($val) ?>">
                <?= htmlspecialchars($chipLabel) ?>
            </label>
        </div>
        <?php endforeach; ?>
    </form>

    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <span class="text-muted small me-1">Type:</span>
        <?php
        $statusKeysForTypes = array_merge(membersListStatusFilterKeys(), ['current']);
        $allTypeCounts = [];
        if ($flagFilters === []) {
            foreach ($statusKeysForTypes as $sk) {
                $allTypeCounts[$sk] = array_sum($typeCountsByStatus[$sk] ?? []);
            }
        }
        $typeChips = [
            '' => [
                'label'  => 'All',
                'counts' => $allTypeCounts,
            ],
        ];
        foreach ($membershipTypeLabels as $slot => $label) {
            if ($flagFilters === []) {
                $slotCounts = [];
                foreach ($statusKeysForTypes as $sk) {
                    $slotCounts[$sk] = $typeCountsByStatus[$sk][(int) $slot] ?? 0;
                }
            } else {
                $slotCounts = [];
            }
            $typeChips[(string) $slot] = [
                'label'  => $label,
                'counts' => $slotCounts,
            ];
        }
        foreach ($typeChips as $val => $chip):
            $isActive = $memberTypeFilter === $val;
            $typeChipParams = $queryParams;
            unset($typeChipParams['page']);
            if ($val !== '') {
                $typeChipParams['member_type'] = $val;
            } else {
                unset($typeChipParams['member_type']);
            }
            $chipCount = $flagFilters === []
                ? ($chip['counts'][$statusFilter] ?? 0)
                : ($val === '' ? array_sum($typeCounts) : ($typeCounts[(int) $val] ?? 0));
            $countsJson = $flagFilters === []
                ? json_encode($chip['counts'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT)
                : '';
        ?>
        <a href="<?= htmlspecialchars(membersUrl($typeChipParams)) ?>"
           class="btn btn-sm <?= $isActive ? 'btn-secondary' : 'btn-outline-secondary' ?> filter-chip<?= $countsJson !== '' ? ' js-type-chip' : '' ?>"
           data-type-label="<?= htmlspecialchars($chip['label'], ENT_QUOTES, 'UTF-8') ?>"
           <?php if ($countsJson !== ''): ?>data-type-counts="<?= htmlspecialchars($countsJson, ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>>
            <?= htmlspecialchars($chip['label']) ?> (<?= (int) $chipCount ?>)
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Search + sort row -->
    <form method="get" action="members.php" class="row g-2 align-items-end">
        <input type="hidden" name="per" value="<?= (int) $perPage ?>">
        <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
        <?php foreach ($flagFilters as $flag): ?>
        <input type="hidden" name="flag[]" value="<?= htmlspecialchars($flag) ?>">
        <?php endforeach; ?>
        <?php if ($memberTypeFilter !== ''): ?>
        <input type="hidden" name="member_type" value="<?= htmlspecialchars($memberTypeFilter) ?>">
        <?php endif; ?>
        <?php if ($badgeFilter !== ''): ?>
        <input type="hidden" name="badge" value="<?= htmlspecialchars($badgeFilter) ?>">
        <?php endif; ?>

        <div class="col flex-grow-1" style="min-width:180px;">
            <label for="member-search" class="visually-hidden">Search members</label>
            <input type="search" class="form-control form-control-sm" id="member-search" name="q"
                   value="<?= htmlspecialchars($searchQ) ?>"
                   placeholder="Search name, email, AMA, phone…">
        </div>
        <div class="col-auto">
            <label for="member-sort" class="visually-hidden">Sort</label>
            <select name="sort" id="member-sort" class="form-select form-select-sm js-submit-on-change">
                <option value="name"<?= $sort === 'name' ? ' selected' : '' ?>>Name A–Z</option>
                <option value="name_desc"<?= $sort === 'name_desc' ? ' selected' : '' ?>>Name Z–A</option>
                <option value="year_desc"<?= $sort === 'year_desc' ? ' selected' : '' ?>>Renewal ↓</option>
                <option value="year"<?= $sort === 'year' ? ' selected' : '' ?>>Renewal ↑</option>
                <option value="type"<?= $sort === 'type' ? ' selected' : '' ?>>Type A–Z</option>
                <option value="type_desc"<?= $sort === 'type_desc' ? ' selected' : '' ?>>Type Z–A</option>
            </select>
        </div>
        <div class="col-auto d-flex gap-1">
            <button type="submit" class="btn btn-sm btn-outline-primary">Search</button>
            <?php if ($searchQ !== '' || $memberTypeFilter !== '' || $statusFilter !== 'active' || $flagFilters !== [] || $badgeFilter !== '' || $sort !== 'name'): ?>
            <a href="members.php" class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($searchQ !== ''): ?>
    <p class="text-muted small mt-1 mb-0">
        Showing results for &ldquo;<?= htmlspecialchars($searchQ) ?>&rdquo;
    </p>
    <?php endif; ?>
    <?php if ($statusFilter === 'current'): ?>
    <p class="text-muted small mt-1 mb-0">
        Showing current (paid-up) members for <?= (int) $currentYear ?>.
    </p>
    <?php endif; ?>
    <?php if ($flagFilters !== []): ?>
    <p class="text-muted small mt-1 mb-0">
        Showing members matching
        <?php
        $membershipPhrase = match ($statusFilter) {
            'active'   => 'active membership',
            'inactive' => 'inactive membership',
            'current'  => 'current membership',
            default    => 'all memberships',
        };
        echo $membershipPhrase;
        $flagLabels = array_map(
            static fn (string $f) => strtolower($flagChipLabels[$f] ?? $f),
            $flagFilters
        );
        echo ' with ' . implode(' + ', $flagLabels);
        ?>.
    </p>
    <?php endif; ?>
    <?php if ($badgeFilter === 'unprinted'): ?>
    <p class="text-muted small mt-1 mb-0">
        Showing current members whose badge has not been printed for <?= (int) $currentYear ?>.
    </p>
    <?php endif; ?>
    <?php if ($fulfillmentFilter === 'pending'): ?>
    <p class="text-muted small mt-1 mb-0">
        Showing members with a recorded signup/renewal for <?= (int) $currentYear ?> whose card or mailer is not yet complete.
    </p>
    <?php endif; ?>
</div>

<!-- ── Member table ──────────────────────────────────────────────────────────── -->
<?php if (canEditMembers()): ?>
<form method="post" action="member_delete.php" id="bulk-form">
    <?= csrf_field() ?>
<?php endif; ?>

<div class="card shadow-sm">
    <?php if ($totalCount === 0): ?>
    <!-- Empty state -->
    <div class="card-body text-center py-5">
        <div class="empty-state-icon mb-3">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" class="text-muted" viewBox="0 0 16 16">
                <path d="M7 14s-1 0-1-1 1-4 5-4 5 3 5 4-1 1-1 1zm4-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6m-5.784 6A2.24 2.24 0 0 1 5 13c0-1.355.68-2.75 1.936-3.72A6.3 6.3 0 0 0 5 9c-4 0-5 3-5 4s1 1 1 1zM4.5 8a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5"/>
            </svg>
        </div>
        <?php if ($searchQ !== ''): ?>
        <p class="fw-semibold mb-1">No members match &ldquo;<?= htmlspecialchars($searchQ) ?>&rdquo;</p>
        <p class="text-muted small mb-3">Try a different search term or clear the filter.</p>
        <a href="members.php" class="btn btn-outline-secondary btn-sm">Clear search</a>
        <?php elseif ($hasMembersInDatabase): ?>
        <p class="fw-semibold mb-1">No members match your filters</p>
        <p class="text-muted small mb-3">Try changing membership, flags, or type filters.</p>
        <a href="members.php" class="btn btn-outline-secondary btn-sm">Clear filters</a>
        <?php else: ?>
        <p class="fw-semibold mb-1">No members yet</p>
        <p class="text-muted small mb-3">Add your first member to get started.</p>
        <?php if (canEditMembers()): ?>
        <a href="member_wizard.php" class="btn btn-primary btn-sm">Add first member</a>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php else: ?>
        <div class="members-card-list" style="display:none;">
<?php foreach ($members as $m):
    $firstName   = $m['first_name'] ?? '';
    $lastName    = $m['last_name']  ?? '';
    $fullName    = trim("$firstName $lastName") ?: 'Unknown';
    $initials    = mb_strtoupper(mb_substr($firstName, 0, 1) . mb_substr($lastName, 0, 1));
    $bgColor     = members_initials_color($fullName);
    $isInactive  = !memberIsCurrent($m, $currentYear, $renewedMemberIds);
    $isSuspended = (bool) ($m['suspended'] ?? false);
    $renewalYear = (int) ($m['membership_renewal_year'] ?? 0);
    $renewOk     = $renewalYear >= $currentYear;
    $cardClass   = $isInactive ? ' member-inactive' : ($isSuspended ? ' member-suspended' : '');
?>
<div class="member-card<?= $cardClass ?>">
    <?php if (canEditMembers()): ?>
    <div class="pt-1">
        <input type="checkbox" class="form-check-input bulk-check"
               name="member_ids[]" value="<?= (int) $m['id'] ?>">
    </div>
    <?php endif; ?>
    <?php if (!empty($m['photo_path']) && is_readable(__DIR__ . '/' . ltrim((string) $m['photo_path'], '/'))): ?>
    <img src="<?= htmlspecialchars($m['photo_path']) ?>"
         alt="" class="member-card-avatar" loading="lazy" decoding="async">
    <?php else: ?>
    <div class="member-card-initials" style="background:<?= htmlspecialchars($bgColor) ?>;">
        <?= htmlspecialchars($initials) ?>
    </div>
    <?php endif; ?>
    <div class="member-card-body">
        <?php if (canEditMembers()): ?>
            <a href="member_edit.php?id=<?= (int) $m['id'] ?>" class="member-card-name">
                <?= htmlspecialchars($fullName) ?>
            </a>
        <?php else: ?>
            <a href="member_view.php?id=<?= (int) $m['id'] ?>" class="member-card-name">
                <?= htmlspecialchars($fullName) ?>
            </a>
        <?php endif; ?>
        <div class="member-card-meta">
            <?php if (!empty($m['membership_type_slot'])): ?>
            <span class="badge bg-secondary" style="font-size:.7rem;">
                <?= h($membershipTypeLabels[(int)$m['membership_type_slot']] ?? ('Type ' . (int)$m['membership_type_slot'])) ?>
            </span>
            <?php endif; ?>
            <span class="<?= $renewOk ? 'text-success' : 'text-danger' ?>" style="font-size:.78rem;">
                <?= $renewalYear > 0 ? $renewalYear : '—' ?>
            </span>
            <?php if (!empty($m['email'])): ?>
            <span class="text-truncate" style="max-width:160px;">
                <?= htmlspecialchars($m['email']) ?>
            </span>
            <?php endif; ?>
            <?php if ($isInactive): ?>
            <span class="badge bg-secondary" style="font-size:.68rem;">Inactive</span>
            <?php elseif ($isSuspended): ?>
            <span class="badge bg-warning text-dark" style="font-size:.68rem;">Suspended</span>
            <?php endif; ?>
            <?php if (!empty($m['life_member'])): ?>
            <span class="badge bg-primary" style="font-size:.68rem;">Life</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="member-card-actions">
        <button type="button"
                class="btn btn-sm btn-outline-secondary py-0 px-2 quick-view-btn"
                style="font-size:.75rem;"
                title="Quick view"
                data-member-id="<?= (int) $m['id'] ?>"
                data-bs-toggle="offcanvas"
                data-bs-target="#memberQuickView">
            View
        </button>
        <?php if (canEditMembers()): ?>
            <a href="member_edit.php?id=<?= (int) $m['id'] ?>"
               class="btn btn-sm btn-outline-secondary py-0 px-2"
               style="font-size:.75rem;">Edit</a>
            <a href="member_process.php?id=<?= (int) $m['id'] ?>"
               class="btn btn-sm btn-outline-primary py-0 px-2"
               style="font-size:.75rem;">Process</a>
        <?php elseif (canProcessMemberships()): ?>
            <a href="member_process.php?id=<?= (int) $m['id'] ?>"
               class="btn btn-sm btn-outline-primary py-0 px-2"
               style="font-size:.75rem;">Process</a>
        <?php endif; ?>
        <?php if (canEditMembers() || canProcessMemberships()): ?>
        <a href="badge_print.php?id=<?= (int) $m['id'] ?>"
           class="btn btn-sm <?= !empty($m['badge_printed_at']) ? 'btn-outline-success' : 'btn-outline-secondary' ?> py-0 px-2"
           style="font-size:.75rem;"
           title="<?= !empty($m['badge_printed_at']) ? 'Badge printed — reprint?' : 'Print badge' ?>">Print</a>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?php if (empty($members)): ?>
<div class="text-center text-muted py-5"><p class="mb-0">No members found.</p></div>
<?php endif; ?>
</div><!-- /.members-card-list -->

    <div class="members-table-wrap"><div class="table-responsive">
        <table class="table table-hover members-table mb-0">
            <thead class="table-light">
                <tr>
                    <?php if (canEditMembers()): ?>
                    <th class="text-center" style="width:2.5rem;">
                        <label class="mb-0" title="Select all">
                            <input type="checkbox" class="form-check-input" id="select-all">
                        </label>
                    </th>
                    <?php endif; ?>
                    <th style="min-width:220px;">Member</th>
                    <th>Type</th>
                    <th>Renewal</th>
                    <th class="d-none d-md-table-cell">Email</th>
                    <th class="text-end" style="width:80px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($members as $m):
                $firstName   = $m['first_name'] ?? '';
                $lastName    = $m['last_name']  ?? '';
                $fullName    = trim("$firstName $lastName") ?: 'Unknown';
                $initials    = mb_strtoupper(mb_substr($firstName, 0, 1) . mb_substr($lastName, 0, 1));
                $bgColor     = members_initials_color($fullName);
                $isInactive  = !memberIsCurrent($m, $currentYear, $renewedMemberIds);
                $isSuspended = (bool) ($m['suspended'] ?? false);
                $isLife      = (bool) ($m['life_member'] ?? false);
                $isFree      = (bool) ($m['free_membership'] ?? false);
                $hasKey      = !empty($m['gate_key_number']);
                $renewalYear = (int) ($m['membership_renewal_year'] ?? 0);
                $hasPhoto    = !empty($m['photo_path']);

                // Badge printed this renewal year?
                $badgePrinted = false;
                if (!empty($m['badge_printed_at']) && $renewalYear === $currentYear) {
                    $badgePrinted = (int) date('Y', strtotime($m['badge_printed_at'])) >= $currentYear;
                }
            ?>
            <tr class="member-row<?= $isInactive ? ' member-inactive' : '' ?><?= $isSuspended ? ' member-suspended' : '' ?>">

                <?php if (canEditMembers()): ?>
                <td class="text-center">
                    <label class="mb-0">
                        <input type="checkbox" name="member_ids[]" value="<?= (int) $m['id'] ?>"
                               class="form-check-input row-checkbox">
                    </label>
                </td>
                <?php endif; ?>

                <!-- Avatar + name -->
                <td>
                    <div class="d-flex align-items-center gap-2">
                <?php if ($hasPhoto && is_readable(__DIR__ . '/' . ltrim((string) $m['photo_path'], '/'))): ?>
                        <img src="<?= htmlspecialchars($m['photo_path']) ?>"
                             alt="" class="member-avatar"
                             loading="lazy" decoding="async" fetchpriority="low">
                        <?php else: ?>
                        <div class="member-avatar member-initials"
                             style="background-color:<?= $bgColor ?>;">
                            <?= htmlspecialchars($initials) ?>
                        </div>
                        <?php endif; ?>

                        <div class="member-name-block">
                            <?php if (canEditMembers()): ?>
                            <a href="member_edit.php?id=<?= (int) $m['id'] ?>"
                               class="member-name fw-semibold text-decoration-none">
                                <?= htmlspecialchars("$lastName, $firstName") ?>
                            </a>
                            <?php else: ?>
                            <a href="member_view.php?id=<?= (int) $m['id'] ?>"
                               class="member-name fw-semibold text-decoration-none">
                                <?= htmlspecialchars("$lastName, $firstName") ?>
                            </a>
                            <?php endif; ?>

                            <!-- Flag icons -->
                            <div class="member-flags mt-1">
                                <?php if ($isInactive): ?>
                                <span class="flag-badge flag-inactive" title="Not a current member">Inactive</span>
                                <?php elseif ($isSuspended): ?>
                                <span class="flag-badge flag-suspended" title="Suspended">Suspended</span>
                                <?php endif; ?>
                                <?php if ($isLife): ?>
                                <span class="flag-icon" title="Life member">♾</span>
                                <?php endif; ?>
                                <?php if ($isFree): ?>
                                <span class="flag-icon" title="Free membership">★</span>
                                <?php endif; ?>
                                <?php if ($hasKey): ?>
                                <span class="flag-icon" title="Gate key: <?= htmlspecialchars($m['gate_key_number']) ?>">🔑</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </td>

                <!-- Type pill -->
                <td class="align-middle">
                    <?= $m['membership_type_slot'] ? members_type_badge((int)$m['membership_type_slot'], $membershipTypeLabels) : '<span class="text-muted">—</span>' ?>
                </td>

                <!-- Renewal year (color-coded) -->
                <td class="align-middle">
                    <?= members_year_badge($m['membership_renewal_year'], $currentYear) ?>
                </td>

                <!-- Email (hidden on small screens) -->
                <td class="align-middle d-none d-md-table-cell text-muted small">
                    <?= $m['email'] ? htmlspecialchars($m['email']) : '—' ?>
                </td>

                <!-- Actions -->
                <td class="text-end align-middle">
                    <div class="d-flex justify-content-end gap-1">
                        <!-- Quick view -->
                        <button type="button"
                                class="btn btn-sm btn-outline-secondary quick-view-btn"
                                title="Quick view"
                                data-member-id="<?= (int) $m['id'] ?>"
                                data-bs-toggle="offcanvas"
                                data-bs-target="#memberQuickView">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8M1.173 8a13 13 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5s3.879 1.168 5.168 2.457A13 13 0 0 1 14.828 8q-.086.13-.195.288c-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5s-3.879-1.168-5.168-2.457A13 13 0 0 1 1.172 8z"/>
                                <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5M4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0"/>
                            </svg>
                        </button>
                        <?php if (canEditMembers()): ?>
                        <!-- Edit -->
                        <a href="member_edit.php?id=<?= (int) $m['id'] ?>"
                           class="btn btn-sm btn-outline-primary" title="Edit member">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M12.854.146a.5.5 0 0 0-.707 0L10.5 1.793 14.207 5.5l1.647-1.646a.5.5 0 0 0 0-.708zm.646 6.061L9.793 2.5 3.293 9H3.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.207zm-7.468 7.468A.5.5 0 0 1 6 13.5V13h-.5a.5.5 0 0 1-.5-.5V12h-.5a.5.5 0 0 1-.5-.5V11h-.5a.5.5 0 0 1-.5-.5V10h-.5a.499.499 0 0 1-.175-.032l-.179.178a.5.5 0 0 0-.11.168l-2 5a.5.5 0 0 0 .65.65l5-2a.5.5 0 0 0 .168-.11z"/>
                            </svg>
                        </a>
                        <?php endif; ?>
                        <?php if (canEditMembers() || canProcessMemberships()): ?>
                        <a href="member_process.php?id=<?= (int) $m['id'] ?>"
                           class="btn btn-sm btn-outline-primary" title="Process signup / renewal">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M8 3.5a.5.5 0 0 0-1 0V9a.5.5 0 0 0 .252.434l3.5 2a.5.5 0 0 0 .496-.868L8 8.71z"/>
                                <path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16m7-8A7 7 0 1 1 1 8a7 7 0 0 1 14 0"/>
                            </svg>
                        </a>
                        <!-- Print badge -->
                        <a href="badge_print.php?id=<?= (int) $m['id'] ?>"
                           class="btn btn-sm <?= $badgePrinted ? 'btn-outline-success' : 'btn-outline-secondary' ?>"
                           title="<?= $badgePrinted ? 'Badge printed — reprint?' : 'Print badge' ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M2.5 8a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1"/>
                                <path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2zm6 14H5a1 1 0 0 1-1-1v-1h8v1a1 1 0 0 1-1 1M4 3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2H4zm1 5a2 2 0 0 0-2 2v1H2a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v-1a2 2 0 0 0-2-2z"/>
                            </svg>
                        </a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div><!-- /.members-table-wrap -->
    <?php endif; ?>
</div><!-- /.card -->

<?php if (canEditMembers()): ?>
</form><!-- #bulk-form -->
<?php endif; ?>

<!-- ── Pagination + per-page + bulk actions ───────────────────────────────── -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3">

    <!-- Bulk delete -->
    <?php if (canEditMembers()): ?>
    <div class="d-flex align-items-center gap-2">
        <button type="submit" form="bulk-form" class="btn btn-danger btn-sm" id="bulk-delete-btn" disabled>
            Delete selected
        </button>
        <span class="text-muted small" id="selected-count"></span>
    </div>
    <?php else: ?>
    <div></div>
    <?php endif; ?>

    <!-- Per-page + pagination -->
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <div class="d-flex align-items-center gap-2">
            <span class="text-muted small">Show</span>
            <form method="get" action="members.php" id="per-form">
                <?php if ($searchQ !== ''):       ?><input type="hidden" name="q"           value="<?= htmlspecialchars($searchQ) ?>"><?php endif; ?>
                <?php if ($memberTypeFilter !== ''):?><input type="hidden" name="member_type" value="<?= htmlspecialchars($memberTypeFilter) ?>"><?php endif; ?>
                <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                <?php foreach ($flagFilters as $flag): ?>
                <input type="hidden" name="flag[]" value="<?= htmlspecialchars($flag) ?>">
                <?php endforeach; ?>
                <?php if ($badgeFilter !== ''): ?>
                <input type="hidden" name="badge" value="<?= htmlspecialchars($badgeFilter) ?>">
                <?php endif; ?>
                <?php if ($sort !== 'name'):        ?><input type="hidden" name="sort"        value="<?= htmlspecialchars($sort) ?>"><?php endif; ?>
                <select name="per" class="form-select form-select-sm js-submit-on-change" style="width:auto">
                    <option value="25"<?=  $perPage === 25  ? ' selected' : '' ?>>25</option>
                    <option value="50"<?=  $perPage === 50  ? ' selected' : '' ?>>50</option>
                    <option value="100"<?= $perPage === 100 ? ' selected' : '' ?>>100</option>
                    <option value="0"<?=   $perPage === 0   ? ' selected' : '' ?>>All</option>
                </select>
            </form>
            <span class="text-muted small">per page</span>
        </div>

        <?php if ($totalPages > 1 && $perPage > 0): ?>
        <nav aria-label="Member list pages" class="d-flex align-items-center gap-1">
            <?php if ($page > 1): ?>
            <a href="<?= membersUrl($queryParams, $page - 1) ?>" class="btn btn-sm btn-outline-secondary">&laquo;</a>
            <?php endif; ?>
            <span class="text-muted small px-1">Page <?= $page ?> of <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
            <a href="<?= membersUrl($queryParams, $page + 1) ?>" class="btn btn-sm btn-outline-secondary">&raquo;</a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     Quick-view offcanvas (Bootstrap built-in, no dependencies)
     Populated via a lightweight JSON fetch to member_detail.php
     ════════════════════════════════════════════════════════════════════════ -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="memberQuickView" aria-labelledby="quickViewLabel" style="width:320px;">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title" id="quickViewLabel">Member detail</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body" id="quickViewBody">
        <p class="text-muted small">Loading&hellip;</p>
    </div>
</div>

<!-- ── Styles ────────────────────────────────────────────────────────────── -->
<style<?= csp_nonce_attr() ?>>
/* Avatar */
.member-avatar {
    width: 36px; height: 36px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
}
.member-initials {
    display: flex; align-items: center; justify-content: center;
    color: #fff;
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.03em;
    user-select: none;
}
/* Name block */
.member-name { color: var(--bs-body-color); }
.member-name:hover { color: var(--club-primary); }
/* Flag row */
.member-flags { display: flex; gap: 4px; align-items: center; flex-wrap: wrap; min-height: 1px; }
.flag-badge {
    font-size: 10px; font-weight: 600; letter-spacing: 0.04em;
    padding: 1px 5px; border-radius: 3px; text-transform: uppercase;
}
.flag-inactive  { background: #f8d7da; color: #842029; }
.flag-suspended { background: #fff3cd; color: #664d03; }
.flag-icon { font-size: 12px; line-height: 1; }
/* Type badge sizing */
.member-type-badge { font-size: 11px; }
/* Renewal year badges */
.badge-year-current { background: #198754; color: #fff; }
.badge-year-due     { background: #ffc107; color: #212529; }
.badge-year-lapsed  { background: #dc3545; color: #fff; }
/* Row tinting */
.member-inactive  td { opacity: 0.6; }
.member-suspended td { background: #fffbe6; }
/* Filter chips */
.filter-chip { font-size: 0.8rem; }
/* Table hover */
.members-table tbody tr:hover { background-color: rgba(var(--club-primary-rgb), 0.04); }

/* ── Mobile responsive: hide table, show cards below md breakpoint ── */
@media (max-width: 767.98px) {
    .members-table-wrap { display: none !important; }
    .members-card-list  { display: block !important; }
}
@media (min-width: 768px) {
    .members-card-list  { display: none !important; }
}
.member-card {
    background: var(--club-card);
    border: 1px solid var(--club-border);
    border-radius: 10px;
    padding: .875rem 1rem;
    margin-bottom: .625rem;
    display: flex;
    align-items: flex-start;
    gap: .75rem;
}
.member-card.member-inactive  { opacity: .6; }
.member-card.member-suspended { border-left: 3px solid var(--club-warning); }
.member-card-avatar {
    width: 42px; height: 42px; border-radius: 50%;
    object-fit: cover; flex-shrink: 0;
}
.member-card-initials {
    width: 42px; height: 42px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: .8rem; font-weight: 600; color: #fff;
    flex-shrink: 0; user-select: none;
}
.member-card-body  { flex: 1; min-width: 0; }
.member-card-name  {
    font-weight: 600; font-size: .95rem; color: var(--club-text);
    text-decoration: none; display: block; line-height: 1.3;
}
.member-card-name:hover { color: var(--club-primary); }
.member-card-meta  {
    font-size: .78rem; color: #6c757d; margin-top: .15rem;
    display: flex; flex-wrap: wrap; gap: .35rem; align-items: center;
}
.member-card-actions {
    display: flex; flex-direction: column; gap: .3rem;
    flex-shrink: 0; align-items: flex-end;
}
.qv-initials { background: var(--club-primary); color: var(--club-on-primary); }
.qv-avatar { width: 52px; height: 52px; }
.qv-badge-type { background: color-mix(in srgb, var(--club-muted) 25%, var(--club-bg)); color: var(--club-text); font-size: 11px; }
.qv-badge-year { background: var(--club-success); color: #fff; font-size: 11px; }




</style>

<!-- ── Scripts ───────────────────────────────────────────────────────────── -->
<script<?= csp_nonce_attr() ?>>
window.FLIGHTOPS_MEMBERS_LIST = <?= json_encode([
    'canEdit' => canEditMembers(),
    'canProcess' => canEditMembers() || canProcessMemberships(),
], JSON_THROW_ON_ERROR) ?>;
</script>
<script src="js/members_list.js?v=<?= htmlspecialchars(FLIGHT_OPS_VERSION) ?>"<?= csp_nonce_attr() ?>></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>