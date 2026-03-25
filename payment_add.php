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
        $paidAt = trim((string) ($_POST['paid_at'] ?? ''));
        $year   = (int) ($_POST['year'] ?? date('Y'));
        $dues   = (float) ($_POST['amount_dues'] ?? 0);
        $init   = (float) ($_POST['amount_initiation'] ?? 0);
        $late   = (float) ($_POST['amount_late_fee'] ?? 0);
        $comp   = !empty($_POST['comp']);

        if ($paidAt !== '') {
            $ins = $pdo->prepare('INSERT INTO payments (member_id, paid_at, year, amount_dues, amount_initiation, amount_late_fee, comp) VALUES (?,?,?,?,?,?,?)');
            $ins->execute([$memberId, $paidAt, $year, $dues, $init, $late, $comp ? 1 : 0]);
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

