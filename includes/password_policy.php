<?php
/**
 * Password policy and strength for the app.
 * Rule: at least 10 characters, OR at least 8 characters with at least one number and one symbol.
 * Strength indicator: weak / fair / good / strong for UI.
 */

/**
 * Validate password against policy.
 * Returns [true] on success, or [false, 'error message'] on failure.
 *
 * @param string $password
 * @return array{0: bool, 1?: string}
 */
function validate_password_policy(string $password): array {
    $len = strlen($password);
    if ($len >= 10) {
        return [true];
    }
    if ($len < 8) {
        return [false, 'Password must be at least 10 characters, or 8+ characters with a number and a symbol.'];
    }
    $hasDigit  = preg_match('/[0-9]/', $password);
    $hasSymbol = preg_match('/[^a-zA-Z0-9]/', $password);
    if ($hasDigit && $hasSymbol) {
        return [true];
    }
    return [false, 'Password must be at least 10 characters, or 8+ characters with a number and a symbol.'];
}

/**
 * Return a strength label for UI: 'weak' | 'fair' | 'good' | 'strong'.
 * Used for display only; validation is validate_password_policy().
 *
 * @param string $password
 * @return string
 */
function password_strength_label(string $password): string {
    $len = strlen($password);
    if ($len === 0) {
        return 'weak';
    }
    $hasDigit  = preg_match('/[0-9]/', $password);
    $hasSymbol = preg_match('/[^a-zA-Z0-9]/', $password);
    $hasUpper  = preg_match('/[A-Z]/', $password);
    $hasLower  = preg_match('/[a-z]/', $password);
    $variety   = ($hasDigit ? 1 : 0) + ($hasSymbol ? 1 : 0) + ($hasUpper ? 1 : 0) + ($hasLower ? 1 : 0);
    if ($len >= 12 && $variety >= 3) {
        return 'strong';
    }
    if ($len >= 10 && $variety >= 2) {
        return 'good';
    }
    if ($len >= 8 && ($hasDigit || $hasSymbol)) {
        return 'fair';
    }
    return 'weak';
}
