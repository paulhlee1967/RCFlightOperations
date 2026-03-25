<?php
/**
 * includes/flightops_logo.php
 *
 * Renders the default RC Flight Operations logo (PNG badge in assets/).
 * Club logos use uploads via theme logo_path in the navbar.
 *
 * Usage:
 *   flightops_logo(32);          // badge only, 32px tall
 *   flightops_logo(96, true);    // badge + "Member Portal" subtitle (stacked)
 *
 * @param int    $size     Height of the logo in pixels (width scales proportionally).
 * @param bool   $wordmark When true, adds "Member Portal" under the badge (the artwork already includes the product name).
 * @param string $class    Extra CSS classes on the wrapper <span>.
 */
function flightops_logo_asset_file(): string
{
    return __DIR__ . '/../assets/rc-flight-operations-logo.png';
}

function flightops_logo_asset_src(): string
{
    $file = flightops_logo_asset_file();
    $q = is_readable($file) ? ('?t=' . filemtime($file)) : '';

    return 'assets/rc-flight-operations-logo.png' . $q;
}

function flightops_logo(int $size = 28, bool $wordmark = false, string $class = ''): void
{
    // “Member Portal” stays a modest label; hero size should scale the badge, not the subtitle.
    $subSize   = $wordmark ? 12 : 0;
    $wrapClass = trim('fo-brand ' . $class);
    $src       = htmlspecialchars(flightops_logo_asset_src());
    $alt       = 'RC Flight Operations';
    $wordmarkMaxW = $wordmark
        ? (int) max(220, (int) round($size * 2.45))
        : 0;
    ?>
    <span class="<?= htmlspecialchars($wrapClass) ?>"
          style="display:inline-flex;align-items:center;gap:<?= round($size * 0.28) ?>px;line-height:1;">

        <?php if ($wordmark): ?>
        <span style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:0.65rem;text-align:center;">
        <?php endif; ?>

        <img src="<?= $src ?>"
             alt="<?= htmlspecialchars($alt) ?>"
             decoding="async"
             height="<?= (int) $size ?>"
             style="height:<?= (int) $size ?>px;width:auto;display:block;object-fit:contain;<?= $wordmark ? 'max-width:min(100%, ' . $wordmarkMaxW . 'px);' : '' ?>">

        <?php if ($wordmark): ?>
            <span class="fo-wordmark-sub" style="font-family:'Segoe UI',system-ui,sans-serif;
                         font-weight:400;
                         font-size:<?= $subSize ?>px;
                         letter-spacing:0.2em;
                         color:var(--club-on-primary-muted, rgba(37,32,24,0.72));
                         text-transform:uppercase;
                         line-height:1;">Member Portal</span>
        </span>
        <?php endif; ?>

    </span>
    <?php
}
