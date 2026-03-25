<?php
/**
 * Member list — polished v1.0.
 *
 * Changes from original:
 *  - SELECT now fetches photo_path, inactive, suspended, life_member,
 *    free_membership, gate_key_number, badge_printed_at for display.
 *  - Filter-chip counts added via a single summary query.
 *  - Renders avatar (photo thumbnail or CSS initials), type pill,
 *    status/flag icons, and colour-coded renewal year.
 *  - Inline Delete button removed; destructive action lives in edit form.
 *  - "Print badge" shortcut added to action column.
 *  - Bootstrap offcanvas quick-view panel (read-only detail fetch).
 *  - Flash message support via $_SESSION['flash'].
 *  - All existing filter/sort/pagination/bulk-select logic preserved.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();
if (!canEditMembers() && !canProcessMemberships()) {
    header('Location: index.php');
    exit;
}
$currentYear = (int) date('Y');
$membershipTypeLabels = enabledMembershipTypeLabels($pdo);

// ── Input sanitisation (unchanged from original) ─────────────────────────────
$searchQ = trim((string) ($_GET['q'] ?? ''));

$perPage = isset($_GET['per']) ? (int) $_GET['per'] : 25;
if (!in_array($perPage, [25, 50, 100, 0], true)) {
    $perPage = 25;
}

$page = max(1, (int) ($_GET['page'] ?? 1));

$memberTypeFilter = (string) ($_GET['member_type'] ?? '');
$memberTypeSlotFilter = null;
if ($memberTypeFilter !== '') {
    $slot = is_numeric($memberTypeFilter) ? (int) $memberTypeFilter : 0;
    $memberTypeSlotFilter = ($slot >= 1 && $slot <= 4) ? $slot : null;
    if ($memberTypeSlotFilter === null) $memberTypeFilter = '';
}

$statusFilter = (string) ($_GET['status'] ?? 'active');
if (!in_array($statusFilter, ['', 'active', 'inactive'], true)) {
    $statusFilter = 'active';
}

// ── ORDER BY clauses (avoid fragile string-replacement) ──────────────────────
// We build two explicit ORDER BY clauses: one for the non-aliased query, and
// one for the search query where `members` is aliased as `m`.
// $sort must be a key of $orderByMap only — never concatenate raw $_GET into SQL.
$orderByMap = [
    'name' => [
        'main'   => 'ORDER BY last_name, first_name',
        'search' => 'ORDER BY m.last_name, m.first_name',
    ],
    'name_desc' => [
        'main'   => 'ORDER BY last_name DESC, first_name DESC',
        'search' => 'ORDER BY m.last_name DESC, m.first_name DESC',
    ],
    'year' => [
        'main'   => 'ORDER BY membership_renewal_year ASC, last_name, first_name',
        'search' => 'ORDER BY m.membership_renewal_year ASC, m.last_name, m.first_name',
    ],
    'year_desc' => [
        'main'   => 'ORDER BY membership_renewal_year DESC, last_name, first_name',
        'search' => 'ORDER BY m.membership_renewal_year DESC, m.last_name, m.first_name',
    ],
    'type' => [
        'main'   => 'ORDER BY membership_type_slot ASC, last_name, first_name',
        'search' => 'ORDER BY m.membership_type_slot ASC, m.last_name, m.first_name',
    ],
    'type_desc' => [
        'main'   => 'ORDER BY membership_type_slot DESC, last_name, first_name',
        'search' => 'ORDER BY m.membership_type_slot DESC, m.last_name, m.first_name',
    ],
];

$sort = (string) ($_GET['sort'] ?? 'name');
if (!array_key_exists($sort, $orderByMap)) {
    $sort = 'name';
}
$orderBy       = $orderByMap[$sort]['main'];
$orderBySearch = $orderByMap[$sort]['search'];

// ── Build main query ──────────────────────────────────────────────────────────
// NOTE: extra columns (inactive, suspended, flags, photo_path, badge_printed_at,
//       gate_key_number) are added so the list can render status indicators.
$selectCols = 'id, first_name, last_name, email, membership_type_slot, membership_renewal_year,
               inactive, suspended, life_member, free_membership,
               gate_key_number, badge_printed_at, photo_path';

$params   = [];
$baseSql  = '';
$countSql = '';

if ($searchQ === '') {
    $baseSql  = "SELECT $selectCols FROM members WHERE 1=1";
    $countSql = 'SELECT COUNT(*) FROM members WHERE 1=1';

    if ($memberTypeSlotFilter !== null) {
        $baseSql  .= ' AND membership_type_slot = ?';
        $countSql .= ' AND membership_type_slot = ?';
        $params[]  = $memberTypeSlotFilter;
    }
    if ($statusFilter === 'active') {
        $baseSql  .= ' AND inactive = 0';
        $countSql .= ' AND inactive = 0';
    } elseif ($statusFilter === 'inactive') {
        $baseSql  .= ' AND inactive = 1';
        $countSql .= ' AND inactive = 1';
    }
    $baseSql .= " $orderBy";

} else {
    // Multi-token search across name, email, phone, address, AMA/FAA, type, year
    $tokens = array_filter(array_map('trim', preg_split('/[\s,]+/', $searchQ)));
    if (empty($tokens)) {
        $tokens = [$searchQ];
    }

    $likeClause = '(
        m.first_name LIKE ? OR m.last_name LIKE ? OR m.email LIKE ? OR m.title LIKE ?
        OR m.notes LIKE ? OR CAST(m.membership_type_slot AS CHAR) LIKE ? OR m.gate_key_number LIKE ?
        OR m.ama_number LIKE ? OR m.faa_number LIKE ?
        OR CAST(m.membership_renewal_year AS CHAR) LIKE ?
        OR mp.number LIKE ? OR ma.street LIKE ? OR ma.street2 LIKE ?
        OR ma.city LIKE ? OR ma.state LIKE ? OR ma.postal_code LIKE ?
    )';

    $tokenConditions = implode(' AND ', array_fill(0, count($tokens), $likeClause));

    $baseSql  = "SELECT DISTINCT m.$selectCols
        FROM members m
        LEFT JOIN member_phones mp  ON mp.member_id  = m.id
        LEFT JOIN member_addresses ma ON ma.member_id = m.id
        WHERE $tokenConditions";
    // Rewrite selectCols with m. prefix for the search path
    $baseSql = str_replace(
        "SELECT DISTINCT m.$selectCols",
        'SELECT DISTINCT ' . implode(', ', array_map(fn($c) => 'm.' . trim($c), explode(',', $selectCols))),
        $baseSql
    );

    $countSql = "SELECT COUNT(DISTINCT m.id)
        FROM members m
        LEFT JOIN member_phones mp  ON mp.member_id  = m.id
        LEFT JOIN member_addresses ma ON ma.member_id = m.id
        WHERE $tokenConditions";

    if ($memberTypeSlotFilter !== null) {
        $baseSql  .= ' AND m.membership_type_slot = ?';
        $countSql .= ' AND m.membership_type_slot = ?';
    }
    if ($statusFilter === 'active') {
        $baseSql  .= ' AND m.inactive = 0';
        $countSql .= ' AND m.inactive = 0';
    } elseif ($statusFilter === 'inactive') {
        $baseSql  .= ' AND m.inactive = 1';
        $countSql .= ' AND m.inactive = 1';
    }
    $baseSql .= " $orderBySearch";

    $params = [];
    foreach ($tokens as $t) {
        $like = '%' . $t . '%';
        for ($i = 0; $i < 16; $i++) {
            $params[] = $like;
        }
    }
    if ($memberTypeSlotFilter !== null) {
        $params[] = $memberTypeSlotFilter;
    }
}

// ── Counts ────────────────────────────────────────────────────────────────────
$stmt       = $pdo->prepare($countSql);
$stmt->execute($params);
$totalCount = (int) $stmt->fetchColumn();

// Filter-chip counts (active / inactive totals regardless of current status filter)
$chipCounts = [];
$chipStmt = $pdo->query('SELECT inactive, COUNT(*) AS cnt FROM members GROUP BY inactive');
while ($row = $chipStmt->fetch(PDO::FETCH_ASSOC)) {
    $chipCounts[$row['inactive'] ? 'inactive' : 'active'] = (int) $row['cnt'];
}

// Type chip counts (active members only)
$typeCountStmt = $pdo->query('SELECT membership_type_slot, COUNT(*) AS cnt FROM members WHERE inactive = 0 GROUP BY membership_type_slot');
$typeCounts = [];
while ($row = $typeCountStmt->fetch(PDO::FETCH_ASSOC)) {
    $typeCounts[(int) ($row['membership_type_slot'] ?? 0)] = (int) $row['cnt'];
}

// ── Paginate ──────────────────────────────────────────────────────────────────
if ($perPage > 0) {
    $offset   = ($page - 1) * $perPage;
    $baseSql .= ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
}
$stmt    = $pdo->prepare($baseSql);
$stmt->execute($params);
$members = $stmt->fetchAll();

$totalPages = $perPage > 0 ? max(1, (int) ceil($totalCount / $perPage)) : 1;
$from       = $totalCount === 0 ? 0 : ($perPage > 0 ? ($page - 1) * $perPage + 1 : 1);
$to         = $perPage === 0 ? $totalCount : min($page * $perPage, $totalCount);

// ── Helpers ───────────────────────────────────────────────────────────────────
/**
 * Build a members.php URL preserving current active params, with overrides.
 */
