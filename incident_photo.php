<?php
/**
 * Securely serve an incident photo. Never expose uploads/incidents/ directly.
 *
 * Access: anyone who can view the incident log (report viewers + editors).
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/incident_photos.php';

requireLogin();
if (!canViewReports() && !canEditMembers()) {
    http_response_code(403);
    exit;
}

$photoId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($photoId <= 0) {
    http_response_code(404);
    exit;
}

$file = incident_photo_resolve($pdo, $photoId);
if (!$file['ok'] || empty($file['path'])) {
    http_response_code(404);
    exit;
}

$download = isset($_GET['download']) && $_GET['download'] === '1';
$filename = (string) ($file['filename'] ?? 'incident-photo.jpg');
$filename = str_replace(['"', "\r", "\n"], '', $filename);

header('Content-Type: ' . ($file['mime'] ?? 'image/jpeg'));
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
header('Cache-Control: private, max-age=300');
readfile((string) $file['path']);
exit;
