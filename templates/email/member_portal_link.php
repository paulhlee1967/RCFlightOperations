<?php
/**
 * templates/email/member_portal_link.php
 *
 * Magic-link email for the member self-service profile.
 *
 * $vars: first_name, club_name, link_url, expires_minutes
 */

require_once __DIR__ . '/email_layout.php';

$firstName = htmlspecialchars($first_name ?? 'Member');
$clubNameEsc = htmlspecialchars($club_name ?? 'RC Flight Operations');
$linkUrl = htmlspecialchars($link_url ?? '#');
$expires = (int) ($expires_minutes ?? 60);
$theme = emailTheme(['club_name' => $vars['club_name'] ?? 'RC Flight Operations'], $pdo ?? null);
$btnBg = $theme['color_primary'];
$btnText = $theme['on_primary'];
$linkColor = $theme['color_primary_dark'];

$subject = ($club_name ?? 'RC Flight Operations') . ' — access your membership profile';

$bodyText = 'Hi ' . ($first_name ?? 'Member') . ",\n\n"
    . "Use this link to open your membership profile and update your contact and compliance information:\n\n"
    . ($link_url ?? '') . "\n\n"
    . 'This link expires in ' . $expires . " minutes and can be used only once.\n\n"
    . "If you did not request this, you can ignore this email.\n\n"
    . ($club_name ?? 'RC Flight Operations');

$content = <<<HTML
<p style="margin:0 0 20px;font-size:17px;font-weight:600;">Hi {$firstName},</p>

<p style="margin:0 0 16px;line-height:1.7;">
  Use the button below to open your membership profile. You can update contact details,
  AMA/FAA information, badge photo, FAA card, and email preferences.
</p>

<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0;">
<tr>
  <td style="border-radius:6px;background:{$btnBg};">
    <a href="{$linkUrl}"
       style="display:inline-block;padding:12px 28px;font-size:14px;font-weight:700;
              color:{$btnText};text-decoration:none;letter-spacing:0.04em;">
      Open my membership profile →
    </a>
  </td>
</tr>
</table>

<p style="margin:0 0 12px;line-height:1.7;font-size:13px;color:#6b635a;">
  Or copy this link:<br>
  <a href="{$linkUrl}" style="color:{$linkColor};word-break:break-all;">{$linkUrl}</a>
</p>

<p style="margin:0;line-height:1.7;font-size:13px;color:#6b635a;">
  This link expires in {$expires} minutes and can be used only once.
  If you did not request it, you can ignore this email.
</p>
HTML;

$wrapVars = emailWrapVarsFromTemplate($vars);
$wrapVars['eyebrow'] = $vars['eyebrow'] ?? 'My Membership';
$wrapVars['footer_note'] = $vars['footer_note'] ?? (
    'This email was sent by ' . htmlspecialchars($club_name ?? 'RC Flight Operations')
    . ' so you can update your membership profile. Please do not reply to this address.'
);

$bodyHtml = emailWrap($content, $wrapVars, $pdo ?? null);
