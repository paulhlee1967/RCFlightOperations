<?php
/**
 * Lightweight audit log for club actions.
 * Include after db.php. Call audit_log() after successful mutations.
 */

/**
 * @param PDO    $pdo        Database connection
 * @param int    $userId     User who performed the action (0 if system)
 * @param string $action     Action identifier
 * @param string $targetType Entity type: 'payment', 'member', 'user', etc.
 * @param int    $targetId   ID of the affected record (0 if N/A)
 * @param string $detail     Optional JSON or short human-readable detail
 */
function audit_log(PDO $pdo, int $userId, string $action, string $targetType, int $targetId = 0, string $detail = ''): void {
    static $tableExists = null;
    if ($tableExists === null) {
        try {
            $pdo->query("SELECT 1 FROM audit_log LIMIT 1");
            $tableExists = true;
        } catch (Throwable $e) {
            $tableExists = false;
        }
    }
    if (!$tableExists) {
        return;
    }
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log (user_id, action, target_type, target_id, detail, created_at) VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$userId, $action, $targetType, $targetId, $detail]);
    } catch (Throwable $e) {
    }
}
