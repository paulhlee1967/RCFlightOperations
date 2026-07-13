<?php
/**
 * Import member photos from "Lastname_Firstname.ext" into uploads/member_photos as {id}.ext
 * and set each member's photo_path in the DB.
 *
 * Usage:
 *   php scripts/import_member_photos.php [source_dir]
 *
 * If source_dir is omitted, uses "member_photos_import" in the project root (create it and
 * put your Lastname_Firstname.jpg files there).
 *
 * Files must be named: Lastname_Firstname.extension (one underscore between last and first).
 * Supported extensions: jpg, jpeg, png, gif, bmp, wmf. jpeg is stored as .jpg.
 * Files with no extension are accepted; type is detected from file content (finfo).
 */

require_once __DIR__ . '/../includes/cli_only_script.php';
flightops_require_cli();

require_once dirname(__DIR__) . '/includes/member_save.php';

$baseDir = dirname(__DIR__);
if (!is_file($baseDir . '/config.php')) {
    fwrite(STDERR, "Missing config.php. Run from project root or ensure config exists.\n");
    exit(1);
}

$config = require $baseDir . '/config.php';
$db = $config['db'];
$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    $db['host'],
    $db['name'],
    $db['charset'] ?? 'utf8mb4'
);

try {
    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, 'Database connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$sourceDir = isset($argv[1]) ? $argv[1] : $baseDir . '/member_photos_import';
if (!is_dir($sourceDir)) {
    fwrite(STDERR, "Source directory does not exist: $sourceDir\n");
    fwrite(STDERR, "Create it and put your Lastname_Firstname.jpg files there, or pass a path.\n");
    exit(1);
}

$destBase = $baseDir . '/uploads/member_photos';
if (!is_dir($destBase)) {
    mkdir($destBase, 0755, true);
}

// Extension -> storage extension (lowercase). jpeg stored as jpg.
$allowedExt = [
    'jpg' => 'jpg', 'jpeg' => 'jpg', 'png' => 'png', 'gif' => 'gif',
    'bmp' => 'bmp', 'wmf' => 'wmf',
];
// Mime type -> storage extension (for files with no extension)
$mimeToExt = [
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
    'image/bmp' => 'bmp', 'image/x-ms-bmp' => 'bmp',
    'image/wmf' => 'wmf', 'application/x-msmetafile' => 'wmf',
];
$finfo = new finfo(FILEINFO_MIME_TYPE);

$stmt = $pdo->prepare('
    SELECT id, first_name, last_name
    FROM members
    WHERE LOWER(TRIM(last_name)) = LOWER(?) AND LOWER(TRIM(first_name)) = LOWER(?)
');

$copied = 0;
$skipped = 0;
$noMatch = [];
$errors = [];

$files = new DirectoryIterator($sourceDir);
foreach ($files as $file) {
    if ($file->isDot() || $file->isDir()) {
        continue;
    }
    $basename = $file->getFilename();
    $ext = strtolower($file->getExtension());
    $storeExt = null;

    if ($ext !== '' && isset($allowedExt[$ext])) {
        $storeExt = $allowedExt[$ext];
    } else {
        // No extension or unknown extension: detect from file content
        $mime = @$finfo->file($file->getPathname());
        $storeExt = $mimeToExt[$mime] ?? null;
        if ($storeExt === null) {
            $skipped++;
            continue;
        }
    }

    // Parse "Lastname_Firstname" from filename (with or without extension)
    $namePart = pathinfo($basename, PATHINFO_FILENAME);
    $pos = strpos($namePart, '_');
    if ($pos === false) {
        $noMatch[] = $basename . ' (no underscore in name)';
        continue;
    }
    $lastName = trim(substr($namePart, 0, $pos));
    $firstName = trim(substr($namePart, $pos + 1));
    if ($lastName === '' || $firstName === '') {
        $noMatch[] = $basename . ' (empty first or last name)';
        continue;
    }

    $stmt->execute([$lastName, $firstName]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($members)) {
        $noMatch[] = $basename . " (no member: $lastName, $firstName)";
        continue;
    }

    $sourcePath = $file->getPathname();

    foreach ($members as $m) {
        $destFilename = $m['id'] . '.' . $storeExt;
        $destPath = $destBase . '/' . $destFilename;
        $photoPath = 'uploads/member_photos/' . $destFilename;

        if (!copy($sourcePath, $destPath)) {
            $errors[] = "Failed to copy $basename -> $destFilename";
            continue;
        }
        member_upload_remove_id_files($destBase, (int) $m['id'], $destFilename);
        $pdo->prepare('UPDATE members SET photo_path = ? WHERE id = ?')
            ->execute([$photoPath, $m['id']]);
        echo "  {$basename} -> uploads/member_photos/{$destFilename} (id {$m['id']}, {$m['last_name']}, {$m['first_name']})\n";
        $copied++;
    }
}

echo "\nDone. Copied/updated $copied photo(s). Skipped $skipped non-image file(s).\n";
if (!empty($noMatch)) {
    echo "\nNo matching member (skipped):\n";
    foreach ($noMatch as $n) {
        echo "  - $n\n";
    }
}
if (!empty($errors)) {
    echo "\nErrors:\n";
    foreach ($errors as $e) {
        echo "  - $e\n";
    }
}
