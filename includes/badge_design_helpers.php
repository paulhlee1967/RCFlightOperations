<?php
/**
 * includes/badge_design_helpers.php
 *
 * Pure PHP helpers for the CR80 badge designer (paths, backgrounds, design list).
 */

declare(strict_types=1);

require_once __DIR__ . '/badge_member_data.php';

/** Project root (parent of includes/). */
function badge_design_root(): string
{
    return dirname(__DIR__);
}

/** Uniform JSON exit for designer API endpoints (except raw template load). */
function badge_api_json(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

/** Per-template background files live under uploads/branding/. */
function badge_background_dir(): string
{
    return badge_design_root() . '/uploads/branding';
}

/** Relative URL path for a template's background file. */
function badge_background_rel_path(int $templateId, int $userId, string $ext): string
{
    if ($templateId > 0) {
        return 'uploads/branding/badge_bg_' . $templateId . '.' . $ext;
    }

    return 'uploads/branding/badge_bg_pending_' . $userId . '.' . $ext;
}

/** Remove any existing background image files for a template (or pending upload for a user). */
function badge_delete_background_files(int $templateId, int $userId = 0): void
{
    $dir = badge_background_dir();
    if (!is_dir($dir)) {
        return;
    }
    $pattern = $templateId > 0
        ? $dir . '/badge_bg_' . $templateId . '.*'
        : $dir . '/badge_bg_pending_' . $userId . '.*';
    foreach (glob($pattern) ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

/**
 * When a new design is first saved, rename a pending background upload to the
 * template-specific filename and update template JSON.
 */
function badge_finalize_background_in_template(string $json, int $templateId, int $userId): string
{
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return $json;
    }
    $path = $data['backgroundPath'] ?? '';
    $pendingPrefix = 'uploads/branding/badge_bg_pending_' . $userId . '.';
    if (!is_string($path) || strpos($path, $pendingPrefix) !== 0) {
        return $json;
    }
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    if ($ext === '') {
        return $json;
    }
    $newPath = badge_background_rel_path($templateId, $userId, $ext);
    $root    = badge_design_root();
    $oldFull = $root . '/' . $path;
    $newFull = $root . '/' . $newPath;
    if (is_file($oldFull)) {
        badge_delete_background_files($templateId);
        @rename($oldFull, $newFull);
    }
    $data['backgroundPath'] = $newPath;
    unset($data['backgroundDataUrl']);

    return json_encode($data);
}

/** Return all badge designs (metadata only) with normalised integer flags. */
function badge_designs_list(PDO $pdo): array
{
    try {
        $rows = $pdo->query(
            'SELECT id, name, is_default
               FROM badge_templates
              ORDER BY is_default DESC, name ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    foreach ($rows as &$r) {
        $r['id']         = (int) $r['id'];
        $r['is_default'] = (int) $r['is_default'];
    }
    unset($r);

    return $rows;
}
