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
 * Parse stackable flag filters from a request.
 *
 * @return list<string>
 */
function members_list_parse_flag_filters(array $get): array
{
    $raw = $get['flag'] ?? [];
    if (!is_array($raw)) {
        $raw = trim((string) $raw) === '' ? [] : [trim((string) $raw)];
    }

    $allowed = array_fill_keys(membersListFlagFilterKeys(), true);
    $flags   = [];
    foreach ($raw as $flag) {
        $flag = trim((string) $flag);
        if ($flag !== '' && isset($allowed[$flag]) && !in_array($flag, $flags, true)) {
            $flags[] = $flag;
        }
    }
    sort($flags);

    return $flags;
}

/**
 * @return array{
 *   searchQ:string,
 *   perPage:int,
 *   page:int,
 *   memberTypeFilter:string,
 *   memberTypeSlotFilter:?int,
 *   statusFilter:string,
 *   flagFilters:array<int, string>,
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

    $statusFilter = (string) ($get['status'] ?? 'active');
    if ($statusFilter === 'archived') {
        // Legacy URL: archived flag-as-status → Inactive chip
        $statusFilter = 'inactive';
    }

    $flagFilters = members_list_parse_flag_filters($get);

    // Legacy URLs: status=suspended|free|life → all + flag
    $legacyFlagMap = [
        'suspended' => 'suspended',
        'free'      => 'free',
        'life'      => 'life',
    ];
    if (isset($legacyFlagMap[$statusFilter])) {
        if (!in_array($legacyFlagMap[$statusFilter], $flagFilters, true)) {
            $flagFilters[] = $legacyFlagMap[$statusFilter];
        }
        sort($flagFilters);
        $statusFilter = 'all';
    } elseif (!in_array($statusFilter, membersListAcceptedStatusFilters(), true)) {
        $statusFilter = 'active';
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

    $fulfillmentFilter = (string) ($get['fulfillment'] ?? '');
    if ($fulfillmentFilter !== 'pending') {
        $fulfillmentFilter = '';
    }

    return [
        'searchQ'              => $searchQ,
        'perPage'              => $perPage,
        'page'                 => $page,
        'memberTypeFilter'     => $memberTypeFilter,
        'memberTypeSlotFilter' => $memberTypeSlotFilter,
        'statusFilter'         => $statusFilter,
        'flagFilters'          => $flagFilters,
        'badgeFilter'          => $badgeFilter,
        'fulfillmentFilter'    => $fulfillmentFilter,
        'sort'                 => $sort,
    ];
}

/**
 * Run list query with filters; returns rows and pagination metadata.
 *
 * @return array{
 *   members: array<int, array>,
 *   totalCount: int,
 *   chipCounts: array{all:int,active:int,inactive:int},
 *   flagChipCounts: array<string, int>,
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
    $flagFilters          = $filters['flagFilters'];
    $badgeFilter          = $filters['badgeFilter'];
    $fulfillmentFilter    = $filters['fulfillmentFilter'];
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
        if ($statusFilter !== 'all' || $flagFilters !== []) {
            $filterWhere = membersListCombinedFilterWhereSql($statusFilter, $flagFilters, $currentYear, '');
            $baseSql  .= ' AND ' . $filterWhere['sql'];
            $countSql .= ' AND ' . $filterWhere['sql'];
            $params    = array_merge($params, $filterWhere['params']);
        }
        if ($badgeFilter === 'unprinted') {
            $baseSql  .= ' AND ' . badgeUnprintedWhereSql('');
            $countSql .= ' AND ' . badgeUnprintedWhereSql('');
            $params    = array_merge($params, badgeUnprintedWhereParams($currentYear));
        }
        if ($fulfillmentFilter === 'pending') {
            $baseSql  .= ' AND ' . fulfillmentPendingWhereSql('');
            $countSql .= ' AND ' . fulfillmentPendingWhereSql('');
            $params    = array_merge($params, fulfillmentPendingWhereParams($currentYear));
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
        if ($statusFilter !== 'all' || $flagFilters !== []) {
            $filterWhere = membersListCombinedFilterWhereSql($statusFilter, $flagFilters, $currentYear, 'm');
            $baseSql  .= ' AND ' . $filterWhere['sql'];
            $countSql .= ' AND ' . $filterWhere['sql'];
        }
        if ($badgeFilter === 'unprinted') {
            $baseSql  .= ' AND ' . badgeUnprintedWhereSql('m');
            $countSql .= ' AND ' . badgeUnprintedWhereSql('m');
        }
        if ($fulfillmentFilter === 'pending') {
            $baseSql  .= ' AND ' . fulfillmentPendingWhereSql('m');
            $countSql .= ' AND ' . fulfillmentPendingWhereSql('m');
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
        if ($statusFilter !== 'all' || $flagFilters !== []) {
            $filterWhere = membersListCombinedFilterWhereSql($statusFilter, $flagFilters, $currentYear, 'm');
            $params = array_merge($params, $filterWhere['params']);
        }
        if ($badgeFilter === 'unprinted') {
            $params = array_merge($params, badgeUnprintedWhereParams($currentYear));
        }
        if ($fulfillmentFilter === 'pending') {
            $params = array_merge($params, fulfillmentPendingWhereParams($currentYear));
        }
    }

    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $totalCount = (int) $stmt->fetchColumn();

    $typeCountsByStatus = $flagFilters === []
        ? membershipTypeSlotCountsByStatus($pdo, $currentYear)
        : [];
    $typeCounts         = membershipTypeSlotCounts($pdo, $statusFilter, $flagFilters, $currentYear);
    $chipCounts         = membersListStatusChipCounts($pdo, $currentYear);
    $flagChipCounts     = membersListFlagChipCounts($pdo, $statusFilter, $flagFilters, $currentYear);

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
        'flag'        => $flagFilters !== [] ? $flagFilters : null,
        'badge'       => $badgeFilter !== '' ? $badgeFilter : null,
        'fulfillment' => $fulfillmentFilter !== '' ? $fulfillmentFilter : null,
        'sort'        => $sort !== 'name' ? $sort : null,
    ], static fn ($v) => $v !== null && $v !== []);

    return [
        'members'            => $members,
        'totalCount'         => $totalCount,
        'chipCounts'         => $chipCounts,
        'flagChipCounts'     => $flagChipCounts,
        'typeCounts'         => $typeCounts,
        'typeCountsByStatus' => $typeCountsByStatus,
        'totalPages'         => $totalPages,
        'from'               => $from,
        'to'                 => $to,
        'queryParams'        => $queryParams,
    ];
}
