#!/usr/bin/env php
<?php
/**
 * Audit member badge photos on disk vs members.photo_path in the database.
 *
 * Finds:
 *   - Orphan files in uploads/member_photos/ (on disk but not referenced by any member)
 *   - Missing files (member has photo_path but the file is gone)
 *   - Invalid photo_path values (outside uploads/, path traversal, unreadable)
 *   - Member ID mismatches (e.g. 42.jpg on disk but member #42 points elsewhere)
 *
 * Usage:
 *   php scripts/audit_member_photos.php
 *   php scripts/audit_member_photos.php --delete    # remove orphan files after listing them
 *
 * Exit code 0 when clean; 1 when issues are found (or delete failed).
 */

require_once __DIR__ . '/../includes/cli_only_script.php';
flightops_require_cli();

$opts = getopt('', ['delete', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php scripts/audit_member_photos.php [--delete]\n";
    echo "  --delete  Remove orphan files listed in the audit (safe: uploads/member_photos/ only)\n";
    exit(0);
}

require_once dirname(__DIR__) . '/includes/db.php';

$baseDir = dirname(__DIR__);
$photoDir = $baseDir . '/uploads/member_photos';
$doDelete = isset($opts['delete']);

/**
 * Normalize a stored photo_path to a project-relative path, or null if unusable.
 */
function audit_normalize_photo_path(string $path): ?string
{
    $path = trim($path);
    if ($path === '' || str_contains($path, '..')) {
        return null;
    }
    $path = ltrim(str_replace('\\', '/', $path), '/');
    return $path !== '' ? $path : null;
}

/**
 * Resolve a relative photo path to an absolute file path under the project root.
 */
function audit_resolve_photo_file(string $baseDir, string $relativePath): ?string
{
    $relativePath = audit_normalize_photo_path($relativePath);
    if ($relativePath === null) {
        return null;
    }
    $full = realpath($baseDir . '/' . $relativePath);
    if ($full === false || !is_file($full)) {
        return null;
    }
    $uploadsBase = realpath($baseDir . '/uploads');
    if ($uploadsBase === false) {
        return null;
    }
    if (!str_starts_with($full, $uploadsBase . DIRECTORY_SEPARATOR) && $full !== $uploadsBase) {
        return null;
    }
    return $full;
}

echo "Member photo audit\n";
echo "==================\n";
echo "Photo directory: uploads/member_photos/\n\n";

// ── Load DB references ───────────────────────────────────────────────────────
$stmt = $pdo->query("
    SELECT id, first_name, last_name, photo_path
    FROM members
    WHERE photo_path IS NOT NULL AND TRIM(photo_path) != ''
    ORDER BY id
");
$membersWithPhoto = $stmt->fetchAll(PDO::FETCH_ASSOC);

$referencedRelative = [];
$missingFiles = [];
$invalidPaths = [];
$idMismatches = [];

foreach ($membersWithPhoto as $row) {
    $memberId = (int) $row['id'];
    $rawPath = (string) $row['photo_path'];
    $relative = audit_normalize_photo_path($rawPath);

    if ($relative === null) {
        $invalidPaths[] = [
            'member_id' => $memberId,
            'name'      => trim($row['last_name'] . ', ' . $row['first_name']),
            'photo_path' => $rawPath,
            'reason'    => 'empty or unsafe path',
        ];
        continue;
    }

    if (!str_starts_with($relative, 'uploads/')) {
        $invalidPaths[] = [
            'member_id' => $memberId,
            'name'      => trim($row['last_name'] . ', ' . $row['first_name']),
            'photo_path' => $rawPath,
            'reason'    => 'not under uploads/',
        ];
        continue;
    }

    $full = audit_resolve_photo_file($baseDir, $relative);
    if ($full === null) {
        $missingFiles[] = [
            'member_id' => $memberId,
            'name'      => trim($row['last_name'] . ', ' . $row['first_name']),
            'photo_path' => $rawPath,
        ];
        continue;
    }

    $referencedRelative[$relative] = $memberId;

    // Expected naming: uploads/member_photos/{id}.{ext}
    if (preg_match('#^uploads/member_photos/(\d+)\.[a-z0-9]+$#i', $relative, $m)) {
        $fileMemberId = (int) $m[1];
        if ($fileMemberId !== $memberId) {
            $idMismatches[] = [
                'member_id'     => $memberId,
                'file_member_id' => $fileMemberId,
                'name'          => trim($row['last_name'] . ', ' . $row['first_name']),
                'photo_path'    => $rawPath,
            ];
        }
    }
}

// ── Scan disk ────────────────────────────────────────────────────────────────
$diskFiles = [];
if (is_dir($photoDir)) {
    foreach (scandir($photoDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = $photoDir . '/' . $entry;
        if (!is_file($full)) {
            continue;
        }
        $relative = 'uploads/member_photos/' . $entry;
        $diskFiles[$relative] = $full;
    }
} else {
    echo "Note: uploads/member_photos/ does not exist yet.\n\n";
}

$orphans = [];
foreach ($diskFiles as $relative => $full) {
    if (!isset($referencedRelative[$relative])) {
        $orphans[] = [
            'relative' => $relative,
            'full'     => $full,
            'bytes'    => (int) filesize($full),
        ];
    }
}

// ── Report ───────────────────────────────────────────────────────────────────
$issueCount = 0;

echo "=== Orphan files (on disk, not referenced by any member) ===\n";
echo 'Count: ' . count($orphans) . "\n";
if (count($orphans) === 0) {
    echo "(none)\n";
} else {
    $issueCount += count($orphans);
    $totalBytes = 0;
    printf("%-40s %10s %s\n", 'Path', 'Bytes', 'Note');
    foreach ($orphans as $o) {
        $totalBytes += $o['bytes'];
        $note = '';
        if (preg_match('#/(\d+)\.[a-z0-9]+$#i', $o['relative'], $m)) {
            $mid = (int) $m[1];
            $note = 'member #' . $mid . ' deleted or uses a different file';
        }
        printf("%-40s %10d %s\n", $o['relative'], $o['bytes'], $note);
    }
    echo 'Total orphan size: ' . number_format($totalBytes) . " bytes\n";
}
echo "\n";

echo "=== Missing files (member has photo_path but file is gone) ===\n";
echo 'Count: ' . count($missingFiles) . "\n";
if (count($missingFiles) === 0) {
    echo "(none)\n";
} else {
    $issueCount += count($missingFiles);
    printf("%-6s %-28s %s\n", 'ID', 'Name', 'photo_path');
    foreach ($missingFiles as $m) {
        printf("%-6d %-28s %s\n", $m['member_id'], mb_substr($m['name'], 0, 28), $m['photo_path']);
    }
}
echo "\n";

echo "=== Invalid photo_path values ===\n";
echo 'Count: ' . count($invalidPaths) . "\n";
if (count($invalidPaths) === 0) {
    echo "(none)\n";
} else {
    $issueCount += count($invalidPaths);
    printf("%-6s %-28s %-22s %s\n", 'ID', 'Name', 'Reason', 'photo_path');
    foreach ($invalidPaths as $m) {
        printf(
            "%-6d %-28s %-22s %s\n",
            $m['member_id'],
            mb_substr($m['name'], 0, 28),
            $m['reason'],
            mb_substr($m['photo_path'], 0, 60)
        );
    }
}
echo "\n";

echo "=== Member ID mismatches (filename id ≠ member id) ===\n";
echo 'Count: ' . count($idMismatches) . "\n";
if (count($idMismatches) === 0) {
    echo "(none)\n";
} else {
    $issueCount += count($idMismatches);
    printf("%-6s %-28s %-10s %s\n", 'ID', 'Name', 'File ID', 'photo_path');
    foreach ($idMismatches as $m) {
        printf(
            "%-6d %-28s %-10d %s\n",
            $m['member_id'],
            mb_substr($m['name'], 0, 28),
            $m['file_member_id'],
            $m['photo_path']
        );
    }
}
echo "\n";

echo "=== Summary ===\n";
echo 'Members with photo_path: ' . count($membersWithPhoto) . "\n";
echo 'Files on disk: ' . count($diskFiles) . "\n";
echo 'Referenced files found: ' . count($referencedRelative) . "\n";
echo 'Issues: ' . $issueCount . "\n";

if ($doDelete) {
    echo "\n=== Deleting orphan files ===\n";
    if (count($orphans) === 0) {
        echo "Nothing to delete.\n";
    } else {
        $deleted = 0;
        $failed = 0;
        foreach ($orphans as $o) {
            // Belt-and-suspenders: only delete under uploads/member_photos/
            $full = realpath($o['full']);
            $allowedBase = realpath($photoDir);
            if (
                $full === false
                || $allowedBase === false
                || (!str_starts_with($full, $allowedBase . DIRECTORY_SEPARATOR) && $full !== $allowedBase)
            ) {
                echo "Skip (unsafe path): {$o['relative']}\n";
                $failed++;
                continue;
            }
            if (@unlink($full)) {
                echo "Deleted: {$o['relative']}\n";
                $deleted++;
            } else {
                echo "Failed:  {$o['relative']}\n";
                $failed++;
            }
        }
        echo "Deleted {$deleted}, failed {$failed}.\n";
        if ($failed > 0) {
            exit(1);
        }
        if ($deleted > 0) {
            $issueCount -= $deleted;
        }
    }
}

if ($issueCount > 0) {
    echo "\nAudit finished with issues.\n";
    exit(1);
}

echo "\nAudit clean.\n";
exit(0);
