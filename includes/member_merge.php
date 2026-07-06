<?php
/**
 * includes/member_merge.php
 *
 * Merge a duplicate member record into a keeper. Used by scripts/merge_members.php.
 */

require_once __DIR__ . '/membership_status.php';

/**
 * Scalar member columns merged onto the keeper (photo handled separately).
 *
 * @return list<string>
 */
function member_merge_scalar_columns(): array
{
    return [
        'title',
        'first_name',
        'last_name',
        'email',
        'phone',
        'birthday',
        'notes',
        'date_joined',
        'membership_type_slot',
        'membership_renewal_year',
        'inactive',
        'suspended',
        'life_member',
        'free_membership',
        'gate_key_number',
        'badge_printed_at',
        'ama_number',
        'ama_expiration',
        'ama_life_member',
        'faa_number',
        'faa_expiration',
        'emergency_contact_name',
        'emergency_contact_relationship',
        'emergency_contact_phone',
        'address_street',
        'address_street2',
        'address_city',
        'address_state',
        'address_postal_code',
    ];
}

/**
 * @param mixed $value
 */
function member_merge_value_empty($value): bool
{
    if ($value === null) {
        return true;
    }
    if (is_string($value)) {
        return trim($value) === '';
    }

    return false;
}

/**
 * @param array<string, mixed> $keeper
 * @param array<string, mixed> $duplicate
 * @return array{fields: array<string, mixed>, warnings: list<string>, errors: list<string>}
 */
function member_merge_build_scalar_updates(array $keeper, array $duplicate): array
{
    $updates  = [];
    $warnings = [];
    $errors   = [];

    foreach (member_merge_scalar_columns() as $column) {
        $kVal = $keeper[$column] ?? null;
        $dVal = $duplicate[$column] ?? null;

        if ($column === 'membership_renewal_year') {
            $kYear = (int) ($kVal ?? 0);
            $dYear = (int) ($dVal ?? 0);
            $best  = max($kYear, $dYear);
            if ($best > 0 && $best !== $kYear) {
                $updates[$column] = $best;
            }
            continue;
        }

        if ($column === 'date_joined') {
            if (member_merge_value_empty($kVal) && !member_merge_value_empty($dVal)) {
                $updates[$column] = $dVal;
            } elseif (!member_merge_value_empty($kVal) && !member_merge_value_empty($dVal) && (string) $dVal < (string) $kVal) {
                $updates[$column] = $dVal;
            }
            continue;
        }

        if (in_array($column, ['life_member', 'free_membership', 'ama_life_member'], true)) {
            if (!empty($dVal) && empty($kVal)) {
                $updates[$column] = 1;
            }
            continue;
        }

        if (in_array($column, ['inactive', 'suspended'], true)) {
            if (!empty($kVal) && empty($dVal)) {
                $updates[$column] = 0;
            }
            continue;
        }

        if ($column === 'badge_printed_at') {
            if (member_merge_value_empty($kVal) && !member_merge_value_empty($dVal)) {
                $updates[$column] = $dVal;
            } elseif (!member_merge_value_empty($kVal) && !member_merge_value_empty($dVal) && (string) $dVal > (string) $kVal) {
                $updates[$column] = $dVal;
            }
            continue;
        }

        if ($column === 'notes' && !member_merge_value_empty($kVal) && !member_merge_value_empty($dVal)) {
            if (trim((string) $dVal) !== trim((string) $kVal)) {
                $updates[$column] = trim((string) $kVal) . "\n\n--- merged from #" . (int) ($duplicate['id'] ?? 0) . " ---\n" . trim((string) $dVal);
            }
            continue;
        }

        if (member_merge_value_empty($kVal) && !member_merge_value_empty($dVal)) {
            $updates[$column] = $dVal;
        } elseif (!member_merge_value_empty($kVal) && !member_merge_value_empty($dVal) && (string) $kVal !== (string) $dVal) {
            if ($column === 'ama_number') {
                $errors[] = 'Both records have different AMA numbers (' . $kVal . ' vs ' . $dVal . '). Resolve manually before merging.';
            } elseif ($column === 'email') {
                $warnings[] = 'Different emails on file (keeper: ' . $kVal . ', duplicate: ' . $dVal . '). Keeper email is kept.';
            } elseif ($column === 'gate_key_number') {
                $warnings[] = 'Different gate keys on file (keeper: ' . $kVal . ', duplicate: ' . $dVal . '). Keeper key is kept.';
            }
        }
    }

    $kPhoto = trim((string) ($keeper['photo_path'] ?? ''));
    $dPhoto = trim((string) ($duplicate['photo_path'] ?? ''));
    if ($kPhoto === '' && $dPhoto !== '') {
        $updates['photo_path'] = $dPhoto;
    }

    return ['fields' => $updates, 'warnings' => $warnings, 'errors' => $errors];
}

