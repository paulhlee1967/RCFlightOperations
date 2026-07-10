<?php
/**
 * includes/member_applications.php
 *
 * Staff review queue for membership applications (native apply.php flow).
 * Approve/reject into member records.
 */

require_once __DIR__ . '/member_import_helpers.php';
require_once __DIR__ . '/member_match.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/ama_verify.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/membership_status.php';
require_once __DIR__ . '/dues_helpers.php';
require_once __DIR__ . '/installation_config.php';
require_once __DIR__ . '/mail.php';

function application_parse_money(?string $value): ?float
{
    if ($value === null) {
        return null;
    }
    $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace(['$', ',', ' '], '', $value);
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    $amount = round((float) $value, 2);

    return ($amount >= 0 && $amount <= 100000) ? $amount : null;
}

function application_payment_breakdown(array $application, ?PDO $pdo = null): array
{
    $initiation = application_parse_money(isset($application['payment_initiation']) ? (string) $application['payment_initiation'] : null);
    $processing = application_parse_money(isset($application['payment_processing_fee']) ? (string) $application['payment_processing_fee'] : null);
    $totalPaid  = application_parse_money(isset($application['payment_total']) ? (string) $application['payment_total'] : null);

    $membershipDues = null;
    if ($pdo !== null) {
        $slot = application_resolve_membership_type_slot($application, $pdo);
        if ($slot !== null) {
            $renewalType = (string) ($application['suggested_renewal_type'] ?? 'new');
            if (!in_array($renewalType, ['new', 'on_time', 'late'], true)) {
                $renewalType = 'new';
            }
            $calc = calculateDues($pdo, $slot, $renewalType);
            if ($calc['dues'] > 0) {
                $membershipDues = round($calc['dues'], 2);
            }
        }
    }

    $notesText = !empty($application['notes']) ? (string) $application['notes'] : '';
    $complimentaryLabel = application_payment_complimentary_label($notesText !== '' ? $notesText : null);
    $specialCode = '';
    if ($notesText !== '') {
        if (preg_match('/Coupon code:\s*(.+)$/m', $notesText, $m)) {
            $specialCode = trim($m[1]);
        } elseif (preg_match('/Special code:\s*(.+)$/m', $notesText, $m)) {
            $specialCode = trim($m[1]);
        }
    }

    $parts = array_values(array_filter([$membershipDues, $initiation, $processing], static fn ($v) => $v !== null));
    $subtotal = count($parts) >= 2 ? round(array_sum($parts), 2) : (count($parts) === 1 ? $parts[0] : null);
    $paymentStatus = (string) ($application['payment_status'] ?? '');
    $couponApplied = $complimentaryLabel !== null || ($specialCode !== '' && (
        $paymentStatus === 'waived'
        || ($subtotal !== null && $totalPaid !== null && $totalPaid < $subtotal)
    ));

    return [
        'membership_dues'     => $membershipDues,
        'initiation'          => $initiation,
        'processing'          => $processing,
        'subtotal'            => $subtotal,
        'total_paid'          => $totalPaid,
        'special_code'        => $specialCode !== '' ? $specialCode : null,
        'coupon_applied'      => $couponApplied,
        'complimentary_label' => $complimentaryLabel,
    ];
}

function application_resolve_membership_type_slot(array $application, ?PDO $pdo = null): ?int
{
    $stored = (int) ($application['membership_type_slot'] ?? 0);

    return ($stored >= 1 && $stored <= 4) ? $stored : null;
}

/**
 * Build a payment summary, preferring raw webhook payload keys when present.
 *
 * @return array{
 *   membership_dues: ?float,
 *   initiation: ?float,
 *   processing: ?float,
 *   subtotal: ?float,
 *   total_paid: ?float,
 *   special_code: ?string,
 *   coupon_applied: bool,
 *   complimentary_label: ?string
 * }
 */
