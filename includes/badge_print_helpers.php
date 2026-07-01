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
 */
function badge_print_handle_post(PDO $pdo): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'mark_printed') {
        return;
    }
    csrf_validate();
    $mid = isset($_POST['member_id']) ? (int) $_POST['member_id'] : 0;
    if ($mid > 0) {
        $pdo->prepare('UPDATE members SET badge_printed_at = NOW() WHERE id = ?')->execute([$mid]);
    }
    header('Location: badge_print.php?id=' . $mid . '&printed=1');
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
