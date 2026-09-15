<?php
/**
 * Read-only AMA compliance summary for member view.
 *
 * Required variables:
 *   $member
 */
?>
<section class="border rounded p-3 p-md-4 bg-light bg-opacity-50">
    <h2 class="h6 text-uppercase text-muted fw-semibold mb-3">AMA membership</h2>
    <div class="row g-3">
        <div class="col-12 col-sm-6">
            <label class="form-label">AMA number</label>
            <div class="form-control bg-white"><?= h($member['ama_number'] ?? '') ?></div>
        </div>
        <div class="col-12 col-sm-6">
            <label class="form-label">AMA expiration</label>
            <div class="form-control bg-white"><?= h($member['ama_expiration'] ?? '') ?></div>
            <?php if (!empty($member['ama_life_member'])): ?>
                <div class="small text-muted mt-1">AMA life member</div>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="border rounded p-3 p-md-4 bg-light bg-opacity-50 mt-3">
    <h2 class="h6 text-uppercase text-muted fw-semibold mb-3">TRUST attestation</h2>
    <?php if (!empty($member['trust_attestation'])): ?>
        <div class="fw-semibold">Certified — will carry TRUST proof when flying</div>
        <?php if (!empty($member['trust_attested_at'])): ?>
            <div class="small text-muted mt-1">First recorded <?= h(formatDate(substr((string) $member['trust_attested_at'], 0, 10))) ?></div>
        <?php endif; ?>
    <?php else: ?>
        <div class="text-warning">Not on file</div>
        <p class="small text-muted mb-0 mt-1">Ask the member to certify TRUST on their membership profile, or check the box on Edit.</p>
    <?php endif; ?>
</section>
