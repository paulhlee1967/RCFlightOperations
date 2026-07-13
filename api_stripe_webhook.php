<?php
/**
 * Stripe webhook — finalize membership applications on successful payment.
 */
ob_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/stripe_config.php';
require_once __DIR__ . '/includes/membership_application.php';
require_once __DIR__ . '/includes/rate_limit.php';

flightops_send_security_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Rate limiting: 100 requests per minute per IP to prevent webhook flooding
$clientIp = rate_limit_get_client_ip($config ?? null);
if (!rate_limit_apply_preset($pdo, 'stripe_webhook', $clientIp)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests.']);
    exit;
}

// Payload size check: Stripe webhooks are typically small (<10KB)
$maxPayloadSize = 10240; // 10KB
if (isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > $maxPayloadSize) {
    http_response_code(413);
    echo json_encode(['error' => 'Payload too large.']);
    exit;
}

$payload = file_get_contents('php://input');
$sigHeader = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
$cfg = stripe_load_config($pdo);

if ($cfg['webhook_secret'] === '' || $payload === false) {
    http_response_code(503);
    exit;
}

if (!class_exists(\Stripe\Webhook::class)) {
    require_once __DIR__ . '/vendor/autoload.php';
}

try {
    $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $cfg['webhook_secret']);
} catch (Throwable $e) {
    error_log('stripe webhook verify failed: ' . $e->getMessage());
    http_response_code(400);
    exit;
}

if ($event->type === 'payment_intent.succeeded') {
    /** @var \Stripe\PaymentIntent $intent */
    $intent = $event->data->object;
    $applicationId = (int) ($intent->metadata['application_id'] ?? 0);
    if ($applicationId > 0) {
        membership_application_finalize_submission($pdo, $applicationId, $intent->id ?? null);
    }
}

http_response_code(200);
echo json_encode(['received' => true]);
