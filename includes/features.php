<?php
/**
 * Feature registry for optional modules (single-club install: all enabled when logged in).
 *
 *   FEATURES constant       — canonical slug → display name
 *   featureEnabled()        — check if a feature is available
 *   requireFeature()        — gate a page; redirects if not enabled
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

function clearFeatureCache(): void {
}
