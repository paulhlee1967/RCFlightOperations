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
