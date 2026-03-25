<?php
/**
 * Member detail — lightweight JSON endpoint for the quick-view offcanvas panel.
 *
 * GET /member_detail.php?id=N&format=json
 *
 * Returns a JSON object with the fields needed by the offcanvas panel in
 * members.php. This endpoint intentionally returns only display data; all
 * write operations remain in member_edit.php.
 *
 * Auth: requires login + canEditMembers() or canProcessMemberships().
 * Scope: single-club member record.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/security_headers.php';

flightops_send_security_headers();

requireLogin();

// Only editors and treasurers (and admins) may view member detail
if (!canEditMembers() && !canProcessMemberships()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden']);
    exit;
}
$membershipTypeLabels = enabledMembershipTypeLabels($pdo);
$memberId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$format   = $_GET['format'] ?? 'json';

if ($memberId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing id']);
    exit;
}

// ── Fetch member core row ─────────────────────────────────────────────────────
$stmt = $pdo->prepare('
    SELECT id, first_name, last_name, email, membership_type_slot, membership_renewal_year,
           ama_number, ama_expiration, ama_life_member,
           faa_number, faa_expiration,
           gate_key_number, badge_printed_at, date_joined,
           inactive, suspended, life_member, free_membership, photo_path,
           emergency_contact_name, emergency_contact_relationship, emergency_contact_phone
    FROM members
    WHERE id = ?
');
$stmt->execute([$memberId]);
$member = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$member) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not found']);
    exit;
}

// ── Fetch phone numbers ───────────────────────────────────────────────────────
$stmt = $pdo->prepare('
    SELECT type, number
    FROM member_phones
    WHERE member_id = ?
    ORDER BY FIELD(type, "Cell", "Home", "Work", "Other")
');
$stmt->execute([$memberId]);
$phones = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($phones as &$p) {
    // Keep API responses consistent: callers expect a string, not NULL.
    $p['number'] = $p['number'] ?? '';
}
unset($p);

// ── Fetch primary address ─────────────────────────────────────────────────────
$stmt = $pdo->prepare('
    SELECT type, street, street2, city, state, postal_code
    FROM member_addresses
    WHERE member_id = ?
    ORDER BY FIELD(type, "Home", "Work", "Other")
    LIMIT 1
');
$stmt->execute([$memberId]);
$address = $stmt->fetch(PDO::FETCH_ASSOC);

// ── Payment summary ───────────────────────────────────────────────────────────
$stmt = $pdo->prepare('
    SELECT
        COUNT(*)                             AS payment_count,
        MAX(year)                            AS last_year,
        SUM(amount_dues + amount_initiation + amount_late_fee) AS total_paid,
        MAX(paid_at)                         AS last_paid_at
    FROM payments
    WHERE member_id = ? AND (voided_at IS NULL)
');
$stmt->execute([$memberId]);
$paymentSummary = $stmt->fetch(PDO::FETCH_ASSOC);

// ── Derive AMA status label ───────────────────────────────────────────────────
$today = date('Y-m-d');
$amaStatus = '';
if (!empty($member['ama_life_member'])) {
    $amaStatus = 'life';
} elseif (!empty($member['ama_expiration'])) {
    $exp = $member['ama_expiration'];
    if ($exp < $today) {
        $amaStatus = 'expired';
    } elseif ($exp <= date('Y-m-d', strtotime('+60 days'))) {
        $amaStatus = 'expiring';
    } else {
        $amaStatus = 'valid';
    }
}

// ── Photo URL (only if file actually exists) ──────────────────────────────────
$photoUrl = '';
if (!empty($member['photo_path'])) {
    $photoFile = __DIR__ . '/' . ltrim($member['photo_path'], '/');
    if (is_file($photoFile) && is_readable($photoFile)) {
        $photoUrl = 'badge_photo.php?id=' . $memberId;
    }
}

// ── Build address string ──────────────────────────────────────────────────────
$addressStr = '';
if ($address) {
    $parts = array_filter([
        $address['street'] ?? '',
        $address['street2'] ?? '',
        trim(($address['city'] ?? '') . ', ' . ($address['state'] ?? '') . ' ' . ($address['postal_code'] ?? '')),
    ]);
    $addressStr = implode("\n", $parts);
}

// ── Assemble response ─────────────────────────────────────────────────────────
$currentYear = (int) date('Y');
$renewalYear = (int) ($member['membership_renewal_year'] ?? 0);

$response = [
    'id'           => (int) $member['id'],
    'name'         => trim($member['first_name'] . ' ' . $member['last_name']),
    'first_name'   => $member['first_name'],
    'last_name'    => $member['last_name'],
    'email'        => $member['email'] ?? '',
    'type'         => ((int) ($member['membership_type_slot'] ?? 0)) > 0
        ? ($membershipTypeLabels[(int) $member['membership_type_slot']] ?? ('Type ' . (int) $member['membership_type_slot']))
        : '',
    'renewal_year' => $renewalYear ?: null,
    'date_joined'  => $member['date_joined'] ?? '',
    'photo_url'    => $photoUrl,

    // Contact
    'phones'       => array_values($phones),
    'address'      => $addressStr,
    'emergency_contact_name'         => $member['emergency_contact_name'] ?? '',
    'emergency_contact_relationship' => $member['emergency_contact_relationship'] ?? '',
    'emergency_contact_phone'        => $member['emergency_contact_phone'] ?? '',

    // Compliance
    'ama_number'     => $member['ama_number'] ?? '',
    'ama_expiration' => $member['ama_expiration'] ?? '',
    'ama_status'     => $amaStatus,   // 'valid' | 'expiring' | 'expired' | 'life' | ''
    'faa_number'     => $member['faa_number'] ?? '',
    'faa_expiration' => $member['faa_expiration'] ?? '',
    'gate_key'       => $member['gate_key_number'] ?? '',

    // Flags
    'inactive'       => (bool) $member['inactive'],
    'suspended'      => (bool) $member['suspended'],
    'life_member'    => (bool) $member['life_member'],
    'free_membership'=> (bool) $member['free_membership'],

    // Badge
    'badge_printed_at' => $member['badge_printed_at'] ?? '',

    // Payment summary
    'payment_count' => (int) ($paymentSummary['payment_count'] ?? 0),
    'total_paid'    => $paymentSummary['total_paid'] ? '$' . number_format((float) $paymentSummary['total_paid'], 2) : '$0.00',
    'last_paid_at'  => $paymentSummary['last_paid_at'] ?? '',
    'last_year_paid'=> (int) ($paymentSummary['last_year'] ?? 0),
];

header('Content-Type: application/json; charset=utf-8');
// Prevent caching of member PII
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;