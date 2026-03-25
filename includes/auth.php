<?php
/**
 * Require login. Include after db.php.
 * Expects session keys: user_id, user_email, user_name.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($pdo)) {
    flightops_refresh_maintenance_mode_global($pdo);
}

if (!function_exists('safe_redirect_url')) {
    require_once __DIR__ . '/safe_redirect.php';
}

function requireLogin(): void {
    if (empty($_SESSION['user_id'])) {
        $redirect = safe_redirect_url($_SERVER['REQUEST_URI'] ?? '', 'index.php');
        header('Location: login.php?redirect=' . urlencode($redirect));
        exit;
    }
    if (empty($_SESSION['user_role']) && isset($pdo)) {
        $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch();
        if ($row) {
            $_SESSION['user_role'] = $row['role'];
        }
    }
}

function currentUserId(): int {
    return (int) ($_SESSION['user_id'] ?? 0);
}

function isAdmin(): bool {
    return ($_SESSION['user_role'] ?? '') === 'admin';
}

function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        header('Location: index.php');
        exit;
    }
}

/**
 * Role-based permissions. Roles: admin (full), editor, treasurer, viewer.
 */
function canManageUsers(): bool {
    return isAdmin();
}

function canViewReports(): bool {
    if (empty($_SESSION['user_id'])) return false;
    $role = $_SESSION['user_role'] ?? '';
    return in_array($role, ['admin', 'editor', 'treasurer', 'viewer'], true);
}

function canEditMembers(): bool {
    if (empty($_SESSION['user_id'])) return false;
    $role = $_SESSION['user_role'] ?? '';
    return in_array($role, ['admin', 'editor'], true);
}

function canProcessMemberships(): bool {
    if (empty($_SESSION['user_id'])) return false;
    $role = $_SESSION['user_role'] ?? '';
    return in_array($role, ['admin', 'editor', 'treasurer'], true);
}

function canManagePayments(): bool {
    return canEditMembers() || canProcessMemberships();
}

function canManageConfig(): bool {
    return isAdmin();
}

function getSystemUserRoles(): array {
    return [
        'admin'    => 'Admin (full access + config + users)',
        'editor'   => 'Editor (members, reports)',
        'treasurer' => 'Treasurer (reports, view/export members)',
        'viewer'   => 'Viewer (reports only)',
    ];
}
