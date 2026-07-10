<?php
/**
 * Public AMA verification for the membership application gate.
 */
ob_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/membership_application.php';

flightops_send_security_headers();

function membership_ama_public_json(array $data, int $status = 200): void
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    membership_ama_public_json(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

csrf_validate(['json' => true]);

$clientIp = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
if ($clientIp !== '' && !membership_application_ama_rate_limit_check($pdo, $clientIp)) {
    membership_ama_public_json([
        'ok'    => false,
        'error' => 'Too many verification attempts. Please wait a few minutes and try again.',
    ], 429);
}

$amaNumber = (string) ($_POST['ama_number'] ?? '');
$lastName  = (string) ($_POST['last_name'] ?? '');

$result = membership_application_ama_verify_for_apply($pdo, $amaNumber, $lastName);
if (!$result['ok']) {
    membership_ama_public_json([
        'ok'    => false,
        'error' => $result['error'],
        'data'  => $result['data'],
    ], 422);
}

membership_ama_public_json([
    'ok'   => true,
    'data' => $result['data'],
]);
