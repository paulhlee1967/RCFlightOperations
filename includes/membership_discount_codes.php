<?php
/**
 * Reusable campaign discount codes for online membership applications.
 * Codes are shareable (no identity match, no redemption limit). Complimentary
 * invites remain the single-use $0 path.
 */

require_once __DIR__ . '/helpers.php';

/**
 * @return array{percent:string,amount:string}
 */
function membership_discount_type_labels(): array
{
    return [
        'percent' => 'Percent off',
        'amount'  => 'Dollar off',
    ];
}

/**
 * @return array{dues:string,initiation:string,both:string}
 */
function membership_discount_applies_to_labels(): array
{
    return [
        'dues'       => 'Membership dues',
        'initiation' => 'Initiation fee',
        'both'       => 'Dues and initiation',
    ];
}

function membership_discount_normalize_code(?string $code): string
{
    $code = strtoupper(trim((string) $code));
    $code = preg_replace('/[^A-Z0-9-]/', '', $code) ?? '';

    return substr($code, 0, 32);
}

function membership_discount_normalize_type(?string $type): string
{
    $type = strtolower(trim((string) $type));

    return $type === 'percent' ? 'percent' : 'amount';
}

function membership_discount_normalize_applies_to(?string $applies): string
{
    $applies = strtolower(trim((string) $applies));
    if (in_array($applies, ['dues', 'initiation', 'both'], true)) {
        return $applies;
    }

    return 'both';
}

