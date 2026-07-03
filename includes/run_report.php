<?php
/**
 * includes/run_report.php
 *
 * Report engine for the Reports module. Each report is a small builder that
 * returns a uniform structure the page (or CSV export) can render without
 * knowing anything about the underlying SQL:
 *
 *   [
 *     'slug'        => string,
 *     'title'       => string,
 *     'description' => string,
 *     'columns'     => [ ['key'=>'year','label'=>'Year','format'=>'text','align'=>'start'], ... ],
 *     'rows'        => [ ['year'=>2026, 'members'=>50, ...], ... ],   // keyed by column key
 *     'totals'      => ['year'=>'Total', 'dues'=>1234.0, ...] | null, // optional summary row
 *     'note'        => string | null,
 *   ]
 *
 * All membership counts go through includes/membership_status.php so reports
 * stay consistent with the dashboard and the frozen per-year roster.
 *
 * Cell formats: text | year | int | money | signed (delta with +/-).
 */

require_once __DIR__ . '/membership_status.php';
require_once __DIR__ . '/member_completeness.php';

/**
 * Registry of available reports, in display order.
 *
 * @return array<string, array{label:string, description:string, year:bool}>
 */
function reportRegistry(): array
{
    return [
        'membership_by_year' => [
            'label'       => 'Membership by year',
            'description' => 'Current members per calendar year, with year-over-year change.',
            'year'        => false,
        ],
        'retention_churn' => [
            'label'       => 'Retention & churn',
            'description' => 'Retained, new, and lapsed members year over year.',
            'year'        => false,
        ],
        'membership_type_mix' => [
            'label'       => 'Membership type mix',
            'description' => 'Members by membership type for a selected year.',
            'year'        => true,
        ],
        'not_yet_renewed' => [
            'label'       => 'Not yet renewed',
            'description' => "Members who were current the prior year but haven't renewed for the selected year.",
            'year'        => true,
            'cohort'      => true,
        ],
        'revenue_by_year' => [
            'label'       => 'Revenue by year',
            'description' => 'Dues, initiation, and late fees collected per membership year.',
            'year'        => false,
        ],
        'compliance' => [
            'label'       => 'AMA/FAA compliance',
            'description' => 'Current members with credentials expired or expiring within 60 days.',
            'year'        => false,
        ],
        'data_completeness' => [
            'label'       => 'Missing member data',
            'description' => 'Current members with incomplete contact, emergency, compliance, or membership fields.',
            'year'        => false,
            'cohort'      => true,
        ],
    ];
}

/**
 * Whether a report takes a year parameter (shows a year picker on the page).
 */
function reportNeedsYear(string $slug): bool
{
    return !empty(reportRegistry()[$slug]['year'] ?? false);
}

/**
 * Whether a report is a member cohort that supports "email these members".
 */
function reportSupportsCohortEmail(string $slug): bool
{
    return !empty(reportRegistry()[$slug]['cohort'] ?? false);
}

/**
 * Mailable recipients for a cohort report: members with a non-empty email.
 *
 * @return array<int, array{id:int, first_name:string, last_name:string, email:string}>
 */
function reportCohortRecipients(PDO $pdo, string $slug, int $year): array
{
    if (!reportSupportsCohortEmail($slug)) {
        return [];
    }

    if ($slug === 'not_yet_renewed') {
        $filter = notYetRenewedReportFilter($pdo, 'm', $year);
        $sql = "SELECT m.id, m.first_name, m.last_name, m.email
                FROM members m
                WHERE {$filter['where']}
                  AND m.email IS NOT NULL AND TRIM(m.email) != ''
                ORDER BY m.last_name, m.first_name";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($filter['params']);

        $out = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'id'         => (int) $r['id'],
                'first_name' => (string) $r['first_name'],
                'last_name'  => (string) $r['last_name'],
                'email'      => (string) $r['email'],
            ];
        }

        return $out;
    }

    if ($slug === 'data_completeness') {
        $current = membershipStatusYear();
        $where   = currentMemberWhereSql('m', $current);
        $sql     = 'SELECT ' . memberCompletenessSelectSql('m') . "
                  FROM members m
                  WHERE {$where}
                  ORDER BY m.last_name, m.first_name";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(currentMemberWhereParams($current));

        $out = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (memberCompletenessMissingFields($r) === []) {
                continue;
            }
            if (trim((string) ($r['email'] ?? '')) === '') {
                continue;
            }
            $out[] = [
                'id'         => (int) $r['id'],
                'first_name' => (string) $r['first_name'],
                'last_name'  => (string) $r['last_name'],
                'email'      => (string) $r['email'],
            ];
        }

        return $out;
    }

    return [];
}

