<?php
/**
 * templates/email/member_portal_staff_notify.php
 *
 * Notify membership staff when a member updates their own profile.
 *
 * $vars: club_name, member_name, member_email, member_id, change_lines (list), edit_url
 */

require_once __DIR__ . '/email_layout.php';

$memberName = htmlspecialchars($member_name ?? 'Member');
$memberEmail = htmlspecialchars($member_email ?? '');
$memberId = (int) ($member_id ?? 0);
$editUrl = htmlspecialchars($edit_url ?? '');
$lines = is_array($change_lines ?? null) ? $change_lines : [];
$theme = emailTheme(['club_name' => $vars['club_name'] ?? 'RC Flight Operations'], $pdo ?? null);
$btnBg = $theme['color_primary'];
$btnText = $theme['on_primary'];

$subject = ($club_name ?? 'RC Flight Operations') . ' — member profile updated: ' . ($member_name ?? 'Member');

$bodyTextLines = [];
foreach ($lines as $line) {
    $bodyTextLines[] = '- ' . (string) $line;
}
$bodyText = 'Member self-service update' . "\n\n"
    . 'Member: ' . ($member_name ?? '') . "\n"
    . 'Email: ' . ($member_email ?? '') . "\n"
    . 'Member ID: ' . $memberId . "\n\n"
    . "Changes:\n" . implode("\n", $bodyTextLines) . "\n";
if (($edit_url ?? '') !== '') {
    $bodyText .= "\nReview: " . $edit_url . "\n";
}

$listHtml = '';
foreach ($lines as $line) {
    $listHtml .= '<li style="margin:0 0 6px;line-height:1.5;">'
        . htmlspecialchars((string) $line)
        . '</li>';
}
if ($listHtml === '') {
    $listHtml = '<li style="margin:0;">(no field details)</li>';
}

$reviewBlock = '';
if ($editUrl !== '') {
    $reviewBlock = <<<HTML
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 0;">
<tr>
  <td style="border-radius:6px;background:{$btnBg};">
    <a href="{$editUrl}"
       style="display:inline-block;padding:12px 28px;font-size:14px;font-weight:700;
              color:{$btnText};text-decoration:none;letter-spacing:0.04em;">
      Open member record →
    </a>
  </td>
</tr>
</table>
HTML;
}

$content = <<<HTML
<p style="margin:0 0 16px;line-height:1.7;">
  A member updated their own profile through self-service. Please review the changes.
</p>

<table role="presentation" cellpadding="0" cellspacing="0" width="100%"
       style="margin-bottom:20px;border:1px solid #e8e0d4;border-radius:8px;overflow:hidden;">
<tr style="background:#f9f6f1;">
  <td style="padding:10px 16px;font-size:12px;font-weight:700;letter-spacing:0.08em;
             text-transform:uppercase;color:#9e8f7e;width:35%;">Member</td>
  <td style="padding:10px 16px;font-size:14px;font-weight:600;color:#252018;">{$memberName}</td>
</tr>
<tr style="border-top:1px solid #e8e0d4;">
  <td style="padding:10px 16px;font-size:12px;font-weight:700;letter-spacing:0.08em;
             text-transform:uppercase;color:#9e8f7e;">Email</td>
  <td style="padding:10px 16px;font-size:14px;color:#252018;">{$memberEmail}</td>
</tr>
<tr style="border-top:1px solid #e8e0d4;">
  <td style="padding:10px 16px;font-size:12px;font-weight:700;letter-spacing:0.08em;
             text-transform:uppercase;color:#9e8f7e;">Member ID</td>
  <td style="padding:10px 16px;font-size:14px;color:#252018;">{$memberId}</td>
</tr>
</table>

<p style="margin:0 0 8px;font-size:13px;font-weight:700;letter-spacing:0.06em;
          text-transform:uppercase;color:#9e8f7e;">Changes</p>
<ul style="margin:0 0 8px;padding-left:20px;color:#252018;font-size:14px;">
{$listHtml}
</ul>
{$reviewBlock}
HTML;

$wrapVars = emailWrapVarsFromTemplate($vars);
$wrapVars['eyebrow'] = $vars['eyebrow'] ?? 'Member self-service';
$wrapVars['footer_note'] = $vars['footer_note'] ?? (
    'Automated notice from ' . htmlspecialchars($club_name ?? 'RC Flight Operations')
    . '. Recipient is the Membership email under Administration → Installation → General.'
);

$bodyHtml = emailWrap($content, $wrapVars, $pdo ?? null);
