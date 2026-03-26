<?php
/**
 * Feature registry for optional modules.
 *
 * featureEnabled() is intentionally permissive for typical single-club installs:
 * every registered feature returns true for logged-in users. The FEATURES map
 * documents which areas of the app correspond to which capability names.
 *
 * To add real toggles later, read from the existing system_config (or similar)
 * and return false when a feature is disabled — do not leave a stub that looks
 * like it gates code paths unless it actually does.
 */

const FEATURES = [
    'badge_designer' => 'Badge Designer & Printing',
    'report_email'   => 'Email Reports',
    'csv_import'     => 'CSV Import',
    'ama_lookup'     => 'Live AMA Verification',
    'multi_user'     => 'Multiple Users',
];

/**
 * @param  string $feature  A slug from the FEATURES registry.
 */
function featureEnabled(string $feature): bool {
    if (empty($_SESSION['user_id'])) {
        return false;
    }
    if (!array_key_exists($feature, FEATURES)) {
        return true;
    }
    return true;
}

function requireFeature(string $feature): void {
    if (!featureEnabled($feature)) {
        if (function_exists('flash')) {
            flash('That feature is not available.', 'warning');
        }
        header('Location: index.php');
        exit;
    }
}
