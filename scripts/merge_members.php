#!/usr/bin/env php
<?php
/**
 * Merge duplicate member records (one-time / selective cleanup).
 *
 * Uses the same duplicate groups as Reports → Possible duplicate members.
 *
 * Usage:
 *   php scripts/merge_members.php --list
 *   php scripts/merge_members.php --write-plan=merge_plan.txt
 *   php scripts/merge_members.php --keeper=123 --duplicate=456
 *   php scripts/merge_members.php --plan=merge_plan.txt
 *   php scripts/merge_members.php --plan=merge_plan.txt --execute
 *
 * Options:
 *   --list                  Show duplicate groups from the report logic
 *   --write-plan=FILE       Write suggested keeper/duplicate pairs (edit file, remove unwanted lines)
 *   --plan=FILE             Merge pairs listed in FILE (one "keeper duplicate" per line)
 *   --keeper=ID             Keeper member id (combine with --duplicate)
 *   --duplicate=ID[,ID...]  Duplicate member id(s) to fold into keeper
 *   --execute               Apply changes (default is dry run)
 *   --min-confidence=LEVEL  Filter --list / --write-plan: exact|probable (default: all)
 */

require_once __DIR__ . '/../includes/cli_only_script.php';

flightops_require_cli();

$mergeIncludes = ['member_match.php', 'member_merge.php', 'audit_log.php'];
foreach ($mergeIncludes as $includeFile) {
    $includePath = __DIR__ . '/../includes/' . $includeFile;
    if (!is_file($includePath)) {
        fwrite(STDOUT, "Missing required file: includes/{$includeFile}\n");
        fwrite(STDOUT, "Upload includes/member_merge.php and related files, then retry.\n");
        exit(1);
    }
    require_once $includePath;
}

/**
 * Parse CLI args without relying solely on getopt() (more reliable on shared hosting).
 *
 * @return array{
 *   list: bool,
 *   execute: bool,
 *   write-plan: string,
 *   plan: string,
 *   keeper: int,
 *   duplicate: string,
 *   min-confidence: string,
 *   help: bool
 * }
 */
function merge_members_parse_argv(array $argv): array
{
    $parsed = [
        'list'             => false,
        'execute'          => false,
        'write-plan'       => '',
        'plan'             => '',
        'keeper'           => 0,
        'duplicate'        => '',
        'min-confidence'   => '',
        'help'             => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--list') {
            $parsed['list'] = true;
            continue;
        }
        if ($arg === '--execute') {
            $parsed['execute'] = true;
            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            $parsed['help'] = true;
            continue;
        }
        if (str_starts_with($arg, '--write-plan=')) {
            $parsed['write-plan'] = substr($arg, strlen('--write-plan='));
            continue;
        }
        if (str_starts_with($arg, '--plan=')) {
            $parsed['plan'] = substr($arg, strlen('--plan='));
            continue;
        }
        if (str_starts_with($arg, '--keeper=')) {
            $parsed['keeper'] = (int) substr($arg, strlen('--keeper='));
            continue;
        }
        if (str_starts_with($arg, '--duplicate=')) {
            $parsed['duplicate'] = substr($arg, strlen('--duplicate='));
            continue;
        }
        if (str_starts_with($arg, '--min-confidence=')) {
            $parsed['min-confidence'] = strtolower(substr($arg, strlen('--min-confidence=')));
        }
    }

    return $parsed;
}

function merge_members_print_help(): void
{
    echo "RC Flight Operations — merge duplicate members\n\n";
    echo "Usage:\n";
    echo "  php scripts/merge_members.php --list\n";
    echo "  php scripts/merge_members.php --write-plan=merge_plan.txt\n";
    echo "  php scripts/merge_members.php --plan=merge_plan.txt\n";
    echo "  php scripts/merge_members.php --keeper=123 --duplicate=456\n";
    echo "  php scripts/merge_members.php --plan=merge_plan.txt --execute\n\n";
    echo "Add --execute to apply changes (default is dry run).\n";
}

$parsedArgv = merge_members_parse_argv($argv);

if ($parsedArgv['help']) {
    merge_members_print_help();
    exit(0);
}

