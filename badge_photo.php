<?php
/**
 * Serve member photo for badge printing and for member list/detail display.
 * Any user who can view members (edit or process) may load photos; the
 * badge_designer feature is not required for viewing photos in the list.
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

$stmt = $pdo->prepare('SELECT photo_path FROM members WHERE id = ?');
$stmt->execute([$memberId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row || empty($row['photo_path'])) {
    http_response_code(404);
    exit;
}

$relative = ltrim((string) $row['photo_path'], '/');
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
$mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'];
$mime = $mimes[$ext] ?? 'image/jpeg';
header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=60');
readfile($path);
exit;
