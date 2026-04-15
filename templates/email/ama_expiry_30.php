<?php
/**
 * templates/email/ama_expiry_30.php
 *
 * Urgent reminder: AMA membership expires in ~30 days.
 *
 * $vars: first_name, last_name, email, ama_number, ama_expiration,
 *        days_remaining, club_name
 */

require_once __DIR__ . '/email_layout.php';

$firstName     = htmlspecialchars($first_name    ?? '');
$amaNumber     = htmlspecialchars($ama_number     ?? '');
$amaExpiration = htmlspecialchars($ama_expiration ?? '');
$daysRemaining = (int) ($days_remaining           ?? 30);
$clubNameEsc   = htmlspecialchars($club_name      ?? 'RC Flight Operations');
$theme         = emailTheme(['club_name' => $vars['club_name'] ?? 'RC Flight Operations'], $pdo ?? null);
$btnBg         = $theme['color_primary_dark'];
$btnText       = $theme['on_primary_dark'];

$subject = ($club_name ?? 'RC Flight Operations')
    . ' – AMA expires in ' . $daysRemaining . ' days – renew soon';

$bodyText =
    "Hi {$firstName},\n\n"
    . "Reminder: Your AMA membership ({$amaNumber}) expires on {$amaExpiration}.\n"
    . "That's only {$daysRemaining} days away — renew at "
    . "https://www.modelaircraft.org/membership/enroll "
    . "so you can keep flying at the field.\n\n"
    . "After renewing, please email membership@pvmac.com with your updated AMA expiration date and a copy of your new AMA card so we can update club records.\n\n"
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
          Only <strong>{$daysRemaining} days</strong> until your AMA expires!
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
             text-transform:uppercase;color:#9e8f7e;width:40%;">AMA Number</td>
  <td style="padding:10px 16px;font-size:14px;font-weight:600;color:#252018;">{$amaNumber}</td>
</tr>
<tr style="border-top:1px solid #e8e0d4;">
  <td style="padding:10px 16px;font-size:12px;font-weight:700;letter-spacing:0.08em;
             text-transform:uppercase;color:#9e8f7e;">Expires On</td>
  <td style="padding:10px 16px;font-size:14px;font-weight:700;color:#dc2626;">{$amaExpiration}</td>
</tr>
</table>

<p style="margin:0 0 16px;line-height:1.7;">
  Your AMA membership is expiring very soon. <strong>You must renew before flying
  at the field</strong> — an expired AMA is a club charter violation.
</p>
<p style="margin:0 0 20px;line-height:1.7;">
  Renewing takes just a few minutes online. After you renew, please update
  your expiration date with the club so our records stay current.
</p>

<!-- CTA button -->
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
<tr>
  <td style="border-radius:6px;background:{$btnBg};">
    <a href="https://www.modelaircraft.org/membership/enroll"
       style="display:inline-block;padding:12px 28px;font-size:14px;font-weight:700;
              color:{$btnText};text-decoration:none;letter-spacing:0.04em;">
      Renew Now at modelaircraft.org →
    </a>
  </td>
</tr>
</table>

<p style="margin:0;font-size:13px;color:#9e8f7e;line-height:1.6;">
  Questions about your AMA membership? Visit
  <a href="https://www.modelaircraft.org" style="color:#6f7c3d;">modelaircraft.org</a>
  or contact your club secretary.
</p>
HTML;


$bodyHtml = emailWrap($content, [
    'club_name' => $vars['club_name'] ?? 'RC Flight Operations',
], $pdo ?? null);