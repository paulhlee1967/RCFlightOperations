<?php
/**
 * Incident photo attachments: store under uploads/incidents/{id}/, serve via
 * incident_photo.php. JPEG/PNG/GIF, 5 MB each, up to 10 per incident.
 */

require_once __DIR__ . '/member_save.php';

/** Max photos stored per incident. */
const INCIDENT_PHOTOS_MAX = 10;

/** Max bytes per photo (5 MB). */
const INCIDENT_PHOTO_MAX_BYTES = 5242880;

/**
 * @return array<string, string> MIME => extension
 */
function incident_photo_allowed_mimes(): array
{
    return member_photo_allowed_mimes();
}

function incident_photos_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `incident_photos` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `incident_id` int unsigned NOT NULL,
              `file_path` varchar(512) NOT NULL DEFAULT '',
              `original_filename` varchar(255) DEFAULT NULL,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_incident_photos_incident` (`incident_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
    }
}

/**
 * Absolute directory for an incident's photos.
 */
function incident_photos_uploads_dir(int $incidentId): string
{
    return dirname(__DIR__) . '/uploads/incidents/' . max(0, $incidentId);
}

function incident_photo_relative_path(int $incidentId, int $photoId, string $ext): string
{
    $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', $ext) ?? '');

    return 'uploads/incidents/' . $incidentId . '/' . $photoId . '.' . $ext;
}

function incident_photo_is_local_path(string $path): bool
{
    $path = ltrim(str_replace('\\', '/', trim($path)), '/');

    return str_starts_with($path, 'uploads/incidents/');
}

/**
 * Absolute path for a relative uploads path, or '' if unsafe.
 */
function incident_photo_absolute_path(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || str_contains($relativePath, '..') || !incident_photo_is_local_path($relativePath)) {
        return '';
    }

    return dirname(__DIR__) . '/' . $relativePath;
}

function incident_photo_serve_url(int $photoId): string
{
    return 'incident_photo.php?id=' . $photoId;
}

/**
 * @return list<array{id:int, incident_id:int, file_path:string, original_filename:?string, created_at:string}>
 */
