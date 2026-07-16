<?php
/**
 * templates/email/application_request_info.php
 *
 * Applicant notification when staff request additional information.
 *
 * $vars: first_name, request_message, reference, club_name, support_email
 */

require_once __DIR__ . '/email_layout.php';

$firstName = htmlspecialchars($first_name ?? '');
$reference = htmlspecialchars($reference ?? '');
$clubNameEsc = htmlspecialchars($club_name ?? 'RC Flight Operations');
$supportEsc  = htmlspecialchars($support_email ?? '');
$supportLine = $supportEsc !== ''
    ? '<a href="mailto:' . $supportEsc . '" style="color:inherit;text-decoration:none;">' . $supportEsc . '</a>'
    : 'your club membership team';

$messageHtml = nl2br(htmlspecialchars((string) ($request_message ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

$subject = ($club_name ?? 'RC Flight Operations') . ' - additional information needed for your application';

$refPlain = ($reference ?? '') !== '' ? ($reference ?? '') : '-';
$supportPlain = trim((string) ($support_email ?? ''));
$bodyText = 'Hi ' . ($first_name ?? '') . ",\n\n"
    . "We are reviewing your membership application and need additional information:\n\n"
    . ($request_message ?? '') . "\n\n"
    . 'Reference: ' . $refPlain . "\n\n"
    . 'Please reply to '
    . ($supportPlain !== '' ? $supportPlain : 'your club membership team')
    . " with the requested details. Your application remains pending until we hear from you.\n\n"
    . '- ' . ($club_name ?? 'RC Flight Operations');

$content = <<<HTML
<p style="margin:0 0 20px;font-size:17px;font-weight:600;">Hi {$firstName},</p>

<p style="margin:0 0 16px;line-height:1.7;">
  We are reviewing your membership application and need additional information before we can proceed.
</p>

<table role="presentation" cellpadding="0" cellspacing="0" width="100%"
       style="margin-bottom:24px;">
<tr>
  <td style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:20px 24px;">
    <p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:0.08em;
              text-transform:uppercase;color:#92400e;">Message from the club</p>
    <p style="margin:0;font-size:15px;line-height:1.7;color:#78350f;">{$messageHtml}</p>
  </td>
</tr>
</table>

<p style="margin:0 0 16px;line-height:1.7;">
  Please reply to {$supportLine} with the requested details.
  Your application will stay <strong>pending</strong> until we hear from you.
</p>

<p style="margin:0;line-height:1.7;">
  <strong>Reference:</strong> {$reference}
</p>
HTML;

$wrapVars = emailWrapVarsFromTemplate($vars);
$wrapVars['eyebrow'] = $vars['eyebrow'] ?? 'Membership Application';
$wrapVars['footer_note'] = $vars['footer_note'] ?? '';

$bodyHtml = emailWrap($content, $wrapVars, $pdo ?? null);
