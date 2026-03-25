<?php
/**
 * Void a payment (erroneous entry). Does not delete the row; sets voided_at / voided_by.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit_log.php';
require_once __DIR__ . '/includes/flash.php';

requireLogin();
if (!canManagePayments()) {
    header('Location: index.php');
    exit;
}
$userId   = currentUserId();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: members.php');
    exit;
}

csrf_validate();

$paymentId = (int) ($_POST['payment_id'] ?? 0);
$memberId  = (int) ($_POST['member_id'] ?? 0);
$return    = (string) ($_POST['return'] ?? 'edit');

if ($paymentId <= 0 || $memberId <= 0) {
    flash('Invalid payment void request.', 'danger');
    header('Location: members.php');
    exit;
}

$stmt = $pdo->prepare('
    SELECT id FROM payments
    WHERE id = ? AND member_id = ? AND voided_at IS NULL
    LIMIT 1
');
$stmt->execute([$paymentId, $memberId]);
if (!$stmt->fetch()) {
    flash('That payment could not be voided (already void or not found).', 'warning');
} else {
    $pdo->prepare('
        UPDATE payments
        SET voided_at = NOW(), voided_by = ?
        WHERE id = ? AND voided_at IS NULL
    ')->execute([$userId, $paymentId]);
    audit_log($pdo,
        $userId,
        'payment_void',
        'payment',
        $paymentId,
        json_encode(['member_id' => $memberId])
    );
    flash('Payment voided. Revenue reports exclude voided rows.', 'success');
}

if ($return === 'process') {
    header('Location: member_process.php?id=' . $memberId);
} else {
    header('Location: member_edit.php?id=' . $memberId);
}
exit;
