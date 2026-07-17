<?php
/**
 * templates/email/faa_expiry_60.php
 *
 * Reminder: FAA drone registration expires in ~60 days.
 *
 * $vars: first_name, last_name, email, faa_number, faa_expiration,
 *        days_remaining, club_name, profile_update_url
 */

require_once __DIR__ . '/email_layout.php';

$firstName     = htmlspecialchars($first_name    ?? '');
$faaNumber     = htmlspecialchars($faa_number     ?? '');
$faaExpiration = htmlspecialchars($faa_expiration ?? '');
$daysRemaining = (int) ($days_remaining           ?? 60);
$clubNameEsc   = htmlspecialchars($club_name      ?? 'RC Flight Operations');
$theme         = emailTheme(['club_name' => $vars['club_name'] ?? 'RC Flight Operations'], $pdo ?? null);
$btnBg         = $theme['color_primary'];
$btnText       = $theme['on_primary'];
require __DIR__ . '/reminder_profile_update_cta.php';

$subject = ($club_name ?? 'RC Flight Operations')
    . ' – Your FAA registration expires in ' . $daysRemaining . ' days';

$bodyText =
    "Hi {$firstName},\n\n"
    . "Your FAA registration ({$faaNumber}) expires on {$faaExpiration}.\n"
    . "That's in {$daysRemaining} days. Please renew at faadronezone.faa.gov "
    . "to keep your registration current and stay legal to fly at the field.\n\n"
    . "After renewing, update your club membership profile"
    . $profileUpdatePlainSuffix
    . "— {$clubNameEsc}";

$content = <<<HTML
<p style="margin:0 0 20px;font-size:17px;font-weight:600;">Hi {$firstName},</p>

<table role="presentation" cellpadding="0" cellspacing="0" width="100%"
       style="margin-bottom:24px;">
<tr>
  <td style="background:#eff6ff;border:1px solid #93c5fd;border-radius:8px;padding:20px 24px;">
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
    <tr>
      <td style="vertical-align:top;padding-right:16px;width:44px;">
        <div style="width:40px;height:40px;background:#3b82f6;border-radius:50%;
                    text-align:center;line-height:40px;font-size:20px;">&#128203;</div>
      </td>
      <td style="vertical-align:top;">
        <p style="margin:0 0 4px;font-size:13px;font-weight:700;letter-spacing:0.06em;
                  text-transform:uppercase;color:#1e40af;">FAA Registration Reminder</p>
        <p style="margin:0;font-size:15px;font-weight:600;color:#1e3a8a;">
          Registration expires in <strong>{$daysRemaining} days</strong>
        </p>
      </td>
    </tr>
    </table>
  </td>
</tr>
</table>

<table role="presentation" cellpadding="0" cellspacing="0" width="100%"
       style="margin-bottom:24px;border:1px solid #e8e0d4;border-radius:8px;overflow:hidden;">
<tr style="background:#f9f6f1;">
  <td style="padding:10px 16px;font-size:12px;font-weight:700;letter-spacing:0.08em;
             text-transform:uppercase;color:#9e8f7e;width:40%;">FAA Number</td>
  <td style="padding:10px 16px;font-size:14px;font-weight:600;color:#252018;">{$faaNumber}</td>
</tr>
<tr style="border-top:1px solid #e8e0d4;">
  <td style="padding:10px 16px;font-size:12px;font-weight:700;letter-spacing:0.08em;
             text-transform:uppercase;color:#9e8f7e;">Expiration</td>
  <td style="padding:10px 16px;font-size:14px;font-weight:600;color:#b45309;">{$faaExpiration}</td>
</tr>
</table>

<p style="margin:0 0 16px;line-height:1.7;">
  All RC pilots must maintain a current FAA registration (or Part 107 certificate)
  to operate at our field. Please renew before <strong>{$faaExpiration}</strong>
  to avoid any interruption in your flying privileges.
</p>

<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 16px;">
<tr>
  <td style="border-radius:6px;background:{$btnBg};">
    <a href="https://faadronezone.faa.gov"
       style="display:inline-block;padding:12px 28px;font-size:14px;font-weight:700;
              color:{$btnText};text-decoration:none;letter-spacing:0.04em;">
      Renew at faadronezone.faa.gov &#8594;
    </a>
  </td>
</tr>
</table>
{$profileUpdateCtaHtml}
HTML;

$bodyHtml = emailWrap($content, emailWrapVarsFromTemplate($vars), $pdo ?? null);
