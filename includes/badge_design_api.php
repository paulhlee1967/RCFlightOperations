<?php
/**
 * includes/badge_design_api.php
 *
 * JSON/AJAX handlers for badge_design.php. Included after auth; exits on API requests.
 * Expects $pdo, $userId, and $membershipTypeLabels in scope.
 */

declare(strict_types=1);

// ── API: list designs ───────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'list') {
    badge_api_json(['ok' => true, 'designs' => badge_designs_list($pdo)]);
}

// ── API: load ──────────────────────────────────────────────────────────────
// load&id=N → that design; load (no id) → the default design, else the oldest.
if (isset($_GET['action']) && $_GET['action'] === 'load') {
    header('Content-Type: application/json');
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT template_data FROM badge_templates WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->query('SELECT template_data FROM badge_templates ORDER BY is_default DESC, id ASC LIMIT 1');
        $row  = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    }
    echo $row ? $row['template_data'] : '{}';
    exit;
}

// ── API: save ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    csrf_validate(['json' => true]);
    header('Content-Type: application/json; charset=utf-8');
    $json = $_POST['template'] ?? '';
    if ($json === '') {
        badge_api_json(['ok' => false, 'error' => 'No template data']);
    }
    $templateId = (int) ($_POST['template_id'] ?? 0);
    $name       = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        $name = 'Untitled design';
    }
    $name      = function_exists('mb_substr') ? mb_substr($name, 0, 100) : substr($name, 0, 100);
    $isDefault = !empty($_POST['is_default']) ? 1 : 0;

    try {
        $pdo->beginTransaction();

        if ($templateId > 0) {
            $chk = $pdo->prepare('SELECT id FROM badge_templates WHERE id = ?');
            $chk->execute([$templateId]);
            if (!$chk->fetch()) {
                $pdo->rollBack();
                badge_api_json(['ok' => false, 'error' => 'Design not found']);
            }
            $stmt = $pdo->prepare('UPDATE badge_templates SET name = ?, template_data = ?, is_default = ? WHERE id = ?');
            $stmt->execute([$name, $json, $isDefault, $templateId]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO badge_templates (name, template_data, is_default) VALUES (?,?,?)');
            $stmt->execute([$name, $json, $isDefault]);
            $templateId = (int) $pdo->lastInsertId();
            $json = badge_finalize_background_in_template($json, $templateId, $userId);
            $pdo->prepare('UPDATE badge_templates SET template_data = ? WHERE id = ?')->execute([$json, $templateId]);
        }

        if ($isDefault) {
            $pdo->prepare('UPDATE badge_templates SET is_default = 0 WHERE id <> ?')->execute([$templateId]);
            $pdo->prepare('UPDATE badge_templates SET is_default = 1 WHERE id = ?')->execute([$templateId]);
        }

        $hasDefault = (int) $pdo->query('SELECT COUNT(*) FROM badge_templates WHERE is_default = 1')->fetchColumn();
        if ($hasDefault === 0) {
            $pdo->prepare('UPDATE badge_templates SET is_default = 1 WHERE id = ?')->execute([$templateId]);
            $isDefault = 1;
        }

        $pdo->commit();
        badge_api_json([
            'ok'         => true,
            'id'         => $templateId,
            'name'       => $name,
            'is_default' => $isDefault,
            'designs'    => badge_designs_list($pdo),
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        badge_api_json(['ok' => false, 'error' => 'Save failed — database error']);
    }
}

// ── API: delete design ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_design') {
    csrf_validate(['json' => true]);
    header('Content-Type: application/json; charset=utf-8');
    $templateId = (int) ($_POST['template_id'] ?? 0);
    if ($templateId <= 0) {
        badge_api_json(['ok' => false, 'error' => 'Invalid design']);
    }
    try {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM badge_templates')->fetchColumn();
        if ($count <= 1) {
            badge_api_json(['ok' => false, 'error' => 'Cannot delete the only design']);
        }
        $sel = $pdo->prepare('SELECT is_default FROM badge_templates WHERE id = ?');
        $sel->execute([$templateId]);
        $existing = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            badge_api_json(['ok' => false, 'error' => 'Design not found']);
        }

        $pdo->beginTransaction();
        badge_delete_background_files($templateId);
        $pdo->prepare('DELETE FROM badge_templates WHERE id = ?')->execute([$templateId]);
        if ((int) $existing['is_default'] === 1) {
            $newDefault = $pdo->query('SELECT id FROM badge_templates ORDER BY id ASC LIMIT 1')->fetchColumn();
            if ($newDefault) {
                $pdo->prepare('UPDATE badge_templates SET is_default = 1 WHERE id = ?')->execute([(int) $newDefault]);
            }
        }
        $pdo->commit();
        badge_api_json(['ok' => true, 'designs' => badge_designs_list($pdo)]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        badge_api_json(['ok' => false, 'error' => 'Delete failed — database error']);
    }
}

// ── API: background image upload ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['background']) && is_uploaded_file($_FILES['background']['tmp_name'])) {
    csrf_validate(['json' => true]);
    header('Content-Type: application/json; charset=utf-8');
    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($_FILES['background']['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];
    if (!isset($allowed[$mime]) || $_FILES['background']['size'] > 3 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'error' => 'Invalid or too-large file (max 3 MB, JPEG/PNG/GIF)']);
        exit;
    }
    $templateId = (int) ($_POST['template_id'] ?? 0);
    $uploadDir  = badge_background_dir();
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $ext      = $allowed[$mime];
    $root     = badge_design_root();
    badge_delete_background_files($templateId, $userId);
    $relPath  = badge_background_rel_path($templateId, $userId, $ext);
    $fullPath = $root . '/' . $relPath;
    if (move_uploaded_file($_FILES['background']['tmp_name'], $fullPath)) {
        echo json_encode(['ok' => true, 'url' => $relPath]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Upload failed — check permissions on uploads/']);
    }
    exit;
}

// ── API: member data for preview ───────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'member_data') {
    header('Content-Type: application/json; charset=utf-8');
    $mid = (int) ($_GET['member_id'] ?? 0);
    if ($mid <= 0) {
        badge_api_json(['ok' => false, 'error' => 'Invalid member']);
    }

    $stmt = $pdo->prepare(badge_member_with_address_sql());
    try {
        $stmt->execute([$mid]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        badge_api_json(['ok' => false, 'error' => 'Database error']);
    }
    if (!$m) {
        badge_api_json(['ok' => false, 'error' => 'Member not found']);
    }

    $data = badge_member_data_from_row($m, $membershipTypeLabels, $mid);
    badge_api_json(array_merge(['ok' => true], $data));
}

// ── API: member list for preview picker ────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'member_list') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $badgeYear = membershipStatusYear();
        $stmt = $pdo->prepare('
            SELECT m.id, m.first_name, m.last_name
            FROM members m
            WHERE ' . currentMemberWhereSql('m', $badgeYear) . '
            ORDER BY m.last_name, m.first_name
            LIMIT 200
        ');
        $stmt->execute(currentMemberWhereParams($badgeYear));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        badge_api_json(['ok' => false, 'error' => 'Could not load members', 'members' => []]);
    }
    badge_api_json(['ok' => true, 'members' => $rows]);
}
