<?php
/**
 * includes/member_match.php
 *
 * Duplicate member detection for imports and WPForms applications.
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
    $email = $email !== null && trim($email) !== '' ? trim($email) : null;
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
