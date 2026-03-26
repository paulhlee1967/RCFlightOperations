<?php
/**
 * Shared helpers for reports / emailed charts (QuickChart fetch).
 */

/**
 * Given a Chart.js config array (from runReport() $extra['chart']), fetches a
 * PNG from QuickChart.io and returns an <img> tag with the image embedded as a
 * base64 data URI.
 *
 * @param array  $chartConfig  The chart array from $result['extra']['chart'].
 * @param string $altText      Alt text for the <img> element.
 * @param string $fallbackUrl  URL to link to if chart fetch fails (the live report).
 */
function buildChartHtml(array $chartConfig, string $altText, string $fallbackUrl): string
{
    $type     = $chartConfig['type']     ?? 'bar';
    $labels   = $chartConfig['labels']   ?? [];
    $datasets = $chartConfig['datasets'] ?? [];

    $palette = ['#4e9cf5', '#28a745', '#ffc107', '#dc3545', '#6f42c1', '#17a2b8', '#fd7e14', '#6c757d'];

    $jsDatasets = [];
    foreach ($datasets as $i => $ds) {
        $base = [
            'label' => $ds['label'] ?? '',
            'data'  => $ds['data']  ?? [],
        ];
        if (!empty($ds['colors'])) {
            $base['backgroundColor'] = $ds['colors'];
        } elseif (!empty($ds['color'])) {
            $base['backgroundColor'] = $ds['color'];
            $base['borderColor']     = $ds['color'];
        } else {
            $color = $palette[$i % count($palette)];
            $base['backgroundColor'] = $color;
            $base['borderColor']     = $color;
        }
        $base['fill'] = false;
        $jsDatasets[] = $base;
    }

    $chartJs = [
        'type' => $type,
        'data' => [
            'labels'   => $labels,
            'datasets' => $jsDatasets,
        ],
        'options' => [
            'plugins' => [
                'legend' => ['display' => count($jsDatasets) > 1 || $type === 'doughnut'],
            ],
            'scales' => $type === 'doughnut' ? new stdClass() : [
                'yAxes' => [['ticks' => ['beginAtZero' => true]]],
            ],
        ],
    ];

    $chartParam = json_encode($chartJs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $url = 'https://quickchart.io/chart?'
         . 'w=600&h=300&bkg=white&devicePixelRatio=2'
         . '&c=' . rawurlencode($chartParam);

    $pngData = fetchUrl($url, 8);

    if ($pngData !== null && str_starts_with($pngData, "\x89PNG")) {
        $b64 = base64_encode($pngData);
        return '<div style="margin:20px 0;">'
             . '<img src="data:image/png;base64,' . $b64 . '"'
             . ' alt="' . htmlspecialchars($altText) . '"'
             . ' style="max-width:100%;height:auto;display:block;">'
             . '</div>';
    }

    return '<p style="margin:16px 0;font-size:13px;color:#666;">'
         . '📊 <a href="' . htmlspecialchars($fallbackUrl) . '" style="color:#0d6efd;">'
         . 'View charts in the online report</a>'
         . ' (chart image unavailable in this email)</p>';
}

/**
 * Render a simple HTML table for report output (used by reports.php).
 */
function renderReportTable(array $headers, array $rows, string $tableClass = ''): void {
    if (empty($rows)) {
        echo '<p class="text-muted small py-2 mb-0 ps-1">No data for this selection.</p>';
        return;
    }
    echo '<div class="table-responsive"><table class="table table-hover table-sm mb-0 ' . htmlspecialchars($tableClass) . '">';
    echo '<thead class="table-light"><tr>';
    foreach ($headers as $h) {
        echo '<th>' . htmlspecialchars($h) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $cell) {
            echo '<td>' . htmlspecialchars((string) $cell) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

/**
 * Fetch a URL and return the response body, or null on failure.
 */
function fetchUrl(string $url, int $timeout = 8): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'RCFlightOperations/1.0',
        ]);
        $body = curl_exec($ch);
        $err  = curl_errno($ch);
        $errMsg = $err !== 0 ? curl_error($ch) : '';
        curl_close($ch);
        if ($err === 0 && $body !== false && $body !== '') {
            return $body;
        }
        error_log('fetchUrl: HTTP fetch failed (curl) ' . $err . ($errMsg !== '' ? ' ' . $errMsg : '') . ' url=' . $url);
        return null;
    }

    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'timeout'         => $timeout,
            'follow_location' => true,
            'user_agent'      => 'RCFlightOperations/1.0',
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body !== false && $body !== '') {
            return $body;
        }
        error_log('fetchUrl: HTTP fetch failed (file_get_contents) url=' . $url);
        return null;
    }

    error_log('fetchUrl: no cURL and allow_url_fopen is off url=' . $url);
    return null;
}
