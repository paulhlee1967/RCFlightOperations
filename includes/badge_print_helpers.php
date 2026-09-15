<?php
/**
 * includes/badge_print_helpers.php
 *
 * Badge print page: design selection and mark-printed action.
 */

declare(strict_types=1);

require_once __DIR__ . '/badge_member_data.php';

/**
 * Handle POST mark_printed; redirects and exits when handled.
 *
 * When opened from the Process Signup / Renewal workflow (from_process=1),
 * also mark the fulfillment checklist item and redirect back to #fulfill —
 * mirroring member_mailer.php's "Mark mailer as printed" behavior.
 */
function badge_print_handle_post(PDO $pdo): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'mark_printed') {
        return;
    }
    csrf_validate();

    $mid         = isset($_POST['member_id']) ? (int) $_POST['member_id'] : 0;
    $fromProcess = !empty($_POST['from_process']);
    $fromWizard  = !empty($_POST['wizard']);
    $year        = isset($_POST['year']) ? (int) $_POST['year'] : (int) date('Y');
    $userId      = function_exists('currentUserId') ? (int) currentUserId() : 0;

    if ($mid > 0) {
        $pdo->prepare('UPDATE members SET badge_printed_at = NOW() WHERE id = ?')->execute([$mid]);

        if ($fromProcess && $year > 0) {
            $pdo->prepare('
                INSERT INTO member_fulfillments (member_id, year)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE id = id
            ')->execute([$mid, $year]);

            $pdo->prepare('
                UPDATE member_fulfillments
                SET card_printed_at = NOW(), card_printed_by = ?
                WHERE member_id = ? AND year = ?
            ')->execute([$userId > 0 ? $userId : null, $mid, $year]);

            $wizardQs = $fromWizard ? '&wizard=1' : '';
            header('Location: member_process.php?id=' . $mid . '&year=' . $year . $wizardQs . '#fulfill');
            exit;
        }
    }

    // Standalone print (from member edit): stay on badge page, preserve context.
    $qs = 'id=' . $mid . '&printed=1';
    if ($fromProcess) {
        $qs .= '&from_process=1&year=' . $year;
        if ($fromWizard) {
            $qs .= '&wizard=1';
        }
    }
    header('Location: badge_print.php?' . $qs);
    exit;
}

/**
 * @return array<int, array<string, mixed>>
 */
function badge_print_designs_list(PDO $pdo): array
{
    try {
        return $pdo->query(
            'SELECT id, name, template_data, is_default
               FROM badge_templates
              ORDER BY is_default DESC, name ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @param array<int, array<string, mixed>> $designs
 *
 * @return array{design: ?array, templateData: ?array, designId: int}
 */
function badge_print_select_design(array $designs, int $requestedDesignId): array
{
    $selected = null;
    if ($requestedDesignId > 0) {
        foreach ($designs as $d) {
            if ((int) $d['id'] === $requestedDesignId) {
                $selected = $d;
                break;
            }
        }
    }
    if ($selected === null) {
        foreach ($designs as $d) {
            if ((int) ($d['is_default'] ?? 0) === 1) {
                $selected = $d;
                break;
            }
        }
    }
    if ($selected === null && $designs !== []) {
        $selected = $designs[0];
    }

    $templateData = null;
    if ($selected && !empty($selected['template_data'])) {
        $decoded = json_decode((string) $selected['template_data'], true);
        $templateData = is_array($decoded) ? $decoded : null;
    }

    return [
        'design'       => $selected,
        'templateData' => $templateData,
        'designId'     => $selected ? (int) $selected['id'] : 0,
    ];
}

/**
 * @return array<string, mixed>|false
 */
function badge_print_load_member(PDO $pdo, int $memberId): array|false
{
    if ($memberId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare(badge_member_with_address_sql());
    $stmt->execute([$memberId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: false;
}
