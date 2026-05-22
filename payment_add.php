<?php
/**
 * Add a payment for a member.
 *
 * Extracted from the legacy `member_edit.php` handler so member editing and
 * payment recording don't live in one large file.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit_log.php';
require_once __DIR__ . '/includes/validation.php';
require_once __DIR__ . '/includes/flash.php';

requireLogin();
if (!canEditMembers()) {
    header('Location: index.php');
    exit;
}
$memberId = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['member_id'] ?? 0);
if ($memberId <= 0) {
    header('Location: members.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    $stmt = $pdo->prepare('SELECT id FROM members WHERE id = ?');
    $stmt->execute([$memberId]);
    if ($stmt->fetch()) {
        [$valErrors, $c] = validate_payment_input($_POST);
        if ($valErrors !== []) {
            flash(implode(' ', array_values($valErrors)), 'warning');
        } else {
            $paidAt = $c['paid_at'];
            $year   = $c['year'];
            $dues   = $c['amount_dues'];
            $init   = $c['amount_initiation'];
            $late   = $c['amount_late_fee'];
            $comp   = $c['comp'];

            $ins = $pdo->prepare('INSERT INTO payments (member_id, paid_at, year, amount_dues, amount_initiation, amount_late_fee, comp) VALUES (?,?,?,?,?,?,?)');
            $ins->execute([$memberId, $paidAt, $year, $dues, $init, $late, $comp ? 1 : 0]);
            recordMemberMembershipYear($pdo, $memberId, $year, 'payment');
            audit_log($pdo,
                currentUserId(),
                'payment_add',
                'payment',
                (int) $pdo->lastInsertId(),
                json_encode(['member_id' => $memberId, 'year' => $year])
            );
        }
    }
}

header('Location: member_edit.php?id=' . $memberId);
exit;

