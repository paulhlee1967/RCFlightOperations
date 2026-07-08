<?php
/**
 * AMA/FAA compliance fields for member edit and wizard forms.
 *
 * Required variables:
 *   $member    array
 * Optional:
 *   $memberId  int|null
 */
require_once __DIR__ . '/member_compliance_helpers.php';

$memberId = isset($memberId) ? (int) $memberId : null;
$hasFaaCard = member_faa_card_has_file($member);
$faaCardUrl = ($memberId && $hasFaaCard) ? member_faa_card_serve_url($memberId) : '';
$faaCardIsImage = $hasFaaCard && member_faa_card_is_image($member);
$faaCardIsPdf = $hasFaaCard && member_faa_card_is_pdf($member);
?>
<div class="row g-4">
    <div class="col-12 col-lg-6">
        <section class="border rounded p-3 p-md-4 h-100 bg-light bg-opacity-50">
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
    </div>

    <div class="col-12 col-lg-6">
        <section class="border rounded p-3 p-md-4 h-100 bg-light bg-opacity-50">
            <h2 class="h6 text-uppercase text-muted fw-semibold mb-3">FAA registration</h2>
            <div class="row g-3">
                <div class="col-12 col-sm-6">
                    <label class="form-label" for="faa_number">FAA number</label>
                    <input type="text" class="form-control" name="faa_number" id="faa_number" value="<?= h($member['faa_number'] ?? '') ?>">
                </div>
                <div class="col-12 col-sm-6">
                    <label class="form-label" for="faa_expiration">FAA expiration</label>
                    <input type="date" class="form-control" name="faa_expiration" id="faa_expiration" value="<?= h($member['faa_expiration'] ?? '') ?>">
                </div>
                <div class="col-12 pt-1 border-top">
                    <label class="form-label mb-2">FAA registration card</label>
                    <div class="row g-3 align-items-start">
                        <div class="col-12 col-md-7">
                            <?php if ($hasFaaCard && $faaCardUrl !== ''): ?>
                                <div class="bg-white border rounded p-2">
                                    <?php if ($faaCardIsImage): ?>
                                        <img
                                            src="<?= h($faaCardUrl) ?>?t=<?= time() ?>"
                                            alt="FAA registration card"
                                            class="img-fluid rounded d-block mx-auto"
                                            style="max-height:280px;object-fit:contain;"
                                        >
                                    <?php elseif ($faaCardIsPdf): ?>
                                        <iframe
                                            src="<?= h($faaCardUrl) ?>#toolbar=0"
                                            title="FAA registration card"
                                            class="w-100 rounded border-0"
                                            style="height:280px;"
                                        ></iframe>
                                    <?php else: ?>
                                        <p class="text-muted small mb-0 py-3 text-center">Card on file (unsupported preview format)</p>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="bg-white border rounded text-center text-muted small py-5 px-3">
                                    No FAA card on file
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12 col-md-5">
                            <input type="file" class="form-control form-control-sm" name="faa_card" id="faa_card" accept="application/pdf,image/jpeg,image/png">
                            <small class="text-muted d-block mt-1">
                                <?= $hasFaaCard ? 'Upload a new file to replace the current card.' : 'Optional — PDF, JPG, or PNG.' ?>
                                Max 5 MB.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>
