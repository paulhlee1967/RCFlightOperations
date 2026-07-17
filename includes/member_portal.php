<?php
/**
 * Member self-service portal: magic-link auth, session, allowlisted profile saves.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/member_save.php';
require_once __DIR__ . '/sender_net.php';
require_once __DIR__ . '/email_urls.php';
require_once __DIR__ . '/email_templates.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/installation_config.php';
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/membership_application.php';

/** Session idle TTL for member portal (seconds). */
const MEMBER_PORTAL_SESSION_TTL = 7200;

/** Magic link lifetime (minutes). */
const MEMBER_PORTAL_LINK_TTL_MINUTES = 60;

/**
 * Ensure member_magic_links exists (safe for installs that skip migrations).
 */
function member_portal_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS member_magic_links (
            id int unsigned NOT NULL AUTO_INCREMENT,
            member_id int unsigned NOT NULL,
            token_hash varchar(64) NOT NULL,
            expires_at datetime NOT NULL,
            used_at datetime DEFAULT NULL,
            requested_ip varchar(45) NOT NULL DEFAULT '',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_member_magic_token (token_hash),
            KEY idx_member_magic_member (member_id),
            KEY idx_member_magic_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        // Table may already exist with FK from migration.
    }
    $done = true;
}

/**
 * Columns members may update via the portal.
 *
 * @return list<string>
 */
function member_portal_editable_fields(): array
{
    return [
        'phone',
        'address_street',
        'address_street2',
        'address_city',
        'address_state',
        'address_postal_code',
        'emergency_contact_name',
        'emergency_contact_relationship',
        'emergency_contact_phone',
        'ama_number',
        'ama_expiration',
        'ama_life_member',
        'faa_number',
        'faa_expiration',
        'email_opt_in_club_events',
        'email_opt_in_expiry_reminders',
    ];
}

function member_portal_hash_token(string $token): string
{
    return hash('sha256', $token);
}

/**
 * @return array{ok:bool, token:?string, member:?array, error:?string}
 */
