<?php
/**
 * scripts/reparse_applications.php
 *
 * Re-derive application_kind, form_season, membership_type_slot, etc. from raw_payload.
 * Use after parser fixes or when review data looks wrong for existing submissions.
 *
 *   php scripts/reparse_applications.php
 *   php scripts/reparse_applications.php --id=2
 *   php scripts/reparse_applications.php --all
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/wpforms_application.php';

$opts = getopt('', ['id::', 'all']);
$singleId = isset($opts['id']) ? (int) $opts['id'] : 0;
$all = array_key_exists('all', $opts);

if ($singleId <= 0 && !$all) {
    fwrite(STDERR, "Usage: php scripts/reparse_applications.php --id=ID\n");
    fwrite(STDERR, "   or: php scripts/reparse_applications.php --all\n");
    exit(1);
}

/** @var PDO $pdo */
global $pdo;

if ($singleId > 0) {
    $ids = [$singleId];
} else {
    $ids = array_map('intval', $pdo->query('SELECT id FROM member_applications ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
}

$updated = 0;
foreach ($ids as $id) {
    $result = application_reparse_stored_fields($pdo, $id);
    if (!$result['ok']) {
        fwrite(STDERR, "Application #{$id}: {$result['error']}\n");
        continue;
    }
    if ($result['updated']) {
        $updated++;
        echo "Updated application #{$id}\n";
    } else {
        echo "No change for application #{$id}\n";
    }
}

echo "Done. {$updated} application(s) updated.\n";