function application_payment_complimentary_label(?string $notes): ?string
{
    if ($notes === null || trim($notes) === '') {
        return null;
    }
    if (preg_match('/Complimentary invite #(\d+)(?:\s*\((free membership|life member)\))?/i', $notes, $m)) {
        $label = 'Comp invite #' . $m[1];
        if (!empty($m[2])) {
            $label .= ' (' . strtolower($m[2]) . ')';
        }

        return $label;
    }
    if (preg_match('/Complimentary:\s*(.+?)\s*\(member record\)/i', $notes, $m)) {
        return ucfirst(trim($m[1])) . ' (member record)';
    }
    if (str_contains($notes, 'Complimentary: free membership member flag')) {
        return 'Complimentary member (member record)';
    }

    return null;
}

/**
 * Match tiers that are not strong enough to trust a self-reported website renewal.
 */
function application_match_is_weak_for_renewal(?string $confidence): bool
{
    $confidence = strtolower(trim((string) $confidence));

    return in_array($confidence, ['', 'none', 'name_only', 'ambiguous'], true);
}

/**
 * Cross-check a self-reported website renewal against club membership records.
 *
 * @return array{
 *   status: string,
 *   warnings: list<string>,
 *   adjusted_renewal_type: ?string,
 *   member_id: ?int
 * }
 */
function application_renewal_verification(PDO $pdo, array $application, ?int $overrideMemberId = null): array
{
    $warnings = [];
    $adjustedType = null;
    $status = 'verified';

    if (($application['application_kind'] ?? '') !== 'renewal') {
        return [
            'status'                => 'verified',
            'warnings'              => [],
            'adjusted_renewal_type' => null,
            'member_id'             => null,
        ];
    }

    $confidence = (string) ($application['match_confidence'] ?? '');
    $memberId = $overrideMemberId;
    if ($memberId === null && !empty($application['matched_member_id'])) {
        $memberId = (int) $application['matched_member_id'];
    }

    $renewalYear = (int) ($application['suggested_renewal_year'] ?? 0);
    $beforeYear = $renewalYear > 0 ? $renewalYear : null;

    if ($memberId === null) {
        $status = 'no_match';
        $warnings[] = 'Applicant selected Renewal on the website but no matching member was found. They may be a new member avoiding the initiation fee.';
        $adjustedType = 'late';
    } elseif ($confidence === 'ambiguous') {
        $status = 'ambiguous_match';
        $warnings[] = 'Applicant selected Renewal but multiple members match this name. Confirm the correct member before treating this as a renewal.';
        $adjustedType = 'late';
    } elseif ($confidence === 'name_only') {
        $warnings[] = 'Member match is based on name only (' . member_match_confidence_label($confidence) . '). Verify identity before approving as a renewal.';
        if (!member_has_prior_membership($pdo, $memberId, $beforeYear)) {
            $status = 'no_history';
            $warnings[] = 'Matched member has no prior membership payments or renewals on file. This may be a new member who selected Renewal to skip the initiation fee.';
            $adjustedType = 'late';
        } else {
            $status = 'weak_match';
        }
    } elseif (!member_has_prior_membership($pdo, $memberId, $beforeYear)) {
        $status = 'no_history';
        $warnings[] = 'Matched member has no prior membership payments or renewals on file. This may be a new member who selected Renewal to skip the initiation fee.';
        $adjustedType = 'late';
    }

    return [
        'status'                => $status,
        'warnings'              => $warnings,
        'adjusted_renewal_type' => $adjustedType,
        'member_id'             => $memberId,
    ];
}

/**
 * Compare website payment to what a new/late member should have paid.
 *
 * @param array{status:string,warnings:list<string>,adjusted_renewal_type:?string,member_id:?int} $verification
 * @return array{
 *   underpaid: bool,
 *   shortfall: ?float,
 *   expected_subtotal: ?float,
 *   warnings: list<string>
 * }
 */