$queryParams = array_filter([
    'q'           => $searchQ !== '' ? $searchQ : null,
    'per'         => $perPage !== 25 ? $perPage : null,
    'member_type' => $memberTypeFilter !== '' ? $memberTypeFilter : null,
    'status'      => $statusFilter !== 'active' ? $statusFilter : null,
    'sort'        => $sort !== 'name' ? $sort : null,
], fn($v) => $v !== null);

function membersUrl(array $params, ?int $pg = null): string {
    $p = $params;
    if ($pg !== null) {
        $p['page'] = $pg;
    }
    return 'members.php' . (count($p) > 0 ? '?' . http_build_query($p) : '');
}

/**
 * Return CSS initials-avatar background colour deterministically from a name.
 * Uses a palette of 8 muted colours safe on white text.
 */
function initialsColor(string $name): string {
    $palette = ['#5b7fa6', '#6b8f6b', '#9b6b6b', '#7b6b9b', '#9b8b5b', '#5b9b8b', '#9b6b8b', '#6b7b9b'];
    return $palette[abs(crc32($name)) % count($palette)];
}

/**
 * Return Bootstrap badge class and label for membership type.
 */
function typeBadge(?int $slot, array $labels): string {
    $slot = (int) ($slot ?? 0);
    $map = [
        1 => ['bg-primary',   $labels[1] ?? 'Type 1'],
        2 => ['bg-info',      $labels[2] ?? 'Type 2'],
        3 => ['bg-success',   $labels[3] ?? 'Type 3'],
        4 => ['bg-secondary', $labels[4] ?? 'Type 4'],
    ];
    [$cls, $label] = $map[$slot] ?? ['bg-light text-dark', '—'];
    return '<span class="badge ' . $cls . ' member-type-badge">' . h($label) . '</span>';
}

