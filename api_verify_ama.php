<?php
/**
 * api_verify_ama.php
 *
 * AJAX endpoint: verifies AMA number + last name via includes/ama_verify.php.
 */
ob_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/ama_verify.php';

flightops_send_security_headers();

function sendAmaJson(array $data, int $status = 200): void
{
    if (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendAmaJson(['valid' => false, 'message' => 'Method not allowed.'], 405);
}
csrf_validate(['json' => true]);

$userId  = currentUserId();
$now     = time();
$rateKey = 'ama_verify_rl_' . $userId;
if (!isset($_SESSION[$rateKey]) || !is_array($_SESSION[$rateKey])) {
    $_SESSION[$rateKey] = ['ts' => $now, 'count' => 0];
}
$windowSeconds = 60;
if (($now - (int) $_SESSION[$rateKey]['ts']) >= $windowSeconds) {
    $_SESSION[$rateKey] = ['ts' => $now, 'count' => 0];
}
$_SESSION[$rateKey]['count'] = (int) ($_SESSION[$rateKey]['count'] ?? 0) + 1;
$maxPerMinute = 20;
if ((int) $_SESSION[$rateKey]['count'] > $maxPerMinute) {
    sendAmaJson(['valid' => false, 'message' => 'Too many verification requests. Try again shortly.'], 429);
}

$lastName  = ama_verify_normalize_last_name((string) ($_POST['lastname'] ?? ''));
$amaNumber = ama_verify_normalize_number((string) ($_POST['ama_number'] ?? ''));

$result = ama_verify_membership($amaNumber, $lastName);
sendAmaJson(ama_verify_to_api_json($result));
