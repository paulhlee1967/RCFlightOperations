<?php
/**
 * Securely serve membership application uploads (badge, FAA card, signature).
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/membership_application.php';

requireLogin();
if (!canEditMembers() && !canProcessMemberships()) {
    http_response_code(403);
    exit;
}

$applicationId = isset($_GET['application_id']) ? (int) $_GET['application_id'] : 0;
$kind = trim((string) ($_GET['kind'] ?? ''));
if ($applicationId <= 0 || $kind === '') {
    http_response_code(404);
    exit;
}

$file = application_file_resolve($pdo, $applicationId, $kind);
if (!$file['ok'] || empty($file['path'])) {
    http_response_code(404);
    exit;
}

$download = isset($_GET['download']) && $_GET['download'] === '1';
header('Content-Type: ' . ($file['mime'] ?? 'application/octet-stream'));
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . ($file['filename'] ?? 'file') . '"');
header('Cache-Control: private, max-age=300');
readfile((string) $file['path']);
