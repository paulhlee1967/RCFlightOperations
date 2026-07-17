<?php
/**
 * templates/letter/new_member.php
 *
 * Rendered inside member_mailer.php for new members (first-year signup).
 *
 * Available variables (set by member_mailer.php):
 *   $memberAddr  array  ['name', 'street', 'street2', 'city', 'state', 'postal']
 *   $member      array  Full member row from DB
 *   $clubName    string Club name from club settings
 *   $year        int    Membership year
 *   $today       string Formatted date string
 *
 * h() is defined in member_mailer.php.
 */

$firstName = $member['first_name'] ?? 'Member';
$memType   = $member['membership_type'] ?? 'Adult';
$amaNum    = $member['ama_number'] ?? null;
$faaNum    = $member['faa_number'] ?? null;
?>

<p>Dear <?= h($firstName) ?>,</p>

<p>
    Welcome to <strong><?= h($clubName) ?></strong>! We're thrilled to have you join our community
    of RC aviation enthusiasts. Your <?= h($memType) ?> membership for
    <strong><?= h($year) ?></strong> is now active.
</p>

<p>
    Enclosed you'll find your membership ID card. Please keep it with you whenever
    you're flying — it may be requested at the field.
</p>

<?php if ($amaNum || $faaNum): ?>
<p>Your membership details are on file as follows:</p>
<ul>
    <li><strong>Membership type:</strong> <?= h($memType) ?></li>
    <li><strong>Membership year:</strong> <?= h($year) ?></li>
    <?php if ($amaNum): ?><li><strong>AMA #:</strong> <?= h($amaNum) ?></li><?php endif; ?>
    <?php if ($faaNum): ?><li><strong>FAA Registration #:</strong> <?= h($faaNum) ?></li><?php endif; ?>
</ul>
<?php endif; ?>

<p>
    <strong>Important:</strong> Please make sure your AMA and FAA registrations remain
    current. Per AMA rules and FAA regulations, both must be valid before operating your
    aircraft. Renewal reminders will be sent as your expirations approach.
</p>

<p>
    We look forward to seeing you at the field. Don't hesitate to reach out with any
    questions — we're a friendly bunch.
</p>