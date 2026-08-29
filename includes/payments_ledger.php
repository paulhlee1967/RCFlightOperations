<?php
/**
 * Payment ledger helpers: Stripe vs cash, refunds vs hard-delete.
 */

declare(strict_types=1);

/**
 * Whether this payment was collected through Stripe (do not hard-delete).
 */
function payment_is_stripe(array $payment): bool
{
    $txn = trim((string) ($payment['payment_transaction_id'] ?? ''));
    if ($txn !== '') {
        return true;
    }
    $gateway = strtolower(trim((string) ($payment['payment_gateway'] ?? '')));

    return $gateway === 'stripe' || str_starts_with($txn, 'pi_');
}

function payment_ledger_status(array $payment): string
{
    $status = strtolower(trim((string) ($payment['ledger_status'] ?? 'recorded')));

    return $status === 'refunded' ? 'refunded' : 'recorded';
}

function payment_is_refunded(array $payment): bool
{
    return payment_ledger_status($payment) === 'refunded';
}

/**
 * Whether this row still counts as this year's payment (blocks recording another).
 */
function payment_occupies_membership_year(array $payment): bool
{
    return !payment_is_refunded($payment);
}

/**
 * Latest non-refunded payment for a member + membership year, if any.
 */
function payment_active_for_member_year(PDO $pdo, int $memberId, int $year): ?array
{
    if ($memberId <= 0 || $year <= 0) {
        return null;
    }
    payment_ensure_refund_schema($pdo);
    $stmt = $pdo->prepare('
        SELECT * FROM payments
        WHERE member_id = ? AND year = ?
          AND COALESCE(ledger_status, \'recorded\') <> \'refunded\'
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmt->execute([$memberId, $year]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function payment_gross_collected(array $payment): float
{
    return round(
        (float) ($payment['amount_dues'] ?? 0)
        + (float) ($payment['amount_initiation'] ?? 0)
        + (float) ($payment['amount_late_fee'] ?? 0)
        + (float) ($payment['amount_processing_fee'] ?? 0),
        2
    );
}

/** Club operating revenue on this row (excludes Stripe processing pass-through). */
function payment_club_net(array $payment): float
{
    if (payment_is_refunded($payment)) {
        return 0.0;
    }

    return round(
        (float) ($payment['amount_dues'] ?? 0)
        + (float) ($payment['amount_initiation'] ?? 0)
        + (float) ($payment['amount_late_fee'] ?? 0),
        2
    );
}

function payment_ensure_refund_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $add = static function (PDO $pdo, string $sql, string $column): void {
        try {
            $stmt = $pdo->query('SHOW COLUMNS FROM payments LIKE ' . $pdo->quote($column));
            if ($stmt && $stmt->fetch()) {
                return;
            }
            $pdo->exec($sql);
        } catch (Throwable $e) {
        }
    };

    $add($pdo, "ALTER TABLE payments ADD COLUMN ledger_status varchar(16) NOT NULL DEFAULT 'recorded' COMMENT 'recorded or refunded' AFTER payment_transaction_id", 'ledger_status');
    $add($pdo, 'ALTER TABLE payments ADD COLUMN amount_refunded decimal(10,2) NOT NULL DEFAULT 0.00 AFTER ledger_status', 'amount_refunded');
    $add($pdo, 'ALTER TABLE payments ADD COLUMN stripe_refund_id varchar(128) DEFAULT NULL AFTER amount_refunded', 'stripe_refund_id');
    $add($pdo, 'ALTER TABLE payments ADD COLUMN refunded_at datetime DEFAULT NULL AFTER stripe_refund_id', 'refunded_at');
}

/**
 * Mark a Stripe (or other) payment as refunded. Does not call Stripe — record
 * the Dashboard refund here so revenue reports stop counting the original charge.
 *
 * @return array{ok:bool, error:?string}
 */
function payment_record_refund(
    PDO $pdo,
    int $paymentId,
    int $memberId,
    float $amountRefunded,
    string $stripeRefundId,
    int $userId
): array {
    payment_ensure_refund_schema($pdo);

    $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? AND member_id = ? LIMIT 1');
    $stmt->execute([$paymentId, $memberId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment) {
        return ['ok' => false, 'error' => 'That payment was not found.'];
    }
    if (payment_is_refunded($payment)) {
        return ['ok' => false, 'error' => 'This payment is already marked refunded.'];
    }

    $gross = payment_gross_collected($payment);
    if ($amountRefunded <= 0) {
        $amountRefunded = $gross;
    }
    $amountRefunded = round($amountRefunded, 2);
    if ($amountRefunded > $gross + 0.009) {
        return ['ok' => false, 'error' => 'Refund amount cannot exceed what was collected.'];
    }

    $refundId = trim($stripeRefundId);
    if (strlen($refundId) > 128) {
        $refundId = substr($refundId, 0, 128);
    }

    $pdo->prepare(
        'UPDATE payments
         SET ledger_status = ?,
             amount_refunded = ?,
             stripe_refund_id = ?,
             refunded_at = NOW()
         WHERE id = ? AND member_id = ?'
    )->execute(['refunded', $amountRefunded, $refundId !== '' ? $refundId : null, $paymentId, $memberId]);

    if (function_exists('audit_log')) {
        audit_log(
            $pdo,
            $userId,
            'payment_refund',
            'payment',
            $paymentId,
            json_encode([
                'member_id' => $memberId,
                'year' => (int) ($payment['year'] ?? 0),
                'amount_refunded' => $amountRefunded,
                'stripe_refund_id' => $refundId,
            ])
        );
    }

    return ['ok' => true, 'error' => null];
}