function application_payment_underpaid_check(PDO $pdo, array $application, array $verification): array
{
    $warnings = [];

    if (($application['application_kind'] ?? '') !== 'renewal') {
        return [
            'underpaid'         => false,
            'shortfall'         => null,
            'expected_subtotal' => null,
            'warnings'          => [],
        ];
    }

    if ($verification['adjusted_renewal_type'] !== 'late') {
        return [
            'underpaid'         => false,
            'shortfall'         => null,
            'expected_subtotal' => null,
            'warnings'          => [],
        ];
    }

    $slot = application_resolve_membership_type_slot($application, $pdo);
    if ($slot === null || $slot < 1) {
        return [
            'underpaid'         => false,
            'shortfall'         => null,
            'expected_subtotal' => null,
            'warnings'          => [],
        ];
    }

    $payment = application_payment_breakdown($application, $pdo);
    $lateCalc = calculateDues($pdo, $slot, 'late');
    $processing = $payment['processing'] ?? 0.0;
    $expectedParts = array_values(array_filter([
        $lateCalc['dues'] > 0 ? round($lateCalc['dues'], 2) : null,
        $lateCalc['init'] > 0 ? round($lateCalc['init'], 2) : null,
        $processing !== null && $processing > 0 ? round((float) $processing, 2) : null,
    ], static fn ($v) => $v !== null));
    $expectedSubtotal = count($expectedParts) >= 2
        ? round(array_sum($expectedParts), 2)
        : null;

    $totalPaid = $payment['total_paid'];
    $initiation = $payment['initiation'];
    $underpaid = false;
    $shortfall = null;

    if ($initiation === null || $initiation <= 0.0) {
        $warnings[] = 'No initiation fee was collected on the website. New and late members normally pay an initiation fee.';
    }

    if ($expectedSubtotal !== null && $totalPaid !== null && !($payment['coupon_applied'] ?? false)) {
        if ($totalPaid + 0.009 < $expectedSubtotal) {
            $underpaid = true;
            $shortfall = round($expectedSubtotal - $totalPaid, 2);
            $warnings[] = 'Total paid (' . formatMoney($totalPaid) . ') is less than the expected new/late amount ('
                . formatMoney($expectedSubtotal) . '). Collect the balance before recording.';
        }
    }

    return [
        'underpaid'         => $underpaid,
        'shortfall'         => $shortfall,
        'expected_subtotal' => $expectedSubtotal,
        'warnings'          => $warnings,
    ];
}

/**
 * Renewal type staff should default to after verification (may override stored suggestion).
 */
function application_effective_renewal_type(PDO $pdo, array $application, ?int $overrideMemberId = null): string
{
    $base = (string) ($application['suggested_renewal_type'] ?? 'new');
    if (!in_array($base, ['new', 'on_time', 'late'], true)) {
        $base = 'new';
    }

    $verification = application_renewal_verification($pdo, $application, $overrideMemberId);
    if ($verification['adjusted_renewal_type'] !== null) {
        return $verification['adjusted_renewal_type'];
    }

    return $base;
}

/**
 * Human-readable label for renewal verification status.
 */
function application_renewal_verification_label(string $status): string
{
    return match ($status) {
        'no_match'        => 'Renewal claimed — no member match',
        'ambiguous_match' => 'Renewal claimed — ambiguous match',
        'no_history'      => 'Renewal claimed — no prior history',
        'weak_match'      => 'Renewal claimed — weak match',
        default           => '',
    };
}

