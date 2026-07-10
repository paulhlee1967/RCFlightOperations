<?php
/**
 * includes/member_match.php
 *
 * Duplicate member detection for imports and membership applications.
 */

require_once __DIR__ . '/validation.php';

/**
 * Find an existing member using AMA number and tiered name/email/birthday matching.
 *
 * @return array{
 *   member_id: ?int,
 *   confidence: 'exact'|'probable'|'ambiguous'|'none',
 *   method: ?string,
 *   candidate_ids: int[]
 * }
 */
function member_match_find(
    PDO $pdo,
    ?string $amaNumber,
    string $firstName,
    string $lastName,
    ?string $email = null,
    ?string $birthday = null,
    ?int $excludeMemberId = null
): array {
    $first = trim($firstName);
    $last  = trim($lastName);
    $email = $email !== null && trim($email) !== '' ? normalize_email($email) : null;
    $birthday = $birthday !== null && trim($birthday) !== '' ? trim($birthday) : null;

    if ($amaNumber !== null && trim($amaNumber) !== '') {
        $amaConflict = member_find_by_ama_number($pdo, $amaNumber, $excludeMemberId);
        if ($amaConflict !== null) {
            return [
                'member_id'      => (int) $amaConflict['id'],
                'confidence'     => 'exact',
                'method'         => 'ama_number',
                'candidate_ids'  => [(int) $amaConflict['id']],
            ];
        }
    }

    if ($first === '' || $last === '') {
        return [
            'member_id'     => null,
            'confidence'    => 'none',
            'method'        => null,
            'candidate_ids' => [],
        ];
    }

    $findExisting4 = $pdo->prepare('
        SELECT id FROM members
        WHERE first_name = ? AND last_name = ? AND email <=> ? AND birthday <=> ?
        ORDER BY id ASC
        LIMIT 2
    ');
    $findExisting3 = $pdo->prepare('
        SELECT id FROM members
        WHERE first_name = ? AND last_name = ? AND email <=> ?
        ORDER BY id ASC
        LIMIT 2
    ');
    $findExisting2 = $pdo->prepare('
        SELECT id FROM members
        WHERE first_name = ? AND last_name = ?
        ORDER BY id ASC
        LIMIT 2
    ');

    $matches = [];
    $method  = null;

    if ($email !== null && $birthday !== null) {
        $findExisting4->execute([$first, $last, $email, $birthday]);
        $matches = array_map('intval', $findExisting4->fetchAll(PDO::FETCH_COLUMN));
        if ($matches !== []) {
            $method = 'name_email_birthday';
        }
    }

    if ($matches === [] && $email !== null) {
        $findExisting3->execute([$first, $last, $email]);
        $matches = array_map('intval', $findExisting3->fetchAll(PDO::FETCH_COLUMN));
        if ($matches !== []) {
            $method = 'name_email';
        }
    }

    if ($matches === []) {
        $findExisting2->execute([$first, $last]);
        $matches = array_map('intval', $findExisting2->fetchAll(PDO::FETCH_COLUMN));
        if ($matches !== []) {
            $method = 'name_only';
        }
    }

    if ($excludeMemberId !== null) {
        $matches = array_values(array_filter($matches, static fn (int $id): bool => $id !== $excludeMemberId));
    }

    if (count($matches) >= 2) {
        return [
            'member_id'     => null,
            'confidence'    => 'ambiguous',
            'method'        => $method,
            'candidate_ids' => $matches,
        ];
    }

    if (count($matches) === 1) {
        $confidence = match ($method) {
            'name_email_birthday' => 'exact',
            'name_email'          => 'probable',
            default               => 'probable',
        };
        return [
            'member_id'     => $matches[0],
            'confidence'    => $confidence,
            'method'        => $method,
            'candidate_ids' => $matches,
        ];
    }

    return [
        'member_id'     => null,
        'confidence'    => 'none',
        'method'        => null,
        'candidate_ids' => [],
    ];
}

/**
 * Human-readable label for a match confidence tier.
 */
function member_match_confidence_label(string $confidence): string
{
    return match ($confidence) {
        'exact'    => 'Exact',
        'probable' => 'Probable',
        'ambiguous'=> 'Ambiguous',
        default    => ucfirst($confidence),
    };
}

/**
 * Human-readable label for a match method slug.
 */
function member_match_method_label(string $method): string
{
    return match ($method) {
        'ama_number'          => 'AMA number',
        'name_email_birthday' => 'Name + email + birthday',
        'name_email'          => 'Name + email',
        'name_only'           => 'Name only',
        default               => $method,
    };
}

/**
 * Stable key for a member pair (order-independent).
 */
function member_match_pair_key(int $idA, int $idB): string
{
    if ($idA > $idB) {
        [$idA, $idB] = [$idB, $idA];
    }

    return $idA . ':' . $idB;
}

/**
 * Scan existing members for possible duplicate groups using the same tiers as
 * member_match_find() (AMA, name+email+birthday, name+email, name only).
 * Each pair is reported once, at the strongest matching tier.
 *
 * @return array<int, array{
 *   confidence: string,
 *   method: string,
 *   member_ids: int[],
 *   members: array<int, array<string, mixed>>
 * }>
 */
function member_match_scan_duplicates(PDO $pdo): array
{
    $tiers = [
        [
            'confidence' => 'exact',
            'method'     => 'ama_number',
            'sql'        => "
                SELECT GROUP_CONCAT(id ORDER BY id) AS ids
                FROM members
                WHERE ama_number IS NOT NULL AND TRIM(ama_number) != ''
                GROUP BY ama_number
                HAVING COUNT(*) > 1
            ",
        ],
        [
            'confidence' => 'exact',
            'method'     => 'name_email_birthday',
            'sql'        => "
                SELECT GROUP_CONCAT(id ORDER BY id) AS ids
                FROM members
                WHERE TRIM(first_name) != '' AND TRIM(last_name) != ''
                  AND email IS NOT NULL AND TRIM(email) != ''
                  AND birthday IS NOT NULL
                GROUP BY first_name, last_name, email, birthday
                HAVING COUNT(*) > 1
            ",
        ],
        [
            'confidence' => 'probable',
            'method'     => 'name_email',
            'sql'        => "
                SELECT GROUP_CONCAT(id ORDER BY id) AS ids
                FROM members
                WHERE TRIM(first_name) != '' AND TRIM(last_name) != ''
                  AND email IS NOT NULL AND TRIM(email) != ''
                GROUP BY first_name, last_name, email
                HAVING COUNT(*) > 1
            ",
        ],
        [
            'confidence' => 'probable',
            'method'     => 'name_only',
            'sql'        => "
                SELECT GROUP_CONCAT(id ORDER BY id) AS ids
                FROM members
                WHERE TRIM(first_name) != '' AND TRIM(last_name) != ''
                GROUP BY first_name, last_name
                HAVING COUNT(*) > 1
            ",
        ],
    ];

    $seenPairs = [];
    $groups    = [];

    foreach ($tiers as $tier) {
        try {
            $stmt = $pdo->query($tier['sql']);
        } catch (Throwable $e) {
            continue;
        }

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ids = array_values(array_unique(array_map('intval', explode(',', (string) ($row['ids'] ?? '')))));
            $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
            if (count($ids) < 2) {
                continue;
            }

            $hasNewPair = false;
            for ($i = 0; $i < count($ids); $i++) {
                for ($j = $i + 1; $j < count($ids); $j++) {
                    $key = member_match_pair_key($ids[$i], $ids[$j]);
                    if (!isset($seenPairs[$key])) {
                        $seenPairs[$key] = true;
                        $hasNewPair      = true;
                    }
                }
            }
            if (!$hasNewPair) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $memberStmt   = $pdo->prepare(
                "SELECT id, first_name, last_name, email, ama_number, birthday
                 FROM members
                 WHERE id IN ({$placeholders})
                 ORDER BY last_name, first_name, id"
            );
            $memberStmt->execute($ids);
            $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($members) < 2) {
                continue;
            }

            $groups[] = [
                'confidence' => $tier['confidence'],
                'method'     => $tier['method'],
                'member_ids' => $ids,
                'members'    => $members,
            ];
        }
    }

    return $groups;
}
