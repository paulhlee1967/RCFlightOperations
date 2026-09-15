<?php
/**
 * CSRF protection: token generation, form field, and validation.
 * Include after session_start() (e.g. from auth.php or db.php + session).
 *
 * Usage:
 *   - In forms: <?= csrf_field() ?>
 *   - At top of POST handling: csrf_validate(); (exits with 403 if invalid)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** Ensure a CSRF token exists in session; return it. */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Return HTML for a hidden input containing the CSRF token (use in forms). */
function csrf_field(): string {
    $name = 'csrf_token';
    $token = csrf_token();
    return '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($token) . '">';
}

/**
 * Validate CSRF token on POST. Call at the start of any POST handler.
 * Exits with 403 if invalid or missing.
 *
 * @param array{json?: bool} $options  If json => true, respond with application/json (for fetch/XHR APIs).
 */
function csrf_validate(array $options = []): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $sent = $_POST['csrf_token'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if ($sent === '' || $expected === '' || !hash_equals($expected, $sent)) {
        http_response_code(403);
        if (!empty($options['json'])) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'ok'      => false,
                'valid'   => false,
                'message' => 'Invalid or missing security token. Reload the page and try again.',
            ]);
        } else {
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Invalid or missing security token. Please go back and try again.';
        }
        exit;
    }
}
