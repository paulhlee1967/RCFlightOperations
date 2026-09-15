<?php
/**
 * Require login. Include after db.php.
 * Expects session keys: user_id, user_email, user_name, user_role.
 *
 * Roles (stored in users.role):
 *   admin         — Administrator (full access)
 *   manager       — Membership Manager (member records, renewals, badges)
 *   staff         — Club Staff (renewals, applications, exports; read-only member PII)
 *   report_viewer — Report Viewer (reports and incidents read-only; no member PII)
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

/** @var array<string, string> Legacy role slugs from before the 2026 rename. */
function legacyUserRoleMap(): array
{
    return [
        'editor'    => 'manager',
        'treasurer' => 'staff',
        'viewer'    => 'report_viewer',
    ];
}

function normalizeUserRole(string $role): string
{
    $role = trim($role);
    if ($role === '') {
        return 'manager';
    }

    return legacyUserRoleMap()[$role] ?? $role;
}

/** Staff idle timeout (seconds). Member portal uses its own TTL. */
const STAFF_SESSION_TTL = 1800;

function staff_session_touch(): void
{
    $_SESSION['user_active_at'] = time();
}

function staff_session_idle_expired(?int $now = null, ?int $lastActive = null, int $ttl = STAFF_SESSION_TTL): bool
{
    $now = $now ?? time();
    if ($lastActive === null || $lastActive <= 0) {
        return false;
    }

    return ($now - $lastActive) > $ttl;
}

function staff_session_clear(): void
{
    unset(
        $_SESSION['user_id'],
        $_SESSION['user_email'],
        $_SESSION['user_name'],
        $_SESSION['user_role'],
        $_SESSION['user_active_at']
    );
}

function requireLogin(): void {
    if (empty($_SESSION['user_id'])) {
        $redirect = safe_redirect_url($_SERVER['REQUEST_URI'] ?? '', 'index.php');
        header('Location: login.php?redirect=' . urlencode($redirect));
        exit;
    }
    $lastActive = isset($_SESSION['user_active_at']) ? (int) $_SESSION['user_active_at'] : 0;
    if ($lastActive > 0 && staff_session_idle_expired(null, $lastActive)) {
        staff_session_clear();
        $redirect = safe_redirect_url($_SERVER['REQUEST_URI'] ?? '', 'index.php');
        header('Location: login.php?reason=idle&redirect=' . urlencode($redirect));
        exit;
    }
    staff_session_touch();
    if (empty($_SESSION['user_role']) && isset($pdo)) {
        $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch();
        if ($row) {
            $_SESSION['user_role'] = normalizeUserRole((string) ($row['role'] ?? ''));
        }
    } elseif (!empty($_SESSION['user_role'])) {
        $_SESSION['user_role'] = normalizeUserRole((string) $_SESSION['user_role']);
    }
}

function currentUserId(): int {
    return (int) ($_SESSION['user_id'] ?? 0);
}

function currentUserRole(): string {
    if (empty($_SESSION['user_id'])) {
        return '';
    }

    return normalizeUserRole((string) ($_SESSION['user_role'] ?? ''));
}

function isAdmin(): bool {
    return currentUserRole() === 'admin';
}

function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        header('Location: index.php');
        exit;
    }
}

function canManageUsers(): bool {
    return isAdmin();
}

function canViewReports(): bool {
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    return in_array(currentUserRole(), ['admin', 'manager', 'staff', 'report_viewer'], true);
}

/**
 * Members list + read-only member detail (PII). Excludes Report Viewer.
 */
function canViewMembers(): bool {
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    return in_array(currentUserRole(), ['admin', 'manager', 'staff'], true);
}

function canEditMembers(): bool {
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    return in_array(currentUserRole(), ['admin', 'manager'], true);
}

/** Renewals, applications, exports, badge print — without editing member contact fields. */
function canProcessMemberships(): bool {
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    return in_array(currentUserRole(), ['admin', 'manager', 'staff'], true);
}

function canManagePayments(): bool {
    return canEditMembers() || canProcessMemberships();
}

function canManageConfig(): bool {
    return isAdmin();
}

function getSystemUserRoles(): array {
    return [
        'admin'         => 'Administrator — full access, users, and club configuration',
        'manager'       => 'Membership Manager — member records, renewals, badges, and incidents',
        'staff'         => 'Club Staff — renewals, applications, exports, and badge printing (read-only member details)',
        'report_viewer' => 'Report Viewer — reports and incident log read-only (no member list or exports)',
    ];
}

/** CSS class suffix for role badges/avatars (hyphenated). */
function userRoleCssSuffix(string $role): string
{
    return str_replace('_', '-', normalizeUserRole($role));
}
