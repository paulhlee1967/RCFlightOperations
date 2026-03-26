<?php
/**
 * AMA membership verification API for club management.
 * Accepts last name + AMA number, queries AMA website, returns JSON with
 * validity and expiration date (and life member flag) for use on the Compliance tab.
 */
ob_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/security_headers.php';

flightops_send_security_headers();

/** Send JSON and exit; discards any buffered output (e.g. PHP notices) so response is valid JSON only. */
function sendAmaJson($data, int $status = 200) {
    if (ob_get_level()) ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

requireLogin();
if (!featureEnabled('ama_lookup')) { http_response_code(404); echo json_encode(['error'=>'Feature not available']); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendAmaJson(['valid' => false, 'message' => 'Method not allowed.'], 405);
}
csrf_validate(['json' => true]);

$lastName  = trim($_POST['lastname'] ?? '');
$amaNumber = trim($_POST['ama_number'] ?? '');

// Per-user, per-minute throttle (session-backed). Clearing cookies or switching
// browsers resets the counter. That is acceptable here: single-club deployment,
// authenticated users only, and the response is non-sensitive AMA validity metadata.
// For stricter abuse resistance (public multi-tenant APIs), use IP- or DB-backed limits.
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

if ($amaNumber === '' || $lastName === '') {
    sendAmaJson(['valid' => false, 'message' => 'AMA number and last name are required.']);
}

$formBuildID = getAmaFormBuildID();
if ($formBuildID === null) {
    sendAmaJson(['valid' => false, 'message' => 'Unable to reach AMA verification service. Try again later.']);
}

$result = getAmaMembershipData($amaNumber, $lastName, $formBuildID);
sendAmaJson($result);

// ---------------------------------------------------------------------------
// Helpers: query AMA site and return JSON (no redirects)
//
// This integration scrapes the AMA public verification page HTML to obtain
// Drupal form_build_id (and related tokens). If the AMA site is redesigned,
// verification may fail until this code is updated — form_build_id extraction
// is the most likely breakage point.
// ---------------------------------------------------------------------------

function getAmaFormBuildID() {
    $url = 'https://www.modelaircraft.org/membership/verify';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    $html = curl_exec($ch);
    $err = curl_errno($ch);
    curl_close($ch);
    if ($err || $html === false) return null;
    if (preg_match('/name="form_build_id" value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Query AMA and return array: valid (bool), message (string), expiration (Y-m-d or null), life_member (bool).
 */
function getAmaMembershipData($amaNumber, $lastName, $formBuildID) {
    $url = 'https://www.modelaircraft.org/membership/verify?ajax_form=1&_wrapper_format=drupal_ajax';
    $fields = [
        'ama_number'    => $amaNumber,
        'last_name'     => $lastName,
        'form_build_id' => $formBuildID,
        'form_id'       => 'membership_verify_form'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response === false) {
        return ['valid' => false, 'message' => 'Verification request failed. Try again later.', 'expiration' => null, 'life_member' => false];
    }

    if (str_contains($response, 'No match found')) {
        return ['valid' => false, 'message' => 'No match found for this AMA number and last name.', 'expiration' => null, 'life_member' => false];
    }

    $validPatterns = ['is valid until', 'The Life membership', 'Temporary Status'];
    $invalidPatterns = ['Park Pilot', 'Trial membership'];

    $hasValid = false;
    foreach ($validPatterns as $p) {
        if (strpos($response, $p) !== false) { $hasValid = true; break; }
    }
    $hasInvalid = false;
    foreach ($invalidPatterns as $p) {
        if (strpos($response, $p) !== false) { $hasInvalid = true; break; }
    }

    if (!$hasValid || $hasInvalid) {
        return ['valid' => false, 'message' => 'Membership could not be verified or is not a full membership.', 'expiration' => null, 'life_member' => false];
    }

    $isLifetime = (strpos($response, 'The Life membership') !== false);
    $expireDateString = null; // m/d/Y

    if ($isLifetime) {
        $d = new DateTime();
        $d->modify('+100 years');
        $expireDateString = $d->format('m/d/Y');
    } else {
        if (preg_match('/valid until (.*?)\./', $response, $m)) {
            $expireDateString = trim(stripslashes($m[1]));
        }
    }

    if ($expireDateString === null) {
        return ['valid' => true, 'message' => 'Verified.', 'expiration' => null, 'life_member' => $isLifetime];
    }

    $dt = DateTime::createFromFormat('m/d/Y', $expireDateString);
    $yymd = $dt ? $dt->format('Y-m-d') : null;

    return [
        'valid'      => true,
        'message'    => $isLifetime ? 'Life membership verified.' : 'Membership verified.',
        'expiration' => $yymd,
        'life_member' => $isLifetime
    ];
}
