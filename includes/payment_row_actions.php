<?php
/**
 * Staff actions for one payments row: delete cash mistakes, record Stripe refunds.
 *
 * Expects: $payment (array), $memberId (int), $paymentReturn ('edit'|'process')
 */
if (!function_exists('payment_is_stripe')) {
    require_once __DIR__ . '/payments_ledger.php';
}

$payment = $payment ?? [];
$memberId = (int) ($memberId ?? 0);
$paymentReturn = (string) ($paymentReturn ?? 'edit');

if (!canManagePayments() || $memberId <= 0 || empty($payment['id'])) {
    return;
}

if (payment_is_refunded($payment)): ?>
    <span class="badge text-bg-secondary">Refunded</span>
    <?php if ((float) ($payment['amount_refunded'] ?? 0) > 0): ?>
    <span class="small text-muted"><?= h(formatMoney($payment['amount_refunded'])) ?></span>
    <?php endif; ?>
<?php elseif (payment_is_stripe($payment)): ?>
    <form method="post" action="payment_refund.php" class="d-inline"
          data-confirm-submit="Record this Stripe payment as refunded? Refund it in the Stripe Dashboard first, then confirm here so reports stay accurate.">
        <?= csrf_field() ?>
        <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
        <input type="hidden" name="member_id" value="<?= (int) $memberId ?>">
        <input type="hidden" name="return" value="<?= h($paymentReturn) ?>">
        <input type="hidden" name="amount_refunded" value="<?= h((string) payment_gross_collected($payment)) ?>">
        <button type="submit" class="btn btn-outline-warning btn-sm py-0 px-1">Record refund</button>
    </form>
<?php else: ?>
    <form method="post" action="payment_delete.php" class="d-inline"
          data-confirm-submit="Delete this payment? This permanently removes it from the record.">
        <?= csrf_field() ?>
        <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
        <input type="hidden" name="member_id" value="<?= (int) $memberId ?>">
        <input type="hidden" name="return" value="<?= h($paymentReturn) ?>">
        <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1">Delete</button>
    </form>
<?php endif;