$baseDir = dirname(__DIR__);
if (!is_file($baseDir . '/config.php')) {
    fwrite(STDOUT, "Missing config.php. Run from project root or ensure config exists.\n");
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
    fwrite(STDOUT, 'Database connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

try {
    ensureMembershipYearsTable($pdo);
} catch (Throwable $e) {
    fwrite(STDOUT, 'Database setup failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$execute        = $parsedArgv['execute'];
$list           = $parsedArgv['list'];
$writePlanPath  = $parsedArgv['write-plan'];
$planPath       = $parsedArgv['plan'];
$keeperArg      = $parsedArgv['keeper'];
$duplicateArg   = $parsedArgv['duplicate'];
$minConfidence  = $parsedArgv['min-confidence'];

if ($minConfidence !== '' && !in_array($minConfidence, ['exact', 'probable'], true)) {
    fwrite(STDOUT, "Invalid --min-confidence. Use exact or probable.\n");
    exit(1);
}

function merge_members_filter_groups(array $groups, string $minConfidence): array
{
    if ($minConfidence === '') {
        return $groups;
    }

    $allowed = $minConfidence === 'exact' ? ['exact'] : ['exact', 'probable'];

    return array_values(array_filter(
        $groups,
        static function (array $group) use ($allowed): bool {
            return in_array((string) ($group['confidence'] ?? ''), $allowed, true);
        }
    ));
}

function merge_members_print_preview(array $preview, bool $execute): void
{
    echo "Keeper:    {$preview['keeper_label']}\n";
    echo "Duplicate: {$preview['duplicate_label']}\n";

    if ($preview['warnings'] !== []) {
        echo "Warnings:\n";
        foreach ($preview['warnings'] as $warning) {
            echo "  - {$warning}\n";
        }
    }

    if ($preview['errors'] !== []) {
        echo "Errors:\n";
        foreach ($preview['errors'] as $error) {
            echo "  - {$error}\n";
        }
        return;
    }

    echo "Duplicate child rows: payments={$preview['duplicate_counts']['payments']}"
        . ", fulfillments={$preview['duplicate_counts']['member_fulfillments']}"
        . ", membership_years={$preview['duplicate_counts']['member_membership_years']}"
        . ", incidents={$preview['duplicate_counts']['incidents']}\n";

    if ($preview['scalar_updates'] !== []) {
        echo "Field updates on keeper:\n";
        foreach ($preview['scalar_updates'] as $field => $value) {
            $display = is_string($value) ? str_replace("\n", '\\n', $value) : (string) $value;
            if (strlen($display) > 80) {
                $display = substr($display, 0, 77) . '...';
            }
            echo "  - {$field}: {$display}\n";
        }
    }

    echo "Steps:\n";
    foreach ($preview['steps'] as $step) {
        echo "  - {$step}\n";
    }

    if ($execute) {
        echo "Result: MERGED\n";
    } else {
        echo "Result: DRY RUN (add --execute to apply)\n";
    }
    echo "\n";
}

function merge_members_run_pair(PDO $pdo, string $baseDir, int $keeperId, int $duplicateId, bool $execute): bool
{
    $preview = member_merge_preview($pdo, $keeperId, $duplicateId);
    merge_members_print_preview($preview, false);

    if (!$preview['ok']) {
        return false;
    }

    if (!$execute) {
        return true;
    }

    $result = member_merge_execute($pdo, $keeperId, $duplicateId);
    if (!$result['ok']) {
        echo "FAILED: " . ($result['error'] ?? 'unknown error') . "\n\n";
        return false;
    }

    if (!empty($result['deleted_photo'])) {
        member_merge_unlink_photo($baseDir, (string) $result['deleted_photo']);
    }

    audit_log(
        $pdo,
        0,
        'member_merge',
        'member',
        $keeperId,
        json_encode(['duplicate_id' => $duplicateId], JSON_THROW_ON_ERROR)
    );

    echo "APPLIED: merged #{$duplicateId} into #{$keeperId}\n\n";
    return true;
}

// ── List duplicate groups ─────────────────────────────────────────────────────
if ($list || $writePlanPath !== '') {
    try {
        $groups = merge_members_filter_groups(member_match_scan_duplicates($pdo), $minConfidence);
    } catch (Throwable $e) {
        fwrite(STDOUT, 'Failed to scan duplicates: ' . $e->getMessage() . "\n");
        exit(1);
    }

    if ($writePlanPath !== '') {
        $pairs = member_merge_suggested_pairs_from_groups($groups);
        $lines = [
            '# Member merge plan — generated ' . date('Y-m-d H:i:s'),
            '# Format: keeper_id duplicate_id',
            '# Delete or comment out (#) pairs you do NOT want to merge.',
            '# Keeper is the lowest member id in each group; review before running.',
            '',
        ];
        $lastGroup = null;
        foreach ($pairs as $pair) {
            $groupKey = $pair['confidence'] . '|' . $pair['method'] . '|' . $pair['group_label'];
            if ($groupKey !== $lastGroup) {
                $lines[] = sprintf(
                    '# %s / %s — %s',
                    member_match_confidence_label($pair['confidence']),
                    member_match_method_label($pair['method']),
                    $pair['group_label']
                );
                $lastGroup = $groupKey;
            }
            $lines[] = $pair['keeper_id'] . ' ' . $pair['duplicate_id'];
        }
        if ($pairs === []) {
            $lines[] = '# No duplicate groups found.';
        }
        file_put_contents($writePlanPath, implode("\n", $lines) . "\n");
        echo "Wrote " . count($pairs) . " suggested pair(s) to {$writePlanPath}\n";
        echo "Edit the file (remove unwanted lines), then run:\n";
        echo "  php scripts/merge_members.php --plan={$writePlanPath}\n";
        echo "  php scripts/merge_members.php --plan={$writePlanPath} --execute\n";
        exit(0);
    }

    echo "Possible duplicate member groups (DB: {$db['name']} @ {$db['host']})\n";
    if ($minConfidence !== '') {
        echo "Filter: min-confidence={$minConfidence}\n";
    }
    echo $execute ? "Mode: EXECUTE (listing only — no merges from --list)\n\n" : "Mode: LIST\n\n";

    if ($groups === []) {
        echo "No duplicate groups found.\n";
        exit(0);
    }

    $groupNum = 0;
    foreach ($groups as $group) {
        $groupNum++;
        $ids = array_map('intval', $group['member_ids'] ?? []);
        sort($ids);
        $keeper = $ids[0] ?? 0;
        echo "Group {$groupNum}: " . member_match_confidence_label((string) $group['confidence'])
            . ' / ' . member_match_method_label((string) $group['method']) . "\n";
        foreach ($group['members'] as $member) {
            $id = (int) ($member['id'] ?? 0);
            $marker = $id === $keeper ? ' [suggested keeper]' : '';
            echo '  #' . $id . ' ' . trim((string) ($member['last_name'] ?? '') . ', ' . (string) ($member['first_name'] ?? ''));
            if (!empty($member['email'])) {
                echo ' <' . $member['email'] . '>';
            }
            if (!empty($member['ama_number'])) {
                echo ' AMA:' . $member['ama_number'];
            }
            echo $marker . "\n";
        }
        if (count($ids) >= 2) {
            echo '  Suggested merges:';
            for ($i = 1, $n = count($ids); $i < $n; $i++) {
                echo " --keeper={$keeper} --duplicate={$ids[$i]}";
            }
            echo "\n";
        }
        echo "\n";
    }

    echo count($groups) . " group(s) found.\n";
    echo "Next: php scripts/merge_members.php --write-plan=merge_plan.txt\n";
    exit(0);
}

// ── Plan file or explicit pair(s) ─────────────────────────────────────────────
$pairs = [];
if ($planPath !== '') {
    try {
        $pairs = member_merge_parse_plan_file($planPath);
    } catch (RuntimeException $e) {
        fwrite(STDOUT, $e->getMessage() . "\n");
        exit(1);
    }
} elseif ($keeperArg > 0 && $duplicateArg !== '') {
    $duplicateIds = array_values(array_unique(array_filter(array_map('intval', preg_split('/\s*,\s*/', $duplicateArg) ?: []))));
    foreach ($duplicateIds as $duplicateId) {
        $pairs[] = ['keeper_id' => $keeperArg, 'duplicate_id' => $duplicateId, 'line' => 0];
    }
}

if ($pairs === []) {
    fwrite(STDOUT, "Nothing to merge. Try --list, --write-plan=FILE, --plan=FILE, or --keeper=ID --duplicate=ID\n");
    fwrite(STDOUT, "Run: php scripts/merge_members.php --help\n");
    exit(1);
}

echo "Member merge\n";
echo $execute ? "Mode: EXECUTE\n" : "Mode: DRY RUN (add --execute to apply)\n";
echo 'Pairs: ' . count($pairs) . "\n\n";

$okCount = 0;
$failCount = 0;
foreach ($pairs as $pair) {
    $keeperId    = (int) $pair['keeper_id'];
    $duplicateId = (int) $pair['duplicate_id'];
    if (!empty($pair['line'])) {
        echo "Plan line {$pair['line']}: ";
    }
    $ok = merge_members_run_pair($pdo, $baseDir, $keeperId, $duplicateId, $execute);
    if ($ok) {
        $okCount++;
    } else {
        $failCount++;
    }
}

echo "Summary: {$okCount} ok, {$failCount} failed";
if (!$execute && $okCount > 0) {
    echo ' (dry run — no changes written)';
}
echo "\n";

if ($failCount > 0) {
    exit(1);
}

exit(0);
