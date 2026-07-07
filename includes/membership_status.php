<?php
/**
 * includes/membership_status.php
 *
 * Shared definition of "current" vs "inactive" members for a membership year.
 *
 * A member is current for year Y when:
 *   - membership_renewal_year = Y (signed up / renewed for that year)
 *   - not manually flagged inactive or suspended
 *   - and either life_member, free_membership (complimentary), or a
 *     payment or fulfillment record for year Y
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
    // Must qualify member id — unqualified `id` inside EXISTS binds to payments.id, not the member.
    $idCol  = $alias === '' ? 'members.id' : "{$alias}.id";

    return "(
        {$c}membership_renewal_year = ?
        AND ({$c}inactive = 0 OR {$c}inactive IS NULL)
        AND ({$c}suspended = 0 OR {$c}suspended IS NULL)
        AND (
            {$c}life_member = 1
            OR {$c}free_membership = 1
            OR EXISTS (
                SELECT 1 FROM payments p
                WHERE p.member_id = {$idCol} AND p.year = ?
            )
            OR EXISTS (
                SELECT 1 FROM member_fulfillments f
                WHERE f.member_id = {$idCol} AND f.year = ?
            )
        )
    )";
}

/**
 * Bound parameters for currentMemberWhereSql() (three copies of $year).
 *
 * @return int[]
 */
function currentMemberWhereParams(?int $year = null): array
{
    $year = $year ?? membershipStatusYear();

    return [$year, $year, $year];
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
 * Whether a member row is current for the given year (uses preloaded renewed IDs when provided).
 */
function memberIsCurrent(array $member, ?int $year = null, ?array $renewedIds = null): bool
{
    $year = $year ?? membershipStatusYear();
    if (!empty($member['inactive']) || !empty($member['suspended'])) {
        return false;
    }
    if ((int) ($member['membership_renewal_year'] ?? 0) !== $year) {
        return false;
    }
    if (!empty($member['life_member']) || !empty($member['free_membership'])) {
        return true;
    }
    $id = (int) ($member['id'] ?? 0);
    if ($renewedIds === null) {
        return false;
    }

    return in_array($id, $renewedIds, true);
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
 * Member counts grouped by membership_type_slot for a list status filter.
 *
 * @param  string  $statusFilter  One of: all, current, inactive
 * @return array<int, int>  slot => count
 */
function membershipTypeSlotCounts(PDO $pdo, string $statusFilter, ?int $year = null): array
{
    $year = $year ?? membershipStatusYear();
    $sql    = 'SELECT membership_type_slot, COUNT(*) AS cnt FROM members WHERE 1=1';
    $params = [];

    if ($statusFilter === 'current') {
        $sql .= ' AND ' . currentMemberWhereSql('', $year);
        $params = currentMemberWhereParams($year);
    } elseif ($statusFilter === 'inactive') {
        $sql .= ' AND NOT ' . currentMemberWhereSql('', $year);
        $params = currentMemberWhereParams($year);
    }

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
 * Type-slot counts for all, current, and inactive (for filter chips).
 *
 * @return array{all: array<int, int>, current: array<int, int>, inactive: array<int, int>}
 */
function membershipTypeSlotCountsByStatus(PDO $pdo, ?int $year = null): array
{
    return [
        'all'      => membershipTypeSlotCounts($pdo, 'all', $year),
        'current'  => membershipTypeSlotCounts($pdo, 'current', $year),
        'inactive' => membershipTypeSlotCounts($pdo, 'inactive', $year),
    ];
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
