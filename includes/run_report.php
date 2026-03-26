<?php
/**
 * reports.php — data layer: runReport()
 *
 * All SQL and row shaping for each report type. Included by reports.php after
 * auth is established.
 *
 * Maintenance: runReport() is a large switch — adding a report means a new case
 * here (and often a label in reports.php). Splitting into one function per report
 * is a reasonable future refactor if this file becomes hard to test or navigate.
 */

/**
 * Run a named report and return title, column headers, data rows, and a
 * summary array of stat cards to display above the table.
 *
 * @param PDO        $pdo
 * @param string     $report  One of: membership_snapshot, birthdays_this_month, renewal_not_renewed,
 *                            revenue_by_year, ama_faa_expiring, gate_key_compliance, member_type_breakdown,
 *                            waived_analysis (must match keys in reports.php $reportTypes).
 * @param int        $year    Selected report year (meaning varies by report; e.g. renewal year filter).
 * @param array<int, string> $membershipTypeLabels slot => label from enabledMembershipTypeLabels()
 * @param array      $options  ['member_type_slot' => ?int] optional filter for applicable reports
 * @return array{title:string, headers:array, rows:array, summary:array, extra:array, emails:array}
 *   summary items: ['label'=>'...', 'value'=>'...', 'sub'=>'...', 'colour'=>'success|warning|danger|secondary']
 *   extra: arbitrary data passed to the template (e.g. chart datasets, sectioned AMA/FAA tables)
 *   emails: rows for CSV/email export — each item includes name, first_name, last_name, email when available
 */