function application_pending_count(PDO $pdo): int
{
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM member_applications WHERE status IN ('pending', 'pending_payment')");
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Ensure review/rejection columns exist on older member_applications tables.
 */
function application_ensure_review_schema(PDO $pdo): void
{
    try {
        $columns = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM member_applications');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[(string) ($row['Field'] ?? '')] = true;
        }
        if (!isset($columns['reviewed_at'])) {
            $pdo->exec('ALTER TABLE member_applications ADD COLUMN reviewed_at datetime DEFAULT NULL AFTER submitted_at');
        }
        if (!isset($columns['reviewed_by'])) {
            $pdo->exec('ALTER TABLE member_applications ADD COLUMN reviewed_by int unsigned DEFAULT NULL AFTER reviewed_at');
        }
        if (!isset($columns['rejection_reason'])) {
            $pdo->exec('ALTER TABLE member_applications ADD COLUMN rejection_reason text DEFAULT NULL AFTER reviewed_by');
        }
    } catch (Throwable $e) {
    }
}

function application_is_reviewable_status(?string $status): bool
{
    return in_array((string) $status, ['pending', 'pending_payment'], true);
}

/**
 * Whether staff may approve an application (payment complete or waived).
 */
function application_can_approve(?string $status): bool
{
    return (string) $status === 'pending';
}

/**
 * Payment context for member_process after approving an online application.
 *
 * @return array{
 *   payment: array<string,mixed>,
 *   paid_online: bool,
 *   waived: bool,
 *   suggest_complementary: bool,
 *   stripe_id: string,
 *   gateway: string
 * }
 */
function application_online_payment_context(array $application, ?PDO $pdo = null): array
{
    $payment = application_payment_breakdown($application, $pdo);
    $paymentStatus = (string) ($application['payment_status'] ?? '');
    $paymentTotal = (float) ($application['payment_total'] ?? 0);
    $waived = $payment['coupon_applied'] || $paymentStatus === 'waived';
    $paidOnline = $paymentStatus === 'succeeded' && $paymentTotal > 0;

    return [
        'payment'               => $payment,
        'paid_online'           => $paidOnline,
        'waived'                => $waived,
        'suggest_complementary' => $waived || $paidOnline,
        'stripe_id'             => trim((string) ($application['payment_transaction_id'] ?? '')),
        'gateway'               => trim((string) ($application['payment_gateway'] ?? '')),
    ];
}

/**
 * @return array<string, mixed>|null
 */
