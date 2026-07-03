<?php
/**
 * api_webhook_application.php
 *
 * Machine-to-machine endpoint for WPForms submissions via Uncanny Automator.
 * POST JSON body + X-Webhook-Secret header.
 */
ob_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/application_webhook_config.php';
require_once __DIR__ . '/includes/wpforms_application.php';

flightops_send_security_headers();

function webhook_send_json(array $data, int $status = 200): void
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
    webhook_send_json(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$secret = application_webhook_secret($pdo);
if ($secret === '') {
    webhook_send_json(['ok' => false, 'error' => 'Webhook is not configured.'], 503);
}

$provided = '';
if (!empty($_SERVER['HTTP_X_WEBHOOK_SECRET'])) {
    $provided = trim((string) $_SERVER['HTTP_X_WEBHOOK_SECRET']);
} elseif (!empty($_SERVER['HTTP_AUTHORIZATION']) && str_starts_with((string) $_SERVER['HTTP_AUTHORIZATION'], 'Bearer ')) {
    $provided = trim(substr((string) $_SERVER['HTTP_AUTHORIZATION'], 7));
}

if ($provided === '' || !hash_equals($secret, $provided)) {
    webhook_send_json(['ok' => false, 'error' => 'Unauthorized.'], 401);
}

$raw = file_get_contents('php://input');
if ($raw === false || trim($raw) === '') {
    webhook_send_json(['ok' => false, 'error' => 'Empty request body.'], 400);
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    webhook_send_json(['ok' => false, 'error' => 'Invalid JSON body.'], 400);
}

try {
    $result = application_receive_webhook($pdo, $payload);
} catch (Throwable $e) {
    error_log('application webhook error: ' . $e->getMessage());
    webhook_send_json(['ok' => false, 'error' => 'Server error processing application.'], 500);
}

if (!$result['ok']) {
    webhook_send_json([
        'ok'    => false,
        'error' => $result['error'] ?? 'Could not store application.',
    ], 422);
}

webhook_send_json([
    'ok'             => true,
    'application_id' => $result['application_id'],
    'duplicate'      => $result['duplicate'] ?? false,
]);
