<?php
/**
 * templates/letter/renewal.php
 *
 * Rendered inside member_mailer.php for existing member renewals.
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

$firstName  = $member['first_name'] ?? 'Member';
$memType    = $member['membership_type'] ?? 'Adult';
$amaNum     = $member['ama_number'] ?? null;
$faaNum     = $member['faa_number'] ?? null;
$amaExp     = $member['ama_expiration'] ?? null;
$faaExp     = $member['faa_expiration'] ?? null;
$isLifeMem  = !empty($member['life_member']);
$isFreeMem  = !empty($member['free_membership']);
$amaLifeMem = !empty($member['ama_life_member']);
?>

<p>Dear <?= h($firstName) ?>,</p>

<p>
    Thank you for renewing your membership with <strong><?= h($clubName) ?></strong>!
    Your <?= h($memType) ?> membership for <strong><?= h($year) ?></strong> has been
    recorded and your updated ID card is enclosed.
</p>

<p>Your membership details for <?= h($year) ?> are on file as follows:</p>
<ul>
    <li><strong>Membership type:</strong> <?= h($memType) ?>
        <?php if ($isLifeMem): ?> <em>(Life Member)</em><?php endif; ?>
        <?php if ($isFreeMem): ?> <em>(Complimentary)</em><?php endif; ?>
    </li>
    <li><strong>Membership year:</strong> <?= h($year) ?></li>
    <?php if ($amaNum): ?><li><strong>AMA #:</strong> <?= h($amaNum) ?>
        <?php if ($amaLifeMem): ?><em>(Life Member — no expiry)</em>
        <?php elseif ($amaExp): ?> — expires <?= date('M j, Y', strtotime($amaExp)) ?><?php endif; ?></li><?php endif; ?>
    <?php if ($faaNum): ?><li><strong>FAA Registration #:</strong> <?= h($faaNum) ?>
        <?php if ($faaExp): ?> — expires <?= date('M j, Y', strtotime($faaExp)) ?><?php endif; ?></li><?php endif; ?>
</ul>

<p>
    <strong>Reminder:</strong> Please keep your AMA and FAA registrations current.
    Both must be valid to fly at the field. Reach out if you have any questions about
    your registration status — we're happy to help.
</p>

<p>
    We appreciate your continued support of the club and look forward to another great
    year of flying together!
</p>