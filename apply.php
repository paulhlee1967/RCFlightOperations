<?php
/**
 * apply.php — Public PVMAC membership application (replaces WPForms).
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/membership_application.php';
require_once __DIR__ . '/includes/stripe_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_GET['reset_ama']) && (string) $_GET['reset_ama'] === '1') {
    membership_application_ama_clear_session();
    header('Location: apply.php');
    exit;
}

$flightopsCspOptions = ['stripe' => true];
$noNav = true;
$baseHref = '/';
$pageTitle = 'Membership Application';

$context = membership_application_context($pdo);
$stripeCfg = stripe_load_config($pdo);
$stripeConfigured = stripe_is_configured($pdo);
$renewalYear = (int) $context['renewal_year'];
$amaSession = membership_application_ama_get_session();
$amaVerified = $amaSession !== null;
$amaMinExpiryLabel = membership_application_ama_minimum_expiry_label($pdo);
$amaMinExpiryYmd = membership_application_ama_minimum_expiry_ymd($pdo);
$amaMinExpiryLong = formatDate($amaMinExpiryYmd, 'F j, Y');
$amaMinExpiryYear = (int) substr($amaMinExpiryYmd, 0, 4);
$amaEnrollUrl = 'https://www.modelaircraft.org/membership/enroll';
$faaRegisterUrl = 'https://faadronezone.faa.gov';
$amaPrefill = [
    'first_name'     => $amaSession['first_name'] ?? '',
    'last_name'      => $amaSession['last_name'] ?? '',
    'ama_number'     => $amaSession['ama_number'] ?? '',
    'ama_expiration' => $amaSession['ama_expiration_mdy'] ?? membership_application_ymd_to_mdy($amaSession['ama_expiration_ymd'] ?? null),
];
$renewalEligible = $amaVerified && !empty($amaSession['renewal_eligible']);
$renewalEligibleMessage = (string) ($amaSession['renewal_eligible_message'] ?? '');
$complimentaryMember = $amaVerified && !empty($amaSession['complimentary_member']);
$complimentaryMemberDetail = (string) ($amaSession['complimentary_member_detail'] ?? '');

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/csp_nonce.php';
?>

<style<?= csp_nonce_attr() ?>>
.apply-preflight-item {
    border: 1px solid color-mix(in srgb, var(--club-primary) 22%, transparent);
    border-radius: 12px;
    padding: 1rem 1.1rem;
    height: 100%;
    background: #fff;
}
.apply-preflight-check {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.35rem;
    height: 1.35rem;
    border-radius: 50%;
    background: color-mix(in srgb, var(--club-success) 16%, #fff);
    color: var(--club-success);
    font-size: 0.8rem;
    font-weight: 700;
    flex-shrink: 0;
}
.apply-preflight-deadline {
    border: 2px solid var(--club-primary);
    border-radius: 14px;
    padding: 1rem 1.25rem;
    background: color-mix(in srgb, var(--club-primary) 6%, #fff);
}
.apply-preflight-deadline-date {
    color: var(--club-primary);
    font-size: clamp(1.35rem, 3vw, 1.85rem);
    font-weight: 700;
    line-height: 1.2;
}
</style>

<div class="row justify-content-center">
    <div class="col-12 col-lg-10 col-xl-9">
        <div class="text-center mb-4">
            <h1 class="h2" style="color: var(--club-primary);"><?= h($theme['name'] ?? 'PVMAC') ?></h1>
            <h2 class="h4">Membership Application</h2>
            <p class="text-muted mb-0">One membership submission per applicant. Current AMA membership and FAA registration are required.</p>
        </div>

        <?php if (!$stripeConfigured): ?>
        <div class="alert alert-warning">Online payment is not configured yet. Complimentary members and valid coupon codes may still apply without payment.</div>
        <?php endif; ?>

        <?php if ($complimentaryMember): ?>
        <div class="alert alert-success">Our records show complimentary membership<?= $complimentaryMemberDetail !== '' ? ' (' . h($complimentaryMemberDetail) . ')' : '' ?> — no online payment will be required.</div>
        <?php endif; ?>

        <form id="membership-apply-form" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>

            <div class="card mb-3" id="ama-gate-card">
                <div class="card-header fw-semibold">Step 1 — Pre-flight checklist &amp; AMA verification</div>
                <div class="card-body">
                    <h3 class="h5 fw-bold mb-1">Pre-Flight Membership Checklist</h3>
                    <p class="small mb-3" style="color: color-mix(in srgb, var(--club-text) 82%, var(--club-muted));">
                        Please complete these items before continuing with your <?= h($theme['name'] ?? 'PVMAC') ?> membership application.
                    </p>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <div class="apply-preflight-item">
                                <div class="d-flex gap-2">
                                    <span class="apply-preflight-check" aria-hidden="true">✓</span>
                                    <div>
                                        <div class="fw-semibold">Active Full AMA Open Membership</div>
                                        <p class="small mb-0 mt-1" style="color: color-mix(in srgb, var(--club-text) 80%, var(--club-muted));">
                                            AMA Park Pilot, Temporary, and Trial memberships do <strong>not</strong> qualify for club membership.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="apply-preflight-item">
                                <div class="d-flex gap-2">
                                    <span class="apply-preflight-check" aria-hidden="true">✓</span>
                                    <div>
                                        <div class="fw-semibold">Current FAA Recreational Registration</div>
                                        <p class="small mb-0 mt-1" style="color: color-mix(in srgb, var(--club-text) 80%, var(--club-muted));">
                                            Your FAA registration must be current before you complete the application in Step 2.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="apply-preflight-deadline text-center mb-3">
                        <div class="small fw-semibold mb-1" style="color: var(--club-primary);">
                            Your AMA Open Membership MUST remain valid through
                        </div>
                        <div class="apply-preflight-deadline-date"><?= h($amaMinExpiryLong) ?></div>
                        <p class="small mb-0 mt-2" style="color: color-mix(in srgb, var(--club-text) 82%, var(--club-muted));">
                            If your AMA membership expires before this date, you will not be able to complete your
                            <?= (int) $amaMinExpiryYear ?> <?= h($theme['name'] ?? 'PVMAC') ?> membership application.
                        </p>
                    </div>

                    <div class="d-grid d-sm-flex gap-2 justify-content-sm-center mb-4">
                        <a href="<?= h($amaEnrollUrl) ?>" class="btn btn-danger" target="_blank" rel="noopener noreferrer">
                            Join / Renew AMA Membership
                        </a>
                        <a href="<?= h($faaRegisterUrl) ?>" class="btn btn-danger" target="_blank" rel="noopener noreferrer">
                            Get / Renew FAA Registration
                        </a>
                    </div>

                    <hr class="my-4">

                    <p class="small mb-3">
                        Enter your <strong>last name</strong> and <strong>AMA number</strong> below to verify your AMA membership and continue.
                        Your AMA must be valid through at least <strong><?= h($amaMinExpiryLabel) ?></strong>.
                    </p>
                    <div id="ama-gate-form" class="<?= $amaVerified ? 'd-none' : '' ?>">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label" for="ama_verify_number">AMA # <span class="text-danger">*</span></label>
                                <input type="text" id="ama_verify_number" class="form-control" autocomplete="off">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="ama_verify_last_name">Last name (as on AMA card) <span class="text-danger">*</span></label>
                                <input type="text" id="ama_verify_last_name" class="form-control" autocomplete="family-name">
                            </div>
                            <div class="col-md-4">
                                <button type="button" class="btn btn-danger w-100" id="ama-verify-btn">Verify membership</button>
                            </div>
                        </div>
                        <div id="ama-verify-errors" class="alert alert-danger mt-3 d-none" role="alert"></div>
                    </div>
                    <div id="ama-gate-success" class="<?= $amaVerified ? '' : 'd-none' ?>">
                        <div class="alert alert-success mb-2 py-2">
                            <strong>AMA membership verified.</strong>
                            <span id="ama-gate-success-name"><?= $amaVerified ? h($amaPrefill['first_name'] . ' ' . $amaPrefill['last_name']) : '' ?></span>
                            · AMA #<span id="ama-gate-success-number"><?= h($amaPrefill['ama_number']) ?></span>
                            · exp <span id="ama-gate-success-exp"><?= h($amaPrefill['ama_expiration']) ?></span>
                        </div>
                        <a href="apply.php?reset_ama=1" class="small">Use a different AMA number</a>
                    </div>
                </div>
            </div>

            <div id="apply-step-2" class="<?= $amaVerified ? '' : 'd-none' ?>">
            <div class="card mb-3">
                <div class="card-header fw-semibold">Applicant information</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">First name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" class="form-control bg-light" required readonly autocomplete="given-name" value="<?= h($amaPrefill['first_name']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Middle name</label>
                            <input type="text" name="middle_name" class="form-control" autocomplete="additional-name">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Last name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" class="form-control bg-light" required readonly autocomplete="family-name" value="<?= h($amaPrefill['last_name']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Street address <span class="text-danger">*</span></label>
                            <input type="text" name="address_street" class="form-control" required autocomplete="address-line1">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address line 2</label>
                            <input type="text" name="address_street2" class="form-control" autocomplete="address-line2">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">City <span class="text-danger">*</span></label>
                            <input type="text" name="address_city" class="form-control" required autocomplete="address-level2">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">State <span class="text-danger">*</span></label>
                            <input type="text" name="address_state" class="form-control" value="CA" required autocomplete="address-level1">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ZIP <span class="text-danger">*</span></label>
                            <input type="text" name="address_postal_code" class="form-control" required autocomplete="postal-code">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Phone <span class="text-danger">*</span></label>
                            <input type="tel" name="phone" class="form-control js-phone-us" required autocomplete="tel" placeholder="(555) 123-4567" inputmode="tel" maxlength="14">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required autocomplete="email">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Date of birth <span class="text-danger">*</span></label>
                            <input type="text" name="birthday" class="form-control js-date-us" required placeholder="MM/DD/YYYY" inputmode="numeric" autocomplete="bday" maxlength="10">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Emergency contact</label>
                            <input type="text" name="emergency_contact_name" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Relationship</label>
                            <input type="text" name="emergency_contact_relationship" class="form-control" placeholder="Spouse, parent, etc.">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Emergency phone</label>
                            <input type="tel" name="emergency_contact_phone" class="form-control js-phone-us" placeholder="(555) 123-4567" inputmode="tel" maxlength="14">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3" style="border-color: var(--club-primary);">
                <div class="card-body text-center">
                    <h3 class="h5 fw-bold mb-1" style="color: var(--club-primary);">Membership fees are non-refundable</h3>
                    <p class="mb-1">Membership expires December 31, <?= (int) $renewalYear ?></p>
                    <p class="small text-muted mb-2">All fees and requirements subject to change without notice.</p>
                    <p class="mb-0 fw-bold py-2 px-2 rounded" style="background: var(--club-primary); color: var(--club-on-primary);">All transaction fees are passed to the applicant</p>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header fw-semibold">Membership type</div>
                <div class="card-body">
                    <div class="mb-3" id="application-kind-group">
                        <?php if ($context['renewal_open']): ?>
                        <div id="renewal-eligibility-notice" class="alert alert-warning py-2 small mb-2<?= ($amaVerified && !$renewalEligible) ? '' : ' d-none' ?>" role="status">
                            <?= h($renewalEligibleMessage !== '' ? $renewalEligibleMessage : 'Select New member to apply.') ?>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="application_kind" id="kind_renewal" value="renewal"
                                <?= ($amaVerified ? $renewalEligible : true) ? ' checked' : '' ?>
                                <?= ($amaVerified && !$renewalEligible) ? ' disabled' : '' ?>>
                            <label class="form-check-label" for="kind_renewal">Renewal</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="application_kind" id="kind_new" value="new"
                                <?= ($amaVerified && !$renewalEligible) ? ' checked' : '' ?>>
                            <label class="form-check-label" for="kind_new">New member</label>
                        </div>
                        <?php else: ?>
                        <input type="hidden" name="application_kind" value="new">
                        <p class="mb-0 text-muted">New member applications (renewal period opens later in the year).</p>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Membership type <span class="text-danger">*</span></label>
                        <select name="membership_type_slot" id="membership_type_slot" class="form-select" required>
                            <option value="">— Select —</option>
                            <?php foreach ($context['membership_types'] as $type): ?>
                            <option value="<?= (int) $type['slot'] ?>"><?= h($type['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text" style="color: var(--club-primary);">Children, students, or youth under 18 must be accompanied by a parent or legal guardian at the flying field.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Coupon code (if you have one)</label>
                        <input type="text" name="coupon_code" id="coupon_code" class="form-control" autocomplete="off">
                    </div>

                    <div id="fee-summary" class="border rounded p-3 bg-light d-none">
                        <div id="fee-complimentary" class="alert alert-success py-2 small d-none mb-2"></div>
                        <div class="d-flex justify-content-between"><span>Membership dues</span><span id="fee-dues">—</span></div>
                        <div class="d-flex justify-content-between"><span>Initiation fee</span><span id="fee-initiation">—</span></div>
                        <div class="d-flex justify-content-between"><span>Processing fee</span><span id="fee-processing">—</span></div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between fw-bold"><span>Total</span><span id="fee-total">—</span></div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header fw-semibold">AMA &amp; FAA</div>
                <div class="card-body row g-3">
                    <div class="col-md-6">
                        <label class="form-label">AMA # <span class="text-danger">*</span></label>
                        <input type="text" name="ama_number" class="form-control bg-light" required readonly value="<?= h($amaPrefill['ama_number']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">AMA expiration <span class="text-danger">*</span></label>
                        <input type="text" name="ama_expiration" class="form-control bg-light" required readonly value="<?= h($amaPrefill['ama_expiration']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">FAA registration number <span class="text-danger">*</span></label>
                        <input type="text" name="faa_number" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">FAA registration expiration <span class="text-danger">*</span></label>
                        <input type="text" name="faa_expiration" class="form-control js-date-us" required placeholder="MM/DD/YYYY" inputmode="numeric" maxlength="10">
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header fw-semibold">Uploads</div>
                <div class="card-body row g-3">
                    <div class="col-md-6" id="badge-photo-wrap">
                        <label class="form-label">Badge photo (.jpg, .png) <span class="text-danger" id="badge-required-star">*</span></label>
                        <input type="file" name="badge_photo" id="badge_photo" class="form-control" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
                        <div class="form-text">Full face in color — printed on your membership card. Max 5 MB.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">FAA registration (PDF, .jpg, .png) <span class="text-danger">*</span></label>
                        <input type="file" name="faa_card" class="form-control" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
                        <div class="form-text">Current valid FAA registration. PDF or image. Max 5 MB.</div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header fw-semibold">Terms &amp; signature</div>
                <div class="card-body">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="terms" value="1" id="terms" required>
                        <label class="form-check-label" for="terms">
                            I agree to comply with all club rules and regulations as defined by the PVMAC and the PVMAC
                            <a href="https://www.pvmac.com/bylaws" target="_blank" rel="noopener noreferrer">bylaws</a>
                            and conditions as set forth by the contract for Prado Airpark. I further agree to comply with
                            all AMA (Academy of Model Aeronautics) rules and regulations and FAA drone regulations.
                            Copies of the rules, regulations, and bylaws are available on the PVMAC website
                            (<a href="https://www.pvmac.com" target="_blank" rel="noopener noreferrer">www.pvmac.com</a>).
                            Click the links below to review these documents:
                            <a href="https://www.pvmac.com/rules" target="_blank" rel="noopener noreferrer">Club rules</a>
                            ·
                            <a href="https://www.pvmac.com/bylaws" target="_blank" rel="noopener noreferrer">Bylaws</a>
                        </label>
                    </div>
                    <label class="form-label">Signature <span class="text-danger">*</span></label>
                    <canvas id="signature-pad" class="border rounded bg-white w-100" style="height:120px; touch-action:none;"></canvas>
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="signature-clear">Clear signature</button>
                    <input type="hidden" name="signature_data" id="signature_data">
                </div>
            </div>

            <div class="card mb-3" id="payment-card">
                <div class="card-header fw-semibold">Payment</div>
                <div class="card-body">
                    <p id="payment-placeholder" class="text-muted mb-3<?= $stripeConfigured ? '' : ' text-warning' ?>">
                        <?php if ($stripeConfigured): ?>
                        Card details appear here after you click <strong>Submit application</strong> below (your form is validated first).
                        <?php else: ?>
                        Online payment is not configured yet — add Stripe keys under Installation, or use a valid coupon code.
                        <?php endif; ?>
                    </p>
                    <div id="payment-element" class="d-none"></div>
                    <div id="payment-errors" class="text-danger small mt-2" role="alert"></div>
                </div>
            </div>

            <div id="form-errors" class="alert alert-danger d-none" role="alert"></div>

            <div class="d-grid gap-2 mb-5">
                <button type="submit" class="btn btn-danger btn-lg" id="submit-btn">Submit application</button>
            </div>
            </div><!-- /#apply-step-2 -->
        </form>
    </div>
</div>

<script nonce="<?= h(flightops_csp_nonce()) ?>">
window.MEMBERSHIP_APPLY = {
    stripePublishableKey: <?= json_encode($stripeCfg['publishable_key'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    quoteUrl: 'api_membership_quote.php',
    submitUrl: 'api_membership_submit.php',
    amaVerifyUrl: 'api_verify_ama_public.php',
    confirmBase: 'apply_confirm.php',
    renewalOpen: <?= $context['renewal_open'] ? 'true' : 'false' ?>,
    amaVerified: <?= $amaVerified ? 'true' : 'false' ?>,
    renewalEligible: <?= $renewalEligible ? 'true' : 'false' ?>,
    renewalMessage: <?= json_encode($renewalEligibleMessage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
};
</script>
<script src="https://js.stripe.com/v3/"></script>
<script src="js/membership_apply.js" nonce="<?= h(flightops_csp_nonce()) ?>"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
