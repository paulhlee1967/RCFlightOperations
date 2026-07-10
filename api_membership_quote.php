<?php
/**
 * Public JSON fee quote for the membership application form.
 */
ob_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/membership_application.php';

flightops_send_security_headers();

function membership_quote_json(array $data, int $status = 200): void
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
    membership_quote_json(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

csrf_validate(['json' => true]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$kind = (string) ($_POST['application_kind'] ?? 'new');
$slot = (int) ($_POST['membership_type_slot'] ?? 0);
$coupon = (string) ($_POST['coupon_code'] ?? '');
$email = trim((string) ($_POST['email'] ?? ''));
$amaNumber = trim((string) ($_POST['ama_number'] ?? ''));

if ($slot < 1 || $slot > 4) {
    membership_quote_json(['ok' => false, 'error' => 'Select a membership type.'], 422);
}

if ($kind === 'renewal') {
    $amaSession = membership_application_ama_get_session();
    if ($amaSession === null) {
        membership_quote_json(['ok' => false, 'error' => 'Verify AMA membership before requesting a renewal quote.'], 422);
    }
    $renewalCheck = membership_application_renewal_eligibility(
        $pdo,
        (string) ($amaSession['ama_number'] ?? ''),
        (string) ($amaSession['first_name'] ?? ''),
        (string) ($amaSession['last_name'] ?? '')
    );
    if (!$renewalCheck['eligible']) {
        membership_quote_json([
            'ok'     => false,
            'error'  => $renewalCheck['message'],
            'errors' => ['application_kind' => $renewalCheck['message']],
        ], 422);
    }
}

if ($amaNumber === '') {
    $amaSession = membership_application_ama_get_session();
    if ($amaSession !== null) {
        $amaNumber = (string) ($amaSession['ama_number'] ?? '');
    }
}

$quote = membership_application_quote($pdo, $kind, $slot, $coupon, null, [
    'ama_number' => $amaNumber,
    'email'      => $email,
]);

membership_quote_json([
    'ok'    => true,
    'quote' => $quote,
]);
