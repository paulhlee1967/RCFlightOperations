<?php
/**
 * Delete a payment (erroneous entry). Removes the row outright; the action is
 * recorded in the audit log. After deletion, the member's frozen membership-year
 * roster is re-synced so year-over-year counts stay accurate.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit_log.php';
require_once __DIR__ . '/includes/flash.php';
require_once __DIR__ . '/includes/membership_status.php';

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

if ($paymentId <= 0 || $memberId <= 0) {
    flash('Invalid payment delete request.', 'danger');
    header('Location: members.php');
    exit;
}

$stmt = $pdo->prepare('SELECT id, year FROM payments WHERE id = ? AND member_id = ? LIMIT 1');
$stmt->execute([$paymentId, $memberId]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    flash('That payment could not be deleted (not found).', 'warning');
} else {
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM payments WHERE id = ? AND member_id = ?')
            ->execute([$paymentId, $memberId]);
        audit_log($pdo,
            $userId,
            'payment_delete',
            'payment',
            $paymentId,
            json_encode(['member_id' => $memberId, 'year' => (int) $payment['year']])
        );
        // Keep the frozen per-year roster in step with the removed payment.
        syncMemberMembershipYearForMember($pdo, $memberId);
        $pdo->commit();
        flash('Payment deleted.', 'success');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('payment_delete failed: ' . $e->getMessage());
        flash('Could not delete that payment. Please try again.', 'danger');
    }
}

if ($return === 'process') {
    header('Location: member_process.php?id=' . $memberId);
} else {
    header('Location: member_edit.php?id=' . $memberId);
}
exit;