/**
 * Whether a slug is a known report.
 */
function reportExists(string $slug): bool
{
    return array_key_exists($slug, reportRegistry());
}

/**
 * Default report slug (first in the registry).
 */
function reportDefaultSlug(): string
{
    return (string) array_key_first(reportRegistry());
}

/**
 * Earliest year that has any reportable data, falling back to the current year.
 */
function reportEarliestYear(PDO $pdo): int
{
    $current = membershipStatusYear();
    ensureMembershipYearsTable($pdo);
    try {
        $stmt = $pdo->query('
            SELECT MIN(y) AS min_y FROM (
                SELECT year AS y FROM member_membership_years
                UNION
                SELECT year AS y FROM payments
            ) t
        ');
        $min = (int) ($stmt ? $stmt->fetchColumn() : 0);
    } catch (Throwable $e) {
        $min = 0;
    }
    if ($min < 1990 || $min > $current) {
        $min = $current;
    }

    return $min;
}

/**
 * Highest year a report can target. During the renewal pre-book window this is
 * next calendar year (so "not yet renewed" can look ahead), otherwise it is the
 * current calendar year. Mirrors defaultRenewalYear() used by the renewal desk.
 */
function reportMaxSelectableYear(PDO $pdo): int
{
    return max(membershipStatusYear(), defaultRenewalYear($pdo));
}

/**
 * Default target year for a year-based report.
 *
 * - not_yet_renewed: the working renewal year (rolls to next year in Oct–Dec by
 *   default), so the report tracks the renewals staff are actually collecting.
 * - everything else: the current calendar year.
 */
function reportDefaultYear(PDO $pdo, string $slug): int
{
    if ($slug === 'not_yet_renewed') {
        return defaultRenewalYear($pdo);
    }

    return membershipStatusYear();
}

/**
 * First membership year with complete, trustworthy data (configurable). Reports
 * flag earlier years as reconstructed/approximate.
 */
function reportsAccurateFromYear(PDO $pdo): int
{
    require_once __DIR__ . '/installation_config.php';
    return reports_accurate_from_year($pdo);
}

/**
 * Attach a data-accuracy caveat to a report when it surfaces any year before the
 * club's "complete data" threshold. Stored as a distinct 'accuracy_note' key so
 * the page can show it as a prominent banner while exports render it as a
 * footnote. Kept in one place so every output stays consistent. Compliance is
 * current-state only and is left untouched.
 *
 * @param  array<string, mixed>  $report
 * @return array<string, mixed>
 */
function reportAppendAccuracyNote(PDO $pdo, string $slug, int $year, array $report): array
{
    $report['accuracy_note'] = null;

    if ($slug === 'compliance') {
        return $report;
    }

    $threshold = reportsAccurateFromYear($pdo);

    // Determine the earliest year the report actually exposes.
    $minYear   = null;
    $hasYearCol = false;
    foreach (($report['columns'] ?? []) as $col) {
        if (($col['key'] ?? '') === 'year') {
            $hasYearCol = true;
            break;
        }
    }
    if ($hasYearCol) {
        foreach (($report['rows'] ?? []) as $row) {
            if (isset($row['year']) && is_numeric($row['year'])) {
                $y = (int) $row['year'];
                $minYear = $minYear === null ? $y : min($minYear, $y);
            }
        }
    } else {
        // Single-year reports. "Not yet renewed" leans on the prior year's roster.
        $minYear = $slug === 'not_yet_renewed' ? $year - 1 : $year;
    }

    if ($minYear === null || $minYear >= $threshold) {
        return $report;
    }

    $report['accuracy_note'] = 'The club began tracking complete membership history in '
        . $threshold . '. Figures for earlier years are reconstructed from available '
        . 'payment records and may undercount members or revenue.';

    return $report;
}

/**
 * Run a report by slug. Returns the uniform structure described above.
 *
 * @param  array<string, mixed>  $params  Reserved for per-report options (e.g. year).
 * @return array<string, mixed>
 */
function runReport(PDO $pdo, string $slug, array $params = []): array
{
    $year = (int) ($params['year'] ?? membershipStatusYear());

    $report = match ($slug) {
        'membership_by_year'  => reportMembershipByYear($pdo),
        'retention_churn'     => reportRetentionChurn($pdo),
        'membership_type_mix' => reportMembershipTypeMix($pdo, $year),
        'not_yet_renewed'     => reportNotYetRenewed($pdo, $year),
        'revenue_by_year'     => reportRevenueByYear($pdo),
        'compliance'          => reportCompliance($pdo),
        'data_completeness'   => reportDataCompleteness($pdo),
        default               => throw new InvalidArgumentException('Unknown report: ' . $slug),
    };

    return reportAppendAccuracyNote($pdo, $slug, $year, $report);
}

/**
 * Membership type labels for all four slots (including disabled, e.g. legacy
 * "Senior"), keyed by slot number.
 *
 * @return array<int, string>
 */
function reportTypeLabels(PDO $pdo): array
{
    $out = [];
    foreach (membershipTypeSlots($pdo) as $slot) {
        $out[(int) $slot['slot']] = (string) $slot['label'];
    }

    return $out;
}

/**
 * Member IDs counted for a year, mirroring countMembersForMembershipYear():
 * frozen snapshot when present, else live rules (current year) or payment/
 * fulfillment history (past years).
 *
 * @return int[]
 */
function reportMemberIdsForYear(PDO $pdo, int $year): array
{
    ensureMembershipYearsTable($pdo);
    if (membershipYearHasSnapshot($pdo, $year)) {
        $stmt = $pdo->prepare('SELECT member_id FROM member_membership_years WHERE year = ?');
        $stmt->execute([$year]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    if ($year === membershipStatusYear()) {
        $where = currentMemberWhereSql('m', $year);
        $stmt  = $pdo->prepare("SELECT m.id FROM members m WHERE {$where}");
        $stmt->execute(currentMemberWhereParams($year));

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    return renewedMemberIdsForYear($pdo, $year);
}

/**
 * Membership counts per calendar year (most recent first) with YoY change.
 *
 * @return array<string, mixed>
 */
function reportMembershipByYear(PDO $pdo): array
{
    $meta    = reportRegistry()['membership_by_year'];
    $current = membershipStatusYear();
    $start   = reportEarliestYear($pdo);

    // Count per year ascending so we can compute change vs the prior year,
    // then present most-recent-first.
    $counts = [];
    for ($y = $start; $y <= $current; $y++) {
        $counts[$y] = countMembersForMembershipYear($pdo, $y);
    }

    $rows = [];
    for ($y = $current; $y >= $start; $y--) {
        $prior  = $counts[$y - 1] ?? null;
        $change = $prior === null ? null : ($counts[$y] - $prior);
        $rows[] = [
            'year'    => $y,
            'members' => $counts[$y],
            'change'  => $change,
        ];
    }

    return [
        'slug'        => 'membership_by_year',
        'title'       => $meta['label'],
        'description' => $meta['description'],
        'columns'     => [
            ['key' => 'year',    'label' => 'Year',          'format' => 'year',   'align' => 'start'],
            ['key' => 'members', 'label' => 'Members',       'format' => 'int',    'align' => 'end'],
            ['key' => 'change',  'label' => 'Change vs prior', 'format' => 'signed', 'align' => 'end'],
        ],
        'rows'   => $rows,
        'totals' => null,
        'note'   => 'Past years use the frozen membership roster; the current year uses live current-member rules.',
    ];
}

/**
 * Dues / initiation / late-fee revenue per membership year (most recent first).
 *
 * @return array<string, mixed>
 */
function reportRevenueByYear(PDO $pdo): array
{
    $meta = reportRegistry()['revenue_by_year'];

    $stmt = $pdo->query('
        SELECT
            year,
            COUNT(*)                  AS payments,
            SUM(amount_dues)          AS dues,
            SUM(amount_initiation)    AS initiation,
            SUM(amount_late_fee)      AS late_fee,
            SUM(amount_dues + amount_initiation + amount_late_fee) AS total
        FROM payments
        GROUP BY year
        ORDER BY year DESC
    ');

    $rows = [];
    $tPayments = 0;
    $tDues = $tInit = $tLate = $tTotal = 0.0;
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = [
            'year'       => (int) $r['year'],
            'payments'   => (int) $r['payments'],
            'dues'       => (float) $r['dues'],
            'initiation' => (float) $r['initiation'],
            'late_fee'   => (float) $r['late_fee'],
            'total'      => (float) $r['total'],
        ];
        $tPayments += (int) $r['payments'];
        $tDues     += (float) $r['dues'];
        $tInit     += (float) $r['initiation'];
        $tLate     += (float) $r['late_fee'];
        $tTotal    += (float) $r['total'];
    }

    return [
        'slug'        => 'revenue_by_year',
        'title'       => $meta['label'],
        'description' => $meta['description'],
        'columns'     => [
            ['key' => 'year',       'label' => 'Year',       'format' => 'year',  'align' => 'start'],
            ['key' => 'payments',   'label' => 'Payments',   'format' => 'int',   'align' => 'end'],
            ['key' => 'dues',       'label' => 'Dues',       'format' => 'money', 'align' => 'end'],
            ['key' => 'initiation', 'label' => 'Initiation', 'format' => 'money', 'align' => 'end'],
            ['key' => 'late_fee',   'label' => 'Late fees',  'format' => 'money', 'align' => 'end'],
            ['key' => 'total',      'label' => 'Total',      'format' => 'money', 'align' => 'end'],
        ],
        'rows'   => $rows,
        'totals' => [
            'year'       => 'All years',
            'payments'   => $tPayments,
            'dues'       => $tDues,
            'initiation' => $tInit,
            'late_fee'   => $tLate,
            'total'      => $tTotal,
        ],
        'note'   => 'Revenue is attributed to the membership year recorded on each payment.',
    ];
}

/**
 * Format a single cell value for display (HTML) or CSV (plain text).
 *
 * @param  mixed  $value
 */
function reportFormatCell(mixed $value, string $format, bool $forCsv): string
{
    if ($value === null || $value === '') {
        return $forCsv ? '' : '—';
    }

    switch ($format) {
        case 'money':
            return $forCsv ? number_format((float) $value, 2, '.', '') : formatMoney((float) $value);
        case 'int':
            return $forCsv ? (string) (int) $value : number_format((int) $value);
        case 'signed':
            $n    = (int) $value;
            $sign = $n > 0 ? '+' : '';
            return $sign . ($forCsv ? (string) $n : number_format($n));
        case 'percent':
            return number_format((float) $value, 1) . ($forCsv ? '' : '%');
        case 'date':
            return $forCsv ? (string) $value : formatDate((string) $value);
        case 'year':
        case 'text':
        default:
            return (string) $value;
    }
}

/**
 * Retained / new / lapsed members year over year, with retention rate.
 *
 * @return array<string, mixed>
 */
function reportRetentionChurn(PDO $pdo): array
{
    $meta    = reportRegistry()['retention_churn'];
    $current = membershipStatusYear();
    $start   = reportEarliestYear($pdo);

    // Build a fast lookup (member_id => true) per year.
    $sets = [];
    for ($y = $start; $y <= $current; $y++) {
        $sets[$y] = array_fill_keys(reportMemberIdsForYear($pdo, $y), true);
    }

    $rows = [];
    for ($y = $current; $y >= $start + 1; $y--) {
        $prior = $sets[$y - 1] ?? [];
        $cur   = $sets[$y] ?? [];
        $priorCount = count($prior);

        $retained = 0;
        foreach ($cur as $id => $_) {
            if (isset($prior[$id])) {
                $retained++;
            }
        }
        $new       = count($cur) - $retained;
        $lapsed    = $priorCount - $retained;
        $retention = $priorCount > 0 ? ($retained / $priorCount) * 100 : null;

        $rows[] = [
            'year'      => $y,
            'prior'     => $priorCount,
            'retained'  => $retained,
            'new'       => $new,
            'lapsed'    => $lapsed,
            'retention' => $retention,
        ];
    }

    return [
        'slug'        => 'retention_churn',
        'title'       => $meta['label'],
        'description' => $meta['description'],
        'columns'     => [
            ['key' => 'year',      'label' => 'Year',           'format' => 'year',    'align' => 'start'],
            ['key' => 'prior',     'label' => 'Prior year',     'format' => 'int',     'align' => 'end'],
            ['key' => 'retained',  'label' => 'Retained',       'format' => 'int',     'align' => 'end'],
            ['key' => 'new',       'label' => 'New',            'format' => 'int',     'align' => 'end'],
            ['key' => 'lapsed',    'label' => 'Lapsed',         'format' => 'int',     'align' => 'end'],
            ['key' => 'retention', 'label' => 'Retention rate', 'format' => 'percent', 'align' => 'end'],
        ],
        'rows'   => $rows,
        'totals' => null,
        'note'   => 'Retained = in both years; New = in the year but not the prior; Lapsed = in the prior year but not this one. Rate = retained ÷ prior-year members.',
    ];
}

/**
 * Members by membership type for a year's roster (uses each member's current type).
 *
 * @return array<string, mixed>
 */
function reportMembershipTypeMix(PDO $pdo, int $year): array
{
    $meta   = reportRegistry()['membership_type_mix'];
    $labels = reportTypeLabels($pdo);
    $filter = membershipYearReportFilter($pdo, 'm', $year);

    $sql = "SELECT m.membership_type_slot AS slot, COUNT(*) AS cnt
            FROM members m
            WHERE {$filter['where']}
            GROUP BY m.membership_type_slot";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($filter['params']);

    $counts = [];
    $total  = 0;
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $slot = $r['slot'] === null ? 0 : (int) $r['slot'];
        $counts[$slot] = (int) $r['cnt'];
        $total += (int) $r['cnt'];
    }
    ksort($counts);

    $rows = [];
    foreach ($counts as $slot => $cnt) {
        if ($slot === 0) {
            $label = 'Unspecified';
        } else {
            $label = $labels[$slot] ?? ('Type ' . $slot);
        }
        $rows[] = [
            'type'    => $label,
            'members' => $cnt,
            'percent' => $total > 0 ? ($cnt / $total) * 100 : 0,
        ];
    }
    // Unspecified (slot 0) sorts first via ksort; move it to the end for readability.
    usort($rows, static fn($a, $b) => ($a['type'] === 'Unspecified') <=> ($b['type'] === 'Unspecified'));

    return [
        'slug'        => 'membership_type_mix',
        'title'       => $meta['label'] . ' — ' . $year,
        'description' => $meta['description'],
        'columns'     => [
            ['key' => 'type',    'label' => 'Membership type', 'format' => 'text',    'align' => 'start'],
            ['key' => 'members', 'label' => 'Members',         'format' => 'int',     'align' => 'end'],
            ['key' => 'percent', 'label' => 'Share',           'format' => 'percent', 'align' => 'end'],
        ],
        'rows'   => $rows,
        'totals' => $total > 0 ? ['type' => 'Total', 'members' => $total, 'percent' => 100.0] : null,
        'note'   => 'Type reflects each member\'s current membership type. Disabled types (e.g. legacy Senior) still appear if members hold them.',
    ];
}

/**
 * Members current the prior year but not yet renewed for the selected year.
 *
 * @return array<string, mixed>
 */
function reportNotYetRenewed(PDO $pdo, int $year): array
{
    $meta   = reportRegistry()['not_yet_renewed'];
    $labels = reportTypeLabels($pdo);
    $filter = notYetRenewedReportFilter($pdo, 'm', $year);

    $sql = "SELECT m.last_name, m.first_name, m.membership_type_slot AS slot, m.email, m.membership_renewal_year AS renewal_year
            FROM members m
            WHERE {$filter['where']}
            ORDER BY m.last_name, m.first_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($filter['params']);

    $rows = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $slot = $r['slot'] === null ? 0 : (int) $r['slot'];
        $rows[] = [
            'last_name'    => $r['last_name'],
            'first_name'   => $r['first_name'],
            'type'         => $slot === 0 ? '' : ($labels[$slot] ?? ('Type ' . $slot)),
            'email'        => $r['email'],
            'renewal_year' => $r['renewal_year'],
        ];
    }

    return [
        'slug'        => 'not_yet_renewed',
        'title'       => $meta['label'] . ' — ' . $year,
        'description' => $meta['description'],
        'columns'     => [
            ['key' => 'last_name',    'label' => 'Last name',     'format' => 'text', 'align' => 'start'],
            ['key' => 'first_name',   'label' => 'First name',    'format' => 'text', 'align' => 'start'],
            ['key' => 'type',         'label' => 'Type',          'format' => 'text', 'align' => 'start'],
            ['key' => 'email',        'label' => 'Email',         'format' => 'text', 'align' => 'start'],
            ['key' => 'renewal_year', 'label' => 'Renewal yr on file', 'format' => 'year', 'align' => 'end'],
        ],
        'rows'   => $rows,
        'totals' => null,
        'note'   => 'Members who counted for ' . ($year - 1) . ' but have no payment/fulfillment (or life/free status) for ' . $year . '.',
    ];
}

/**
 * Current members whose AMA or FAA credentials are expired or expiring within 60 days.
 *
 * @return array<string, mixed>
 */
function reportCompliance(PDO $pdo): array
{
    $meta    = reportRegistry()['compliance'];
    $current = membershipStatusYear();
    $where   = currentMemberWhereSql('m', $current);
    $in60    = date('Y-m-d', strtotime('+60 days'));
    $today   = date('Y-m-d');

    $sql = "SELECT m.last_name, m.first_name, m.ama_number, m.ama_expiration, m.faa_number, m.faa_expiration
            FROM members m
            WHERE {$where}
              AND (
                (m.ama_expiration IS NOT NULL AND m.ama_expiration != '' AND m.ama_expiration <= ?)
                OR (m.faa_expiration IS NOT NULL AND m.faa_expiration != '' AND m.faa_expiration <= ?)
              )
            ORDER BY LEAST(
                COALESCE(NULLIF(m.ama_expiration, ''), '9999-12-31'),
                COALESCE(NULLIF(m.faa_expiration, ''), '9999-12-31')
            ) ASC, m.last_name, m.first_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge(currentMemberWhereParams($current), [$in60, $in60]));

    $rows = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $amaExp = (string) ($r['ama_expiration'] ?? '');
        $faaExp = (string) ($r['faa_expiration'] ?? '');
        $expired = ($amaExp !== '' && $amaExp < $today) || ($faaExp !== '' && $faaExp < $today);
        $rows[] = [
            'last_name'  => $r['last_name'],
            'first_name' => $r['first_name'],
            'ama_number' => $r['ama_number'],
            'ama_exp'    => $amaExp,
            'faa_number' => $r['faa_number'],
            'faa_exp'    => $faaExp,
            'status'     => $expired ? 'Expired' : 'Expiring',
        ];
    }

    return [
        'slug'        => 'compliance',
        'title'       => $meta['label'],
        'description' => $meta['description'],
        'columns'     => [
            ['key' => 'last_name',  'label' => 'Last name',  'format' => 'text', 'align' => 'start'],
            ['key' => 'first_name', 'label' => 'First name', 'format' => 'text', 'align' => 'start'],
            ['key' => 'ama_number', 'label' => 'AMA #',      'format' => 'text', 'align' => 'start'],
            ['key' => 'ama_exp',    'label' => 'AMA expires','format' => 'date', 'align' => 'end'],
            ['key' => 'faa_number', 'label' => 'FAA #',      'format' => 'text', 'align' => 'start'],
            ['key' => 'faa_exp',    'label' => 'FAA expires','format' => 'date', 'align' => 'end'],
            ['key' => 'status',     'label' => 'Status',     'format' => 'text', 'align' => 'end'],
        ],
        'rows'   => $rows,
        'totals' => null,
        'note'   => 'Current members only. Includes credentials already expired or expiring on or before ' . formatDate($in60) . '.',
    ];
}

