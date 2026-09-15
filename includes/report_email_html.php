<?php
/**
 * Inline-styled HTML tables for emailed reports (shared by report_email.php and board_packet.php).
 */

require_once __DIR__ . '/run_report.php';

/** Parse a comma/semicolon-separated address list into validated emails. */
function report_email_parse_addresses(string $raw): array
{
    $parts = preg_split('/[,;]+/', $raw) ?: [];
    $out   = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) {
            $out[$p] = true;
        }
    }

    return array_keys($out);
}

function report_email_column_style(array $col, string $align): string
{
    $style = 'text-align:' . $align;
    $compact = reportColumnClass($col);
    if (in_array($compact, ['col-num', 'col-date', 'col-id'], true)) {
        $style .= ';white-space:nowrap;width:1%';
    }

    return $style;
}

/**
 * Inline-styled HTML table for an emailed report (no <style> dependency).
 */
function report_email_table_html(array $report, string $primary, string $primaryDark, string $onPrimary): string
{
    $th = 'style="text-align:%s;padding:8px 10px;background:' . $primary . ';color:' . $onPrimary
        . ';font-size:11px;text-transform:uppercase;letter-spacing:.03em;%s"';
    $td = 'style="text-align:%s;padding:7px 10px;border-bottom:1px solid #e3e0d7;font-size:13px;%s"';

    $head = '';
    foreach ($report['columns'] as $col) {
        $align = ($col['align'] ?? 'start') === 'end' ? 'right' : 'left';
        $extra = report_email_column_style($col, $align);
        $extra = str_replace('text-align:' . $align . ';', '', $extra);
        $head .= '<th ' . sprintf($th, $align, $extra) . '>' . h($col['label']) . '</th>';
    }

    $body = '';
    $i = 0;
    foreach ($report['rows'] as $row) {
        $bg = ($i++ % 2 === 1) ? ' background:#f6f5ef;' : '';
        $body .= '<tr>';
        foreach ($report['columns'] as $col) {
            $align = ($col['align'] ?? 'start') === 'end' ? 'right' : 'left';
            $extra = report_email_column_style($col, $align);
            $extra = str_replace('text-align:' . $align . ';', '', $extra);
            $cell  = reportFormatCell($row[$col['key']] ?? null, $col['format'], false);
            $body .= '<td style="text-align:' . $align . ';padding:7px 10px;border-bottom:1px solid #e3e0d7;font-size:13px;' . $extra . $bg . '">' . h($cell) . '</td>';
        }
        $body .= '</tr>';
    }
    if ($body === '') {
        $span = max(1, count($report['columns']));
        $body = '<tr><td colspan="' . $span . '" style="text-align:center;padding:16px;color:#8a8276;font-size:13px;">No data for this report.</td></tr>';
    }

    $foot = '';
    if (!empty($report['totals'])) {
        $foot .= '<tr>';
        foreach ($report['columns'] as $col) {
            $align = ($col['align'] ?? 'start') === 'end' ? 'right' : 'left';
            $extra = report_email_column_style($col, $align);
            $extra = str_replace('text-align:' . $align . ';', '', $extra);
            $cell  = reportFormatCell($report['totals'][$col['key']] ?? null, $col['format'], false);
            $foot .= '<td style="text-align:' . $align . ';padding:8px 10px;border-top:2px solid ' . $primaryDark . ';font-weight:bold;font-size:13px;color:' . $primaryDark . ';' . $extra . '">' . h($cell) . '</td>';
        }
        $foot .= '</tr>';
    }

    return '<table style="border-collapse:collapse;width:100%;">'
        . '<thead><tr>' . $head . '</tr></thead>'
        . '<tbody>' . $body . '</tbody>'
        . ($foot !== '' ? '<tfoot>' . $foot . '</tfoot>' : '')
        . '</table>';
}
