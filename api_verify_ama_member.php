<?php
/**
 * AMA verification for the member portal session.
 */
ob_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/member_portal.php';
require_once __DIR__ . '/includes/ama_verify.php';
require_once __DIR__ . '/includes/rate_limit.php';

flightops_send_security_headers();

function member_portal_ama_json(array $data, int $status = 200): void
{
    if (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$memberId = member_portal_current_member_id();
if ($memberId <= 0) {
    member_portal_ama_json(['valid' => false, 'message' => 'Please open your profile link again.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    member_portal_ama_json(['valid' => false, 'message' => 'Method not allowed.'], 405);
}

csrf_validate(['json' => true]);

global $config;
$clientIp = rate_limit_get_client_ip(is_array($config ?? null) ? $config : null);
if (!rate_limit_check($pdo, 'member_portal_ama', $clientIp, 20, 15)) {
    member_portal_ama_json(['valid' => false, 'message' => 'Too many verification requests. Try again shortly.'], 429);
}

$stmt = $pdo->prepare('SELECT last_name, ama_number FROM members WHERE id = ? LIMIT 1');
$stmt->execute([$memberId]);
$member = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$member) {
    member_portal_ama_json(['valid' => false, 'message' => 'Member not found.'], 404);
}

// Prefer posted AMA number (what they are about to save); last name always from roster.
$amaNumber = ama_verify_normalize_number((string) ($_POST['ama_number'] ?? ($member['ama_number'] ?? '')));
$lastName = ama_verify_normalize_last_name((string) ($member['last_name'] ?? ''));

$result = ama_verify_membership($amaNumber, $lastName);
member_portal_ama_json(ama_verify_to_api_json($result));
