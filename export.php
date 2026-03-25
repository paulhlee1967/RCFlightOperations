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
$format = isset($_POST['format']) && in_array($_POST['format'], ['full', 'short', 'email'], true) ? $_POST['format'] : 'full';
$currentYear = (int) date('Y');

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
    $prevYear = $year - 1;
    $sql = 'SELECT m.id, m.title, m.first_name, m.last_name, m.email, m.birthday, m.notes, m.date_joined, m.membership_type_slot, m.membership_renewal_year, m.inactive, m.suspended, m.life_member, m.free_membership, m.gate_key_number, m.ama_number, m.ama_expiration, m.ama_life_member, m.faa_number, m.faa_expiration, m.emergency_contact_name, m.emergency_contact_relationship, m.emergency_contact_phone, m.allow_email, m.allow_postal
            FROM members m
            WHERE m.membership_renewal_year = ? AND m.inactive = 0
            AND m.id NOT IN (
                SELECT member_id FROM payments
                WHERE year = ? AND (voided_at IS NULL)
            )
            ORDER BY m.last_name, m.first_name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$prevYear, $year]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $sql = 'SELECT id, title, first_name, last_name, email, birthday, notes, date_joined, membership_type_slot, membership_renewal_year, inactive, suspended, life_member, free_membership, gate_key_number, ama_number, ama_expiration, ama_life_member, faa_number, faa_expiration, emergency_contact_name, emergency_contact_relationship, emergency_contact_phone, allow_email, allow_postal FROM members WHERE 1=1';
    $params = [];
    if ($filter === 'year') {
        $sql .= ' AND membership_renewal_year = ?';
        $params[] = $year;
    }
    $sql .= ' ORDER BY last_name, first_name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$phoneStmt = $pdo->prepare('SELECT type, number FROM member_phones WHERE member_id = ? ORDER BY FIELD(type, "Home", "Cell", "Work", "Other")');
$addrStmt = $pdo->prepare('SELECT type, street, street2, city, state, postal_code FROM member_addresses WHERE member_id = ? ORDER BY id');

$rows = [];
foreach ($members as $m) {
    $homePhone = $cellPhone = $workPhone = '';
    $addr1 = $addr2 = null;
    if ($format === 'full') {
        $phoneStmt->execute([$m['id']]);
        $phones = [];
        while ($row = $phoneStmt->fetch(PDO::FETCH_ASSOC)) $phones[$row['type']] = $row['number'];
        $homePhone = $phones['Home'] ?? '';
        $cellPhone = $phones['Cell'] ?? '';
        $workPhone = $phones['Work'] ?? '';
        $addrStmt->execute([$m['id']]);
        $addresses = $addrStmt->fetchAll(PDO::FETCH_ASSOC);
        $addr1 = $addresses[0] ?? null;
        $addr2 = $addresses[1] ?? null;
    } elseif ($format === 'short') {
        $phoneStmt->execute([$m['id']]);
        while ($row = $phoneStmt->fetch(PDO::FETCH_ASSOC)) {
            if (($row['type'] ?? '') === 'Home') $homePhone = $row['number'];
        }
    }

    if ($format === 'email') {
        if (isset($m['allow_email']) && !(int) $m['allow_email']) {
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
            !empty($m['allow_email']) ? '1' : '0',
            !empty($m['allow_postal']) ? '1' : '0',
            $homePhone,
            $cellPhone,
            $workPhone,
            $addr1 ? ($addr1['type'] ?? 'Home') : '',
            $addr1 ? ($addr1['street'] ?? '') : '',
            $addr1 ? ($addr1['street2'] ?? '') : '',
            $addr1 ? ($addr1['city'] ?? '') : '',
            $addr1 ? ($addr1['state'] ?? '') : '',
            $addr1 ? ($addr1['postal_code'] ?? '') : '',
            $addr2 ? ($addr2['type'] ?? 'Other') : '',
            $addr2 ? ($addr2['street'] ?? '') : '',
            $addr2 ? ($addr2['street2'] ?? '') : '',
            $addr2 ? ($addr2['city'] ?? '') : '',
            $addr2 ? ($addr2['state'] ?? '') : '',
            $addr2 ? ($addr2['postal_code'] ?? '') : '',
        ];
    }
}

$csvHeaders = $format === 'email' ? ['Last', 'First', 'Email'] :
    ($format === 'short' ? ['FirstName', 'LastName', 'Email', 'AMA_NO', 'AMA_EXP', 'FAA_NO', 'FAA_EXP', 'GateKey'] :
    ['first_name', 'last_name', 'email', 'title', 'birthday', 'notes', 'date_joined', 'membership_type_slot', 'membership_renewal_year', 'Member Inactive', 'Member Suspended', 'Life Member', 'Free Membership', 'AMA Life Member', 'gate_key_number', 'ama_number', 'AMA Expiry', 'FAA Number', 'FAA Expiry', 'Emergency contact name', 'Emergency contact relationship', 'Emergency contact phone', 'Allow email', 'Allow postal mail', 'Phone (Home)', 'Mobile', 'Work', 'Address Type', 'street', 'street2', 'city', 'state', 'postal_code', 'Address 2 Type', 'Address 2 Street', 'Address 2 Street 2', 'Address 2 City', 'Address 2 State', 'Address 2 Postal Code']);

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