/**
 * Return coloured badge for renewal year:
 *   current year  → green
 *   last year     → amber (needs renewal)
 *   older         → red (lapsed)
 *   null/0        → grey
 */
function yearBadge(mixed $year, int $currentYear): string {
    $y = (int) $year;
    if ($y <= 0) {
        return '<span class="badge bg-light text-muted border">—</span>';
    }
    $cls = match (true) {
        $y >= $currentYear      => 'badge-year-current',
        $y === $currentYear - 1 => 'badge-year-due',
        default                 => 'badge-year-lapsed',
    };
    return '<span class="badge ' . $cls . '">' . $y . '</span>';
}

$pageTitle = 'Members';
require_once __DIR__ . '/includes/header.php';
?>

<?php
// Legacy success messages: header.php already consumes $_SESSION['flash'] as toasts;
// here we only handle legacy ?deleted=N and render inline alerts.
$flashes = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);
if (!empty($_GET['deleted'])) {
    $n = (int) $_GET['deleted'];
    $flashes[] = ['type' => 'success', 'msg' => $n === 1 ? 'Member deleted.' : "$n members deleted."];
}
?>

<?php foreach ($flashes as $f): ?>
<div class="alert alert-<?= htmlspecialchars($f['type'] ?? 'success') ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($f['msg']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endforeach; ?>

<!-- ── Page header ──────────────────────────────────────────────────────────── -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
        <h1 class="h2 mb-0">Members</h1>
        <p class="text-muted small mb-0">
            <?= $totalCount ?> member<?= $totalCount !== 1 ? 's' : '' ?>
            <?php if ($totalCount > 0 && $perPage > 0 && $totalPages > 1): ?>
                &mdash; showing <?= $from ?>&ndash;<?= $to ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (canEditMembers()): ?>
        <a href="member_edit.php" class="btn btn-primary btn-sm">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="me-1" viewBox="0 0 16 16">
                <path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4z"/>
            </svg>New member
        </a>
        <a href="import.php" class="btn btn-outline-secondary btn-sm">Import</a>
        <?php endif; ?>
        <?php if (canEditMembers() || canProcessMemberships()): ?>
        <div class="dropdown">
            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
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
    </div>
