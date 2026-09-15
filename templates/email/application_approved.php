<?php
/**
 * templates/email/application_approved.php
 *
 * Applicant notification after staff approve a membership application.
 *
 * $vars: first_name, reference, club_name, support_email
 */

require_once __DIR__ . '/email_layout.php';

$firstName = htmlspecialchars($first_name ?? '');
$reference = htmlspecialchars($reference ?? '');
$clubNameEsc = htmlspecialchars($club_name ?? 'RC Flight Operations');
$supportEsc  = htmlspecialchars($support_email ?? '');
$supportLine = $supportEsc !== ''
    ? '<a href="mailto:' . $supportEsc . '" style="color:inherit;text-decoration:none;">' . $supportEsc . '</a>'
    : 'your club membership team';

$subject = ($club_name ?? 'RC Flight Operations') . ' - membership application approved';

$supportPlain = trim((string) ($support_email ?? ''));
$refPlain = ($reference ?? '') !== '' ? ($reference ?? '') : '-';
$bodyText = 'Hi ' . ($first_name ?? '') . ",\n\n"
    . "Good news - your membership application has been approved.\n\n"
    . 'Reference: ' . $refPlain . "\n\n"
    . "The club will finalize your membership and badge details separately. "
    . 'If you have questions, contact '
    . ($supportPlain !== '' ? $supportPlain : 'your club membership team') . ".\n\n"
    . '- ' . ($club_name ?? 'RC Flight Operations');

$content = <<<HTML
<p style="margin:0 0 20px;font-size:17px;font-weight:600;">Hi {$firstName},</p>

<table role="presentation" cellpadding="0" cellspacing="0" width="100%"
       style="margin-bottom:24px;">
<tr>
  <td style="background:#ecfdf5;border:1px solid #86efac;border-radius:8px;padding:20px 24px;">
    <p style="margin:0 0 4px;font-size:13px;font-weight:700;letter-spacing:0.06em;
              text-transform:uppercase;color:#166534;">Approved</p>
    <p style="margin:0;font-size:15px;font-weight:600;color:#14532d;">
      Your membership application has been approved.
    </p>
  </td>
</tr>
</table>

<p style="margin:0 0 16px;line-height:1.7;">
  The club will finalize your membership record and badge fulfillment separately.
  Field access and other onboarding details are shared once processing is complete.
</p>

<p style="margin:0 0 16px;line-height:1.7;">
  <strong>Reference:</strong> {$reference}
</p>

<p style="margin:0;line-height:1.7;">
  Questions? Contact {$supportLine}.
</p>
HTML;

$wrapVars = emailWrapVarsFromTemplate($vars);
$wrapVars['eyebrow'] = $vars['eyebrow'] ?? 'Membership Application';
$wrapVars['footer_note'] = $vars['footer_note'] ?? '';

$bodyHtml = emailWrap($content, $wrapVars, $pdo ?? null);
