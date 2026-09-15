<?php
/**
 * Securely serve badge photo or FAA card for the logged-in member portal session.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/member_portal.php';

$memberId = member_portal_require_member();
$type = strtolower(trim((string) ($_GET['type'] ?? '')));

$stmt = $pdo->prepare('SELECT photo_path, faa_card_path FROM members WHERE id = ? LIMIT 1');
$stmt->execute([$memberId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit;
}

$relative = '';
if ($type === 'photo') {
    $relative = ltrim((string) ($row['photo_path'] ?? ''), '/');
} elseif ($type === 'faa') {
    $relative = ltrim((string) ($row['faa_card_path'] ?? ''), '/');
} else {
    http_response_code(404);
    exit;
}

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
    'gif'  => 'image/gif',
];
$mime = $mimes[$ext] ?? 'application/octet-stream';
$filename = ($type === 'photo' ? 'Badge_Photo_' : 'FAA_Card_') . $memberId . ($ext ? ('.' . $ext) : '');

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: private, max-age=60');
readfile($path);
exit;
