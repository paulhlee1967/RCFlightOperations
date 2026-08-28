<?php
/**
 * AMA compliance fields for member edit and wizard forms.
 *
 * Required variables:
 *   $member    array
 */
?>
<section class="border rounded p-3 p-md-4 bg-light bg-opacity-50">
    <h2 class="h6 text-uppercase text-muted fw-semibold mb-3">AMA membership</h2>
    <div class="row g-3">
        <div class="col-12 col-sm-6">
            <label class="form-label" for="ama_number">AMA number</label>
            <input type="text" class="form-control" name="ama_number" id="ama_number" value="<?= h($member['ama_number'] ?? '') ?>">
        </div>
        <div class="col-12 col-sm-6" id="ama-expiration-wrap">
            <label class="form-label" for="ama_expiration">AMA expiration</label>
            <input type="date" class="form-control" name="ama_expiration" id="ama_expiration" value="<?= h($member['ama_expiration'] ?? '') ?>">
            <span id="ama-status-badge" class="ama-status-badge" aria-live="polite"></span>
        </div>
        <div class="col-12">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="ama_life_member" id="ama_life_member" value="1"<?= checked($member['ama_life_member'] ?? 0) ?>>
                <label class="form-check-label" for="ama_life_member">AMA life member</label>
            </div>
        </div>
        <div class="col-12 d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center gap-2 pt-1 border-top">
            <input type="hidden" id="page_csrf_token" value="<?= h(csrf_token()) ?>">
            <button type="button" class="btn btn-primary btn-sm flex-shrink-0" id="verify-ama-btn">Verify AMA membership</button>
            <span id="verify-ama-status" class="small flex-grow-1 border border-light rounded px-2 py-2 bg-white" role="status" aria-live="polite"></span>
        </div>
    </div>
</section>
