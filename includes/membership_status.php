<?php
/**
 * includes/membership_status.php
 *
 * Shared definition of "current" vs "inactive" members for a membership year.
 *
 * A member is current for year Y when:
 *   - membership_renewal_year = Y (signed up / renewed for that year)
 *   - not manually flagged inactive or suspended
 *
 * Life / complimentary membership and payment records do not override the
 * inactive flag — life members can be inactive like anyone else.
 *
 * Everyone else is inactive (for list filters, dashboard counts, and reports).
 */

/**
 * Calendar year used for "current membership" across the app.
 */
function membershipStatusYear(): int
{
    return (int) date('Y');
}

/**
 * SQL subquery: distinct member_ids with payment or fulfillment for a year.
 */
function renewedMemberIdsSql(): string
{
    return '
        SELECT DISTINCT member_id FROM payments
        WHERE year = ?
        UNION
        SELECT DISTINCT member_id FROM member_fulfillments
        WHERE year = ?
    ';
}

/**
 * Member IDs with a payment or fulfillment for the given year.
 *
 * @return int[]
 */
function renewedMemberIdsForYear(PDO $pdo, int $year): array
{
    static $cache = [];
    if (isset($cache[$year])) {
        return $cache[$year];
    }
    $stmt = $pdo->prepare('SELECT member_id FROM (' . renewedMemberIdsSql() . ') t');
    $stmt->execute([$year, $year]);
    $cache[$year] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    return $cache[$year];
}

/**
 * Whether a member has any recorded membership activity before a given year.
 * Used to detect applicants who claim "renewal" but have no club history on file.
 */
