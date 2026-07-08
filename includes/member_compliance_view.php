<?php
/**
 * Read-only AMA/FAA compliance summary for member view.
 *
 * Required variables:
 *   $member
 *   $memberId
 */
require_once __DIR__ . '/member_compliance_helpers.php';

$memberId = (int) ($memberId ?? 0);
$hasFaaCard = member_faa_card_has_file($member);
$faaCardUrl = ($memberId > 0 && $hasFaaCard) ? member_faa_card_serve_url($memberId) : '';
$faaCardIsImage = $hasFaaCard && member_faa_card_is_image($member);
$faaCardIsPdf = $hasFaaCard && member_faa_card_is_pdf($member);
?>
<div class="row g-4">
    <div class="col-12 col-lg-6">
        <section class="border rounded p-3 p-md-4 h-100 bg-light bg-opacity-50">
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
    </div>

    <div class="col-12 col-lg-6">
        <section class="border rounded p-3 p-md-4 h-100 bg-light bg-opacity-50">
            <h2 class="h6 text-uppercase text-muted fw-semibold mb-3">FAA registration</h2>
            <div class="row g-3">
                <div class="col-12 col-sm-6">
                    <label class="form-label">FAA number</label>
                    <div class="form-control bg-white"><?= h($member['faa_number'] ?? '') ?></div>
                </div>
                <div class="col-12 col-sm-6">
                    <label class="form-label">FAA expiration</label>
                    <div class="form-control bg-white"><?= h($member['faa_expiration'] ?? '') ?></div>
                </div>
                <div class="col-12 pt-1 border-top">
                    <label class="form-label mb-2">FAA registration card</label>
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
                                <p class="text-muted small mb-0 py-3 text-center">Card on file</p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="bg-white border rounded text-center text-muted small py-5 px-3">
                            No FAA card on file
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>
</div>