function incident_photos_fetch(PDO $pdo, int $incidentId): array
{
    if ($incidentId <= 0) {
        return [];
    }

    incident_photos_ensure_schema($pdo);

    try {
        $stmt = $pdo->prepare('
            SELECT id, incident_id, file_path, original_filename, created_at
            FROM incident_photos
            WHERE incident_id = ?
            ORDER BY created_at ASC, id ASC
        ');
        $stmt->execute([$incidentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $r): array {
            return [
                'id'                => (int) $r['id'],
                'incident_id'       => (int) $r['incident_id'],
                'file_path'         => (string) $r['file_path'],
                'original_filename' => $r['original_filename'] !== null ? (string) $r['original_filename'] : null,
                'created_at'        => (string) $r['created_at'],
            ];
        }, $rows);
    } catch (Throwable $e) {
        return [];
    }
}

function incident_photos_count(PDO $pdo, int $incidentId): int
{
    if ($incidentId <= 0) {
        return 0;
    }

    incident_photos_ensure_schema($pdo);

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM incident_photos WHERE incident_id = ?');
        $stmt->execute([$incidentId]);

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Normalize a multi-file $_FILES['photos'] structure into a list of single-file arrays.
 *
 * @param  array<string, mixed>  $filesField
 * @return list<array{name:string, type:string, tmp_name:string, error:int, size:int}>
 */
function incident_photos_normalize_uploads(array $filesField): array
{
    if (!isset($filesField['name'])) {
        return [];
    }

    // Single file uploaded without [] naming
    if (!is_array($filesField['name'])) {
        return [[
            'name'     => (string) $filesField['name'],
            'type'     => (string) ($filesField['type'] ?? ''),
            'tmp_name' => (string) ($filesField['tmp_name'] ?? ''),
            'error'    => (int) ($filesField['error'] ?? UPLOAD_ERR_NO_FILE),
            'size'     => (int) ($filesField['size'] ?? 0),
        ]];
    }

    $out = [];
    $count = count($filesField['name']);
    for ($i = 0; $i < $count; $i++) {
        $out[] = [
            'name'     => (string) ($filesField['name'][$i] ?? ''),
            'type'     => (string) ($filesField['type'][$i] ?? ''),
            'tmp_name' => (string) ($filesField['tmp_name'][$i] ?? ''),
            'error'    => (int) ($filesField['error'][$i] ?? UPLOAD_ERR_NO_FILE),
            'size'     => (int) ($filesField['size'][$i] ?? 0),
        ];
    }

    return $out;
}

/**
 * Save one uploaded photo for an incident.
 *
 * @param  array{name?:string, type?:string, tmp_name?:string, error?:int, size?:int}  $file
 * @return array{ok:bool, error:?string, photo_id:?int}
 */
function incident_photo_save_upload(PDO $pdo, int $incidentId, array $file): array
{
    if ($incidentId <= 0) {
        return ['ok' => false, 'error' => 'Invalid incident.', 'photo_id' => null];
    }

    incident_photos_ensure_schema($pdo);

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'No file uploaded.', 'photo_id' => null];
    }
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed (error ' . $error . ').', 'photo_id' => null];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'No file uploaded.', 'photo_id' => null];
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > INCIDENT_PHOTO_MAX_BYTES) {
        return ['ok' => false, 'error' => 'Photo exceeds the 5 MB size limit.', 'photo_id' => null];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $allowed = incident_photo_allowed_mimes();
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Photos must be JPEG, PNG, or GIF.', 'photo_id' => null];
    }

    if (incident_photos_count($pdo, $incidentId) >= INCIDENT_PHOTOS_MAX) {
        return [
            'ok'       => false,
            'error'    => 'This incident already has the maximum of ' . INCIDENT_PHOTOS_MAX . ' photos.',
            'photo_id' => null,
        ];
    }

    $original = basename((string) ($file['name'] ?? 'photo'));
    $original = mb_substr($original, 0, 255);

    try {
        $pdo->prepare('
            INSERT INTO incident_photos (incident_id, file_path, original_filename)
            VALUES (?, \'\', ?)
        ')->execute([$incidentId, $original !== '' ? $original : null]);
        $photoId = (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save photo record.', 'photo_id' => null];
    }

    if ($photoId <= 0) {
        return ['ok' => false, 'error' => 'Could not save photo record.', 'photo_id' => null];
    }

    $ext = $allowed[$mime];
    $relative = incident_photo_relative_path($incidentId, $photoId, $ext);
    $absolute = incident_photo_absolute_path($relative);
    if ($absolute === '') {
        $pdo->prepare('DELETE FROM incident_photos WHERE id = ?')->execute([$photoId]);

        return ['ok' => false, 'error' => 'Invalid photo path.', 'photo_id' => null];
    }

    $dir = dirname($absolute);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        $pdo->prepare('DELETE FROM incident_photos WHERE id = ?')->execute([$photoId]);

        return ['ok' => false, 'error' => 'Could not create photo directory.', 'photo_id' => null];
    }

    if (!move_uploaded_file($tmp, $absolute)) {
        $pdo->prepare('DELETE FROM incident_photos WHERE id = ?')->execute([$photoId]);

        return ['ok' => false, 'error' => 'Could not save uploaded photo.', 'photo_id' => null];
    }

    try {
        $pdo->prepare('UPDATE incident_photos SET file_path = ? WHERE id = ?')
            ->execute([$relative, $photoId]);
    } catch (Throwable $e) {
        @unlink($absolute);
        $pdo->prepare('DELETE FROM incident_photos WHERE id = ?')->execute([$photoId]);

        return ['ok' => false, 'error' => 'Could not finalize photo record.', 'photo_id' => null];
    }

    return ['ok' => true, 'error' => null, 'photo_id' => $photoId];
}

/**
 * @return array{ok:bool, path:?string, mime:?string, filename:?string, incident_id:?int}
 */
function incident_photo_resolve(PDO $pdo, int $photoId): array
{
    if ($photoId <= 0) {
        return ['ok' => false, 'path' => null, 'mime' => null, 'filename' => null, 'incident_id' => null];
    }

    incident_photos_ensure_schema($pdo);

    try {
        $stmt = $pdo->prepare('
            SELECT id, incident_id, file_path, original_filename
            FROM incident_photos
            WHERE id = ?
            LIMIT 1
        ');
        $stmt->execute([$photoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['ok' => false, 'path' => null, 'mime' => null, 'filename' => null, 'incident_id' => null];
    }

    if (!$row) {
        return ['ok' => false, 'path' => null, 'mime' => null, 'filename' => null, 'incident_id' => null];
    }

    $relative = trim((string) ($row['file_path'] ?? ''));
    $absolute = incident_photo_absolute_path($relative);
    $uploadsBase = realpath(dirname(__DIR__) . '/uploads');
    $resolved = $absolute !== '' ? realpath($absolute) : false;

    if (
        $resolved === false
        || $uploadsBase === false
        || !is_file($resolved)
        || !is_readable($resolved)
        || !str_starts_with($resolved, $uploadsBase . DIRECTORY_SEPARATOR)
    ) {
        return ['ok' => false, 'path' => null, 'mime' => null, 'filename' => null, 'incident_id' => null];
    }

    $ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
    $mimes = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
    ];
    $filename = trim((string) ($row['original_filename'] ?? ''));
    if ($filename === '') {
        $filename = 'incident-photo-' . $photoId . '.' . $ext;
    }

    return [
        'ok'          => true,
        'path'        => $resolved,
        'mime'        => $mimes[$ext] ?? 'image/jpeg',
        'filename'    => $filename,
        'incident_id' => (int) $row['incident_id'],
    ];
}

/**
 * Unlink a file if it resolves under uploads/.
 */
function incident_photo_safe_unlink(string $relativePath): void
{
    $absolute = incident_photo_absolute_path($relativePath);
    if ($absolute === '') {
        return;
    }
    $uploadsBase = realpath(dirname(__DIR__) . '/uploads');
    $resolved = realpath($absolute);
    if (
        $resolved === false
        || $uploadsBase === false
        || !is_file($resolved)
        || !str_starts_with($resolved, $uploadsBase . DIRECTORY_SEPARATOR)
    ) {
        return;
    }
    @unlink($resolved);
}

/**
 * Delete one photo row and its file.
 *
 * @return array{ok:bool, error:?string}
 */
function incident_photo_delete(PDO $pdo, int $photoId): array
{
    if ($photoId <= 0) {
        return ['ok' => false, 'error' => 'Invalid photo.'];
    }

    incident_photos_ensure_schema($pdo);

    try {
        $stmt = $pdo->prepare('SELECT id, incident_id, file_path FROM incident_photos WHERE id = ? LIMIT 1');
        $stmt->execute([$photoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not load photo.'];
    }

    if (!$row) {
        return ['ok' => false, 'error' => 'Photo not found.'];
    }

    incident_photo_safe_unlink((string) ($row['file_path'] ?? ''));

    try {
        $pdo->prepare('DELETE FROM incident_photos WHERE id = ?')->execute([$photoId]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not delete photo record.'];
    }

    $incidentId = (int) ($row['incident_id'] ?? 0);
    if ($incidentId > 0) {
        $dir = incident_photos_uploads_dir($incidentId);
        if (is_dir($dir)) {
            $remaining = glob($dir . '/*') ?: [];
            if ($remaining === []) {
                @rmdir($dir);
            }
        }
    }

    return ['ok' => true, 'error' => null];
}

/**
 * Delete all photos for an incident (filesystem + DB rows).
 */
function incident_photos_delete_all(PDO $pdo, int $incidentId): void
{
    if ($incidentId <= 0) {
        return;
    }

    incident_photos_ensure_schema($pdo);

    $photos = incident_photos_fetch($pdo, $incidentId);
    foreach ($photos as $photo) {
        incident_photo_safe_unlink($photo['file_path']);
    }

    try {
        $pdo->prepare('DELETE FROM incident_photos WHERE incident_id = ?')->execute([$incidentId]);
    } catch (Throwable $e) {
    }

    $dir = incident_photos_uploads_dir($incidentId);
    if (is_dir($dir)) {
        $left = glob($dir . '/*') ?: [];
        foreach ($left as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
