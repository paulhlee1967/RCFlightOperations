<?php
/**
 * templates/email/email_layout.php
 *
 * Shared HTML email layout helper.
 *
 * Provides emailWrap($content, $vars, $pdo) which returns a complete
 * transactional-style HTML email with:
 *   - Club logo (embedded as base64 data URI so it renders in clients
 *     that block external images)
 *   - Club name, primary color, and on-brand footer
 *   - A hero accent bar in the club's primary color
 *   - Responsive single-column layout that works in Gmail, Apple Mail,
 *     Outlook 2016+, and mobile clients
 *
 * Called from each email template after building its own $content HTML.
 *
 * @param  string     $content   Inner HTML (already escaped where needed)
 * @param  array      $vars      Template variables (must include 'club_name')
 * @param  PDO|null   $pdo       When provided, fetches club theme from `club` id 1.
 *                               When null, falls back to defaults.
 * @return string     Complete HTML document string.
 */
function emailTheme(array $vars, ?PDO $pdo = null): array
{
    $clubName = htmlspecialchars($vars['club_name'] ?? 'RC Flight Operations');

    // ── Fetch club theme ──────────────────────────────────────────────────
    $colorPrimary     = '#6f7c3d';  // default olive
    $colorPrimaryDark = '#556030';
    $colorBg          = '#f3efe4';
    $colorText        = '#252018';
    $logoDataUri      = null;
    $logoPublicUrl    = null;
    $appConfig        = $vars['app_config'] ?? null;
    $useSenderApi     = !empty($vars['use_sender_api']);

    if ($pdo !== null) {
        try {
            $stmt = $pdo->prepare(
                'SELECT color_primary, color_primary_dark, color_bg, color_text, logo_path
                   FROM club WHERE id = 1'
            );
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                if (preg_match('/^#[0-9A-Fa-f]{6}$/', $row['color_primary']      ?? '')) $colorPrimary     = $row['color_primary'];
                if (preg_match('/^#[0-9A-Fa-f]{6}$/', $row['color_primary_dark'] ?? '')) $colorPrimaryDark = $row['color_primary_dark'];
                if (preg_match('/^#[0-9A-Fa-f]{6}$/', $row['color_bg']           ?? '')) $colorBg          = $row['color_bg'];
                if (preg_match('/^#[0-9A-Fa-f]{6}$/', $row['color_text']         ?? '')) $colorText        = $row['color_text'];

                // Embed logo as base64 so it displays even when external images are
                // blocked. Use a small cached raster (see logo_thumb.php) so a very
                // high-resolution upload doesn't bloat every email.
                // Sender.net strips data: URIs from <img src>; use a public HTTPS URL instead.
                if ($useSenderApi && !empty($row['logo_path'])) {
                    require_once dirname(__DIR__, 2) . '/includes/email_urls.php';
                    $logoPublicUrl = email_club_logo_public_url($row['logo_path'], is_array($appConfig) ? $appConfig : null);
                }

                if ($logoPublicUrl === null && !empty($row['logo_path'])) {
                    require_once dirname(__DIR__, 2) . '/includes/logo_thumb.php';
                    $logoFile = clubLogoThumbFile($row['logo_path']);
                    if ($logoFile !== null && is_file($logoFile) && is_readable($logoFile)) {
                        $mime = match (strtolower(pathinfo($logoFile, PATHINFO_EXTENSION))) {
                            'png'  => 'image/png',
                            'gif'  => 'image/gif',
                            'svg'  => 'image/svg+xml',
                            default => 'image/jpeg',
                        };
                        $logoData = @file_get_contents($logoFile);
                        if ($logoData !== false) {
                            $logoDataUri = 'data:' . $mime . ';base64,' . base64_encode($logoData);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // Keep defaults on any DB/filesystem error.
        }
    }

    $computeOnColor = static function (string $hexColor): string {
        $hex = ltrim($hexColor, '#');
        if (!preg_match('/^[0-9A-Fa-f]{6}$/', $hex)) {
            return '#ffffff';
        }
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;
        $toLin = fn(float $c): float => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        $lum = 0.2126 * $toLin($r) + 0.7152 * $toLin($g) + 0.0722 * $toLin($b);
        return $lum >= 0.45 ? '#252018' : '#ffffff';
    };

    return [
        'club_name'          => $clubName,
        'color_primary'      => $colorPrimary,
        'color_primary_dark' => $colorPrimaryDark,
        'color_bg'           => $colorBg,
        'color_text'         => $colorText,
        'on_primary'         => $computeOnColor($colorPrimary),
        'on_primary_dark'    => $computeOnColor($colorPrimaryDark),
        'logo_data_uri'      => $logoDataUri,
        'logo_public_url'    => $logoPublicUrl,
    ];
}

/**
 * Pass layout-related keys from a reminder/report template into emailWrap().
 *
 * @param array<string, mixed> $vars
 * @return array<string, mixed>
 */
function emailWrapVarsFromTemplate(array $vars): array
{
    $out = [
        'club_name' => $vars['club_name'] ?? 'RC Flight Operations',
    ];
    if (!empty($vars['unsubscribe_url'])) {
        $out['unsubscribe_url'] = $vars['unsubscribe_url'];
    }
    if (!empty($vars['use_sender_api'])) {
        $out['use_sender_api'] = true;
    }
    if (isset($vars['app_config']) && is_array($vars['app_config'])) {
        $out['app_config'] = $vars['app_config'];
    }
    if (!empty($vars['show_unsubscribe_notice'])) {
        $out['show_unsubscribe_notice'] = true;
    }
    if (isset($vars['footer_note']) && $vars['footer_note'] !== '') {
        $out['footer_note'] = $vars['footer_note'];
    }
    if (isset($vars['eyebrow']) && $vars['eyebrow'] !== '') {
        $out['eyebrow'] = $vars['eyebrow'];
    }

    return $out;
}

function emailWrap(string $content, array $vars, ?PDO $pdo = null): string
{
    $theme = emailTheme($vars, $pdo);
    $clubName = $theme['club_name'];
    $colorPrimary = $theme['color_primary'];
    $colorPrimaryDark = $theme['color_primary_dark'];
    $colorBg = $theme['color_bg'];
    $colorText = $theme['color_text'];
    $logoDataUri = $theme['logo_data_uri'];
    $logoPublicUrl = $theme['logo_public_url'];
    $onPrimary = $theme['on_primary'];

    // Optional overrides so non-member emails (e.g. report snapshots) read correctly.
    $eyebrow    = htmlspecialchars($vars['eyebrow'] ?? 'Member Communications');
    $footerNote = $vars['footer_note'] ?? (
        'This email was sent to you as a club member.<br>'
        . 'Please do not reply to this address. If you need to contact the club, email '
        . '<a href="mailto:info@pvmac.com" style="color:' . $colorPrimary . ';text-decoration:none;">info@pvmac.com</a>.'
    );

    $unsubscribeUrl = trim((string) ($vars['unsubscribe_url'] ?? ''));
    $unsubscribeHtml = '';
    if ($unsubscribeUrl !== '') {
        $unsubscribeEsc = htmlspecialchars($unsubscribeUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $unsubscribeHtml = '<p style="margin:14px 0 0;font-size:12px;color:#7a6f62;line-height:1.6;">'
            . 'You are receiving this message because you are a club member with an email address on file.<br>'
            . 'This link stops <strong>AMA/FAA expiry reminders only</strong>. '
            . 'Newsletters and general club notices use a separate list; '
            . 'use the unsubscribe link in those emails if you want to opt out of them too.<br>'
            . '<a href="' . $unsubscribeEsc . '" style="color:' . $colorPrimary . ';text-decoration:underline;font-weight:600;">Unsubscribe from reminders</a>.'
            . '</p>';
    } elseif (!empty($vars['show_unsubscribe_notice'])) {
        $unsubscribeHtml = '<p style="margin:14px 0 0;font-size:12px;color:#7a6f62;line-height:1.6;">'
            . 'You are receiving this message because you are a club member with an email address on file.<br>'
            . 'To stop AMA/FAA expiry reminders, contact the club treasurer. '
            . 'Newsletters and general notices are managed separately.</p>';
    }

    $year = date('Y');

    // ── Assemble ───────────────────────────────────────────────────────────
    return <<<HTML
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="x-apple-disable-message-reformatting">
<title>$clubName</title>
<!--[if mso]>
<noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
<![endif]-->
<style>
  /* Reset */
  * { box-sizing: border-box; }
  body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
  table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
  img { -ms-interpolation-mode: bicubic; border: 0; display: block; }
  body { margin: 0; padding: 0; width: 100% !important; }
  /* Responsive */
  @media only screen and (max-width: 620px) {
    .email-wrapper   { width: 100% !important; }
    .email-content   { padding: 20px 16px !important; }
    .email-header    { padding: 28px 16px 22px !important; }
    .hero-title      { font-size: 22px !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background-color:#f0ece3;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">

<!-- Preheader (hidden preview text) -->
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">
  A message from $clubName &nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
</div>

<!-- Email wrapper -->
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f0ece3;">
<tr><td align="center" style="padding:32px 16px;">

  <!-- Card -->
  <table role="presentation" cellpadding="0" cellspacing="0" width="600" class="email-wrapper"
         style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.10);">

    <!-- ── Header / hero ─────────────────────────────────────────────── -->
    <tr>
      <td align="center" class="email-header"
          style="background:linear-gradient(160deg, {$colorPrimary} 0%, {$colorPrimaryDark} 100%);
                 padding:36px 40px 28px;">
        <!-- Logo or text mark -->
        <div style="margin-bottom:16px;">
HTML
    . ($logoPublicUrl
        ? '<img src="' . htmlspecialchars($logoPublicUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="' . $clubName . '" height="52" '
          . 'style="height:52px;max-width:240px;object-fit:contain;">'
        : ($logoDataUri
        ? '<img src="' . $logoDataUri . '" alt="' . $clubName . '" height="52" '
          . 'style="height:52px;max-width:240px;object-fit:contain;">'
        : '<span style="font-family:Georgia,\'Times New Roman\',serif;font-weight:bold;'
          . 'font-size:28px;letter-spacing:-0.02em;color:' . $onPrimary . ';">✈ ' . $clubName . '</span>'))
    . <<<HTML
        </div>
        <p style="margin:0;font-size:13px;letter-spacing:0.12em;text-transform:uppercase;
                  font-weight:600;color:{$onPrimary};opacity:0.75;">
          {$eyebrow}
        </p>
      </td>
    </tr>

    <!-- ── Accent stripe ──────────────────────────────────────────────── -->
    <tr>
      <td style="height:4px;background:linear-gradient(90deg,{$colorPrimary} 0%,#c9a852 50%,#9d6b4a 100%);"></td>
    </tr>

    <!-- ── Body content ──────────────────────────────────────────────── -->
    <tr>
      <td class="email-content" style="padding:36px 40px 28px;color:{$colorText};">
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
        <tr><td style="font-size:15px;line-height:1.7;color:{$colorText};">
          {$content}
        </td></tr>
        </table>
      </td>
    </tr>

    <!-- ── Divider ────────────────────────────────────────────────────── -->
    <tr>
      <td style="padding:0 40px;">
        <div style="height:1px;background:#e8e0d4;"></div>
      </td>
    </tr>

    <!-- ── Footer ─────────────────────────────────────────────────────── -->
    <tr>
      <td style="padding:20px 40px 28px;background:#faf8f4;border-radius:0 0 12px 12px;">
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
        <tr>
          <td style="text-align:center;">
            <p style="margin:0 0 6px;font-size:12px;font-weight:700;letter-spacing:0.1em;
                      text-transform:uppercase;color:{$colorPrimary};">
              $clubName
            </p>
            <p style="margin:0;font-size:11px;color:#9e9080;line-height:1.6;">
              {$footerNote}
            </p>
            {$unsubscribeHtml}
            <p style="margin:12px 0 0;font-size:10px;color:#bbb;">
              &copy; {$year} $clubName &nbsp;·&nbsp; Powered by RC Flight Operations
            </p>
          </td>
        </tr>
        </table>
      </td>
    </tr>

  </table><!-- /card -->
</td></tr>
</table><!-- /wrapper -->

</body>
</html>
HTML;
}