function membership_discount_codes_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS membership_discount_codes (
            id int unsigned NOT NULL AUTO_INCREMENT,
            code varchar(32) NOT NULL,
            discount_type varchar(16) NOT NULL DEFAULT 'amount',
            amount decimal(10,2) NOT NULL DEFAULT 0.00,
            applies_to varchar(16) NOT NULL DEFAULT 'both',
            notes text DEFAULT NULL,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_by int unsigned DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime DEFAULT NULL,
            disabled_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_discount_code (code),
            KEY idx_discount_codes_active (active, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function membership_discount_code_is_usable(array $row, ?DateTimeInterface $now = null): bool
{
    if (empty($row['active'])) {
        return false;
    }
    if (!empty($row['disabled_at'])) {
        return false;
    }
    $expiresAt = trim((string) ($row['expires_at'] ?? ''));
    if ($expiresAt === '') {
        return true;
    }
    $now = $now ?? new DateTimeImmutable('now');

    return $expiresAt > $now->format('Y-m-d H:i:s');
}

/**
 * @return array<string, mixed>|null
 */
function membership_discount_code_find_active(PDO $pdo, ?string $code, ?DateTimeInterface $now = null): ?array
{
    $code = membership_discount_normalize_code($code);
    if ($code === '') {
        return null;
    }
    try {
        membership_discount_codes_ensure_schema($pdo);
    } catch (Throwable $e) {
        return null;
    }
    $now = $now ?? new DateTimeImmutable('now');

    try {
        $stmt = $pdo->prepare('
            SELECT *
            FROM membership_discount_codes
            WHERE code = ?
              AND active = 1
              AND disabled_at IS NULL
              AND (expires_at IS NULL OR expires_at > ?)
            LIMIT 1
        ');
        $stmt->execute([$code, $now->format('Y-m-d H:i:s')]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }

    if (!$row || !membership_discount_code_is_usable($row, $now)) {
        return null;
    }

    return $row;
}

/**
 * Apply a discount row to list dues + initiation. Never reduces club net to $0.
 *
 * @param array<string, mixed> $row
 * @return array{
 *   ok: bool,
 *   error: ?string,
 *   dues: float,
 *   initiation: float,
 *   dues_list: float,
 *   initiation_list: float,
 *   discount_amount: float,
 *   code: string,
 *   applies_to: string,
 *   message: ?string
 * }
 */
function membership_discount_code_apply(float $dues, float $initiation, array $row): array
{
    $dues = round($dues, 2);
    $initiation = round($initiation, 2);
    $code = membership_discount_normalize_code($row['code'] ?? '');
    $type = membership_discount_normalize_type($row['discount_type'] ?? '');
    $applies = membership_discount_normalize_applies_to($row['applies_to'] ?? '');
    $value = round((float) ($row['amount'] ?? 0), 2);

    $base = [
        'ok'               => false,
        'error'            => null,
        'dues'             => $dues,
        'initiation'       => $initiation,
        'dues_list'        => $dues,
        'initiation_list'  => $initiation,
        'discount_amount'  => 0.0,
        'code'             => $code,
        'applies_to'       => $applies,
        'message'          => null,
    ];

    if ($code === '' || $value <= 0) {
        $base['error'] = 'That discount code is not valid.';

        return $base;
    }
    if ($type === 'percent' && $value >= 100) {
        $base['error'] = 'Campaign codes cannot waive the entire fee.';

        return $base;
    }

    $targetDues = $applies === 'initiation' ? 0.0 : $dues;
    $targetInit = $applies === 'dues' ? 0.0 : $initiation;
    $target = round($targetDues + $targetInit, 2);
    if ($target <= 0) {
        $base['error'] = 'This code does not apply to this membership quote.';

        return $base;
    }

    if ($type === 'percent') {
        $cutDues = $applies === 'initiation' ? 0.0 : round($dues * ($value / 100), 2);
        $cutInit = $applies === 'dues' ? 0.0 : round($initiation * ($value / 100), 2);
    } elseif ($applies === 'dues') {
        $cutDues = round(min($value, $dues), 2);
        $cutInit = 0.0;
    } elseif ($applies === 'initiation') {
        $cutDues = 0.0;
        $cutInit = round(min($value, $initiation), 2);
    } else {
        $cut = round(min($value, $target), 2);
        $cutDues = $target > 0 ? round($cut * ($dues / $target), 2) : 0.0;
        $cutInit = round($cut - $cutDues, 2);
        if ($cutInit > $initiation) {
            $overflow = round($cutInit - $initiation, 2);
            $cutInit = $initiation;
            $cutDues = round($cutDues + $overflow, 2);
        }
        if ($cutDues > $dues) {
            $cutDues = $dues;
            $cutInit = round($cut - $cutDues, 2);
        }
    }

    $newDues = round(max(0, $dues - $cutDues), 2);
    $newInit = round(max(0, $initiation - $cutInit), 2);
    $newSub = round($newDues + $newInit, 2);
    $discountAmount = round(($dues + $initiation) - $newSub, 2);

    if ($newSub <= 0) {
        $base['error'] = 'This code would waive the entire fee. Use a complimentary invite instead.';

        return $base;
    }
    if ($discountAmount <= 0) {
        $base['error'] = 'This code does not apply to this membership quote.';

        return $base;
    }

    $labels = membership_discount_applies_to_labels();
    $appliesLabel = strtolower($labels[$applies] ?? 'the quoted fees');

    $base['ok'] = true;
    $base['dues'] = $newDues;
    $base['initiation'] = $newInit;
    $base['discount_amount'] = $discountAmount;
    $base['message'] = 'Discount ' . $code . ' applied — ' . formatMoney($discountAmount) . ' off ' . $appliesLabel . '.';

    return $base;
}

/**
 * @return array{ok:bool, id:?int, error:?string}
 */
function membership_discount_code_create(PDO $pdo, array $data, int $createdBy): array
{
    membership_discount_codes_ensure_schema($pdo);

    $code = membership_discount_normalize_code($data['code'] ?? '');
    $type = membership_discount_normalize_type($data['discount_type'] ?? 'amount');
    $applies = membership_discount_normalize_applies_to($data['applies_to'] ?? 'both');
    $amount = round((float) ($data['amount'] ?? 0), 2);
    $notes = trim((string) ($data['notes'] ?? ''));
    $expiresAt = trim((string) ($data['expires_at'] ?? ''));

    if ($code === '' || strlen($code) < 3) {
        return ['ok' => false, 'id' => null, 'error' => 'Enter a code of at least 3 letters or numbers.'];
    }
    if ($amount <= 0) {
        return ['ok' => false, 'id' => null, 'error' => 'Enter a discount greater than zero.'];
    }
    if ($type === 'percent' && $amount >= 100) {
        return ['ok' => false, 'id' => null, 'error' => 'Percent-off codes must be less than 100%. Use a complimentary invite for a free membership.'];
    }
    if ($type === 'percent' && $amount > 99.99) {
        $amount = 99.99;
    }
    if ($type === 'amount' && $amount > 9999.99) {
        return ['ok' => false, 'id' => null, 'error' => 'Dollar-off amount is too large.'];
    }
    if ($expiresAt !== '' && strtotime($expiresAt) === false) {
        return ['ok' => false, 'id' => null, 'error' => 'Enter a valid expiration date.'];
    }
    if ($expiresAt === '') {
        $expiresAt = null;
    } else {
        $expiresAt = date('Y-m-d 23:59:59', strtotime($expiresAt));
    }

    try {
        $pdo->prepare('
            INSERT INTO membership_discount_codes
                (code, discount_type, amount, applies_to, notes, created_by, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $code,
            $type,
            $amount,
            $applies,
            $notes !== '' ? $notes : null,
            $createdBy > 0 ? $createdBy : null,
            $expiresAt,
        ]);
    } catch (PDOException $e) {
        if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate')) {
            return ['ok' => false, 'id' => null, 'error' => 'That code already exists. Disable it or choose a different code.'];
        }
        throw $e;
    }

    return ['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'error' => null];
}

/**
 * @return list<array<string, mixed>>
 */
function membership_discount_code_list(PDO $pdo, string $filter = 'active'): array
{
    membership_discount_codes_ensure_schema($pdo);

    $sql = 'SELECT c.*, u.name AS created_by_name
            FROM membership_discount_codes c
            LEFT JOIN users u ON u.id = c.created_by';

    if ($filter === 'active') {
        $sql .= ' WHERE c.active = 1 AND c.disabled_at IS NULL
                  AND (c.expires_at IS NULL OR c.expires_at > NOW())';
    } elseif ($filter === 'expired') {
        $sql .= ' WHERE c.active = 1 AND c.disabled_at IS NULL
                  AND c.expires_at IS NOT NULL AND c.expires_at <= NOW()';
    } elseif ($filter === 'disabled') {
        $sql .= ' WHERE c.active = 0 OR c.disabled_at IS NOT NULL';
    }

    $sql .= ' ORDER BY c.id DESC LIMIT 200';
    $stmt = $pdo->query($sql);

    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function membership_discount_code_set_active(PDO $pdo, int $id, bool $active): bool
{
    if ($id < 1) {
        return false;
    }
    membership_discount_codes_ensure_schema($pdo);
    if ($active) {
        $stmt = $pdo->prepare('
            UPDATE membership_discount_codes
            SET active = 1, disabled_at = NULL
            WHERE id = ?
        ');
        $stmt->execute([$id]);
    } else {
        $stmt = $pdo->prepare('
            UPDATE membership_discount_codes
            SET active = 0, disabled_at = NOW()
            WHERE id = ? AND active = 1
        ');
        $stmt->execute([$id]);
    }

    return $stmt->rowCount() > 0;
}

function membership_discount_code_status_label(array $row, ?DateTimeInterface $now = null): string
{
    if (empty($row['active']) || !empty($row['disabled_at'])) {
        return 'Disabled';
    }
    if (!membership_discount_code_is_usable($row, $now)) {
        return 'Expired';
    }

    return 'Active';
}

function membership_discount_code_summary(array $row): string
{
    $type = membership_discount_normalize_type($row['discount_type'] ?? '');
    $amount = round((float) ($row['amount'] ?? 0), 2);
    $applies = membership_discount_normalize_applies_to($row['applies_to'] ?? '');
    $labels = membership_discount_applies_to_labels();
    $appliesLabel = strtolower($labels[$applies] ?? 'quoted fees');
    if ($type === 'percent') {
        $off = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.') . '% off';
    } else {
        $off = formatMoney($amount) . ' off';
    }

    return $off . ' ' . $appliesLabel;
}
