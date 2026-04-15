<?php
/**
 * templates/email/faa_expiry_30.php
 *
 * Urgent reminder: FAA drone registration expires in ~30 days.
 *
 * $vars: first_name, last_name, email, faa_number, faa_expiration,
 *        days_remaining, club_name
 */

require_once __DIR__ . '/email_layout.php';

$firstName     = htmlspecialchars($first_name    ?? '');
$faaNumber     = htmlspecialchars($faa_number     ?? '');
$faaExpiration = htmlspecialchars($faa_expiration ?? '');
$daysRemaining = (int) ($days_remaining           ?? 30);
$clubNameEsc   = htmlspecialchars($club_name      ?? 'RC Flight Operations');
$theme         = emailTheme(['club_name' => $vars['club_name'] ?? 'RC Flight Operations'], $pdo ?? null);
$btnBg         = $theme['color_primary_dark'];
$btnText       = $theme['on_primary_dark'];

$subject = ($club_name ?? 'RC Flight Operations')
    . ' – FAA expires in ' . $daysRemaining . ' days – renew soon';

$bodyText =
    "Hi {$firstName},\n\n"
    . "Reminder: Your FAA registration ({$faaNumber}) expires on {$faaExpiration}.\n"
    . "That's only {$daysRemaining} days away — renew at https://faadronezone.faa.gov "
    . "to keep your registration current and stay legal to fly at the field.\n\n"
    . "After renewing, please email membership@pvmac.com with your updated FAA registration number/expiration date and a copy of your new FAA card so we can update club records.\n\n"
    . "Please do not reply to this address. If you need to contact the club, email info@pvmac.com.\n\n"
    . "— {$clubNameEsc}";

$content = <<<HTML
<!-- Greeting -->
<p style="margin:0 0 20px;font-size:17px;font-weight:600;">Hi {$firstName},</p>

<!-- Urgent alert card -->
<table role="presentation" cellpadding="0" cellspacing="0" width="100%"
       style="margin-bottom:24px;">
<tr>
  <td style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:20px 24px;">
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
    <tr>
      <td style="vertical-align:top;padding-right:16px;width:44px;">
        <div style="width:40px;height:40px;background:#ef4444;border-radius:50%;
                    text-align:center;line-height:40px;font-size:20px;">🚨</div>
      </td>
      <td style="vertical-align:top;">
        <p style="margin:0 0 4px;font-size:13px;font-weight:700;letter-spacing:0.06em;
                  text-transform:uppercase;color:#991b1b;">Action Required</p>
        <p style="margin:0;font-size:15px;font-weight:600;color:#7f1d1d;">
          Only <strong>{$daysRemaining} days</strong> until your FAA registration expires!
        </p>
      </td>
    </tr>
    </table>
  </td>
</tr>
</table>

<!-- Details table -->
<table role="presentation" cellpadding="0" cellspacing="0" width="100%"
       style="margin-bottom:24px;border:1px solid #e8e0d4;border-radius:8px;overflow:hidden;">
<tr style="background:#f9f6f1;">
  <td style="padding:10px 16px;font-size:12px;font-weight:700;letter-spacing:0.08em;
             text-transform:uppercase;color:#9e8f7e;width:40%;">FAA Number</td>
  <td style="padding:10px 16px;font-size:14px;font-weight:600;color:#252018;">{$faaNumber}</td>
</tr>
<tr style="border-top:1px solid #e8e0d4;">
  <td style="padding:10px 16px;font-size:12px;font-weight:700;letter-spacing:0.08em;
             text-transform:uppercase;color:#9e8f7e;">Expires On</td>
  <td style="padding:10px 16px;font-size:14px;font-weight:700;color:#dc2626;">{$faaExpiration}</td>
</tr>
</table>

<p style="margin:0 0 16px;line-height:1.7;">
  Your FAA registration is expiring very soon. <strong>You must renew before flying
  at the field</strong> to remain compliant.
</p>
<p style="margin:0 0 20px;line-height:1.7;">
  Renewing is quick online. After you renew, please email
  <a href="mailto:membership@pvmac.com" style="color:#6f7c3d;">membership@pvmac.com</a>
  with your updated FAA registration number/expiration date and a copy of your new FAA card so we can update club records.
</p>

<!-- CTA button -->
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
<tr>
  <td style="border-radius:6px;background:{$btnBg};">
    <a href="https://faadronezone.faa.gov"
       style="display:inline-block;padding:12px 28px;font-size:14px;font-weight:700;
              color:{$btnText};text-decoration:none;letter-spacing:0.04em;">
      Renew Now at faadronezone.faa.gov →
    </a>
  </td>
</tr>
</table>

<p style="margin:0;font-size:13px;color:#9e8f7e;line-height:1.6;">
  Questions about FAA registration? Visit
  <a href="https://faadronezone.faa.gov" style="color:#6f7c3d;">faadronezone.faa.gov</a>
  or contact the club.
</p>
HTML;

$bodyHtml = emailWrap($content, [
    'club_name' => $vars['club_name'] ?? 'RC Flight Operations',
], $pdo ?? null);

