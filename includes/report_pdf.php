<?php
/**
 * includes/report_pdf.php
 *
 * Render a report structure (from includes/run_report.php) to a downloadable
 * PDF using Dompdf. Dompdf is a Composer dependency installed under vendor/;
 * if it is not present, reportPdfAvailable() returns false so callers can fall
 * back gracefully instead of fataling.
 *
 * The PDF picks up club branding (name, logo, primary colors) from the `club`
 * table so reports match the rest of the portal.
 */

require_once __DIR__ . '/run_report.php';
require_once __DIR__ . '/logo_thumb.php';

/**
 * Whether Dompdf is installed and loadable.
 */
function reportPdfAvailable(): bool
{
    $vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (is_file($vendorAutoload)) {
        require_once $vendorAutoload;
    }

    return class_exists('Dompdf\\Dompdf');
}

/**
 * Validate a CSS hex color, returning a fallback when it is missing/invalid.
 */
function reportPdfColor(?string $value, string $fallback): string
{
    $value = trim((string) $value);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : $fallback;
}

/**
 * Turn a club logo (relative path under the app root) into an embeddable
 * data: URI so Dompdf renders it without needing remote/file access.
 * Returns null when the logo is missing or an unsupported type.
 */
function reportPdfLogoDataUri(?string $logoPath): ?string
{
    // Use a small cached raster so a high-resolution logo can't exhaust memory.
    $file = clubLogoThumbFile($logoPath);
    if ($file === null || !is_file($file) || !is_readable($file)) {
        return null;
    }

    $mime = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
        'png'         => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif'         => 'image/gif',
        default       => null,
    };
    if ($mime === null) {
        return null;
    }

    $data = @file_get_contents($file);
    if ($data === false) {
        return null;
    }

    return 'data:' . $mime . ';base64,' . base64_encode($data);
}

/**
 * Build the self-contained HTML document for a report PDF.
 *
 * @param  array<string, mixed>  $report
 * @param  array<string, mixed>  $club   Branding: name, logo_path, color_primary, color_primary_dark
 */
