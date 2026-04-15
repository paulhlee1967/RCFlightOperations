<?php
/**
 * templates/email/ama_expiry_60.php
 *
 * Reminder: AMA membership expires in ~60 days.
 *
 * $vars: first_name, last_name, email, ama_number, ama_expiration,
 *        days_remaining, club_name
 */

require_once __DIR__ . '/email_layout.php';

$firstName      = htmlspecialchars($first_name      ?? '');
$amaNumber      = htmlspecialchars($ama_number       ?? '');
$amaExpiration  = htmlspecialchars($ama_expiration   ?? '');
$daysRemaining  = (int) ($days_remaining             ?? 60);
$clubNameEsc    = htmlspecialchars($club_name        ?? 'RC Flight Operations');
$theme          = emailTheme(['club_name' => $vars['club_name'] ?? 'RC Flight Operations'], $pdo ?? null);
$btnBg          = $theme['color_primary'];
$btnText        = $theme['on_primary'];

$subject = ($club_name ?? 'RC Flight Operations')
    . ' – Your AMA membership expires in ' . $daysRemaining . ' days';

$bodyText =
    "Hi {$firstName},\n\n"
    . "Your AMA membership ({$amaNumber}) expires on {$amaExpiration}.\n"
    . "That's in {$daysRemaining} days — please renew at "
    . "https://www.modelaircraft.org/membership/enroll "
    . "to stay in good standing and keep flying at the field.\n\n"
    . "After renewing, please email membership@pvmac.com with your updated AMA expiration date and a copy of your new AMA card so we can update club records.\n\n"
    . "Please do not reply to this address. If you need to contact the club, email info@pvmac.com.\n\n"
    . "— {$clubNameEsc}";

// ── Status pill color (60 days = amber warning) ──────────────────────────────
$pillBg    = '#b45309';   // amber-700
$pillText  = '#ffffff';

$content = <<<HTML
<!-- Greeting -->
<p style="margin:0 0 20px;font-size:17px;font-weight:600;">Hi {$firstName},</p>

<!-- Alert card -->
<table role="presentation" cellpadding="0" cellspacing="0" width="100%"
       style="margin-bottom:24px;">
<tr>
  <td style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:20px 24px;">
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
    <tr>
      <td style="vertical-align:top;padding-right:16px;width:44px;">
        <!-- Clock icon -->
        <div style="width:40px;height:40px;background:#f59e0b;border-radius:50%;
                    text-align:center;line-height:40px;font-size:20px;">⏰</div>
      </td>
      <td style="vertical-align:top;">
        <p style="margin:0 0 4px;font-size:13px;font-weight:700;letter-spacing:0.06em;
                  text-transform:uppercase;color:#92400e;">Renewal Reminder</p>
        <p style="margin:0;font-size:15px;font-weight:600;color:#78350f;">
          AMA membership expires in <strong>{$daysRemaining} days</strong>
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
             text-transform:uppercase;color:#9e8f7e;">Expiration</td>
  <td style="padding:10px 16px;font-size:14px;font-weight:600;color:#b45309;">{$amaExpiration}</td>
</tr>
</table>

<p style="margin:0 0 16px;line-height:1.7;">
  Your AMA membership must remain current for you to fly at our field.
  Please renew before <strong>{$amaExpiration}</strong> to avoid any interruption.
</p>

<!-- CTA button -->
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0;">
<tr>
  <td style="border-radius:6px;background:{$btnBg};">
    <a href="https://www.modelaircraft.org/membership/enroll"
       style="display:inline-block;padding:12px 28px;font-size:14px;font-weight:700;
              color:{$btnText};text-decoration:none;letter-spacing:0.04em;">
      Renew at modelaircraft.org →
    </a>
  </td>
</tr>
</table>

<p style="margin:0;font-size:13px;color:#9e8f7e;line-height:1.6;">
  After renewing, please email <a href="mailto:membership@pvmac.com" style="color:#6f7c3d;">membership@pvmac.com</a>
  with your updated AMA expiration date and a copy of your new AMA card so we can update club records.
</p>
HTML;

// emailWrap() loads club logo/colors when $pdo is set.

$bodyHtml = emailWrap($content, [
    'club_name' => $vars['club_name'] ?? 'RC Flight Operations',
], $pdo ?? null);