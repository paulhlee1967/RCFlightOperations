<?php
/**
 * includes/page_header.php
 *
 * Standard in-app page title row. Include after header.php when needed.
 *
 * render_page_header([
 *     'title'    => 'Members',
 *     'subtitle' => 'Optional plain-text subtitle',
 *     'subtitle_html' => '<em>optional</em> HTML subtitle (caller must escape)',
 *     'actions'  => '<a class="btn ...">...</a>',  // trusted HTML from the caller
 *     'border'   => false,  // true adds pb-2 border-bottom (filter pages)
 *     'class'    => 'mb-3', // extra wrapper classes when border is false
 * ]);
 */

declare(strict_types=1);

function render_page_header(array $options): void
{
    $title   = (string) ($options['title'] ?? '');
    $subtitle     = isset($options['subtitle']) ? (string) $options['subtitle'] : '';
    $subtitleHtml = isset($options['subtitle_html']) ? (string) $options['subtitle_html'] : '';
    $actions = (string) ($options['actions'] ?? '');
    $border  = !empty($options['border']);
    $class   = (string) ($options['class'] ?? 'mb-3');

    if ($border) {
        $wrapClass = 'd-flex align-items-start justify-content-between flex-wrap gap-3 mb-4 pb-2 border-bottom';
    } else {
        $wrapClass = 'd-flex flex-wrap align-items-center justify-content-between gap-2 ' . $class;
    }

    $hasSubtitle = $subtitle !== '' || $subtitleHtml !== '';

    echo '<div class="' . htmlspecialchars($wrapClass, ENT_QUOTES, 'UTF-8') . '">';
    echo '<div>';
    echo '<h1 class="h2 mb-' . ($hasSubtitle ? '1' : '0') . '">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    if ($subtitleHtml !== '') {
        echo '<p class="text-muted small mb-0">' . $subtitleHtml . '</p>';
    } elseif ($subtitle !== '') {
        echo '<p class="text-muted small mb-0">' . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    echo '</div>';
    if ($actions !== '') {
        echo '<div class="d-flex gap-2 flex-wrap align-items-center">' . $actions . '</div>';
    }
    echo '</div>';
}
