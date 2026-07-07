<?php
/**
 * includes/members_list_query.php
 *
 * Filter/pagination query builder for members.php.
 */

declare(strict_types=1);

/**
 * @return array<string, array{main:string,search:string}>
 */
function members_list_order_by_map(): array
{
    return [
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
}

/**
 * @return array{
 *   searchQ:string,
 *   perPage:int,
 *   page:int,
 *   memberTypeFilter:string,
 *   memberTypeSlotFilter:?int,
 *   statusFilter:string,
 *   badgeFilter:string,
 *   sort:string
 * }
 */
function members_list_parse_request(array $get): array
{
    $searchQ = trim((string) ($get['q'] ?? ''));

    $perPage = isset($get['per']) ? (int) $get['per'] : 25;
    if (!in_array($perPage, [25, 50, 100, 0], true)) {
        $perPage = 25;
    }

    $page = max(1, (int) ($get['page'] ?? 1));

    $memberTypeFilter = (string) ($get['member_type'] ?? '');
    $memberTypeSlotFilter = null;
    if ($memberTypeFilter !== '') {
        $slot = is_numeric($memberTypeFilter) ? (int) $memberTypeFilter : 0;
        $memberTypeSlotFilter = ($slot >= 1 && $slot <= 4) ? $slot : null;
        if ($memberTypeSlotFilter === null) {
            $memberTypeFilter = '';
        }
    }

    $statusFilter = (string) ($get['status'] ?? 'current');
    if ($statusFilter === 'active') {
        $statusFilter = 'current';
    }
    if (!in_array($statusFilter, ['all', 'current', 'inactive'], true)) {
        $statusFilter = 'current';
    }

    $orderByMap = members_list_order_by_map();
    $sort = (string) ($get['sort'] ?? 'name');
    if (!array_key_exists($sort, $orderByMap)) {
        $sort = 'name';
    }

    $badgeFilter = (string) ($get['badge'] ?? '');
    if ($badgeFilter !== 'unprinted') {
        $badgeFilter = '';
    }

    return [
        'searchQ'              => $searchQ,
        'perPage'              => $perPage,
        'page'                 => $page,
        'memberTypeFilter'     => $memberTypeFilter,
        'memberTypeSlotFilter' => $memberTypeSlotFilter,
        'statusFilter'         => $statusFilter,
        'badgeFilter'          => $badgeFilter,
        'sort'                 => $sort,
    ];
}

/**
 * Run list query with filters; returns rows and pagination metadata.
 *
 * @return array{
 *   members: array<int, array>,
 *   totalCount: int,
 *   chipCounts: array{current:int,inactive:int},
 *   typeCounts: array<int, int>,
 *   typeCountsByStatus: array,
 *   totalPages: int,
 *   from: int,
 *   to: int,
 *   queryParams: array<string, mixed>
 * }
 */
function members_list_fetch(PDO $pdo, array $filters, int $currentYear): array
{
    $searchQ              = $filters['searchQ'];
    $perPage              = $filters['perPage'];
    $page                 = $filters['page'];
    $memberTypeSlotFilter = $filters['memberTypeSlotFilter'];
    $statusFilter         = $filters['statusFilter'];
    $badgeFilter          = $filters['badgeFilter'];
    $sort                 = $filters['sort'];

    $orderByMap    = members_list_order_by_map();
    $orderBy       = $orderByMap[$sort]['main'];
    $orderBySearch = $orderByMap[$sort]['search'];

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
        if ($statusFilter === 'current') {
            $baseSql  .= ' AND ' . currentMemberWhereSql('', $currentYear);
            $countSql .= ' AND ' . currentMemberWhereSql('', $currentYear);
            $params    = array_merge($params, currentMemberWhereParams($currentYear));
        } elseif ($statusFilter === 'inactive') {
            $baseSql  .= ' AND NOT ' . currentMemberWhereSql('', $currentYear);
            $countSql .= ' AND NOT ' . currentMemberWhereSql('', $currentYear);
            $params    = array_merge($params, currentMemberWhereParams($currentYear));
        }
        if ($badgeFilter === 'unprinted') {
            $baseSql  .= ' AND ' . badgeUnprintedWhereSql('');
            $countSql .= ' AND ' . badgeUnprintedWhereSql('');
            $params    = array_merge($params, badgeUnprintedWhereParams($currentYear));
        }
        $baseSql .= " $orderBy";
    } else {
        $tokens = array_filter(array_map('trim', preg_split('/[\s,]+/', $searchQ) ?: []));
        if ($tokens === []) {
            $tokens = [$searchQ];
        }

        $likeClause = '(
        m.first_name LIKE ? OR m.last_name LIKE ? OR m.email LIKE ? OR m.title LIKE ?
        OR m.notes LIKE ? OR CAST(m.membership_type_slot AS CHAR) LIKE ? OR m.gate_key_number LIKE ?
        OR m.ama_number LIKE ? OR m.faa_number LIKE ?
        OR CAST(m.membership_renewal_year AS CHAR) LIKE ?
        OR m.phone LIKE ? OR m.address_street LIKE ? OR m.address_street2 LIKE ?
        OR m.address_city LIKE ? OR m.address_state LIKE ? OR m.address_postal_code LIKE ?
    )';

        $tokenConditions = implode(' AND ', array_fill(0, count($tokens), $likeClause));

        $baseSql = 'SELECT DISTINCT ' . implode(', ', array_map(
            static fn (string $c) => 'm.' . trim($c),
            explode(',', $selectCols)
        )) . "
        FROM members m
        WHERE $tokenConditions";

        $countSql = "SELECT COUNT(DISTINCT m.id)
        FROM members m
        WHERE $tokenConditions";

        if ($memberTypeSlotFilter !== null) {
            $baseSql  .= ' AND m.membership_type_slot = ?';
            $countSql .= ' AND m.membership_type_slot = ?';
        }
        if ($statusFilter === 'current') {
            $baseSql  .= ' AND ' . currentMemberWhereSql('m', $currentYear);
            $countSql .= ' AND ' . currentMemberWhereSql('m', $currentYear);
        } elseif ($statusFilter === 'inactive') {
            $baseSql  .= ' AND NOT ' . currentMemberWhereSql('m', $currentYear);
            $countSql .= ' AND NOT ' . currentMemberWhereSql('m', $currentYear);
        }
        if ($badgeFilter === 'unprinted') {
            $baseSql  .= ' AND ' . badgeUnprintedWhereSql('m');
            $countSql .= ' AND ' . badgeUnprintedWhereSql('m');
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
        if ($statusFilter === 'current' || $statusFilter === 'inactive') {
            $params = array_merge($params, currentMemberWhereParams($currentYear));
        }
        if ($badgeFilter === 'unprinted') {
            $params = array_merge($params, badgeUnprintedWhereParams($currentYear));
        }
    }

    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $totalCount = (int) $stmt->fetchColumn();

    $statusCounts       = membershipStatusCounts($pdo, $currentYear);
    $typeCountsByStatus = membershipTypeSlotCountsByStatus($pdo, $currentYear);
    $typeCounts         = $typeCountsByStatus[$statusFilter] ?? [];

    if ($perPage > 0) {
        $offset   = ($page - 1) * $perPage;
        $baseSql .= ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
    }

    $stmt = $pdo->prepare($baseSql);
    $stmt->execute($params);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalPages = $perPage > 0 ? max(1, (int) ceil($totalCount / $perPage)) : 1;
    $from       = $totalCount === 0 ? 0 : ($perPage > 0 ? ($page - 1) * $perPage + 1 : 1);
    $to         = $perPage === 0 ? $totalCount : min($page * $perPage, $totalCount);

    $queryParams = array_filter([
        'q'           => $searchQ !== '' ? $searchQ : null,
        'per'         => $perPage !== 25 ? $perPage : null,
        'member_type' => $filters['memberTypeFilter'] !== '' ? $filters['memberTypeFilter'] : null,
        'status'      => $statusFilter,
        'badge'       => $badgeFilter !== '' ? $badgeFilter : null,
        'sort'        => $sort !== 'name' ? $sort : null,
    ], static fn ($v) => $v !== null);

    return [
        'members'            => $members,
        'totalCount'         => $totalCount,
        'chipCounts'         => [
            'current'  => $statusCounts['current'],
            'inactive' => $statusCounts['inactive'],
        ],
        'typeCounts'         => $typeCounts,
        'typeCountsByStatus' => $typeCountsByStatus,
        'totalPages'         => $totalPages,
        'from'               => $from,
        'to'                 => $to,
        'queryParams'        => $queryParams,
    ];
}
