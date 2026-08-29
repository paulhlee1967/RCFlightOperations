<?php
/**
 * Record a Stripe refund on the ledger (does not call Stripe).
 * Cash/check mistakes still use payment_delete.php.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit_log.php';
require_once __DIR__ . '/includes/flash.php';
require_once __DIR__ . '/includes/payments_ledger.php';

requireLogin();
if (!canManagePayments()) {
    header('Location: index.php');
    exit;
}
$userId = currentUserId();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: members.php');
    exit;
}

csrf_validate();

$paymentId = (int) ($_POST['payment_id'] ?? 0);
$memberId  = (int) ($_POST['member_id'] ?? 0);
$return    = (string) ($_POST['return'] ?? 'edit');
$amount    = (float) ($_POST['amount_refunded'] ?? 0);
$refundId  = trim((string) ($_POST['stripe_refund_id'] ?? ''));

if ($paymentId <= 0 || $memberId <= 0) {
    flash('Invalid refund request.', 'danger');
    header('Location: members.php');
    exit;
}

$result = payment_record_refund($pdo, $paymentId, $memberId, $amount, $refundId, $userId);
if ($result['ok']) {
    flash('Refund recorded. Revenue reports will no longer count this Stripe payment as collected.', 'success');
} else {
    flash($result['error'] ?? 'Could not record that refund.', 'danger');
}

if ($return === 'process') {
    header('Location: member_process.php?id=' . $memberId);
} else {
    header('Location: member_edit.php?id=' . $memberId);
}
exit;