function member_portal_create_magic_link(
    PDO $pdo,
    int $memberId,
    string $requestedIp = '',
    int $ttlMinutes = MEMBER_PORTAL_LINK_TTL_MINUTES
): array {
    member_portal_ensure_schema($pdo);

    if ($memberId <= 0) {
        return ['ok' => false, 'token' => null, 'member' => null, 'error' => 'Invalid member.'];
    }

    $stmt = $pdo->prepare('SELECT * FROM members WHERE id = ? LIMIT 1');
    $stmt->execute([$memberId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        return ['ok' => false, 'token' => null, 'member' => null, 'error' => 'Member not found.'];
    }
    if (!empty($member['suspended'])) {
        return ['ok' => false, 'token' => null, 'member' => null, 'error' => 'Suspended members cannot use the portal.'];
    }

    $email = normalize_email((string) ($member['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'token' => null, 'member' => null, 'error' => 'Member has no valid email on file.'];
    }

    $ttlMinutes = max(5, min(240, $ttlMinutes));
    $token = bin2hex(random_bytes(32));
    $tokenHash = member_portal_hash_token($token);

    try {
        $pdo->prepare('DELETE FROM member_magic_links WHERE member_id = ? AND used_at IS NULL')
            ->execute([$memberId]);
        $pdo->prepare(
            'INSERT INTO member_magic_links (member_id, token_hash, expires_at, requested_ip)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)'
        )->execute([$memberId, $tokenHash, $ttlMinutes, substr($requestedIp, 0, 45)]);
    } catch (Throwable $e) {
        return ['ok' => false, 'token' => null, 'member' => null, 'error' => 'Could not create access link.'];
    }

    return ['ok' => true, 'token' => $token, 'member' => $member, 'error' => null];
}

/**
 * Absolute redeem URL for a raw token.
 *
 * Prefers the current web request host (same pattern as password-reset links) so
 * local/dev magic links stay on the environment that created them. Falls back to
 * email_public_base_url() for CLI/cron.
 */
function member_portal_link_url(string $token, ?array $config = null): ?string
{
    if (PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
        if ($config === null) {
            $cf = dirname(__DIR__) . '/config.php';
            $config = is_file($cf) ? require $cf : [];
        }
        require_once __DIR__ . '/session_ini.php';
        $scheme = flightops_request_scheme(is_array($config) ? $config : []);
        $httpHost = (string) $_SERVER['HTTP_HOST'];
        $basePath = rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($basePath === '/' || $basePath === '\\') {
            $basePath = '';
        }

        return $scheme . '://' . $httpHost . $basePath . '/my_link.php?token=' . rawurlencode($token);
    }

    $base = email_public_base_url($config);
    if ($base === null) {
        return null;
    }

    return $base . '/my_link.php?token=' . rawurlencode($token);
}

/**
 * Normalize a raw magic-link token from a query string / email client.
 */
function member_portal_normalize_token(string $token): string
{
    $token = trim($token);
    // Keep only hex chars (email clients sometimes append punctuation).
    if (preg_match('/^[a-fA-F0-9]+/', $token, $m)) {
        return strtolower($m[0]);
    }

    return '';
}

/**
 * Find exactly one non-suspended member for an email (case-insensitive).
 *
 * @return array|null Member row or null when zero/multiple matches
 */
function member_portal_find_member_by_email(PDO $pdo, string $email): ?array
{
    $email = normalize_email($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM members
         WHERE LOWER(TRIM(email)) = ? AND suspended = 0
         ORDER BY id ASC'
    );
    $stmt->execute([$email]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 1) {
        return null;
    }

    return $rows[0];
}

/**
 * Whether a magic-link request for this member is in cooldown.
 */
function member_portal_link_cooldown_active(PDO $pdo, int $memberId, int $minutes = 5): bool
{
    member_portal_ensure_schema($pdo);
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM member_magic_links
             WHERE member_id = ?
               AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
               AND expires_at > NOW()'
        );
        $stmt->execute([$memberId, max(1, $minutes)]);

        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Send magic-link email. Returns false only when mail send fails for a known member.
 *
 * @return array{sent:bool, matched:bool, error:?string}
 */
function member_portal_send_magic_link_email(
    PDO $pdo,
    string $email,
    string $requestedIp = '',
    ?array $config = null
): array {
    member_portal_ensure_schema($pdo);
    membership_application_ensure_email_opt_in_schema($pdo);

    $member = member_portal_find_member_by_email($pdo, $email);
    if ($member === null) {
        return ['sent' => false, 'matched' => false, 'error' => null];
    }

    $memberId = (int) $member['id'];
    if (member_portal_link_cooldown_active($pdo, $memberId)) {
        // Treat cooldown as success to avoid enumeration / spam.
        return ['sent' => true, 'matched' => true, 'error' => null];
    }

    $created = member_portal_create_magic_link($pdo, $memberId, $requestedIp);
    if (!$created['ok'] || $created['token'] === null) {
        return ['sent' => false, 'matched' => true, 'error' => $created['error'] ?? 'Could not create link.'];
    }

    $link = member_portal_link_url($created['token'], $config);
    if ($link === null) {
        return ['sent' => false, 'matched' => true, 'error' => 'Public site URL is not configured.'];
    }

    $clubName = member_portal_club_name($pdo);
    $firstName = trim((string) ($member['first_name'] ?? ''));
    $to = normalize_email((string) ($member['email'] ?? ''));

    $rendered = render_email_template('member_portal_link', [
        'first_name' => $firstName,
        'club_name'  => $clubName,
        'link_url'   => $link,
        'expires_minutes' => MEMBER_PORTAL_LINK_TTL_MINUTES,
        'eyebrow'    => 'My Membership',
    ], $pdo);

    $mailCfg = installation_mail_config($pdo);
    $ok = send_mail(
        $to,
        $rendered['subject'],
        $rendered['html'],
        $rendered['text'] ?? strip_tags($rendered['html']),
        $mailCfg
    );

    if (!$ok) {
        return ['sent' => false, 'matched' => true, 'error' => 'We could not send the email. Please try again later.'];
    }

    return ['sent' => true, 'matched' => true, 'error' => null];
}

function member_portal_club_name(PDO $pdo): string
{
    try {
        $row = $pdo->query('SELECT name FROM club WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $name = trim((string) ($row['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
    } catch (Throwable $e) {
    }

    return 'RC Flight Operations';
}

/**
 * Redeem a one-time token and start a member session.
 *
 * @return array{ok:bool, member_id:int, error:?string}
 */
function member_portal_redeem_token(PDO $pdo, string $token, string $clientIp = ''): array
{
    member_portal_ensure_schema($pdo);
    $token = member_portal_normalize_token($token);
    // Tokens are bin2hex(random_bytes(32)) → 64 hex chars.
    if ($token === '' || strlen($token) < 64) {
        return ['ok' => false, 'member_id' => 0, 'error' => 'This access link is invalid or has expired.'];
    }

    $tokenHash = member_portal_hash_token($token);

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT id, member_id, expires_at, used_at
             FROM member_magic_links
             WHERE token_hash = ?
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();

            return ['ok' => false, 'member_id' => 0, 'error' => 'This access link is invalid or has expired.'];
        }
        if (!empty($row['used_at'])) {
            $pdo->rollBack();

            return ['ok' => false, 'member_id' => 0, 'error' => 'This access link has already been used. Request a new one.'];
        }

        // Compare expiry in MySQL (same clock as DATE_ADD(NOW(), …) on insert).
        // PHP strtotime() can disagree when date.timezone differs from MySQL time_zone.
        $alive = $pdo->prepare(
            'SELECT 1 FROM member_magic_links WHERE id = ? AND expires_at > NOW() LIMIT 1'
        );
        $alive->execute([(int) $row['id']]);
        if (!$alive->fetchColumn()) {
            $pdo->rollBack();

            return ['ok' => false, 'member_id' => 0, 'error' => 'This access link is invalid or has expired.'];
        }

        $memberId = (int) $row['member_id'];
        $memberStmt = $pdo->prepare('SELECT id, suspended FROM members WHERE id = ? LIMIT 1');
        $memberStmt->execute([$memberId]);
        $member = $memberStmt->fetch(PDO::FETCH_ASSOC);
        if (!$member) {
            $pdo->rollBack();

            return ['ok' => false, 'member_id' => 0, 'error' => 'This access link is invalid or has expired.'];
        }
        if (!empty($member['suspended'])) {
            $pdo->rollBack();

            return [
                'ok' => false,
                'member_id' => 0,
                'error' => 'Your membership is suspended. Please contact the club membership team.',
            ];
        }

        $pdo->prepare('UPDATE member_magic_links SET used_at = NOW() WHERE id = ?')
            ->execute([(int) $row['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'member_id' => 0, 'error' => 'Could not open your membership profile. Try again.'];
    }

    member_portal_session_start($memberId);

    return ['ok' => true, 'member_id' => $memberId, 'error' => null];
}

function member_portal_session_start(int $memberId): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    session_regenerate_id(true);
    $_SESSION['member_portal_id'] = $memberId;
    $_SESSION['member_portal_active_at'] = time();
}

function member_portal_session_touch(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!empty($_SESSION['member_portal_id'])) {
        $_SESSION['member_portal_active_at'] = time();
    }
}

function member_portal_session_clear(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    unset($_SESSION['member_portal_id'], $_SESSION['member_portal_active_at']);
}

/**
 * Current member portal id, or 0 if not logged in / expired.
 */
function member_portal_current_member_id(): int
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $id = (int) ($_SESSION['member_portal_id'] ?? 0);
    if ($id <= 0) {
        return 0;
    }
    $activeAt = (int) ($_SESSION['member_portal_active_at'] ?? 0);
    if ($activeAt <= 0 || (time() - $activeAt) > MEMBER_PORTAL_SESSION_TTL) {
        member_portal_session_clear();

        return 0;
    }
    member_portal_session_touch();

    return $id;
}

/**
 * Require an active member portal session; redirect to my.php when missing.
 */
function member_portal_require_member(): int
{
    $id = member_portal_current_member_id();
    if ($id <= 0) {
        header('Location: my.php');
        exit;
    }

    return $id;
}

/**
 * Validate portal POST into allowlisted clean values.
 *
 * @return array{0: array<string,string>, 1: array<string, mixed>}
 */
function member_portal_validate_input(array $post): array
{
    $errors = [];
    $clean = [];

    $clean['phone'] = trim((string) ($post['phone'] ?? '')) ?: null;
    $clean['address_street'] = trim((string) ($post['address_street'] ?? '')) ?: null;
    $clean['address_street2'] = trim((string) ($post['address_street2'] ?? '')) ?: null;
    $clean['address_city'] = trim((string) ($post['address_city'] ?? '')) ?: null;
    $clean['address_state'] = trim((string) ($post['address_state'] ?? '')) ?: null;
    $clean['address_postal_code'] = trim((string) ($post['address_postal_code'] ?? '')) ?: null;
    $clean['emergency_contact_name'] = trim((string) ($post['emergency_contact_name'] ?? '')) ?: null;
    $clean['emergency_contact_relationship'] = trim((string) ($post['emergency_contact_relationship'] ?? '')) ?: null;
    $clean['emergency_contact_phone'] = trim((string) ($post['emergency_contact_phone'] ?? '')) ?: null;

    $rawAma = trim((string) ($post['ama_number'] ?? ''));
    if ($rawAma !== '') {
        require_once __DIR__ . '/ama_verify.php';
        $normalizedAma = ama_verify_normalize_number($rawAma);
        $clean['ama_number'] = $normalizedAma !== '' ? $normalizedAma : null;
    } else {
        $clean['ama_number'] = null;
    }

    $rawAmaExp = trim((string) ($post['ama_expiration'] ?? ''));
    if ($rawAmaExp !== '') {
        [$dateOk, $dateErr] = validate_date($rawAmaExp);
        if (!$dateOk) {
            $errors['ama_expiration'] = 'AMA expiration: ' . $dateErr;
            $clean['ama_expiration'] = null;
        } else {
            $clean['ama_expiration'] = $rawAmaExp;
        }
    } else {
        $clean['ama_expiration'] = null;
    }

    $clean['ama_life_member'] = !empty($post['ama_life_member']) ? 1 : 0;
    $clean['faa_number'] = trim((string) ($post['faa_number'] ?? '')) ?: null;

    $rawFaaExp = trim((string) ($post['faa_expiration'] ?? ''));
    if ($rawFaaExp !== '') {
        [$dateOk, $dateErr] = validate_date($rawFaaExp);
        if (!$dateOk) {
            $errors['faa_expiration'] = 'FAA expiration: ' . $dateErr;
            $clean['faa_expiration'] = null;
        } else {
            $clean['faa_expiration'] = $rawFaaExp;
        }
    } else {
        $clean['faa_expiration'] = null;
    }

    $clean['email_opt_in_club_events'] = email_opt_in_from_post($post['email_opt_in_club_events'] ?? null);
    $clean['email_opt_in_expiry_reminders'] = email_opt_in_from_post($post['email_opt_in_expiry_reminders'] ?? null);

    return [$errors, $clean];
}

/**
 * Build a compact before/after diff for audit_log detail.
 *
 * @param array<string, mixed> $before
 * @param array<string, mixed> $after
 * @return array<string, array{from:mixed, to:mixed}>
 */
function member_portal_field_diff(array $before, array $after): array
{
    $diff = [];
    foreach (member_portal_editable_fields() as $field) {
        $from = $before[$field] ?? null;
        $to = $after[$field] ?? null;
        // Normalize ints/strings for comparison
        if (is_bool($from)) {
            $from = $from ? 1 : 0;
        }
        if (is_bool($to)) {
            $to = $to ? 1 : 0;
        }
        $fromNorm = $from === null || $from === '' ? null : (string) $from;
        $toNorm = $to === null || $to === '' ? null : (string) $to;
        if ($fromNorm !== $toNorm) {
            $diff[$field] = ['from' => $from, 'to' => $to];
        }
    }

    return $diff;
}

/**
 * Persist allowlisted profile fields + optional uploads; write audit_log.
 *
 * @return array{ok:bool, errors:array<string,string>, changed:array<string, array{from:mixed, to:mixed}>}
 */
function member_portal_save(PDO $pdo, int $memberId, array $post, array $files = []): array
{
    membership_application_ensure_email_opt_in_schema($pdo);

    $stmt = $pdo->prepare('SELECT * FROM members WHERE id = ? LIMIT 1');
    $stmt->execute([$memberId]);
    $before = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$before) {
        return ['ok' => false, 'errors' => ['member' => 'Member not found.'], 'changed' => []];
    }
    if (!empty($before['suspended'])) {
        return [
            'ok' => false,
            'errors' => ['member' => 'Your membership is suspended. Please contact the club.'],
            'changed' => [],
        ];
    }

    [$errors, $clean] = member_portal_validate_input($post);
    if ($errors !== []) {
        return ['ok' => false, 'errors' => $errors, 'changed' => []];
    }

    if ($clean['ama_number'] !== null) {
        $amaConflict = member_find_by_ama_number($pdo, (string) $clean['ama_number'], $memberId);
        if ($amaConflict !== null) {
            return [
                'ok' => false,
                'errors' => ['ama_number' => member_ama_number_conflict_message($amaConflict)],
                'changed' => [],
            ];
        }
    }

    $diff = member_portal_field_diff($before, $clean);

    $pdo->prepare(
        'UPDATE members SET
            phone = ?,
            address_street = ?,
            address_street2 = ?,
            address_city = ?,
            address_state = ?,
            address_postal_code = ?,
            emergency_contact_name = ?,
            emergency_contact_relationship = ?,
            emergency_contact_phone = ?,
            ama_number = ?,
            ama_expiration = ?,
            ama_life_member = ?,
            faa_number = ?,
            faa_expiration = ?,
            email_opt_in_club_events = ?,
            email_opt_in_expiry_reminders = ?
         WHERE id = ?'
    )->execute([
        $clean['phone'],
        $clean['address_street'],
        $clean['address_street2'],
        $clean['address_city'],
        $clean['address_state'],
        $clean['address_postal_code'],
        $clean['emergency_contact_name'],
        $clean['emergency_contact_relationship'],
        $clean['emergency_contact_phone'],
        $clean['ama_number'],
        $clean['ama_expiration'],
        $clean['ama_life_member'],
        $clean['faa_number'],
        $clean['faa_expiration'],
        $clean['email_opt_in_club_events'],
        $clean['email_opt_in_expiry_reminders'],
        $memberId,
    ]);

    $uploadNotes = [];
    if (!empty($files['photo']['tmp_name']) && is_uploaded_file($files['photo']['tmp_name'])) {
        $photoResult = member_save_photo_from_local_file($pdo, $memberId, (string) $files['photo']['tmp_name']);
        if (!$photoResult['ok']) {
            return [
                'ok' => false,
                'errors' => ['photo' => $photoResult['error'] ?? 'Could not save badge photo.'],
                'changed' => $diff,
            ];
        }
        $uploadNotes['photo'] = $photoResult['photo_path'];
        $diff['photo_path'] = ['from' => $before['photo_path'] ?? null, 'to' => $photoResult['photo_path']];
    }

    if (!empty($files['faa_card']['tmp_name']) && is_uploaded_file($files['faa_card']['tmp_name'])) {
        $faaResult = member_save_faa_card_from_local_file($pdo, $memberId, (string) $files['faa_card']['tmp_name']);
        if (!$faaResult['ok']) {
            return [
                'ok' => false,
                'errors' => ['faa_card' => $faaResult['error'] ?? 'Could not save FAA card.'],
                'changed' => $diff,
            ];
        }
        $uploadNotes['faa_card'] = $faaResult['faa_card_path'];
        $diff['faa_card_path'] = ['from' => $before['faa_card_path'] ?? null, 'to' => $faaResult['faa_card_path']];
    }

    if ($diff !== []) {
        $detail = json_encode([
            'source' => 'member_portal',
            'member_id' => $memberId,
            'changes' => $diff,
            'uploads' => $uploadNotes,
        ], JSON_UNESCAPED_SLASHES);
        if ($detail === false) {
            $detail = 'member_self_update';
        }
        if (strlen($detail) > 1000) {
            $detail = substr($detail, 0, 997) . '...';
        }
        audit_log($pdo, 0, 'member_self_update', 'member', $memberId, $detail);
    }

    return ['ok' => true, 'errors' => [], 'changed' => $diff];
}

/**
 * Rate-limit magic-link requests (IP). Returns true when allowed.
 */
function member_portal_rate_limit_ok(PDO $pdo, string $clientIp): bool
{
    return rate_limit_check($pdo, 'member_portal_link', $clientIp, 12, 15);
}
