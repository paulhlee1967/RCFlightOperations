<?php
/**
 * templates/email/report_list.php
 *
 * Sent to individual members when an admin uses "Email this list" from
 * the Reports page. Subject and intro are customised via $report_label
 * and $custom_message.
 *
 * $vars:
 *   first_name      string  Member's first name
 *   last_name       string  Member's last name
 *   club_name       string  Club name
 *   report_label    string  Human-readable report title
 *   custom_message  string  Admin-typed message body
 *   admin_name      string  Name of the logged-in user who sent the email
 */

require_once __DIR__ . '/email_layout.php';

$firstName    = htmlspecialchars($first_name     ?? '');
$clubNameEsc  = htmlspecialchars($club_name      ?? 'RC Flight Operations');
$reportLabel  = htmlspecialchars($report_label   ?? 'Notice');
$adminName    = htmlspecialchars($admin_name     ?? 'RC Flight Operations Admin');
$customMsg    = $custom_message ?? '';   // already HTML-escaped by caller (report_email.php)

$subject = ($club_name ?? 'RC Flight Operations') . ' – ' . ($report_label ?? 'Notice');

$bodyText =
    "Hi {$firstName},\n\n"
    . $customMsg . "\n\n"
    . "— {$adminName}, {$clubNameEsc}";

// Convert plain-text message to HTML paragraphs
$messageHtml = nl2br(htmlspecialchars($customMsg));

$content = <<<HTML
<!-- Greeting -->
<p style="margin:0 0 20px;font-size:17px;font-weight:600;">Hi {$firstName},</p>

<!-- Subject label -->
<p style="margin:0 0 20px;">
  <span style="display:inline-block;background:#f3efe4;border:1px solid #d5cab5;
               border-radius:4px;padding:4px 10px;font-size:12px;font-weight:700;
               letter-spacing:0.06em;text-transform:uppercase;color:#6f7c3d;">
    {$reportLabel}
  </span>
</p>

<!-- Message body -->
<div style="font-size:15px;line-height:1.75;color:#252018;margin-bottom:24px;">
  {$messageHtml}
</div>

<!-- Signature -->
<table role="presentation" cellpadding="0" cellspacing="0" width="100%"
       style="margin-top:28px;border-top:1px solid #e8e0d4;">
<tr>
  <td style="padding-top:20px;">
    <p style="margin:0;font-size:13px;color:#9e8f7e;">
      Sent by <strong style="color:#555;">{$adminName}</strong>
      on behalf of <strong style="color:#555;">{$clubNameEsc}</strong>.
    </p>
  </td>
</tr>
</table>
HTML;

global $pdo;
$bodyHtml = emailWrap($content, [
    'club_name' => $vars['club_name'] ?? 'RC Flight Operations',
], $pdo ?? null);