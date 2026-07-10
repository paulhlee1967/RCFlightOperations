<?php
/**
 * Public membership application submit — validates, stores files, returns Stripe client secret.
 */
ob_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/membership_application.php';

flightops_send_security_headers();

function membership_submit_json(array $data, int $status = 200): void
{
    if (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    membership_submit_json(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

csrf_validate(['json' => true]);

$result = membership_application_submit($pdo, $_POST, $_FILES);

if (!$result['ok']) {
    membership_submit_json([
        'ok'     => false,
        'error'  => $result['error'],
        'errors' => $result['errors'],
    ], 422);
}

membership_submit_json([
    'ok'                 => true,
    'application_id'     => $result['application_id'],
    'client_secret'      => $result['client_secret'],
    'confirmation_token' => $result['confirmation_token'],
    'waive_payment'      => $result['waive_payment'],
]);