/**
 * Current members with incomplete contact, emergency, compliance, or membership data.
 *
 * @return array<string, mixed>
 */
function reportDataCompleteness(PDO $pdo): array
{
    $meta    = reportRegistry()['data_completeness'];
    $current = membershipStatusYear();
    $where   = currentMemberWhereSql('m', $current);

    $sql = 'SELECT ' . memberCompletenessSelectSql('m') . "
            FROM members m
            WHERE {$where}
            ORDER BY m.last_name, m.first_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(currentMemberWhereParams($current));

    $rows = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $missing = memberCompletenessMissingFields($r);
        if ($missing === []) {
            continue;
        }
        $rows[] = [
            'last_name'  => $r['last_name'],
            'first_name' => $r['first_name'],
            'email'      => $r['email'],
            'missing'    => implode(', ', $missing),
            'issue_count'=> count($missing),
        ];
    }

    return [
        'slug'        => 'data_completeness',
        'title'       => $meta['label'],
        'description' => $meta['description'],
        'columns'     => [
            ['key' => 'last_name',   'label' => 'Last name',    'format' => 'text', 'align' => 'start'],
            ['key' => 'first_name',  'label' => 'First name',   'format' => 'text', 'align' => 'start'],
            ['key' => 'email',       'label' => 'Email',        'format' => 'text', 'align' => 'start'],
            ['key' => 'missing',     'label' => 'Missing fields', 'format' => 'text', 'align' => 'start'],
            ['key' => 'issue_count', 'label' => 'Issues',       'format' => 'int',  'align' => 'end'],
        ],
        'rows'   => $rows,
        'totals' => null,
        'note'   => 'Current members only. Checks email, phone, mailing address, emergency contact, AMA/FAA numbers, and membership type. AMA life members are exempt from AMA number/expiration requirements.',
    ];
}
