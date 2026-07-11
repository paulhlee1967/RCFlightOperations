<?php
/**
 * Read-only Sender.net email preference summary for member contact tab.
 *
 * Required variables:
 *   $member
 * Optional:
 *   $pdo — when omitted, Sender config falls back to config.php only
 */
require_once __DIR__ . '/sender_net.php';

$senderStatus = sender_net_member_email_status($pdo ?? null, $member);
if (!$senderStatus['show']) {
    return;
}
?>
<div class="col-12 mt-2 pt-3 border-top">
    <label class="form-label text-muted small text-uppercase fw-semibold mb-2">Email preferences (Sender.net)</label>
    <div class="border rounded p-3 bg-light bg-opacity-50">
        <?php if ($senderStatus['state'] === 'error'): ?>
            <p class="small text-muted mb-2">
                Could not load status from Sender.net<?= ($senderStatus['error'] ?? '') !== '' ? ': ' . h((string) $senderStatus['error']) : '' ?>.
            </p>
        <?php elseif ($senderStatus['state'] === 'not_found'): ?>
            <p class="small text-muted mb-2">
                This address is not in Sender.net yet. Add or update preferences in Sender.net
                (search for <strong><?= h($senderStatus['email']) ?></strong>).
            </p>
        <?php else: ?>
            <dl class="row g-2 mb-0 small">
                <?php foreach ($senderStatus['rows'] as $row): ?>
                    <dt class="col-sm-5 col-md-4 mb-0 text-muted"><?= h($row['label']) ?></dt>
                    <dd class="col-sm-7 col-md-8 mb-0">
                        <span class="badge <?= h($row['badge']) ?>"><?= h($row['text']) ?></span>
                    </dd>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>
        <p class="small text-muted mt-3 mb-2">
            To change club event or expiry reminder preferences, open this contact in Sender.net.
            <?php if ($senderStatus['state'] === 'ok' || $senderStatus['state'] === 'not_found'): ?>
                Search for <strong><?= h($senderStatus['email']) ?></strong> if the link does not open their profile directly.
            <?php endif; ?>
        </p>
        <a href="<?= h($senderStatus['dashboard_url']) ?>"
           class="btn btn-outline-secondary btn-sm"
           target="_blank"
           rel="noopener noreferrer">
            Open in Sender.net ↗
        </a>
    </div>
</div>
