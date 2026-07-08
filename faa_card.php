<?php
/**
 * Securely serve a member's FAA card attachment (PDF/JPG/PNG).
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();
if (!canEditMembers() && !canProcessMemberships()) {
    http_response_code(403);
    exit;
}

$memberId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($memberId <= 0) {
    http_response_code(404);
    exit;
}

$stmt = $pdo->prepare('SELECT faa_card_path FROM members WHERE id = ?');
$stmt->execute([$memberId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row || empty($row['faa_card_path'])) {
    http_response_code(404);
    exit;
}

$relative = ltrim((string) $row['faa_card_path'], '/');
if ($relative === '' || str_contains($relative, '..')) {
    http_response_code(404);
    exit;
}

$path = realpath(__DIR__ . '/' . $relative);
$uploadsBase = realpath(__DIR__ . '/uploads');
if (
    $path === false
    || $uploadsBase === false
    || !is_file($path)
    || !is_readable($path)
    || (!str_starts_with($path, $uploadsBase . DIRECTORY_SEPARATOR) && $path !== $uploadsBase)
) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimes = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
];
$mime = $mimes[$ext] ?? 'application/octet-stream';

$download = isset($_GET['download']) && $_GET['download'] === '1';
$filename = 'FAA_Card_' . $memberId . ($ext ? ('.' . $ext) : '');
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
header('Cache-Control: private, max-age=60');
readfile($path);
exit;