</div>

<!-- ── Filter chips + search row ────────────────────────────────────────────── -->
<div class="members-filter-bar mb-3">

    <!-- Status chips -->
    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <span class="text-muted small me-1">Status:</span>
        <?php
        $statuses = [
            ''         => 'All (' . (($chipCounts['active'] ?? 0) + ($chipCounts['inactive'] ?? 0)) . ')',
            'active'   => 'Active (' . ($chipCounts['active'] ?? 0) . ')',
            'inactive' => 'Inactive (' . ($chipCounts['inactive'] ?? 0) . ')',
        ];
        foreach ($statuses as $val => $label):
            // Re-evaluate: default statusFilter is 'active', so chip '' should not be active unless explicitly chosen
            $isActive = $statusFilter === $val;
        ?>
        <a href="<?= htmlspecialchars(membersUrl(array_merge(
            array_filter($queryParams, fn($k) => $k !== 'status' && $k !== 'page', ARRAY_FILTER_USE_KEY),
            $val !== '' ? ['status' => $val] : []
        ))) ?>"
           class="btn btn-sm <?= $isActive ? 'btn-primary' : 'btn-outline-secondary' ?> filter-chip">
            <?= $label ?>
        </a>
        <?php endforeach; ?>

        <?php if ($statusFilter === 'active' || $statusFilter === ''): ?>
        <span class="text-muted small ms-2 me-1">Type:</span>
        <?php
        $types = ['' => 'All'];
        foreach ($membershipTypeLabels as $slot => $label) {
            $types[(string) $slot] = $label . ' (' . ($typeCounts[(int) $slot] ?? 0) . ')';
        }
        foreach ($types as $val => $label):
            $isActive = $memberTypeFilter === $val;
        ?>
        <a href="<?= htmlspecialchars(membersUrl(array_merge(
            array_filter($queryParams, fn($k) => $k !== 'member_type' && $k !== 'page', ARRAY_FILTER_USE_KEY),
            $val !== '' ? ['member_type' => $val] : []
        ))) ?>"
           class="btn btn-sm <?= $isActive ? 'btn-secondary' : 'btn-outline-secondary' ?> filter-chip">
            <?= $label ?>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Search + sort row -->
    <form method="get" action="members.php" class="row g-2 align-items-end">
        <input type="hidden" name="per" value="<?= (int) $perPage ?>">
        <?php if ($statusFilter !== 'active'): ?>
        <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
        <?php endif; ?>
        <?php if ($memberTypeFilter !== ''): ?>
        <input type="hidden" name="member_type" value="<?= htmlspecialchars($memberTypeFilter) ?>">
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
            <?php if ($searchQ !== '' || $memberTypeFilter !== '' || ($statusFilter !== '' && $statusFilter !== 'active') || $sort !== 'name'): ?>
            <a href="members.php" class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($searchQ !== ''): ?>
    <p class="text-muted small mt-1 mb-0">
        Showing results for &ldquo;<?= htmlspecialchars($searchQ) ?>&rdquo;
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
        <?php else: ?>
        <p class="fw-semibold mb-1">No members yet</p>
        <p class="text-muted small mb-3">Add your first member to get started.</p>
        <?php if (canEditMembers()): ?>
        <a href="member_edit.php" class="btn btn-primary btn-sm">Add first member</a>
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
    $bgColor     = initialsColor($fullName);
    $isInactive  = (bool) ($m['inactive']  ?? false);
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
    <?php if (!empty($m['photo_path']) && is_readable(__DIR__ . '/' . $m['photo_path'])): ?>
    <img src="<?= htmlspecialchars($m['photo_path']) ?>?t=<?= time() ?>"
         alt="" class="member-card-avatar">
    <?php else: ?>
    <div class="member-card-initials" style="background:<?= htmlspecialchars($bgColor) ?>;">
        <?= htmlspecialchars($initials) ?>
    </div>
    <?php endif; ?>
    <div class="member-card-body">
        <a href="member_edit.php?id=<?= (int) $m['id'] ?>" class="member-card-name">
            <?= htmlspecialchars($fullName) ?>
        </a>
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
        <a href="member_edit.php?id=<?= (int) $m['id'] ?>"
           class="btn btn-sm btn-outline-secondary py-0 px-2"
           style="font-size:.75rem;">Edit</a>
        <a href="member_process.php?id=<?= (int) $m['id'] ?>"
           class="btn btn-sm btn-outline-primary py-0 px-2"
           style="font-size:.75rem;">Renew</a>
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
                $bgColor     = initialsColor($fullName);
                $isInactive  = (bool) ($m['inactive']  ?? false);
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
                        <?php if ($hasPhoto): ?>
                        <img src="badge_photo.php?id=<?= (int) $m['id'] ?>"
                             alt="" class="member-avatar"
                             loading="lazy">
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
                            <span class="member-name fw-semibold">
                                <?= htmlspecialchars("$lastName, $firstName") ?>
                            </span>
                            <?php endif; ?>

                            <!-- Flag icons -->
                            <div class="member-flags mt-1">
                                <?php if ($isInactive): ?>
                                <span class="flag-badge flag-inactive" title="Inactive">Inactive</span>
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
                    <?= $m['membership_type_slot'] ? typeBadge((int)$m['membership_type_slot'], $membershipTypeLabels) : '<span class="text-muted">—</span>' ?>
                </td>

                <!-- Renewal year (colour-coded) -->
                <td class="align-middle">
                    <?= yearBadge($m['membership_renewal_year'], $currentYear) ?>
                </td>

                <!-- Email (hidden on small screens) -->
                <td class="align-middle d-none d-md-table-cell text-muted small">
                    <?= $m['email'] ? htmlspecialchars($m['email']) : '—' ?>
                </td>

                <!-- Actions -->
                <td class="text-end align-middle">
                    <div class="d-flex justify-content-end gap-1">
                        <?php if (canEditMembers()): ?>
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
                        <!-- Edit -->
                        <a href="member_edit.php?id=<?= (int) $m['id'] ?>"
                           class="btn btn-sm btn-outline-primary" title="Edit member">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M12.854.146a.5.5 0 0 0-.707 0L10.5 1.793 14.207 5.5l1.647-1.646a.5.5 0 0 0 0-.708zm.646 6.061L9.793 2.5 3.293 9H3.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.207zm-7.468 7.468A.5.5 0 0 1 6 13.5V13h-.5a.5.5 0 0 1-.5-.5V12h-.5a.5.5 0 0 1-.5-.5V11h-.5a.5.5 0 0 1-.5-.5V10h-.5a.499.499 0 0 1-.175-.032l-.179.178a.5.5 0 0 0-.11.168l-2 5a.5.5 0 0 0 .65.65l5-2a.5.5 0 0 0 .168-.11z"/>
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
                <?php if ($statusFilter !== ''):   ?><input type="hidden" name="status"      value="<?= htmlspecialchars($statusFilter) ?>"><?php endif; ?>
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
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 10px;
    padding: .875rem 1rem;
    margin-bottom: .625rem;
    display: flex;
    align-items: flex-start;
    gap: .75rem;
}
.member-card.member-inactive  { opacity: .6; }
.member-card.member-suspended { border-left: 3px solid #ffc107; }
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
    font-weight: 600; font-size: .95rem; color: var(--bs-body-color);
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




</style>

<!-- ── Scripts ───────────────────────────────────────────────────────────── -->
<script<?= csp_nonce_attr() ?>>
(function () {
    'use strict';

    // ── Bulk select ───────────────────────────────────────────────────────
    const form      = document.getElementById('bulk-form');
    const selectAll = document.getElementById('select-all');
    const deleteBtn = document.getElementById('bulk-delete-btn');
    const countSpan = document.getElementById('selected-count');

    if (deleteBtn && form) {
        deleteBtn.addEventListener('click', function (e) {
            const checked = form.querySelectorAll('.row-checkbox:checked').length;
            if (checked <= 0) return;

            // Explicit confirmation for the sensitive destructive action.
            const msg = checked === 1
                ? 'Are you sure you want to delete 1 member?'
                : 'Are you sure you want to delete ' + checked + ' members?';
            if (!window.confirm(msg)) e.preventDefault();
        });
    }

    function updateBulkCount() {
        if (!form) return;
        const checked = form.querySelectorAll('.row-checkbox:checked').length;
        if (deleteBtn) {
            deleteBtn.disabled = checked === 0;
            if (checked === 1) {
                deleteBtn.textContent = 'Delete 1 member';
            } else if (checked > 1) {
                deleteBtn.textContent = 'Delete ' + checked + ' members';
            } else {
                deleteBtn.textContent = 'Delete selected';
            }
        }
        if (countSpan) countSpan.textContent = checked ? checked + ' selected' : '';
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            form.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = selectAll.checked);
            updateBulkCount();
        });
    }
    if (form) {
        form.querySelectorAll('.row-checkbox').forEach(cb => cb.addEventListener('change', updateBulkCount));
    }
    updateBulkCount();

    // ── Quick-view offcanvas ──────────────────────────────────────────────
    // Requires member_detail.php?id=N&format=json endpoint.
    // Falls back gracefully if that endpoint doesn't exist yet.
    const quickViewBody = document.getElementById('quickViewBody');

    document.querySelectorAll('.quick-view-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const memberId = this.dataset.memberId;
            if (!quickViewBody || !memberId) return;

            quickViewBody.innerHTML = '<p class="text-muted small">Loading&hellip;</p>';

            fetch('member_detail.php?id=' + encodeURIComponent(memberId) + '&format=json', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            })
                .then(async r => {
                    if (!r.ok) {
                        const bodyText = await r.text().catch(() => '');
                        const err = new Error('HTTP ' + r.status);
                        err.status = r.status;
                        err.bodyText = bodyText;
                        throw err;
                    }
                    return r.json();
                })
                .then(data => {
                    quickViewBody.innerHTML = buildQuickViewHtml(data);
                })
                .catch(err => {
                    const status = (err && err.status) ? err.status : 'unknown';
                    const requiresText =
                        status === 404
                            ? '<p class="text-muted small mb-3">Quick-view endpoint missing: <code>member_detail.php</code>.</p>'
                            : '<p class="text-muted small mb-3">Quick-view failed to load (HTTP ' + status + ').</p>';
                    quickViewBody.innerHTML =
                        requiresText +
                        '<a href="member_edit.php?id=' + encodeURIComponent(memberId) + '" class="btn btn-primary btn-sm">Open full record</a>';

                    // Keep it debuggable without exposing internals to users.
                    console.error('Quick-view fetch failed', err);
                });
        });
    });

    /**
     * Render quick-view offcanvas body from the JSON payload returned by
     * member_detail.php. Expected keys: name, type, renewal_year, ama_number,
     * faa_number, gate_key, phones (array of {type, number}), email, flags.
     *
     * @param {Object} d
     * @returns {string} HTML string
     */
    function buildQuickViewHtml(d) {
        const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

        let html = '<div class="d-flex align-items-center gap-3 mb-3">';
        if (d.photo_url) {
            html += '<img src="' + esc(d.photo_url) + '" class="member-avatar" style="width:52px;height:52px;" alt="">';
        } else {
            const initials = (d.name || '??').split(' ').map(w => w[0]).join('').toUpperCase().slice(0,2);
            html += '<div class="member-initials member-avatar" style="width:52px;height:52px;font-size:1rem;background:#5b7fa6;">' + esc(initials) + '</div>';
        }
        html += '<div><div class="fw-semibold">' + esc(d.name) + '</div>';
        if (d.type) html += '<span class="badge bg-secondary" style="font-size:11px;">' + esc(d.type) + '</span> ';
        if (d.renewal_year) html += '<span class="badge bg-success" style="font-size:11px;">' + esc(d.renewal_year) + '</span>';
        html += '</div></div>';

        const rows = [
            ['Email',      d.email],
            ['AMA #',      d.ama_number],
            ['FAA #',      d.faa_number],
            ['Gate key',   d.gate_key],
        ];
        html += '<dl class="row g-1 small mb-3">';
        rows.forEach(([label, val]) => {
            if (val) {
                html += '<dt class="col-5 text-muted">' + esc(label) + '</dt><dd class="col-7 mb-0">' + esc(val) + '</dd>';
            }
        });
        if (d.phones && d.phones.length) {
            d.phones.forEach(p => {
                html += '<dt class="col-5 text-muted">' + esc(p.type) + '</dt><dd class="col-7 mb-0">' + esc(p.number) + '</dd>';
            });
        }
        html += '</dl>';

        if (d.id) {
            html += '<a href="member_edit.php?id=' + esc(d.id) + '" class="btn btn-outline-primary btn-sm w-100">Open full record →</a>';
        }
        return html;
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>