function runReport(
    PDO $pdo,
    string $report,
    int $year,
    array $membershipTypeLabels,
    array $options = []
): array {
    $title   = '';
    $headers = [];
    $rows    = [];
    $summary = [];
    $extra   = [];
    $emails  = [];
    $memberTypeSlot = $options['member_type_slot'] ?? null;
    $currentYear = (int) date('Y');

    switch ($report) {

        case 'membership_snapshot': {
            $title = "Membership Snapshot — $year";

            $stmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM members WHERE membership_renewal_year = ? AND inactive = 0');
            $stmt->execute([$year]);
            $totalActive = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

            $stmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM members WHERE membership_renewal_year = ? AND inactive = 0');
            $stmt->execute([$year - 1]);
            $prevYearTotal = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
            $delta = $totalActive - $prevYearTotal;
            $deltaStr = ($delta >= 0 ? '+' : '') . $delta . ' vs ' . ($year - 1);

            $stmt = $pdo->prepare('
                SELECT membership_type_slot, COUNT(*) AS cnt FROM members
                WHERE membership_renewal_year = ? AND inactive = 0
                  AND (life_member = 0 OR life_member IS NULL)
                GROUP BY membership_type_slot
                ORDER BY membership_type_slot ASC
            ');
            $stmt->execute([$year]);
            $byType = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $byType[(int) ($row['membership_type_slot'] ?? 0)] = (int) $row['cnt'];
            }

            $stmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM members WHERE membership_renewal_year = ? AND inactive = 0 AND life_member = 1');
            $stmt->execute([$year]);
            $lifetime = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

            $headers = ['Type', 'Count'];
            foreach ($byType as $t => $cnt) {
                $label = $t > 0 ? ($membershipTypeLabels[$t] ?? ('Type ' . $t)) : '—';
                $rows[] = [$label, $cnt];
            }
            if ($lifetime) {
                $rows[] = ['Life members', $lifetime];
            }
            $rows[] = ['Total active', $totalActive];

            $summary = [
                ['label' => 'Active members', 'value' => $totalActive, 'sub' => $deltaStr, 'colour' => 'primary'],
                ['label' => 'Life members',   'value' => $lifetime,    'sub' => 'of total', 'colour' => 'secondary'],
            ];
            foreach ($byType as $t => $cnt) {
                $summary[] = ['label' => $t > 0 ? ($membershipTypeLabels[$t] ?? ('Type ' . $t)) : 'Other', 'value' => $cnt, 'sub' => '', 'colour' => 'light'];
            }
            break;
        }

        case 'birthdays_this_month': {
            $title = 'Birthdays This Month — ' . date('F Y');
            $thisMonth = (int) date('m');

            $stmt = $pdo->prepare('
                SELECT first_name, last_name, birthday,
                    YEAR(CURDATE()) - YEAR(birthday) AS age
                FROM members
                WHERE inactive = 0
                  AND birthday IS NOT NULL
                  AND MONTH(birthday) = ?
                ORDER BY DAY(birthday)
            ');
            $stmt->execute([$thisMonth]);

            $headers = ['Name', 'Birthday', 'Age this year'];
            $todayDay  = (int) date('j');
            $upcoming  = 0;
            $nextName  = '';
            $nextDate  = '';

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rows[] = [
                    trim($row['last_name'] . ', ' . $row['first_name']),
                    $row['birthday'] ? date('F j', strtotime($row['birthday'])) : '—',
                    $row['age'] ?? '—',
                ];
                $day = $row['birthday'] ? (int) date('j', strtotime($row['birthday'])) : 0;
                if ($day >= $todayDay) {
                    $upcoming++;
                    if ($upcoming === 1) {
                        $nextName = trim($row['first_name'] . ' ' . $row['last_name']);
                        $nextDate = $row['birthday'] ? date('F j', strtotime($row['birthday'])) : '';
                    }
                }
            }

            $summary = [
                ['label' => 'Birthdays this month', 'value' => count($rows), 'sub' => '', 'colour' => 'primary'],
                ['label' => 'Still upcoming',        'value' => $upcoming,   'sub' => $nextName ? 'Next: ' . $nextName . ($nextDate ? ' on ' . $nextDate : '') : '', 'colour' => 'success'],
            ];

            $stmt2 = $pdo->prepare('
                SELECT first_name, last_name, email
                FROM members
                WHERE inactive = 0
                  AND allow_email = 1
                  AND birthday IS NOT NULL AND MONTH(birthday) = ?
                  AND email IS NOT NULL AND TRIM(email) != \'\'
                ORDER BY DAY(birthday)
            ');
            $stmt2->execute([$thisMonth]);
            while ($eRow = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                $emails[] = [
                    'name'       => trim($eRow['first_name'] . ' ' . $eRow['last_name']),
                    'first_name' => $eRow['first_name'] ?? '',
                    'last_name'  => $eRow['last_name'] ?? '',
                    'email'      => $eRow['email'] ?? '',
                ];
            }
            break;
        }

        case 'renewal_not_renewed': {
            $title = "Not Yet Renewed — $year";
            $prevYear = $year - 1;

            $sql = 'SELECT m.first_name, m.last_name, m.email, m.membership_type_slot, m.membership_renewal_year, m.allow_email
                    FROM members m
                    WHERE m.membership_renewal_year = ? AND m.inactive = 0
                      AND m.id NOT IN (
                          SELECT member_id FROM payments
                          WHERE year = ? AND (voided_at IS NULL)
                      )';
            $params = [$prevYear, $year];
            if ($memberTypeSlot !== null) {
                $sql    .= ' AND m.membership_type_slot = ?';
                $params[] = $memberTypeSlot;
            }
            $sql .= ' ORDER BY m.last_name, m.first_name';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $headers = ['Last name', 'First name', 'Email', 'Type', 'Last renewed'];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $slot = (int) ($row['membership_type_slot'] ?? 0);
                $label = $slot > 0 ? ($membershipTypeLabels[$slot] ?? ('Type ' . $slot)) : '';
                $rows[] = [
                    $row['last_name'],
                    $row['first_name'],
                    $row['email'] ?? '',
                    $label,
                    $row['membership_renewal_year'] ?? '',
                ];
                $emailVal = trim((string) ($row['email'] ?? ''));
                if ($emailVal !== '' && !empty($row['allow_email'])) {
                    $emails[] = [
                        'name'       => trim($row['first_name'] . ' ' . $row['last_name']),
                        'first_name' => $row['first_name'] ?? '',
                        'last_name'  => $row['last_name'] ?? '',
                        'email'      => $emailVal,
                    ];
                }
            }

            $stmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM members WHERE membership_renewal_year = ? AND inactive = 0');
            $stmt->execute([$prevYear]);
            $lastYearTotal = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
            $renewedCount  = $lastYearTotal - count($rows);
            $rate = $lastYearTotal > 0 ? round($renewedCount / $lastYearTotal * 100) : 0;

            $summary = [
                ['label' => 'Not yet renewed',      'value' => count($rows),   'sub' => "from $prevYear", 'colour' => count($rows) > 0 ? 'warning' : 'success'],
                ['label' => 'Renewed so far',        'value' => $renewedCount,  'sub' => "for $year",       'colour' => 'success'],
                ['label' => 'Renewal rate',          'value' => $rate . '%',    'sub' => "$year vs $prevYear", 'colour' => $rate >= 80 ? 'success' : ($rate >= 60 ? 'warning' : 'danger')],
            ];

            break;
        }

        case 'revenue_by_year': {
            $title = 'Revenue by Year';
            $headers = ['Year', 'Dues', 'Initiation fees', 'Late fees', 'Payments', 'Total'];

            $stmt = $pdo->prepare('
                SELECT year,
                    SUM(amount_dues) AS dues,
                    SUM(amount_initiation) AS initiation,
                    SUM(amount_late_fee) AS late_fee,
                    COUNT(*) AS payment_count
                FROM payments
                WHERE (voided_at IS NULL)
                GROUP BY year
                ORDER BY year DESC
            ');
            $stmt->execute();

            $chartYears  = [];
            $chartDues   = [];
            $chartInit   = [];
            $chartLate   = [];
            $grandTotal  = 0;
            $ytdTotal    = 0;

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $dues  = (float) $row['dues'];
                $init  = (float) $row['initiation'];
                $late  = (float) $row['late_fee'];
                $total = $dues + $init + $late;

                $rows[] = [
                    $row['year'],
                    '$' . number_format($dues, 2),
                    '$' . number_format($init, 2),
                    '$' . number_format($late, 2),
                    (int) $row['payment_count'],
                    '$' . number_format($total, 2),
                ];

                $chartYears[] = $row['year'];
                $chartDues[]  = round($dues, 2);
                $chartInit[]  = round($init, 2);
                $chartLate[]  = round($late, 2);
                $grandTotal  += $total;
                if ((int) $row['year'] === $currentYear) {
                    $ytdTotal = $total;
                }
            }

            $extra['chart'] = [
                'type'    => 'bar',
                'labels'  => array_reverse($chartYears),
                'datasets' => [
                    ['label' => 'Dues',          'data' => array_reverse($chartDues), 'color' => '#0d6efd'],
                    ['label' => 'Initiation',    'data' => array_reverse($chartInit), 'color' => '#198754'],
                    ['label' => 'Late fees',     'data' => array_reverse($chartLate), 'color' => '#ffc107'],
                ],
            ];

            $summary = [
                ['label' => 'All-time revenue', 'value' => '$' . number_format($grandTotal, 2), 'sub' => count($rows) . ' year' . (count($rows) !== 1 ? 's' : '') . ' of data', 'colour' => 'primary'],
                ['label' => $currentYear . ' to date', 'value' => '$' . number_format($ytdTotal, 2), 'sub' => 'current year', 'colour' => 'success'],
            ];
            break;
        }

        case 'ama_faa_expiring': {
            $title = 'AMA/FAA Compliance';
            $today = date('Y-m-d');
            $in60  = date('Y-m-d', strtotime('+60 days'));

            $stmt = $pdo->prepare("SELECT first_name, last_name, email, ama_number, ama_expiration, allow_email
                FROM members WHERE inactive = 0
                AND ama_expiration IS NOT NULL AND ama_expiration != ''
                AND ama_expiration >= ? AND ama_expiration <= ?
                ORDER BY ama_expiration, last_name");
            $stmt->execute([$today, $in60]);
            $amaExpiring = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("SELECT first_name, last_name, email, ama_number, ama_expiration, allow_email
                FROM members WHERE inactive = 0
                AND ama_expiration IS NOT NULL AND ama_expiration != '' AND ama_expiration < ?
                ORDER BY ama_expiration, last_name");
            $stmt->execute([$today]);
            $amaExpired = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("SELECT first_name, last_name, email, allow_email FROM members
                WHERE membership_renewal_year = ? AND inactive = 0
                AND (ama_number IS NULL OR TRIM(COALESCE(ama_number,'')) = '')
                ORDER BY last_name");
            $stmt->execute([$currentYear]);
            $amaMissing = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("SELECT first_name, last_name, email, faa_number, faa_expiration, allow_email
                FROM members WHERE inactive = 0
                AND faa_expiration IS NOT NULL AND faa_expiration != ''
                AND faa_expiration >= ? AND faa_expiration <= ?
                ORDER BY faa_expiration, last_name");
            $stmt->execute([$today, $in60]);
            $faaExpiring = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("SELECT first_name, last_name, email, faa_number, faa_expiration, allow_email
                FROM members WHERE inactive = 0
                AND faa_expiration IS NOT NULL AND faa_expiration != '' AND faa_expiration < ?
                ORDER BY faa_expiration, last_name");
            $stmt->execute([$today]);
            $faaExpired = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("SELECT first_name, last_name, email, allow_email FROM members
                WHERE membership_renewal_year = ? AND inactive = 0
                AND (faa_number IS NULL OR TRIM(COALESCE(faa_number,'')) = '')
                ORDER BY last_name");
            $stmt->execute([$currentYear]);
            $faaMissing = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $headers = ['Name', 'Email', 'AMA #', 'Expires'];
            foreach ($amaExpired as $r) {
                $rows[] = [
                    trim($r['last_name'] . ', ' . $r['first_name']),
                    $r['email'] ?? '',
                    $r['ama_number'] ?? '',
                    $r['ama_expiration'] ?? '',
                ];
            }
            foreach ($amaExpiring as $r) {
                $rows[] = [
                    trim($r['last_name'] . ', ' . $r['first_name']),
                    $r['email'] ?? '',
                    $r['ama_number'] ?? '',
                    $r['ama_expiration'] ?? '',
                ];
            }

            $summary = [
                ['label' => 'AMA expired',         'value' => count($amaExpired),   'sub' => 'need renewal',     'colour' => count($amaExpired) > 0 ? 'danger' : 'success'],
                ['label' => 'AMA expiring (60 d)',  'value' => count($amaExpiring),  'sub' => 'act soon',         'colour' => count($amaExpiring) > 0 ? 'warning' : 'success'],
                ['label' => 'Missing AMA #',        'value' => count($amaMissing),   'sub' => 'current members',  'colour' => count($amaMissing) > 0 ? 'warning' : 'success'],
                ['label' => 'FAA issues',           'value' => count($faaExpired) + count($faaExpiring), 'sub' => 'expired or expiring', 'colour' => (count($faaExpired) + count($faaExpiring)) > 0 ? 'warning' : 'success'],
            ];

            $emailsSeen = [];
            foreach (array_merge($amaExpired, $amaExpiring, $amaMissing, $faaExpired, $faaExpiring, $faaMissing) as $r) {
                $e = trim($r['email'] ?? '');
                if ($e !== '' && !isset($emailsSeen[$e]) && !empty($r['allow_email'])) {
                    $emailsSeen[$e] = true;
                    $emails[] = [
                        'name'       => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                        'first_name' => $r['first_name'] ?? '',
                        'last_name'  => $r['last_name'] ?? '',
                        'email'      => $e,
                    ];
                }
            }

            $extra['sections'] = [
                ['title' => 'AMA — Expired',         'colour' => 'danger',  'headers' => ['Name', 'Email', 'AMA #', 'Expired'],       'rows' => array_map(fn($r) => [trim($r['last_name'].', '.$r['first_name']), $r['email']??'', $r['ama_number']??'', $r['ama_expiration']??''], $amaExpired)],
                ['title' => 'AMA — Expiring (60 d)', 'colour' => 'warning', 'headers' => ['Name', 'Email', 'AMA #', 'Expires'],       'rows' => array_map(fn($r) => [trim($r['last_name'].', '.$r['first_name']), $r['email']??'', $r['ama_number']??'', $r['ama_expiration']??''], $amaExpiring)],
                ['title' => 'AMA — No number on file','colour' => 'warning','headers' => ['Name', 'Email'],                            'rows' => array_map(fn($r) => [trim($r['last_name'].', '.$r['first_name']), $r['email']??''], $amaMissing)],
                ['title' => 'FAA — Expired',         'colour' => 'danger',  'headers' => ['Name', 'Email', 'FAA #', 'Expired'],       'rows' => array_map(fn($r) => [trim($r['last_name'].', '.$r['first_name']), $r['email']??'', $r['faa_number']??'', $r['faa_expiration']??''], $faaExpired)],
                ['title' => 'FAA — Expiring (60 d)', 'colour' => 'warning', 'headers' => ['Name', 'Email', 'FAA #', 'Expires'],       'rows' => array_map(fn($r) => [trim($r['last_name'].', '.$r['first_name']), $r['email']??'', $r['faa_number']??'', $r['faa_expiration']??''], $faaExpiring)],
                ['title' => 'FAA — No number on file','colour' => 'warning','headers' => ['Name', 'Email'],                            'rows' => array_map(fn($r) => [trim($r['last_name'].', '.$r['first_name']), $r['email']??''], $faaMissing)],
            ];
            break;
        }

        case 'gate_key_compliance': {
            $title = "Gate Key Compliance — $year";

            $stmt = $pdo->prepare('
                SELECT first_name, last_name, email, gate_key_number, allow_email
                FROM members
                WHERE membership_renewal_year = ? AND inactive = 0
                ORDER BY CAST(gate_key_number AS UNSIGNED), last_name
            ');
            $stmt->execute([$year]);

            $headers = ['Name', 'Email', 'Key #'];
            $withKey    = 0;
            $withoutKey = 0;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rows[] = [
                    trim($row['last_name'] . ', ' . $row['first_name']),
                    $row['email'] ?? '',
                    $row['gate_key_number'] ?? '—',
                ];
                if (!empty(trim($row['gate_key_number'] ?? ''))) {
                    $withKey++;
                } else {
                    $withoutKey++;
                }
                $e = trim($row['email'] ?? '');
                if ($e !== '' && !empty($row['allow_email'])) {
                    $emails[] = [
                        'name'       => trim($row['last_name'] . ', ' . $row['first_name']),
                        'first_name' => $row['first_name'] ?? '',
                        'last_name'  => $row['last_name'] ?? '',
                        'email'      => $e,
                    ];
                }
            }

            $summary = [
                ['label' => 'Keys issued',       'value' => $withKey,    'sub' => "for $year", 'colour' => 'success'],
                ['label' => 'No key on record',  'value' => $withoutKey, 'sub' => 'active members', 'colour' => $withoutKey > 0 ? 'warning' : 'success'],
                ['label' => 'Total active',      'value' => count($rows), 'sub' => "in $year",   'colour' => 'secondary'],
            ];

            break;
        }

        case 'member_type_breakdown': {
            $title = "Member Type Breakdown — $year";

            $stmt = $pdo->prepare('
                SELECT membership_type_slot, COUNT(*) AS cnt
                FROM members
                WHERE membership_renewal_year = ? AND inactive = 0
                GROUP BY membership_type_slot
                ORDER BY membership_type_slot ASC
            ');
            $stmt->execute([$year]);

            $headers = ['Type', 'Count', '% of total'];
            $typeCounts = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $slot = (int) ($row['membership_type_slot'] ?? 0);
                if ($slot <= 0) continue;
                $typeCounts[$slot] = (int) $row['cnt'];
            }
            $total = array_sum($typeCounts);
            foreach ($typeCounts as $slot => $cnt) {
                $pct    = $total > 0 ? round($cnt / $total * 100, 1) : 0;
                $rows[] = [$membershipTypeLabels[$slot] ?? ('Type ' . $slot), $cnt, $pct . '%'];
            }

            $extra['chart'] = [
                'type'    => 'doughnut',
                'labels'  => array_map(fn($slot) => $membershipTypeLabels[(int) $slot] ?? ('Type ' . (int) $slot), array_keys($typeCounts)),
                'datasets' => [[
                    'data'   => array_values($typeCounts),
                    'colors' => ['#0d6efd','#198754','#0dcaf0','#6f42c1'],
                ]],
            ];

            $summary = [
                ['label' => 'Total active', 'value' => $total, 'sub' => "in $year", 'colour' => 'primary'],
            ];
            foreach ($typeCounts as $slot => $cnt) {
                $pct      = $total > 0 ? round($cnt / $total * 100) : 0;
                $summary[] = ['label' => $membershipTypeLabels[$slot] ?? ('Type ' . $slot), 'value' => $cnt, 'sub' => "$pct% of total", 'colour' => 'light'];
            }
            break;
        }

        case 'waived_analysis': {
            $title = 'Waived & Free Memberships';
            $headers = ['Year', 'Waived', 'Estimated value', 'Total active', '% waived'];

            for ($y = $currentYear; $y >= max(2020, $currentYear - 5); $y--) {
                $stmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM members WHERE membership_renewal_year = ? AND inactive = 0');
                $stmt->execute([$y]);
                $totalActive = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

                $stmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM members WHERE membership_renewal_year = ? AND inactive = 0 AND (free_membership = 1 OR life_member = 1)');
                $stmt->execute([$y]);
                $waivedCount = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

                $waivedValue = 0;
                $stmt = $pdo->prepare('
                    SELECT m.membership_type_slot,
                        COALESCE(dr.annual_dues, CASE WHEN m.membership_type_slot = 1 THEN t.dues_adult_regular ELSE t.dues_reduced END) AS annual_dues
                    FROM members m
                    LEFT JOIN dues_rules dr ON dr.membership_type_slot = m.membership_type_slot
                    LEFT JOIN club t ON t.id = 1
                    WHERE m.membership_renewal_year = ? AND m.inactive = 0
                      AND (m.free_membership = 1 OR m.life_member = 1)
                ');
                $stmt->execute([$y]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $waivedValue += (float) ($row['annual_dues'] ?? 0);
                }

                $pct    = $totalActive > 0 ? round($waivedCount / $totalActive * 100, 1) : 0;
                $rows[] = [$y, $waivedCount, '$' . number_format($waivedValue, 2), $totalActive, $pct . '%'];
            }

            $thisYearWaived = count($rows) > 0 ? (int) $rows[0][1] : 0;
            $thisYearValue  = count($rows) > 0 ? $rows[0][2] : '$0.00';

            $summary = [
                ['label' => 'Waived this year',     'value' => $thisYearWaived, 'sub' => "in $currentYear", 'colour' => 'secondary'],
                ['label' => 'Estimated value',      'value' => $thisYearValue,  'sub' => 'foregone dues',   'colour' => 'warning'],
            ];

            $detailHeaders = ['Last name', 'First name', 'Email', 'Type', 'Status', 'Renewal year', 'Est. annual dues'];
            $detailRows    = [];

            $stmt = $pdo->prepare('
                SELECT m.first_name, m.last_name, m.email, m.membership_type_slot,
                       m.free_membership, m.life_member,
                       m.membership_renewal_year, m.allow_email,
                       COALESCE(dr.annual_dues,
                           CASE WHEN m.membership_type_slot = 1
                                THEN t.dues_adult_regular
                                ELSE t.dues_reduced
                           END
                       ) AS annual_dues
                FROM members m
                LEFT JOIN dues_rules dr
                    ON dr.membership_type_slot = m.membership_type_slot
                LEFT JOIN club t
                    ON t.id = 1
                WHERE m.membership_renewal_year = ?
                  AND m.inactive = 0
                  AND (m.free_membership = 1 OR m.life_member = 1)
                ORDER BY m.last_name, m.first_name
            ');
            $stmt->execute([$year]);

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $status = !empty($row['life_member']) ? 'Life' : 'Free';
                $dues   = (float) ($row['annual_dues'] ?? 0);
                $slot = (int) ($row['membership_type_slot'] ?? 0);
                $typeLabel = $slot > 0 ? ($membershipTypeLabels[$slot] ?? ('Type ' . $slot)) : '';

                $detailRows[] = [
                    trim((string) ($row['last_name'] ?? '')),
                    trim((string) ($row['first_name'] ?? '')),
                    (string) ($row['email'] ?? ''),
                    $typeLabel,
                    $status,
                    (string) ($row['membership_renewal_year'] ?? ''),
                    $dues > 0 ? '$' . number_format($dues, 2) : '$0.00',
                ];

                $email = trim((string) ($row['email'] ?? ''));
                if ($email !== '' && !empty($row['allow_email'])) {
                    $emails[] = [
                        'name'       => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
                        'first_name' => $row['first_name'] ?? '',
                        'last_name'  => $row['last_name'] ?? '',
                        'email'      => $email,
                    ];
                }
            }

            $extra['waived_members'] = [
                'headers' => $detailHeaders,
                'rows'    => $detailRows,
            ];
            break;
        }
    }

    return ['title' => $title, 'headers' => $headers, 'rows' => $rows, 'summary' => $summary, 'extra' => $extra, 'emails' => $emails];
}

/**
 * Recipient rows for report_email.php — derived from runReport() so bulk email
 * lists match the on-screen report and email CSV export for the same parameters.
 *
 * @param array $options Same as runReport() (e.g. member_type_slot filter).
 * @return array<int, array{first_name:string, last_name:string, email:string}>
 */
function reportEmailRecipientRows(
    PDO $pdo,
    string $report,
    int $year,
    array $membershipTypeLabels,
    array $options = []
): array {
    $result = runReport($pdo, $report, $year, $membershipTypeLabels, $options);
    $out    = [];
    foreach ($result['emails'] as $e) {
        $out[] = [
            'first_name' => (string) ($e['first_name'] ?? ''),
            'last_name'  => (string) ($e['last_name'] ?? ''),
            'email'      => trim((string) ($e['email'] ?? '')),
        ];
    }
    return $out;
}
