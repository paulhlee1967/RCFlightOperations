<?php
/**
 * templates/email/application_received.php
 *
 * Applicant confirmation after a membership application is submitted.
 *
 * $vars: first_name, payment_total, mailing_address, reference, club_name, support_email
 */

require_once __DIR__ . '/email_layout.php';

$firstName = htmlspecialchars($first_name ?? '');
$reference = htmlspecialchars($reference ?? '');
$totalPaid = number_format((float) ($payment_total ?? 0), 2);
$address   = htmlspecialchars($mailing_address ?? '');
$clubNameEsc = htmlspecialchars($club_name ?? 'RC Flight Operations');

$subject = ($club_name ?? 'RC Flight Operations') . ' - membership application received';

$refPlain = ($reference ?? '') !== '' ? ($reference ?? '') : '-';
$bodyText = 'Hi ' . ($first_name ?? '') . ",\n\n"
    . "We received your membership application and will review it shortly.\n\n"
    . 'Reference: ' . $refPlain . "\n"
    . 'Total paid: $' . $totalPaid . "\n"
    . 'Badge mailing address: ' . ($mailing_address ?? '') . "\n\n"
    . "Thanks for applying,\n" . ($club_name ?? 'RC Flight Operations');

$content = <<<HTML
<p style="margin:0 0 20px;font-size:17px;font-weight:600;">Hi {$firstName},</p>

<p style="margin:0 0 16px;line-height:1.7;">
  We received your membership application and will review it shortly.
  You do not need to take further action unless the club contacts you.
</p>

<table role="presentation" cellpadding="0" cellspacing="0" width="100%"
       style="margin-bottom:24px;border:1px solid #e8e0d4;border-radius:8px;overflow:hidden;">
<tr style="background:#f9f6f1;">
  <td style="padding:10px 16px;font-size:12px;font-weight:700;letter-spacing:0.08em;
             text-transform:uppercase;color:#9e8f7e;width:40%;">Reference</td>
  <td style="padding:10px 16px;font-size:14px;font-weight:600;color:#252018;">{$reference}</td>
</tr>
<tr style="border-top:1px solid #e8e0d4;">
  <td style="padding:10px 16px;font-size:12px;font-weight:700;letter-spacing:0.08em;
             text-transform:uppercase;color:#9e8f7e;">Total paid</td>
  <td style="padding:10px 16px;font-size:14px;font-weight:600;color:#252018;">\${$totalPaid}</td>
</tr>
<tr style="border-top:1px solid #e8e0d4;">
  <td style="padding:10px 16px;font-size:12px;font-weight:700;letter-spacing:0.08em;
             text-transform:uppercase;color:#9e8f7e;vertical-align:top;">Badge mailing address</td>
  <td style="padding:10px 16px;font-size:14px;color:#252018;line-height:1.6;">{$address}</td>
</tr>
</table>

<p style="margin:0;line-height:1.7;">
  Thanks for applying,<br>
  <strong>{$clubNameEsc}</strong>
</p>
HTML;

$wrapVars = emailWrapVarsFromTemplate($vars);
$wrapVars['eyebrow'] = $vars['eyebrow'] ?? 'Membership Application';
$wrapVars['footer_note'] = $vars['footer_note'] ?? '';

$bodyHtml = emailWrap($content, $wrapVars, $pdo ?? null);
