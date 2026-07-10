<?php
/**
 * Complimentary membership invites for first-time applicants not yet in the club database.
 * Staff pre-authorize a specific email and/or AMA #; payment is waived when identity matches.
 */

require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ama_verify.php';

function membership_comp_invites_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS membership_comp_invites (
            id int unsigned NOT NULL AUTO_INCREMENT,
            email varchar(255) DEFAULT NULL,
            ama_number varchar(32) DEFAULT NULL,
            membership_type varchar(32) NOT NULL DEFAULT \'free_membership\',
            notes text DEFAULT NULL,
            created_by int unsigned DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime DEFAULT NULL,
            redeemed_at datetime DEFAULT NULL,
            redeemed_application_id int unsigned DEFAULT NULL,
            cancelled_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_comp_invites_email (email),
            KEY idx_comp_invites_ama (ama_number),
            KEY idx_comp_invites_active (redeemed_at, cancelled_at, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM membership_comp_invites LIKE 'membership_type'");
        if ($stmt && !$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE membership_comp_invites
                ADD COLUMN membership_type varchar(32) NOT NULL DEFAULT 'free_membership' AFTER ama_number");
        }
    } catch (Throwable $e) {
    }
}

function membership_comp_invite_normalize_type(?string $type): string
{
    $type = strtolower(trim((string) $type));

    return $type === 'life_member' ? 'life_member' : 'free_membership';
}

function membership_comp_invite_type_label(?string $type): string
{
    return membership_comp_invite_normalize_type($type) === 'life_member'
        ? 'life member'
        : 'free membership';
}

/**
 * Whether an unused invite row matches verified applicant identity.
 */
function membership_comp_invite_row_matches(array $invite, string $amaNumber, string $email): bool
{
    $inviteAma = trim((string) ($invite['ama_number'] ?? ''));
    $inviteEmail = trim((string) ($invite['email'] ?? ''));
    if ($inviteAma === '' && $inviteEmail === '') {
        return false;
    }

    $amaNorm = ama_verify_normalize_number($amaNumber);
    $emailNorm = $email !== '' ? normalize_email($email) : '';

    if ($inviteAma !== '') {
        if ($amaNorm === '' || $amaNorm !== ama_verify_normalize_number($inviteAma)) {
            return false;
        }
    }
    if ($inviteEmail !== '') {
        if ($emailNorm === '' || $emailNorm !== normalize_email($inviteEmail)) {
            return false;
        }
    }

    return true;
}

function membership_comp_invite_is_open(array $invite, ?DateTimeInterface $now = null): bool
{
    if (!empty($invite['redeemed_at']) || !empty($invite['cancelled_at'])) {
        return false;
    }
    $expiresAt = trim((string) ($invite['expires_at'] ?? ''));
    if ($expiresAt === '') {
        return true;
    }
    $now = $now ?? new DateTimeImmutable('now');

    return $expiresAt > $now->format('Y-m-d H:i:s');
}

/**
 * @return array<string, mixed>|null
 */
function membership_comp_invite_find_matching(PDO $pdo, string $amaNumber, string $email, ?DateTimeInterface $now = null): ?array
{
    try {
        membership_comp_invites_ensure_schema($pdo);
    } catch (Throwable $e) {
        return null;
    }
    $now = $now ?? new DateTimeImmutable('now');

    try {
        $stmt = $pdo->query('
            SELECT *
            FROM membership_comp_invites
            WHERE redeemed_at IS NULL
              AND cancelled_at IS NULL
              AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY id ASC
        ');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        return null;
    }

    foreach ($rows as $row) {
        if (!membership_comp_invite_is_open($row, $now)) {
            continue;
        }
        if (membership_comp_invite_row_matches($row, $amaNumber, $email)) {
            return $row;
        }
    }

    return null;
}

/**
 * @return array{ok:bool, id:?int, error:?string}
 */
function membership_comp_invite_create(PDO $pdo, array $data, int $createdBy): array
{
    membership_comp_invites_ensure_schema($pdo);

    $email = trim((string) ($data['email'] ?? ''));
    $ama = ama_verify_normalize_number((string) ($data['ama_number'] ?? ''));
    $notes = trim((string) ($data['notes'] ?? ''));
    $expiresAt = trim((string) ($data['expires_at'] ?? ''));

    if ($email === '' && $ama === '') {
        return ['ok' => false, 'id' => null, 'error' => 'Enter an email address, AMA number, or both.'];
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'id' => null, 'error' => 'Enter a valid email address.'];
    }
    if ($expiresAt !== '' && strtotime($expiresAt) === false) {
        return ['ok' => false, 'id' => null, 'error' => 'Enter a valid expiration date.'];
    }

    if ($email !== '') {
        $email = normalize_email($email);
    }
    if ($expiresAt === '') {
        $expiresAt = (new DateTimeImmutable('+90 days'))->format('Y-m-d 23:59:59');
    } else {
        $expiresAt = date('Y-m-d H:i:s', strtotime($expiresAt));
    }

    $membershipType = membership_comp_invite_normalize_type($data['membership_type'] ?? 'free_membership');

    $pdo->prepare('
        INSERT INTO membership_comp_invites (email, ama_number, membership_type, notes, created_by, expires_at)
        VALUES (?, ?, ?, ?, ?, ?)
    ')->execute([
        $email !== '' ? $email : null,
        $ama !== '' ? $ama : null,
        $membershipType,
        $notes !== '' ? $notes : null,
        $createdBy > 0 ? $createdBy : null,
        $expiresAt,
    ]);

    return ['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'error' => null];
}