function member_has_prior_membership(PDO $pdo, int $memberId, ?int $beforeYear = null): bool
{
    if ($beforeYear !== null && $beforeYear > 0) {
        $stmt = $pdo->prepare('
            SELECT 1 FROM payments
            WHERE member_id = ? AND year < ?
            LIMIT 1
        ');
        $stmt->execute([$memberId, $beforeYear]);
        if ($stmt->fetchColumn()) {
            return true;
        }

        $stmt = $pdo->prepare('
            SELECT 1 FROM member_fulfillments
            WHERE member_id = ? AND year < ? AND processed_at IS NOT NULL
            LIMIT 1
        ');
        $stmt->execute([$memberId, $beforeYear]);
        if ($stmt->fetchColumn()) {
            return true;
        }

        $stmt = $pdo->prepare('
            SELECT 1 FROM member_membership_years
            WHERE member_id = ? AND year < ?
            LIMIT 1
        ');
        $stmt->execute([$memberId, $beforeYear]);
        return (bool) $stmt->fetchColumn();
    }

    $stmt = $pdo->prepare('SELECT 1 FROM payments WHERE member_id = ? LIMIT 1');
    $stmt->execute([$memberId]);
    if ($stmt->fetchColumn()) {
        return true;
    }

    $stmt = $pdo->prepare('
        SELECT 1 FROM member_fulfillments
        WHERE member_id = ? AND processed_at IS NOT NULL
        LIMIT 1
    ');
    $stmt->execute([$memberId]);
    if ($stmt->fetchColumn()) {
        return true;
    }

    $stmt = $pdo->prepare('SELECT 1 FROM member_membership_years WHERE member_id = ? LIMIT 1');
    $stmt->execute([$memberId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Column prefix for members table SQL (empty alias = unqualified columns).
 */
function memberSqlPrefix(string $alias): string
{
    return $alias === '' ? '' : $alias . '.';
}

/**
 * SQL fragment: member alias qualifies as current for $year.
 * Use with currentMemberWhereParams(). Pass alias '' for unaliased `FROM members` queries.
 */
function currentMemberWhereSql(string $alias = 'm', ?int $year = null): string
{
    $year = $year ?? membershipStatusYear();
    $c      = memberSqlPrefix($alias);

    return "(
        {$c}membership_renewal_year = ?
        AND ({$c}inactive = 0 OR {$c}inactive IS NULL)
        AND ({$c}suspended = 0 OR {$c}suspended IS NULL)
    )";
}

/**
 * Bound parameters for currentMemberWhereSql() (one year bind).
 *
 * @return int[]
 */
function currentMemberWhereParams(?int $year = null): array
{
    $year = $year ?? membershipStatusYear();

    return [$year];
}

/**
 * SQL fragment: member was current for prior year but not for $year.
 */
function notYetRenewedWhereSql(string $alias = 'm', ?int $year = null): string
{
    $year = $year ?? membershipStatusYear();

    return currentMemberWhereSql($alias, $year - 1) . ' AND NOT ' . currentMemberWhereSql($alias, $year);
}

/**
 * Bound parameters for notYetRenewedWhereSql().
 *
 * @return int[]
 */
function notYetRenewedWhereParams(?int $year = null): array
{
    $year = $year ?? membershipStatusYear();

    return array_merge(currentMemberWhereParams($year - 1), currentMemberWhereParams($year));
}

/**
 * Whether a member row is current for the given year.
 *
 * @param array<string, mixed> $member
 * @param int[]|null           $renewedIds  Unused; kept for call-site compatibility.
 */
function memberIsCurrent(array $member, ?int $year = null, ?array $renewedIds = null): bool
{
    $year = $year ?? membershipStatusYear();
    if (!empty($member['inactive']) || !empty($member['suspended'])) {
        return false;
    }

    return (int) ($member['membership_renewal_year'] ?? 0) === $year;
}

/**
 * Count current and inactive members for a year.
 *
 * @return array{current: int, inactive: int, total: int}
 */
function membershipStatusCounts(PDO $pdo, ?int $year = null): array
{
    $year = $year ?? membershipStatusYear();
    $where  = currentMemberWhereSql('m', $year);
    $params = currentMemberWhereParams($year);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM members m WHERE {$where}");
    $stmt->execute($params);
    $current = (int) $stmt->fetchColumn();

    $total = (int) $pdo->query('SELECT COUNT(*) FROM members')->fetchColumn();

    return [
        'current'  => $current,
        'inactive' => $total - $current,
        'total'    => $total,
    ];
}

/**
 * Allowed membership filter values for the members-list chips (mutually exclusive).
 * Active / Inactive partition on members.inactive so All = Active + Inactive.
 *
 * @return list<string>
 */
function membersListStatusFilterKeys(): array
{
    return ['all', 'active', 'inactive'];
}

/**
 * Status values accepted on members.php (chips plus dashboard "current members").
 *
 * @return list<string>
 */
function membersListAcceptedStatusFilters(): array
{
    return ['all', 'active', 'inactive', 'current'];
}

/**
 * Allowed stackable flag filters for the members list.
 *
 * @return list<string>
 */
function membersListFlagFilterKeys(): array
{
    return ['free', 'life', 'suspended'];
}

/**
 * SQL WHERE fragment and params for a members list membership filter.
 *
 * @return array{sql:string, params:array<int, mixed>}
 */
function memberStatusFilterWhereSql(string $statusFilter, ?int $year = null, string $alias = 'm'): array
{
    if ($statusFilter === 'all') {
        return ['sql' => '1=1', 'params' => []];
    }

    $c = memberSqlPrefix($alias === '' ? '' : $alias);

    if ($statusFilter === 'active') {
        return [
            'sql'    => "({$c}inactive = 0 OR {$c}inactive IS NULL)",
            'params' => [],
        ];
    }

    if ($statusFilter === 'inactive') {
        return [
            'sql'    => "({$c}inactive = 1)",
            'params' => [],
        ];
    }

    // Dashboard / deep links: paid-up current members for the membership year.
    if ($statusFilter === 'current') {
        $year = $year ?? membershipStatusYear();

        return [
            'sql'    => currentMemberWhereSql($alias === '' ? '' : $alias, $year),
            'params' => currentMemberWhereParams($year),
        ];
    }

    return ['sql' => '1=1', 'params' => []];
}

/**
 * SQL WHERE fragment and params for a single member flag filter.
 *
 * @return array{sql:string, params:array<int, mixed>}
 */
function memberFlagFilterWhereSql(string $flag, string $alias = 'm'): array
{
    $c = memberSqlPrefix($alias);

    return match ($flag) {
        'free'      => ['sql' => "({$c}free_membership = 1)", 'params' => []],
        'life'      => ['sql' => "({$c}life_member = 1)", 'params' => []],
        'suspended' => ['sql' => "({$c}suspended = 1)", 'params' => []],
        default     => ['sql' => '1=1', 'params' => []],
    };
}

/**
 * Combined membership + flag filters for the members list (AND logic).
 *
 * @param  list<string>  $flagFilters
 * @return array{sql:string, params:array<int, mixed>}
 */
function membersListCombinedFilterWhereSql(
    string $statusFilter,
    array $flagFilters,
    ?int $year = null,
    string $alias = 'm'
): array {
    $parts  = [];
    $params = [];

    if ($statusFilter !== 'all') {
        $statusWhere = memberStatusFilterWhereSql($statusFilter, $year, $alias);
        $parts[]     = $statusWhere['sql'];
        $params      = array_merge($params, $statusWhere['params']);
    }

    foreach ($flagFilters as $flag) {
        $flagWhere = memberFlagFilterWhereSql($flag, $alias);
        $parts[]   = $flagWhere['sql'];
        $params    = array_merge($params, $flagWhere['params']);
    }

    if ($parts === []) {
        return ['sql' => '1=1', 'params' => []];
    }

    return [
        'sql'    => implode(' AND ', array_map(static fn (string $p) => "($p)", $parts)),
        'params' => $params,
    ];
}

/**
 * Chip counts for membership filters on the members list.
 *
 * @return array{all:int, active:int, inactive:int}
 */
function membersListStatusChipCounts(PDO $pdo, ?int $year = null): array
{
    $total = (int) $pdo->query('SELECT COUNT(*) FROM members')->fetchColumn();
    $inactiveCount = (int) $pdo->query('SELECT COUNT(*) FROM members WHERE inactive = 1')->fetchColumn();

    return [
        'all'      => $total,
        'active'   => $total - $inactiveCount,
        'inactive' => $inactiveCount,
    ];
}

/**
 * Chip counts for each flag within a membership filter context (single flag only).
 *
 * @param  list<string>  $activeFlags  Currently selected flags (ignored for per-flag counts).
 * @return array<string, int>
 */
function membersListFlagChipCounts(
    PDO $pdo,
    string $statusFilter,
    array $activeFlags = [],
    ?int $year = null
): array {
    $year   = $year ?? membershipStatusYear();
    $counts = [];

    foreach (membersListFlagFilterKeys() as $flag) {
        $where = membersListCombinedFilterWhereSql($statusFilter, [$flag], $year, 'm');
        $stmt  = $pdo->prepare("SELECT COUNT(*) FROM members m WHERE {$where['sql']}");
        $stmt->execute($where['params']);
        $counts[$flag] = (int) $stmt->fetchColumn();
    }

    return $counts;
}

/**
 * Count current members for a year.
 */
function countCurrentMembers(PDO $pdo, ?int $year = null): int
{
    return membershipStatusCounts($pdo, $year)['current'];
}

/**
 * Distinct members with a payment or fulfillment for a given year.
 * Use for year-over-year comparisons — not countCurrentMembers(), because
 * members who renewed since then no longer have membership_renewal_year set to that year.
 */
function countMembersWithMembershipForYear(PDO $pdo, int $year): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM (' . renewedMemberIdsSql() . ') t');
    $stmt->execute([$year, $year]);

    return (int) $stmt->fetchColumn();
}

/**
 * Member counts grouped by membership_type_slot for list filters.
 *
 * @param  list<string>  $flagFilters
 * @return array<int, int>  slot => count
 */
function membershipTypeSlotCounts(
    PDO $pdo,
    string $statusFilter,
    array $flagFilters = [],
    ?int $year = null
): array {
    $year = $year ?? membershipStatusYear();
    $sql    = 'SELECT membership_type_slot, COUNT(*) AS cnt FROM members WHERE 1=1';
    $params = [];

    $where = membersListCombinedFilterWhereSql($statusFilter, $flagFilters, $year, '');
    $sql .= ' AND ' . $where['sql'];
    $params = $where['params'];

    $sql .= ' GROUP BY membership_type_slot';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $counts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $counts[(int) ($row['membership_type_slot'] ?? 0)] = (int) $row['cnt'];
    }

    return $counts;
}

/**
 * Type-slot counts for membership filter chips (plus current for dashboard deep links).
 *
 * @return array<string, array<int, int>>
 */
function membershipTypeSlotCountsByStatus(PDO $pdo, ?int $year = null): array
{
    $out = [];
    foreach (array_merge(membersListStatusFilterKeys(), ['current']) as $key) {
        $out[$key] = membershipTypeSlotCounts($pdo, $key, [], $year);
    }

    return $out;
}

// ── Frozen per-year membership (member_membership_years) ───────────────────

/**
 * Create member_membership_years on older databases if missing.
 */
function ensureMembershipYearsTable(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `member_membership_years` (
          `id` int unsigned NOT NULL AUTO_INCREMENT,
          `member_id` int unsigned NOT NULL,
          `year` smallint unsigned NOT NULL COMMENT 'Calendar year the member was current',
          `recorded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `source` varchar(32) NOT NULL DEFAULT 'renewal',
          PRIMARY KEY (`id`),
          UNIQUE KEY `member_year` (`member_id`, `year`),
          KEY `year_idx` (`year`),
          CONSTRAINT `mmy_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $done = true;
}

/**
 * Whether we have any recorded current members for a calendar year.
 */
function membershipYearHasSnapshot(PDO $pdo, int $year): bool
{
    ensureMembershipYearsTable($pdo);
    $stmt = $pdo->prepare('SELECT 1 FROM member_membership_years WHERE year = ? LIMIT 1');
    $stmt->execute([$year]);

    return (bool) $stmt->fetchColumn();
}

/**
 * Record that a member was current for a year (upsert).
 */
function recordMemberMembershipYear(PDO $pdo, int $memberId, int $year, string $source = 'renewal'): void
{
    ensureMembershipYearsTable($pdo);
    $stmt = $pdo->prepare('
        INSERT INTO member_membership_years (member_id, year, source)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE recorded_at = CURRENT_TIMESTAMP, source = VALUES(source)
    ');
    $stmt->execute([$memberId, $year, $source]);
}

/**
 * Remove a member from a year's historical roster.
 */
function removeMemberMembershipYear(PDO $pdo, int $memberId, int $year): void
{
    ensureMembershipYearsTable($pdo);
    $pdo->prepare('DELETE FROM member_membership_years WHERE member_id = ? AND year = ?')
        ->execute([$memberId, $year]);
}

/**
 * Sync member_membership_years for a member's membership_renewal_year from current rules.
 */
function syncMemberMembershipYearForMember(PDO $pdo, int $memberId): void
{
    $stmt = $pdo->prepare('
        SELECT id, membership_renewal_year, inactive, suspended, life_member, free_membership
        FROM members WHERE id = ?
    ');
    $stmt->execute([$memberId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        return;
    }
    $year = (int) ($member['membership_renewal_year'] ?? 0);
    if ($year < 1990 || $year > 2100) {
        return;
    }
    $renewedIds = renewedMemberIdsForYear($pdo, $year);
    if (memberIsCurrent($member, $year, $renewedIds)) {
        recordMemberMembershipYear($pdo, $memberId, $year, 'edit');
    } else {
        removeMemberMembershipYear($pdo, $memberId, $year);
    }
}

/**
 * Count members recorded as current for a year (frozen historical roster).
 */
function countRecordedMembersForYear(PDO $pdo, int $year): int
{
    ensureMembershipYearsTable($pdo);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM member_membership_years WHERE year = ?');
    $stmt->execute([$year]);

    return (int) $stmt->fetchColumn();
}

/**
 * Official member count for a year: snapshot table when present, else fallback.
 */
function countMembersForMembershipYear(PDO $pdo, int $year): int
{
    if (membershipYearHasSnapshot($pdo, $year)) {
        return countRecordedMembersForYear($pdo, $year);
    }
    if ($year === membershipStatusYear()) {
        return countCurrentMembers($pdo, $year);
    }

    return countMembersWithMembershipForYear($pdo, $year);
}

/**
 * SQL filter for report/export lists: members who count for a membership year.
 *
 * @return array{where: string, params: array<int, int>}
 */
function membershipYearReportFilter(PDO $pdo, string $alias, int $year): array
{
    ensureMembershipYearsTable($pdo);
    $p   = memberSqlPrefix($alias);
    $id  = $alias === '' ? 'members.id' : "{$alias}.id";

    if (membershipYearHasSnapshot($pdo, $year)) {
        return [
            'where'  => "{$p}id IN (SELECT member_id FROM member_membership_years WHERE year = ?)",
            'params' => [$year],
        ];
    }
    if ($year === membershipStatusYear()) {
        return [
            'where'  => currentMemberWhereSql($alias, $year),
            'params' => currentMemberWhereParams($year),
        ];
    }

    return [
        'where'  => "{$p}id IN (SELECT member_id FROM (" . renewedMemberIdsSql() . ") t)",
        'params' => [$year, $year],
    ];
}

/**
 * Members who were in the prior year's roster but not yet in this year's roster.
 *
 * @return array{where: string, params: array<int, int>}
 */
function notYetRenewedReportFilter(PDO $pdo, string $alias, int $year): array
{
    $prevYear = $year - 1;
    $p        = memberSqlPrefix($alias);

    if (membershipYearHasSnapshot($pdo, $prevYear) && membershipYearHasSnapshot($pdo, $year)) {
        return [
            'where'  => "{$p}id IN (SELECT member_id FROM member_membership_years WHERE year = ?)
                AND {$p}id NOT IN (SELECT member_id FROM member_membership_years WHERE year = ?)",
            'params' => [$prevYear, $year],
        ];
    }

    return [
        'where'  => notYetRenewedWhereSql($alias, $year),
        'params' => notYetRenewedWhereParams($year),
    ];
}

/**
 * Insert snapshot rows for one year (backfill / first-time capture).
 *
 * @return int  Rows inserted or updated
 */
function snapshotMembershipYear(PDO $pdo, int $year, string $source = 'backfill', bool $replace = false): int
{
    ensureMembershipYearsTable($pdo);
    if ($replace) {
        $pdo->prepare('DELETE FROM member_membership_years WHERE year = ?')->execute([$year]);
    }

    if ($year === membershipStatusYear()) {
        $where  = currentMemberWhereSql('m', $year);
        $params = array_merge([$year, $source], currentMemberWhereParams($year));
        $sql    = "INSERT IGNORE INTO member_membership_years (member_id, year, source)
            SELECT m.id, ?, ? FROM members m WHERE {$where}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    $sql = 'INSERT IGNORE INTO member_membership_years (member_id, year, source)
        SELECT DISTINCT t.member_id, ?, ?
        FROM (' . renewedMemberIdsSql() . ') t';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$year, $source, $year, $year]);

    return $stmt->rowCount();
}

/**
 * SQL fragment: member badge not printed for the given membership year.
 */
function badgeUnprintedWhereSql(string $alias): string
{
    $p = $alias !== '' ? $alias . '.' : '';

    return "({$p}badge_printed_at IS NULL OR YEAR({$p}badge_printed_at) < ?)";
}

/** @return array{0:int} */
function badgeUnprintedWhereParams(int $year): array
{
    return [$year];
}

/**
 * SQL fragment: member has a recorded fulfillment for the year but card or mailer not printed.
 *
 * When $memberAlias is empty (unaliased FROM members), qualify as members.id —
 * bare "id" is ambiguous inside the EXISTS and binds to member_fulfillments.id.
 */
function fulfillmentPendingWhereSql(string $memberAlias = 'm'): string
{
    $memberId = $memberAlias !== '' ? $memberAlias . '.id' : 'members.id';

    return "EXISTS (
        SELECT 1 FROM member_fulfillments f
        WHERE f.member_id = {$memberId}
          AND f.year = ?
          AND f.processed_at IS NOT NULL
          AND (f.card_printed_at IS NULL OR f.mailer_printed_at IS NULL)
    )";
}

/** @return array{0:int} */
function fulfillmentPendingWhereParams(int $year): array
{
    return [$year];
}

/**
 * Members with signup/renewal recorded for a year but fulfillment tasks still open.
 */
function countRecordedUnfulfilled(PDO $pdo, int $year): int
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS cnt FROM members m WHERE ' . fulfillmentPendingWhereSql('m')
        );
        $stmt->execute(fulfillmentPendingWhereParams($year));

        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * SQL + params for birthdays in the current calendar week (Mon–Sun).
 *
 * @return array{sql:string, params:array<int, string>}
 */
function birthdayThisWeekWhereParts(string $alias): array
{
    $p       = $alias !== '' ? $alias . '.' : '';
    $startMd = date('m-d', strtotime('monday this week'));
    $endMd   = date('m-d', strtotime('sunday this week'));

    if ($startMd <= $endMd) {
        return [
            'sql'    => "{$p}birthday IS NOT NULL AND DATE_FORMAT({$p}birthday, '%m-%d') BETWEEN ? AND ?",
            'params' => [$startMd, $endMd],
        ];
    }

    return [
        'sql'    => "{$p}birthday IS NOT NULL AND (DATE_FORMAT({$p}birthday, '%m-%d') >= ? OR DATE_FORMAT({$p}birthday, '%m-%d') <= ?)",
        'params' => [$startMd, $endMd],
    ];
}
