<?php
/**
 * Shared “update club record” CTA for AMA/FAA expiry reminder emails.
 *
 * Expects in scope: $theme (from emailTheme), $profile_update_url (optional).
 * Sets: $profileUpdateCtaHtml, $profileUpdatePlainSuffix
 *
 * Reminder links go to the durable /membership request page (email prefilled when
 * possible) — not a short-lived magic link — so unread mail still works weeks later.
 */

$profileUrl = trim((string) ($profile_update_url ?? ''));
$profileUpdateCtaHtml = '';
$profileUpdatePlainSuffix = " through the club membership portal at your club’s membership page.\n\n";

if ($profileUrl !== '') {
    $profileUrlEsc = htmlspecialchars($profileUrl);
    $profileBtnBg = htmlspecialchars((string) ($theme['color_primary'] ?? '#2c5f8a'));
    $profileBtnText = htmlspecialchars((string) ($theme['on_primary'] ?? '#ffffff'));
    $profileUpdatePlainSuffix = ":\n{$profileUrl}\n\n";
    $profileUpdateCtaHtml = <<<HTML
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;">
<tr>
  <td style="border-radius:6px;background:{$profileBtnBg};">
    <a href="{$profileUrlEsc}"
       style="display:inline-block;padding:12px 28px;font-size:14px;font-weight:700;
              color:{$profileBtnText};text-decoration:none;letter-spacing:0.04em;">
      Update club record after renewing →
    </a>
  </td>
</tr>
</table>
<p style="margin:0 0 16px;font-size:13px;color:#9e8f7e;line-height:1.6;">
  That opens the membership portal. Enter your email to receive a one-time access link,
  then update your expiration date and upload a card photo.
</p>
HTML;
}