/**
 * @return list<array<string, mixed>>
 */
function membership_comp_invite_list(PDO $pdo, string $filter = 'open'): array
{
    membership_comp_invites_ensure_schema($pdo);

    $sql = 'SELECT i.*, u.name AS created_by_name
            FROM membership_comp_invites i
            LEFT JOIN users u ON u.id = i.created_by';

    if ($filter === 'open') {
        $sql .= ' WHERE i.redeemed_at IS NULL AND i.cancelled_at IS NULL
                  AND (i.expires_at IS NULL OR i.expires_at > NOW())';
    } elseif ($filter === 'redeemed') {
        $sql .= ' WHERE i.redeemed_at IS NOT NULL';
    } elseif ($filter === 'expired') {
        $sql .= ' WHERE i.redeemed_at IS NULL AND i.cancelled_at IS NULL
                  AND i.expires_at IS NOT NULL AND i.expires_at <= NOW()';
    } elseif ($filter === 'cancelled') {
        $sql .= ' WHERE i.cancelled_at IS NOT NULL';
    }

    $sql .= ' ORDER BY i.id DESC LIMIT 200';

    $stmt = $pdo->query($sql);

    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function membership_comp_invite_cancel(PDO $pdo, int $inviteId): bool
{
    membership_comp_invites_ensure_schema($pdo);
    $stmt = $pdo->prepare('
        UPDATE membership_comp_invites
        SET cancelled_at = NOW()
        WHERE id = ? AND redeemed_at IS NULL AND cancelled_at IS NULL
    ');
    $stmt->execute([$inviteId]);

    return $stmt->rowCount() > 0;
}

function membership_comp_invite_redeem(PDO $pdo, int $inviteId, int $applicationId): bool
{
    membership_comp_invites_ensure_schema($pdo);
    $stmt = $pdo->prepare('
        UPDATE membership_comp_invites
        SET redeemed_at = NOW(), redeemed_application_id = ?
        WHERE id = ?
          AND redeemed_at IS NULL
          AND cancelled_at IS NULL
          AND (expires_at IS NULL OR expires_at > NOW())
    ');
    $stmt->execute([$applicationId, $inviteId]);

    return $stmt->rowCount() > 0;
}

/**
 * @return array<string, mixed>|null
 */
function membership_comp_invite_for_application(PDO $pdo, int $applicationId): ?array
{
    if ($applicationId < 1) {
        return null;
    }
    try {
        membership_comp_invites_ensure_schema($pdo);
        $stmt = $pdo->prepare('
            SELECT * FROM membership_comp_invites
            WHERE redeemed_application_id = ?
            LIMIT 1
        ');
        $stmt->execute([$applicationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Set free_membership or life_member on the member from a redeemed comp invite.
 */
function membership_comp_invite_apply_to_member(PDO $pdo, int $applicationId, int $memberId): void
{
    $invite = membership_comp_invite_for_application($pdo, $applicationId);
    if ($invite === null || $memberId < 1) {
        return;
    }

    if (membership_comp_invite_normalize_type($invite['membership_type'] ?? '') === 'life_member') {
        $pdo->prepare('UPDATE members SET life_member = 1 WHERE id = ?')->execute([$memberId]);
    } else {
        $pdo->prepare('UPDATE members SET free_membership = 1 WHERE id = ?')->execute([$memberId]);
    }
}