function application_fetch(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM member_applications WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function application_notify_new_submission(PDO $pdo, int $applicationId): void
{
    $app = application_fetch($pdo, $applicationId);
    if ($app === null) {
        return;
    }

    $config = installation_load_system_config($pdo);
    $to = application_notify_recipient_email($config);
    if ($to === '') {
        return;
    }

    $name = trim($app['first_name'] . ' ' . $app['last_name']);
    $kind = ucfirst((string) $app['application_kind']);
    $subject = 'New membership application: ' . $name;
    $body = '<p>A new membership application is ready for review.</p>'
        . '<ul>'
        . '<li><strong>Applicant:</strong> ' . htmlspecialchars($name) . '</li>'
        . '<li><strong>Type:</strong> ' . htmlspecialchars($kind) . '</li>'
        . '<li><strong>Reference:</strong> ' . htmlspecialchars((string) $app['wpforms_entry_id']) . '</li>'
        . '</ul>'
        . '<p>Review it in RC Flight Operations → Applications.</p>';

    $mailConfig = installation_mail_config($pdo, $config);
    send_mail($to, $subject, $body, strip_tags($body), $mailConfig);
}

/**
 * Build member POST-shaped array from an application row.
 *
 * @return array<string, mixed>
 */
function application_member_post_from_row(array $app): array
{
    return [
        'first_name'                     => $app['first_name'] ?? '',
        'last_name'                      => $app['last_name'] ?? '',
        'email'                          => $app['email'] ?? '',
        'phone'                          => $app['phone'] ?? '',
        'address_street'                 => $app['address_street'] ?? '',
        'address_street2'                => $app['address_street2'] ?? '',
        'address_city'                   => $app['address_city'] ?? '',
        'address_state'                  => $app['address_state'] ?? '',
        'address_postal_code'            => $app['address_postal_code'] ?? '',
        'birthday'                       => $app['birthday'] ?? '',
        'notes'                          => $app['notes'] ?? '',
        'membership_type_slot'           => $app['membership_type_slot'] ?? '',
        'membership_renewal_year'        => $app['suggested_renewal_year'] ?? '',
        'inactive'                       => '0',
        'suspended'                      => '0',
        'life_member'                    => '0',
        'free_membership'                => '0',
        'ama_number'                     => $app['ama_number'] ?? '',
        'ama_expiration'                 => $app['ama_expiration'] ?? '',
        'ama_life_member'                => '0',
        'faa_number'                     => $app['faa_number'] ?? '',
        'faa_expiration'                 => $app['faa_expiration'] ?? '',
        'emergency_contact_name'         => $app['emergency_contact_name'] ?? '',
        'emergency_contact_relationship' => $app['emergency_contact_relationship'] ?? '',
        'emergency_contact_phone'        => $app['emergency_contact_phone'] ?? '',
    ];
}

/**
 * Partially update an existing member from application data (only non-empty submitted fields).
 *
 * @param array<string, 'current'|'incoming'> $fieldChoices  For diff fields, which value to keep.
 */
function application_update_existing_member(PDO $pdo, int $memberId, array $app, array $fieldChoices = []): void
{
    $memberStmt = $pdo->prepare('SELECT * FROM members WHERE id = ? LIMIT 1');
    $memberStmt->execute([$memberId]);
    $existing = $memberStmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        throw new RuntimeException('Matched member not found.');
    }

    $sets = [];
    $vals = [];

    $map = [
        'first_name' => 'first_name',
        'last_name'  => 'last_name',
        'email'      => 'email',
        'phone'      => 'phone',
        'birthday'   => 'birthday',
        'notes'      => 'notes',
        'ama_number' => 'ama_number',
        'ama_expiration' => 'ama_expiration',
        'faa_number' => 'faa_number',
        'faa_expiration' => 'faa_expiration',
        'emergency_contact_name' => 'emergency_contact_name',
        'emergency_contact_relationship' => 'emergency_contact_relationship',
        'emergency_contact_phone' => 'emergency_contact_phone',
        'membership_type_slot' => 'membership_type_slot',
        'address_street' => 'address_street',
        'address_street2' => 'address_street2',
        'address_city' => 'address_city',
        'address_state' => 'address_state',
        'address_postal_code' => 'address_postal_code',
    ];

    foreach ($map as $col => $field) {
        if (isset($fieldChoices[$col]) && $fieldChoices[$col] === 'current') {
            continue;
        }
        if (!array_key_exists($field, $app)) {
            continue;
        }
        $val = $app[$field];
        if ($val === null || $val === '') {
            continue;
        }
        $sets[] = $col . ' = ?';
        $vals[] = $val;
    }

    if ($sets !== []) {
        $vals[] = $memberId;
        $pdo->prepare('UPDATE members SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    }

    require_once __DIR__ . '/membership_status.php';
    syncMemberMembershipYearForMember($pdo, $memberId);
}

/**
 * @return array{ok:bool, member_id:?int, error:?string, renewal_type?:string, renewal_year?:int, photo_imported?:?bool, photo_error?:?string, faa_card_imported?:?bool, faa_card_error?:?string}
 */
function application_approve(
    PDO $pdo,
    int $applicationId,
    int $reviewedBy,
    ?int $overrideMemberId = null,
    ?string $renewalType = null,
    ?int $renewalYear = null,
    array $fieldChoices = []
): array {
    $app = application_fetch($pdo, $applicationId);
    if ($app === null) {
        return ['ok' => false, 'member_id' => null, 'error' => 'Application not found.'];
    }
    if (!application_can_approve($app['status'] ?? null)) {
        if (($app['status'] ?? '') === 'pending_payment') {
            return ['ok' => false, 'member_id' => null, 'error' => 'Payment has not been completed yet. Wait for Stripe confirmation or reject this submission.'];
        }

        return ['ok' => false, 'member_id' => null, 'error' => 'Application is not pending review.'];
    }

    $fieldChoices = application_parse_field_choices($fieldChoices);
    $memberId = $overrideMemberId ?: ($app['matched_member_id'] ? (int) $app['matched_member_id'] : null);

    if ($memberId !== null) {
        $amaForConflict = $app['ama_number'] ?? '';
        if (isset($fieldChoices['ama_number']) && $fieldChoices['ama_number'] === 'current') {
            $memberStmt = $pdo->prepare('SELECT ama_number FROM members WHERE id = ? LIMIT 1');
            $memberStmt->execute([$memberId]);
            $amaForConflict = (string) ($memberStmt->fetchColumn() ?: '');
        }
        if ($amaForConflict) {
            $conflict = member_find_by_ama_number($pdo, $amaForConflict, $memberId);
            if ($conflict !== null) {
                return ['ok' => false, 'member_id' => null, 'error' => member_ama_number_conflict_message($conflict)];
            }
        }
        try {
            application_update_existing_member($pdo, $memberId, $app, $fieldChoices);
        } catch (Throwable $e) {
            return ['ok' => false, 'member_id' => null, 'error' => $e->getMessage()];
        }
    } else {
        require_once __DIR__ . '/member_save.php';
        $post = application_member_post_from_row($app);
        if ($renewalYear !== null) {
            $post['membership_renewal_year'] = (string) $renewalYear;
        }
        $result = save_member_from_post($pdo, null, $post, []);
        if (!$result['ok']) {
            return ['ok' => false, 'member_id' => null, 'error' => implode(' ', $result['errors'])];
        }
        $memberId = (int) $result['member_id'];
    }

    require_once __DIR__ . '/membership_comp_invites.php';
    membership_comp_invite_apply_to_member($pdo, $applicationId, $memberId);

    $photoImported = null;
    $photoError = null;
    if (!empty($app['file_badge_photo_url'])) {
        require_once __DIR__ . '/membership_application.php';
        require_once __DIR__ . '/member_save.php';
        $photoPath = (string) $app['file_badge_photo_url'];
        if (membership_application_is_local_upload_path($photoPath)) {
            $abs = membership_application_absolute_upload_path($photoPath);
            $photoResult = member_save_photo_from_local_file($pdo, $memberId, $abs);
        } else {
            $photoResult = member_import_photo_from_url($pdo, $memberId, $photoPath);
        }
        $photoImported = $photoResult['ok'];
        $photoError = $photoResult['error'];
    }

    $faaCardImported = null;
    $faaCardError = null;
    if (!empty($app['file_faa_registration_url'])) {
        require_once __DIR__ . '/member_save.php';
        $faaPath = (string) $app['file_faa_registration_url'];
        if (membership_application_is_local_upload_path($faaPath)) {
            $abs = membership_application_absolute_upload_path($faaPath);
            $faaResult = member_save_faa_card_from_local_file($pdo, $memberId, $abs);
        } else {
            $faaResult = member_import_faa_card_from_url($pdo, $memberId, $faaPath);
        }
        $faaCardImported = $faaResult['ok'];
        $faaCardError = $faaResult['error'];
    }

    $finalRenewalType = $renewalType ?: ($app['suggested_renewal_type'] ?? 'new');
    $finalRenewalYear = $renewalYear ?: (int) ($app['suggested_renewal_year'] ?? defaultRenewalYear($pdo));

    $pdo->prepare('
        UPDATE member_applications
        SET status = \'approved\',
            reviewed_at = NOW(),
            reviewed_by = ?,
            approved_member_id = ?,
            suggested_renewal_type = ?,
            suggested_renewal_year = ?
        WHERE id = ?
    ')->execute([$reviewedBy, $memberId, $finalRenewalType, $finalRenewalYear, $applicationId]);

    return [
        'ok'             => true,
        'member_id'      => $memberId,
        'error'          => null,
        'renewal_type'   => $finalRenewalType,
        'renewal_year'   => $finalRenewalYear,
        'photo_imported' => $photoImported,
        'photo_error'    => $photoError,
        'faa_card_imported' => $faaCardImported,
        'faa_card_error'    => $faaCardError,
    ];
}

/**
 * @return array{ok:bool, error:?string}
 */
function application_reject(PDO $pdo, int $applicationId, int $reviewedBy, string $reason = ''): array
{
    application_ensure_review_schema($pdo);

    $app = application_fetch($pdo, $applicationId);
    if ($app === null) {
        return ['ok' => false, 'error' => 'Application not found.'];
    }
    if (!application_is_reviewable_status($app['status'] ?? null)) {
        return ['ok' => false, 'error' => 'Application is not pending review.'];
    }

    $pdo->prepare('
        UPDATE member_applications
        SET status = \'rejected\', reviewed_at = NOW(), reviewed_by = ?, rejection_reason = ?
        WHERE id = ?
    ')->execute([$reviewedBy, $reason !== '' ? $reason : null, $applicationId]);

    return ['ok' => true, 'error' => null];
}

/**
 * Member fields compared when reviewing a matched application.
 *
 * @return array<string, string>  column => label
 */
function application_member_merge_fields(): array
{
    return [
        'first_name'             => 'First name',
        'last_name'              => 'Last name',
        'email'                  => 'Email',
        'birthday'               => 'Birthday',
        'ama_number'             => 'AMA number',
        'ama_expiration'         => 'AMA expiration',
        'faa_number'             => 'FAA number',
        'faa_expiration'         => 'FAA expiration',
        'emergency_contact_name' => 'Emergency contact',
        'emergency_contact_phone'=> 'Emergency phone',
    ];
}

function application_member_diff_format_value(string $col, string $value): string
{
    if ($value === '') {
        return '—';
    }
    if (in_array($col, ['birthday', 'ama_expiration', 'faa_expiration'], true)) {
        $formatted = formatDate($value);

        return $formatted !== '' ? $formatted : $value;
    }

    return $value;
}

/**
 * @param array<string, mixed> $raw
 * @return array<string, 'current'|'incoming'>
 */
function application_parse_field_choices(array $raw): array
{
    $allowed = application_member_merge_fields();
    $parsed  = [];
    foreach ($raw as $key => $choice) {
        $key = (string) $key;
        if (!isset($allowed[$key])) {
            continue;
        }
        $choice = (string) $choice;
        if ($choice === 'current' || $choice === 'incoming') {
            $parsed[$key] = $choice;
        }
    }

    return $parsed;
}

/**
 * @return list<array{key:string,field:string,current:string,incoming:string}>
 */
function application_member_diff(PDO $pdo, array $app): array
{
    $memberId = $app['matched_member_id'] ? (int) $app['matched_member_id'] : null;
    if ($memberId === null) {
        return [];
    }

    $stmt = $pdo->prepare('SELECT * FROM members WHERE id = ? LIMIT 1');
    $stmt->execute([$memberId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        return [];
    }

    $diff = [];
    foreach (application_member_merge_fields() as $col => $label) {
        $old = trim((string) ($member[$col] ?? ''));
        $new = trim((string) ($app[$col] ?? ''));
        if ($new === '' || $old === $new) {
            continue;
        }
        $diff[] = [
            'key'      => $col,
            'field'    => $label,
            'current'  => application_member_diff_format_value($col, $old),
            'incoming' => application_member_diff_format_value($col, $new),
        ];
    }

    return $diff;
}

function application_kind_label(?string $kind): string
{
    return match ($kind) {
        'renewal' => 'Renewal',
        'new'     => 'New member',
        default   => 'Unknown',
    };
}

function application_season_label(?string $season): string
{
    return match ($season) {
        'renewal_window' => 'Renewal season (Oct–Dec)',
        'regular_new'    => 'Regular new (Jan–Jun)',
        'prorated_new'   => 'Prorated new (Jul–Oct 14)',
        default          => '—',
    };
}

/**
 * SQL expression for the renewal year associated with an application row.
 */
function application_list_renewal_year_sql(): string
{
    return 'COALESCE(suggested_renewal_year, YEAR(COALESCE(submitted_at, created_at)))';
}

function application_list_per_page(): int
{
    return 50;
}

/**
 * @return array{
 *   status:string,
 *   year:int,
 *   year_is_default:bool,
 *   search:string
 * }
 */
function application_parse_list_filters(?PDO $pdo, array $get): array
{
    $status = (string) ($get['status'] ?? 'pending');
    if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
        $status = 'pending';
    }

    $defaultYear = defaultRenewalYear($pdo);
    $yearRaw = array_key_exists('year', $get) ? trim((string) $get['year']) : null;
    if ($yearRaw === null) {
        $year = $defaultYear;
        $yearIsDefault = true;
    } elseif ($yearRaw === '' || $yearRaw === 'all' || $yearRaw === '0') {
        $year = 0;
        $yearIsDefault = false;
    } elseif (ctype_digit($yearRaw)) {
        $year = (int) $yearRaw;
        if ($year < 2000 || $year > 2100) {
            $year = $defaultYear;
        }
        $yearIsDefault = $year === $defaultYear;
    } else {
        $year = $defaultYear;
        $yearIsDefault = true;
    }

    return [
        'status'          => $status,
        'year'            => $year,
        'year_is_default' => $yearIsDefault,
        'search'          => trim((string) ($get['q'] ?? '')),
    ];
}

/**
 * @return list<int>
 */
function application_list_filter_years(PDO $pdo): array
{
    try {
        $expr = application_list_renewal_year_sql();
        $stmt = $pdo->query("SELECT DISTINCT {$expr} AS yr FROM member_applications WHERE {$expr} IS NOT NULL ORDER BY yr DESC");
        $years = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $years);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @param array{status:string,year:int,search:string} $filters
 * @return array{where:string, params:list<mixed>}
 */
function application_list_where_clause(array $filters): array
{
    $where  = ['1=1'];
    $params = [];

    if ($filters['status'] === 'pending') {
        $where[] = "status IN ('pending', 'pending_payment')";
    } elseif ($filters['status'] !== 'all') {
        $where[]  = 'status = ?';
        $params[] = $filters['status'];
    }

    if ($filters['year'] > 0) {
        $where[] = application_list_renewal_year_sql() . ' = ?';
        $params[] = $filters['year'];
    }

    if ($filters['search'] !== '') {
        $where[]  = '(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR wpforms_entry_id LIKE ? OR CONCAT(first_name, \' \', last_name) LIKE ?)';
        $like     = '%' . $filters['search'] . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    return ['where' => implode(' AND ', $where), 'params' => $params];
}

/**
 * Build a query string value for the renewal-year filter.
 */
function application_list_year_query_value(int $year): ?string
{
    if ($year <= 0) {
        return 'all';
    }
    return (string) $year;
}

/**
 * Build a URL to applications.php preserving list filters.
 *
 * @param array<string, scalar|null> $extra  e.g. ['id' => 5]
 */
function application_list_page_url(
    string $status,
    int $year,
    string $search,
    int $defaultRenewalYear,
    array $extra = []
): string {
    $params = [];
    if ($status !== 'pending') {
        $params['status'] = $status;
    }
    if ($year !== $defaultRenewalYear) {
        $yearValue = application_list_year_query_value($year);
        if ($yearValue !== null) {
            $params['year'] = $yearValue;
        }
    }
    if ($search !== '') {
        $params['q'] = $search;
    }
    foreach ($extra as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        if ($key === 'page' && (int) $value <= 1) {
            continue;
        }
        $params[$key] = $value;
    }

    return 'applications.php' . ($params !== [] ? '?' . http_build_query($params) : '');
}
