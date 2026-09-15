<?php
/**
 * includes/logo_thumb.php
 *
 * Produce a small, print-friendly raster of the club logo for use in PDFs and
 * emails. Club logos can be uploaded at very high resolution (a 4000×4000 PNG is
 * ~16 megapixels, which expands to ~64 MB of raw pixels) — embedding that directly
 * in a Dompdf document blows past PHP's memory_limit. Instead we cache a downscaled
 * copy once and reuse it.
 *
 * Strategy:
 *   1. If the source is already small enough, use it as-is.
 *   2. Otherwise downscale with Imagick (uses its own memory, sidestepping
 *      PHP's memory_limit) and fall back to GD with a memory guard.
 *   3. If everything fails, only return the original when it's within a safe
 *      pixel budget; otherwise return null so callers simply skip the logo.
 */

/**
 * Largest source we'll hand to a PDF/email un-resized (≈ raw bytes = px * 4).
 */
function clubLogoPixelBudget(): int
{
    return 2_000_000; // ~2 MP → ~8 MB decoded
}

/**
 * Resolve the club logo to a small cached file suitable for embedding.
 * Returns an absolute filesystem path, or null when no usable logo exists.
 */
function clubLogoThumbFile(?string $logoPath, int $maxW = 480, int $maxH = 160): ?string
{
    $logoPath = trim((string) $logoPath);
    if ($logoPath === '') {
        return null;
    }

    $src = dirname(__DIR__) . '/' . ltrim($logoPath, '/');
    if (!is_file($src) || !is_readable($src)) {
        return null;
    }

    $info = @getimagesize($src); // reads dimensions without decoding pixels
    if ($info === false) {
        return null;
    }
    [$w, $h] = $info;
    $type = $info[2];

    // Small enough already — use the original.
    if ($w <= $maxW && $h <= $maxH) {
        return $src;
    }

    $cacheDir = dirname(__DIR__) . '/uploads/branding/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
        return ($w * $h) <= clubLogoPixelBudget() ? $src : null;
    }

    $cache = $cacheDir . '/' . pathinfo($src, PATHINFO_FILENAME) . "-{$maxW}x{$maxH}.png";
    if (is_file($cache) && filemtime($cache) >= filemtime($src)) {
        return $cache;
    }

    $scale = min($maxW / $w, $maxH / $h);
    $tw = max(1, (int) round($w * $scale));
    $th = max(1, (int) round($h * $scale));

    if (clubLogoResizeImagick($src, $cache, $tw, $th)) {
        return $cache;
    }
    if (clubLogoResizeGd($src, $cache, $w, $h, $tw, $th, $type)) {
        return $cache;
    }

    // Resize failed; only use the original if it won't blow memory downstream.
    return ($w * $h) <= clubLogoPixelBudget() ? $src : null;
}

/**
 * Downscale with Imagick. Imagick allocates outside PHP's memory_limit, so it
 * handles very large source images that would otherwise fatal under GD.
 */
function clubLogoResizeImagick(string $src, string $cache, int $tw, int $th): bool
{
    if (!class_exists('Imagick')) {
        return false;
    }
    try {
        $im = new \Imagick();
        $im->readImage($src);
        $im->setImageBackgroundColor(new \ImagickPixel('transparent'));
        $im->thumbnailImage($tw, $th, true);
        $im->setImageFormat('png');
        $ok = $im->writeImage($cache);
        $im->clear();
        $im->destroy();
        return (bool) $ok;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Downscale with GD. Guards against decoding an image too large for the current
 * memory_limit (raising it when allowed) and bails out cleanly otherwise.
 */
function clubLogoResizeGd(string $src, string $cache, int $w, int $h, int $tw, int $th, int $type): bool
{
    if (!function_exists('imagecreatetruecolor')) {
        return false;
    }

    // Rough decode cost: width × height × 4 bytes plus working overhead.
    $need = (int) ($w * $h * 4 * 1.8) + 16 * 1024 * 1024;
    if (!clubEnsureMemoryAvailable($need)) {
        return false;
    }

    switch ($type) {
        case IMAGETYPE_PNG:  $srcImg = @imagecreatefrompng($src);  break;
        case IMAGETYPE_JPEG: $srcImg = @imagecreatefromjpeg($src); break;
        case IMAGETYPE_GIF:  $srcImg = @imagecreatefromgif($src);  break;
        default:             return false;
    }
    if (!$srcImg) {
        return false;
    }

    $dst = imagecreatetruecolor($tw, $th);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $srcImg, 0, 0, 0, 0, $tw, $th, $w, $h);
    $ok = imagepng($dst, $cache, 6);
    imagedestroy($srcImg);
    imagedestroy($dst);

    return (bool) $ok;
}

/**
 * Ensure at least $need bytes are available, raising memory_limit when permitted.
 * Returns false if the budget can't be met (caller should skip the operation).
 */
function clubEnsureMemoryAvailable(int $need): bool
{
    $limit = clubMemoryLimitBytes();
    if ($limit < 0) {
        return true; // unlimited
    }

    $headroom = $limit - memory_get_usage(true);
    if ($headroom >= $need) {
        return true;
    }

    $target = memory_get_usage(true) + $need + 16 * 1024 * 1024;
    @ini_set('memory_limit', (string) $target);

    $limit = clubMemoryLimitBytes();
    if ($limit < 0) {
        return true;
    }
    return ($limit - memory_get_usage(true)) >= $need;
}

/**
 * Current memory_limit in bytes (-1 for unlimited).
 */
function clubMemoryLimitBytes(): int
{
    $raw = trim((string) ini_get('memory_limit'));
    if ($raw === '' || $raw === '-1') {
        return -1;
    }
    $bytes = (int) $raw;
    if (stripos($raw, 'g') !== false)      { $bytes *= 1024 * 1024 * 1024; }
    elseif (stripos($raw, 'm') !== false)  { $bytes *= 1024 * 1024; }
    elseif (stripos($raw, 'k') !== false)  { $bytes *= 1024; }
    return $bytes;
}