/**
 * @return array<string, int>
 */
function member_merge_child_counts(PDO $pdo, int $memberId): array
{
    $tables = [
        'payments'               => 'SELECT COUNT(*) FROM payments WHERE member_id = ?',
        'member_fulfillments'    => 'SELECT COUNT(*) FROM member_fulfillments WHERE member_id = ?',
        'member_membership_years'=> 'SELECT COUNT(*) FROM member_membership_years WHERE member_id = ?',
        'incidents'              => 'SELECT COUNT(*) FROM incidents WHERE member_id = ?',
        'applications_matched'   => 'SELECT COUNT(*) FROM member_applications WHERE matched_member_id = ?',
        'applications_approved'  => 'SELECT COUNT(*) FROM member_applications WHERE approved_member_id = ?',
    ];
    $counts = [];
    foreach ($tables as $key => $sql) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$memberId]);
            $counts[$key] = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            $counts[$key] = 0;
        }
    }

    return $counts;
}

/**
 * @return list<array<string, mixed>>
 */
function member_merge_fulfillment_conflicts(PDO $pdo, int $keeperId, int $duplicateId): array
{
    $stmt = $pdo->prepare('
        SELECT dup.year,
               keeper.id AS keeper_id,
               dup.id AS duplicate_id,
               keeper.processed_at AS keeper_processed_at,
               dup.processed_at AS duplicate_processed_at
        FROM member_fulfillments dup
        INNER JOIN member_fulfillments keeper
            ON keeper.member_id = ? AND keeper.year = dup.year
        WHERE dup.member_id = ?
        ORDER BY dup.year
    ');
    $stmt->execute([$keeperId, $duplicateId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return list<array<string, mixed>>
 */
function member_merge_membership_year_conflicts(PDO $pdo, int $keeperId, int $duplicateId): array
{
    $stmt = $pdo->prepare('
        SELECT dup.year, keeper.id AS keeper_id, dup.id AS duplicate_id
        FROM member_membership_years dup
        INNER JOIN member_membership_years keeper
            ON keeper.member_id = ? AND keeper.year = dup.year
        WHERE dup.member_id = ?
        ORDER BY dup.year
    ');
    $stmt->execute([$keeperId, $duplicateId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return array<string, mixed>|null
 */
function member_merge_fetch_member(PDO $pdo, int $memberId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM members WHERE id = ?');
    $stmt->execute([$memberId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * @return array{
 *   ok: bool,
 *   keeper_id: int,
 *   duplicate_id: int,
 *   keeper_label: string,
 *   duplicate_label: string,
 *   scalar_updates: array<string, mixed>,
 *   warnings: list<string>,
 *   errors: list<string>,
 *   steps: list<string>,
 *   keeper_counts: array<string, int>,
 *   duplicate_counts: array<string, int>
 * }
 */
function member_merge_preview(PDO $pdo, int $keeperId, int $duplicateId): array
{
    $keeper   = member_merge_fetch_member($pdo, $keeperId);
    $duplicate = member_merge_fetch_member($pdo, $duplicateId);

    $result = [
        'ok'              => false,
        'keeper_id'       => $keeperId,
        'duplicate_id'    => $duplicateId,
        'keeper_label'    => '',
        'duplicate_label' => '',
        'scalar_updates'  => [],
        'warnings'        => [],
        'errors'          => [],
        'steps'           => [],
        'keeper_counts'   => [],
        'duplicate_counts'=> [],
    ];

    if ($keeperId <= 0 || $duplicateId <= 0) {
        $result['errors'][] = 'Keeper and duplicate IDs must be positive integers.';
        return $result;
    }
    if ($keeperId === $duplicateId) {
        $result['errors'][] = 'Keeper and duplicate cannot be the same member.';
        return $result;
    }
    if ($keeper === null) {
        $result['errors'][] = "Keeper member #{$keeperId} not found.";
        return $result;
    }
    if ($duplicate === null) {
        $result['errors'][] = "Duplicate member #{$duplicateId} not found.";
        return $result;
    }

    $result['keeper_label']    = member_merge_member_label($keeper);
    $result['duplicate_label'] = member_merge_member_label($duplicate);
    $result['keeper_counts']   = member_merge_child_counts($pdo, $keeperId);
    $result['duplicate_counts']= member_merge_child_counts($pdo, $duplicateId);

    $scalar = member_merge_build_scalar_updates($keeper, $duplicate);
    $result['scalar_updates'] = $scalar['fields'];
    $result['warnings']       = $scalar['warnings'];
    $result['errors']         = $scalar['errors'];

    if ($result['errors'] !== []) {
        return $result;
    }

    $steps = [];
    if ($result['scalar_updates'] !== []) {
        $steps[] = 'Update keeper #' . $keeperId . ' with ' . count($result['scalar_updates']) . ' field(s) from duplicate.';
    } else {
        $steps[] = 'No scalar field changes needed on keeper #' . $keeperId . '.';
    }

    $dupCounts = $result['duplicate_counts'];
    if ($dupCounts['payments'] > 0) {
        $steps[] = 'Repoint ' . $dupCounts['payments'] . ' payment(s) to keeper.';
    }
    if ($dupCounts['incidents'] > 0) {
        $steps[] = 'Repoint ' . $dupCounts['incidents'] . ' incident(s) to keeper.';
    }
    if ($dupCounts['applications_matched'] > 0 || $dupCounts['applications_approved'] > 0) {
        $steps[] = 'Repoint member application link(s): matched=' . $dupCounts['applications_matched']
            . ', approved=' . $dupCounts['applications_approved'] . '.';
    }

    $fulfillmentConflicts = member_merge_fulfillment_conflicts($pdo, $keeperId, $duplicateId);
    if ($fulfillmentConflicts !== []) {
        $steps[] = 'Resolve ' . count($fulfillmentConflicts) . ' fulfillment year conflict(s) (duplicate row removed; keeper row kept/enriched).';
    }
    $fulfillmentMoves = max(0, $dupCounts['member_fulfillments'] - count($fulfillmentConflicts));
    if ($fulfillmentMoves > 0) {
        $steps[] = 'Move ' . $fulfillmentMoves . ' fulfillment row(s) to keeper.';
    }

    $yearConflicts = member_merge_membership_year_conflicts($pdo, $keeperId, $duplicateId);
    if ($yearConflicts !== []) {
        $steps[] = 'Drop ' . count($yearConflicts) . ' duplicate membership-year row(s) where keeper already has that year.';
    }
    $yearMoves = max(0, $dupCounts['member_membership_years'] - count($yearConflicts));
    if ($yearMoves > 0) {
        $steps[] = 'Move ' . $yearMoves . ' membership-year row(s) to keeper.';
    }

    $steps[] = 'Delete duplicate member #' . $duplicateId . '.';

    $result['steps'] = $steps;
    $result['ok']    = true;

    return $result;
}

/**
 * @param array<string, mixed> $member
 */
function member_merge_member_label(array $member): string
{
    $name = trim((string) ($member['last_name'] ?? '') . ', ' . (string) ($member['first_name'] ?? ''));
    if ($name === ',') {
        $name = 'Member';
    }

    return $name . ' (#' . (int) ($member['id'] ?? 0) . ')';
}

/**
 * @return array{ok:bool, error:?string}
 */
function member_merge_apply_fulfillment_conflicts(PDO $pdo, int $keeperId, int $duplicateId): array
{
    $conflicts = member_merge_fulfillment_conflicts($pdo, $keeperId, $duplicateId);
    if ($conflicts === []) {
        return ['ok' => true, 'error' => null];
    }

    $load = $pdo->prepare('SELECT * FROM member_fulfillments WHERE id = ?');
    $update = $pdo->prepare('
        UPDATE member_fulfillments
        SET processed_at = ?,
            processed_by = COALESCE(processed_by, ?),
            renewal_type = COALESCE(renewal_type, ?),
            card_printed_at = COALESCE(card_printed_at, ?),
            card_printed_by = COALESCE(card_printed_by, ?),
            mailer_printed_at = COALESCE(mailer_printed_at, ?),
            mailer_printed_by = COALESCE(mailer_printed_by, ?)
        WHERE id = ?
    ');
    $delete = $pdo->prepare('DELETE FROM member_fulfillments WHERE id = ?');

    foreach ($conflicts as $conflict) {
        $keeperRowId = (int) $conflict['keeper_id'];
        $dupRowId    = (int) $conflict['duplicate_id'];

        $load->execute([$keeperRowId]);
        $keeperRow = $load->fetch(PDO::FETCH_ASSOC);
        $load->execute([$dupRowId]);
        $dupRow = $load->fetch(PDO::FETCH_ASSOC);
        if (!$keeperRow || !$dupRow) {
            continue;
        }

        $keeperProcessed = $keeperRow['processed_at'] ?? null;
        $dupProcessed    = $dupRow['processed_at'] ?? null;
        $winnerIsKeeper  = true;
        if ($keeperProcessed === null && $dupProcessed !== null) {
            $winnerIsKeeper = false;
        } elseif ($keeperProcessed !== null && $dupProcessed !== null && (string) $dupProcessed > (string) $keeperProcessed) {
            $winnerIsKeeper = false;
        }

        $winner = $winnerIsKeeper ? $keeperRow : $dupRow;
        $loser  = $winnerIsKeeper ? $dupRow : $keeperRow;

        $update->execute([
            $winner['processed_at'],
            $winner['processed_by'],
            $winner['renewal_type'],
            $winner['card_printed_at'],
            $winner['card_printed_by'],
            $winner['mailer_printed_at'],
            $winner['mailer_printed_by'],
            $keeperRowId,
        ]);
        $delete->execute([(int) $loser['id']]);
    }

    return ['ok' => true, 'error' => null];
}

/**
 * @return array{ok:bool, deleted_photo:?string, error:?string}
 */
function member_merge_execute(PDO $pdo, int $keeperId, int $duplicateId): array
{
    $preview = member_merge_preview($pdo, $keeperId, $duplicateId);
    if (!$preview['ok']) {
        return ['ok' => false, 'deleted_photo' => null, 'error' => implode(' ', $preview['errors'])];
    }

    $keeper    = member_merge_fetch_member($pdo, $keeperId);
    $duplicate = member_merge_fetch_member($pdo, $duplicateId);
    if ($keeper === null || $duplicate === null) {
        return ['ok' => false, 'deleted_photo' => null, 'error' => 'Member record disappeared before merge.'];
    }

    $pdo->beginTransaction();
    try {
        $updates = $preview['scalar_updates'];
        if ($updates !== []) {
            $sets   = [];
            $params = [];
            foreach ($updates as $column => $value) {
                $sets[]   = '`' . $column . '` = ?';
                $params[] = $value;
            }
            $params[] = $keeperId;
            $pdo->prepare('UPDATE members SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        }

        $conflictResult = member_merge_apply_fulfillment_conflicts($pdo, $keeperId, $duplicateId);
        if (!$conflictResult['ok']) {
            throw new RuntimeException($conflictResult['error'] ?? 'Fulfillment conflict resolution failed.');
        }

        $pdo->prepare('UPDATE member_fulfillments SET member_id = ? WHERE member_id = ?')
            ->execute([$keeperId, $duplicateId]);

        $yearConflicts = member_merge_membership_year_conflicts($pdo, $keeperId, $duplicateId);
        foreach ($yearConflicts as $conflict) {
            $pdo->prepare('DELETE FROM member_membership_years WHERE id = ?')
                ->execute([(int) $conflict['duplicate_id']]);
        }
        $pdo->prepare('UPDATE member_membership_years SET member_id = ? WHERE member_id = ?')
            ->execute([$keeperId, $duplicateId]);

        $pdo->prepare('UPDATE payments SET member_id = ? WHERE member_id = ?')
            ->execute([$keeperId, $duplicateId]);
        $pdo->prepare('UPDATE incidents SET member_id = ? WHERE member_id = ?')
            ->execute([$keeperId, $duplicateId]);
        try {
            $pdo->prepare('UPDATE member_applications SET matched_member_id = ? WHERE matched_member_id = ?')
                ->execute([$keeperId, $duplicateId]);
            $pdo->prepare('UPDATE member_applications SET approved_member_id = ? WHERE approved_member_id = ?')
                ->execute([$keeperId, $duplicateId]);
        } catch (Throwable $e) {
            // member_applications may be absent on older installs.
        }

        $duplicatePhoto = trim((string) ($duplicate['photo_path'] ?? ''));
        $keeperPhoto    = trim((string) ($keeper['photo_path'] ?? ''));
        $finalKeeper    = member_merge_fetch_member($pdo, $keeperId);
        $finalPhoto     = trim((string) ($finalKeeper['photo_path'] ?? $keeperPhoto));

        $pdo->prepare('DELETE FROM members WHERE id = ?')->execute([$duplicateId]);

        syncMemberMembershipYearForMember($pdo, $keeperId);

        $pdo->commit();

        $deletedPhoto = null;
        if ($duplicatePhoto !== '' && $duplicatePhoto !== $finalPhoto) {
            $deletedPhoto = $duplicatePhoto;
        }

        return ['ok' => true, 'deleted_photo' => $deletedPhoto, 'error' => null];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'deleted_photo' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Remove a member photo file only if it resolves under project uploads/.
 */
function member_merge_unlink_photo(string $projectRoot, string $relativePhotoPath): void
{
    $relativePhotoPath = ltrim($relativePhotoPath, '/');
    if ($relativePhotoPath === '' || str_contains($relativePhotoPath, '..')) {
        return;
    }
    $full = realpath($projectRoot . '/' . $relativePhotoPath);
    $base = realpath($projectRoot . '/uploads');
    if ($full === false || $base === false || !is_file($full)) {
        return;
    }
    if (!str_starts_with($full, $base . DIRECTORY_SEPARATOR) && $full !== $base) {
        return;
    }
    @unlink($full);
}

/**
 * @return list<array{keeper_id:int, duplicate_id:int, line:int}>
 */
function member_merge_parse_plan_file(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("Plan file not found: {$path}");
    }

    $pairs = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException("Could not read plan file: {$path}");
    }

    foreach ($lines as $index => $line) {
        $lineNum = $index + 1;
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        if (preg_match('/^(\d+)\s*[,:\s]\s*(\d+)\s*$/', $trimmed, $m)) {
            $pairs[] = [
                'keeper_id'    => (int) $m[1],
                'duplicate_id' => (int) $m[2],
                'line'         => $lineNum,
            ];
            continue;
        }

        throw new RuntimeException("Invalid plan line {$lineNum}: {$trimmed}");
    }

    return $pairs;
}

/**
 * Suggested keeper/duplicate pairs from duplicate groups (keeper = lowest id).
 *
 * @return list<array{
 *   keeper_id:int,
 *   duplicate_id:int,
 *   confidence:string,
 *   method:string,
 *   group_label:string
 * }>
 */
function member_merge_suggested_pairs_from_groups(array $groups): array
{
    $pairs = [];
    foreach ($groups as $group) {
        $ids = array_values(array_unique(array_map('intval', $group['member_ids'] ?? [])));
        sort($ids);
        if (count($ids) < 2) {
            continue;
        }
        $keeper = $ids[0];
        $labels = [];
        foreach ($group['members'] ?? [] as $member) {
            $labels[] = member_merge_member_label($member);
        }
        $groupLabel = implode('; ', $labels);
        for ($i = 1, $n = count($ids); $i < $n; $i++) {
            $pairs[] = [
                'keeper_id'    => $keeper,
                'duplicate_id' => $ids[$i],
                'confidence'   => (string) ($group['confidence'] ?? ''),
                'method'       => (string) ($group['method'] ?? ''),
                'group_label'  => $groupLabel,
            ];
        }
    }

    return $pairs;
}