function reportPdfHtml(array $report, array $club, ?int $year): string
{
    $clubName  = (string) ($club['name'] ?? '');
    $club_     = h($clubName !== '' ? $clubName : 'RC Flight Operations');
    $primary   = reportPdfColor($club['color_primary'] ?? null, '#6f7c3d');
    $primaryDk = reportPdfColor($club['color_primary_dark'] ?? null, '#556030');
    $title     = h($report['title'] ?? 'Report');
    $generated = h(date('M j, Y \a\t g:i a'));
    $logoUri   = reportPdfLogoDataUri($club['logo_path'] ?? null);

    $head = '';
    foreach ($report['columns'] as $col) {
        $align = ($col['align'] ?? 'start') === 'end' ? 'right' : 'left';
        $style = 'text-align:' . $align;
        $compact = reportColumnClass($col);
        if (in_array($compact, ['col-num', 'col-date', 'col-id'], true)) {
            $style .= ';white-space:nowrap;width:1%';
        }
        $head .= '<th style="' . $style . '">' . h($col['label']) . '</th>';
    }

    $body = '';
    $i = 0;
    foreach ($report['rows'] as $row) {
        $stripe = ($i++ % 2 === 1) ? ' class="alt"' : '';
        $body .= '<tr' . $stripe . '>';
        foreach ($report['columns'] as $col) {
            $align = ($col['align'] ?? 'start') === 'end' ? 'right' : 'left';
            $style = 'text-align:' . $align;
            $compact = reportColumnClass($col);
            if (in_array($compact, ['col-num', 'col-date', 'col-id'], true)) {
                $style .= ';white-space:nowrap;width:1%';
            }
            $cell  = reportFormatCell($row[$col['key']] ?? null, $col['format'], false);
            $body .= '<td style="' . $style . '">' . h($cell) . '</td>';
        }
        $body .= '</tr>';
    }
    if ($body === '') {
        $span  = max(1, count($report['columns']));
        $body  = '<tr><td colspan="' . $span . '" class="empty">No data for this report.</td></tr>';
    }

    $foot = '';
    if (!empty($report['totals'])) {
        $foot .= '<tr class="totals">';
        foreach ($report['columns'] as $col) {
            $align = ($col['align'] ?? 'start') === 'end' ? 'right' : 'left';
            $style = 'text-align:' . $align;
            $compact = reportColumnClass($col);
            if (in_array($compact, ['col-num', 'col-date', 'col-id'], true)) {
                $style .= ';white-space:nowrap;width:1%';
            }
            $cell  = reportFormatCell($report['totals'][$col['key']] ?? null, $col['format'], false);
            $foot .= '<td style="' . $style . '">' . h($cell) . '</td>';
        }
        $foot .= '</tr>';
    }

    $sub  = $year !== null ? ('<span class="chip">' . h((string) $year) . '</span>') : '';
    $note = !empty($report['note']) ? '<p class="note">' . h($report['note']) . '</p>' : '';
    if (!empty($report['accuracy_note'])) {
        $note .= '<p class="accuracy-note"><strong>Note:</strong> ' . h($report['accuracy_note']) . '</p>';
    }

    $logoCell = $logoUri !== null
        ? '<td class="logo-cell"><img src="' . $logoUri . '" class="logo" alt=""></td>'
        : '';

    return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
        @page { margin: 1.5cm 1.3cm 1.9cm; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #252018; font-size: 10px; }

        .banner { width: 100%; border-collapse: collapse; background: ' . $primary . '; }
        .banner td { padding: 12px 14px; vertical-align: middle; }
        .banner .logo-cell { width: 54px; }
        .banner .logo { height: 40px; width: auto; }
        .banner .club { color: #ffffff; font-size: 12px; font-weight: bold; letter-spacing: .02em; }
        .banner .title { color: #ffffff; font-size: 18px; font-weight: bold; margin-top: 2px; }
        .accent { height: 4px; background: ' . $primaryDk . '; font-size: 0; line-height: 0; }

        .meta { color: #665e52; font-size: 9px; margin: 10px 0 12px; }
        .chip { display: inline-block; background: ' . $primary . '; color: #fff; font-weight: bold;
                padding: 1px 7px; border-radius: 8px; margin-left: 6px; }

        table.data { width: 100%; border-collapse: collapse; }
        table.data th, table.data td { padding: 5px 7px; border-bottom: 1px solid #e3e0d7; }
        table.data thead th { background: ' . $primary . '; color: #ffffff; font-size: 9px;
                text-transform: uppercase; letter-spacing: .03em; border-bottom: none; }
        table.data tbody tr.alt td { background: #f6f5ef; }
        table.data tbody td.empty { text-align: center; color: #8a8276; padding: 16px; }
        table.data tr.totals td { border-top: 2px solid ' . $primaryDk . '; border-bottom: none;
                font-weight: bold; color: ' . $primaryDk . '; }

        .note { color: #665e52; font-size: 8px; margin-top: 12px; font-style: italic; }
        .accuracy-note { color: #7a5c00; background: #fdf6e3; border: 1px solid #f0e2b8;
                padding: 6px 8px; font-size: 8px; margin-top: 8px; }
    </style></head><body>
        <table class="banner"><tr>
            ' . $logoCell . '
            <td>
                <div class="club">' . $club_ . '</div>
                <div class="title">' . $title . '</div>
            </td>
        </tr></table>
        <div class="accent">&nbsp;</div>
        <p class="meta">Generated ' . $generated . $sub . '</p>
        <table class="data">
            <thead><tr>' . $head . '</tr></thead>
            <tbody>' . $body . '</tbody>
            <tfoot>' . $foot . '</tfoot>
        </table>
        ' . $note . '
    </body></html>';
}

/**
 * Stream a report as a PDF download. Caller must check reportPdfAvailable() first.
 *
 * @param  array<string, mixed>  $report
 * @param  array<string, mixed>  $club    Branding row from the `club` table (or a subset).
 */
function renderReportPdf(array $report, array $club, ?int $year = null): void
{
    // Rendering an embedded (often large) logo is memory-hungry; raise the
    // ceiling so PDF export doesn't fatal on the default 128M limit.
    $limit = (string) ini_get('memory_limit');
    if ($limit !== '-1') {
        $bytes = (int) $limit;
        if (stripos($limit, 'g') !== false)      { $bytes *= 1024 * 1024 * 1024; }
        elseif (stripos($limit, 'm') !== false)   { $bytes *= 1024 * 1024; }
        elseif (stripos($limit, 'k') !== false)   { $bytes *= 1024; }
        if ($bytes < 512 * 1024 * 1024) {
            @ini_set('memory_limit', '512M');
        }
    }

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml(reportPdfHtml($report, $club, $year));
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();

    // Branded footer with page numbers on every page.
    $canvas    = $dompdf->getCanvas();
    $clubName  = (string) ($club['name'] ?? '');
    $footLabel = ($clubName !== '' ? $clubName : 'RC Flight Operations');
    $primaryDk = reportPdfColor($club['color_primary_dark'] ?? null, '#556030');
    [$r, $g, $b] = sscanf($primaryDk, '#%02x%02x%02x');
    $rgb  = [$r / 255, $g / 255, $b / 255];
    $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
    $w    = $canvas->get_width();
    $h    = $canvas->get_height();
    $canvas->page_text(40, $h - 28, $footLabel, $font, 8, $rgb);
    $canvas->page_text($w - 120, $h - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, $rgb);

    $filename = 'report_' . ($report['slug'] ?? 'report')
        . ($year !== null ? '_' . $year : '')
        . '_' . date('Y-m-d') . '.pdf';

    if (ob_get_level()) {
        ob_end_clean();
    }
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}
