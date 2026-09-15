<?php
/**
 * Flash message helper.
 *
 * Usage (before a redirect):
 *   require_once __DIR__ . '/flash.php';
 *   flash('Member saved successfully.', 'success');
 *   header('Location: members.php');
 *   exit;
 *
 * Messages are stored in $_SESSION['flash'] and rendered by header.php as
 * Bootstrap toasts. They are consumed (deleted) on the next page load.
 *
 * @param string $message  The human-readable message to display.
 * @param string $type     Bootstrap alert type: 'success' | 'danger' | 'warning' | 'info'
 */
function flash(string $message, string $type = 'success'): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash'][] = [
        'msg'  => $message,
        'type' => in_array($type, ['success', 'danger', 'warning', 'info'], true) ? $type : 'success',
    ];
}

/**
 * Consume and return the first flash message for the current request.
 * `getFlash()` can be used for a single inline alert where toasts are not used.
 * Returns an array with 'message' and 'type', or null if none.
 *
 * @return array{message: string, type: string}|null
 */
function getFlash(): ?array {
    if (session_status() === PHP_SESSION_NONE) {
        return null;
    }
    $messages = $_SESSION['flash'] ?? [];
    if (empty($messages)) {
        return null;
    }
    $first = array_shift($messages);
    $_SESSION['flash'] = $messages;
    return [
        'message' => $first['msg'] ?? '',
        'type'    => in_array($first['type'] ?? 'success', ['success', 'danger', 'warning', 'info'], true)
            ? ($first['type'] ?? 'success') : 'success',
    ];
}