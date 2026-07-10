<?php
/**
 * Export members to CSV. Supports multiple formats and filters (FileMaker-style).
 * format: full (import round-trip), short (name/email/AMA/FAA/gate), email (last, first, email)
 * filter: all, year (members with renewal_year=year), not_renewed (last year members, no payment for year)
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

requireLogin();
if (!canEditMembers() && !canProcessMemberships()) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: export_options.php');
    exit;
}
csrf_validate();
// $format controls column selection and branching below — never interpolate from raw POST;
// keep the allowlist above in sync when adding formats.
$format = isset($_POST['format']) && in_array($_POST['format'], ['full', 'short', 'email'], true) ? $_POST['format'] : 'full';
$currentYear = membershipStatusYear();

// Validate filter explicitly (defense-in-depth).
$allowedFilters = ['all', 'current', 'year', 'not_renewed'];
$filterParam = (string) ($_POST['filter'] ?? 'all');
if (!in_array($filterParam, $allowedFilters, true)) {
    $filterParam = 'all';
}

// "current" always means calendar year for renewal filters — resolve before reading POST year.
if ($filterParam === 'current') {
    $filter = 'year';
    $year   = $currentYear;
} else {
    $filter = $filterParam;
    $year   = $currentYear;
    $yearRaw = $_POST['year'] ?? null;
    if ($yearRaw !== null) {
        $yearStr = (string) $yearRaw;
        if (preg_match('/^\d{4}$/', $yearStr)) {
            $yearCandidate = (int) $yearStr;
            if ($yearCandidate >= 2000 && $yearCandidate <= 2100) {
                $year = $yearCandidate;
            }
        }
    }
}

// Build member list based on filter
if ($filter === 'not_renewed') {
    $sql = 'SELECT m.id, m.title, m.first_name, m.last_name, m.email, m.phone, m.birthday, m.notes, m.date_joined, m.membership_type_slot, m.membership_renewal_year, m.inactive, m.suspended, m.life_member, m.free_membership, m.gate_key_number, m.ama_number, m.ama_expiration, m.ama_life_member, m.faa_number, m.faa_expiration, m.emergency_contact_name, m.emergency_contact_relationship, m.emergency_contact_phone, m.address_street, m.address_street2, m.address_city, m.address_state, m.address_postal_code
            FROM members m
            WHERE ' . notYetRenewedWhereSql('m', $year) . '
            ORDER BY m.last_name, m.first_name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(notYetRenewedWhereParams($year));
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $sql = 'SELECT id, title, first_name, last_name, email, phone, birthday, notes, date_joined, membership_type_slot, membership_renewal_year, inactive, suspended, life_member, free_membership, gate_key_number, ama_number, ama_expiration, ama_life_member, faa_number, faa_expiration, emergency_contact_name, emergency_contact_relationship, emergency_contact_phone, address_street, address_street2, address_city, address_state, address_postal_code FROM members WHERE 1=1';
    $params = [];
    if ($filter === 'year') {
        $yearFilter = membershipYearReportFilter($pdo, '', $year);
        $sql .= ' AND ' . $yearFilter['where'];
        $params = $yearFilter['params'];
    }
    $sql .= ' ORDER BY last_name, first_name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$rows = [];
foreach ($members as $m) {
    if ($format === 'email') {
        if (trim((string) ($m['email'] ?? '')) === '') {
            continue;
        }
        $rows[] = [$m['last_name'] ?? '', $m['first_name'] ?? '', $m['email'] ?? ''];
    } elseif ($format === 'short') {
        $rows[] = [
            $m['first_name'] ?? '',
            $m['last_name'] ?? '',
            $m['email'] ?? '',
            $m['ama_number'] ?? '',
            $m['ama_expiration'] ?? '',
            $m['faa_number'] ?? '',
            $m['faa_expiration'] ?? '',
            $m['gate_key_number'] ?? '',
        ];
    } else {
        $rows[] = [
            $m['first_name'] ?? '',
            $m['last_name'] ?? '',
            $m['email'] ?? '',
            $m['title'] ?? '',
            $m['birthday'] ?? '',
            $m['notes'] ?? '',
            $m['date_joined'] ?? '',
            $m['membership_type_slot'] ?? '',
            $m['membership_renewal_year'] ?? '',
            $m['inactive'] ? '1' : '0',
            $m['suspended'] ? '1' : '0',
            $m['life_member'] ? '1' : '0',
            $m['free_membership'] ? '1' : '0',
            $m['ama_life_member'] ? '1' : '0',
            $m['gate_key_number'] ?? '',
            $m['ama_number'] ?? '',
            $m['ama_expiration'] ?? '',
            $m['faa_number'] ?? '',
            $m['faa_expiration'] ?? '',
            $m['emergency_contact_name'] ?? '',
            $m['emergency_contact_relationship'] ?? '',
            $m['emergency_contact_phone'] ?? '',
            $m['phone'] ?? '',
            $m['address_street'] ?? '',
            $m['address_street2'] ?? '',
            $m['address_city'] ?? '',
            $m['address_state'] ?? '',
            $m['address_postal_code'] ?? '',
        ];
    }
}

$csvHeaders = $format === 'email' ? ['Last', 'First', 'Email'] :
    ($format === 'short' ? ['FirstName', 'LastName', 'Email', 'AMA_NO', 'AMA_EXP', 'FAA_NO', 'FAA_EXP', 'GateKey'] :
    ['first_name', 'last_name', 'email', 'title', 'birthday', 'notes', 'date_joined', 'membership_type_slot', 'membership_renewal_year', 'Member Inactive', 'Member Suspended', 'Life Member', 'Free Membership', 'AMA Life Member', 'gate_key_number', 'ama_number', 'AMA Expiry', 'FAA Number', 'FAA Expiry', 'Emergency contact name', 'Emergency contact relationship', 'Emergency contact phone', 'Phone', 'street', 'street2', 'city', 'state', 'postal_code']);

$filename = 'members';
if ($filter === 'not_renewed') $filename .= '_not_renewed_' . $year;
elseif ($filter === 'year') $filename .= '_' . $year;
$filename .= '_' . date('Y-m-d') . '.csv';

if (ob_get_level()) ob_end_clean();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$out = fopen('php://output', 'w');
fprintf($out, "\xEF\xBB\xBF");
fputcsv($out, $csvHeaders, ',', '"', '\\');
foreach ($rows as $row) fputcsv($out, $row, ',', '"', '\\');
fclose($out);
exit;
