<?php
/**
 * includes/wpforms_application.php
 *
 * Parse WPForms membership application payloads, store pending queue rows,
 * and approve/reject into member records.
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

const WPFORMS_MEMBERSHIP_FORM_ID = 6569;

/**
 * Label / key aliases for WPForms form 6569 (Membership Application).
 *
 * @return array<string, list<string>>
 */
function wpforms_application_field_aliases(): array
{
    return [
        'first_name'                   => ['Name: First', 'first_name', 'First Name', 'Name First'],
        'middle_name'                  => ['Name: Middle', 'middle_name', 'Middle Name'],
        'last_name'                    => ['Name: Last', 'last_name', 'Last Name', 'Name Last'],
        'email'                        => ['Email', 'email'],
        'birthday'                     => ['Date of Birth', 'birthday', 'Date Of Birth'],
        'phone'                        => ['Phone', 'phone', 'Phone Number'],
        'emergency_contact_name'       => ['Emergency Contact', 'emergency_contact_name'],
        'emergency_contact_relationship' => ['Relationship', 'emergency_contact_relationship'],
        'emergency_contact_phone'      => ['Emergency Phone', 'emergency_contact_phone'],
        'address_street'               => ['Address: Address Line 1', 'address_line_1', 'Address Line 1'],
        'address_street2'              => ['Address: Address Line 2', 'address_line_2', 'Address Line 2'],
        'address_city'                 => ['Address: City', 'city', 'Address City'],
        'address_state'                => ['Address: State', 'state', 'Address State'],
        'address_postal_code'          => ['Address: Zip/Postal Code', 'Address: Zip', 'zip', 'postal_code', 'Address Zip'],
        'new_or_renewal'               => ['New Member or Renewal', 'new_member_or_renewal'],
        'new_member_closed'            => ['New Member (Renewal Period Closed)', 'new_member_renewal_period_closed'],
        'membership_type'              => ['Membership Type', 'membership_type'],
        'membership_type_renewal'      => ['Membership Type (Renewal)', 'membership_type_renewal'],
        'membership_type_prorated'     => ['Membership Type (Prorated)', 'membership_type_prorated'],
        'initiation_fee'               => ['Initiation Fee', 'initiation_fee', 'Deleted field #163'],
        'processing_fee'               => ['Processing Fee', 'processing_fee'],
        'payment_total'                => ['Total (Membership + Fees)', 'payment_total', 'total'],
        'special_code'                 => ['Special Code (If you have one)', 'special_code'],
        'ama_number'                   => ['AMA #', 'ama_number', 'AMA Number'],
        'ama_expiration'               => ['AMA Expiration', 'ama_expiration'],
        'faa_number'                   => ['FAA Registration Number', 'faa_number', 'FAA Number'],
        'faa_expiration'               => ['FAA Registration Expiration', 'faa_expiration'],
        // Legacy: AMA card upload removed from form 6569; kept for older application rows.
        'file_ama_verification_url'    => ['AMA Verification (.jpg, .pdf, .png, .doc), 5Mb Max', 'AMA Verification', 'ama_verification'],
        'file_faa_registration_url'    => ['FAA Registration (.jpg, .jpeg, .png), 5Mb Max', 'FAA Registration (.jpg, .png), 5Mb Max', 'FAA Registration (.jpg, .pdf, .png, .doc), 5Mb Max', 'FAA Registration', 'faa_registration'],
        'file_badge_photo_url'         => ['Badge Photo (.jpg, .jpeg, .png), 5Mb Max', 'Badge Photo (.jpg, .png), 5Mb Max', 'Badge Photo (.jpg, .pdf, .png, .doc), 5Mb Max', 'Badge Photo (...)', 'Badge Photo', 'badge_photo'],
        'file_signature_url'           => ['Signature', 'signature'],
        'payment_gateway_info'         => ['Payment Gateway Information', 'payment_gateway_information'],
        'payment_status'               => ['Payment Status', 'payment_status'],
        'wpforms_entry_id'             => ['Entry ID', 'entry_id', 'Entry Id', 'wpforms_entry_id'],
        'submitted_at'                 => ['Application Submission Date', 'application_submission_date', 'Entry Date', 'entry_date'],
        'wpforms_form_id'              => ['Form ID', 'form_id', 'wpforms_form_id'],
    ];
}

/**
 * Flatten nested webhook payloads into a single key => value map.
 */
function wpforms_application_flatten_payload(array $payload): array
{
    $flat = [];
    $walk = static function (array $data, string $prefix = '') use (&$flat, &$walk): void {
        foreach ($data as $key => $value) {
            $k = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                if (array_is_list($value) && isset($value[0]) && !is_array($value[0])) {
                    $flat[$k] = implode(', ', array_map('strval', $value));
                } elseif (isset($value['value'])) {
                    $flat[(string) $key] = is_scalar($value['value']) ? (string) $value['value'] : json_encode($value['value']);
                } else {
                    $walk($value, $k);
                }
            } else {
                $flat[(string) $key] = is_scalar($value) ? trim((string) $value) : json_encode($value);
            }
        }
    };
    $walk($payload);
    return $flat;
}

function wpforms_application_pick_value(array $flat, array $aliases): string
{
    foreach ($aliases as $alias) {
        if (isset($flat[$alias]) && trim((string) $flat[$alias]) !== '') {
            return trim((string) $flat[$alias]);
        }
    }
    $lowerAliases = array_map('strtolower', $aliases);
    foreach ($flat as $key => $value) {
        if (trim((string) $value) === '') {
            continue;
        }
        if (in_array(strtolower((string) $key), $lowerAliases, true)) {
            return trim((string) $value);
        }
    }
    return '';
}

function wpforms_application_pick_phone_values(array $flat): array
{
    $phones = [];
    foreach ($flat as $key => $value) {
        $v = trim((string) $value);
        if ($v === '') {
            continue;
        }
        if (preg_match('/^phone$/i', (string) $key) || stripos((string) $key, 'phone') !== false) {
            if (stripos((string) $key, 'emergency') !== false) {
                continue;
            }
            $phones[] = $v;
        }
    }
    return $phones;
}

/**
 * Normalize currency text from WPForms / Uncanny Automator webhooks.
 * Dollar signs often arrive as HTML entities (&#36; or &amp;#36;) instead of "$".
 */
function wpforms_application_normalize_currency_text(?string $value): string
{
    if ($value === null) {
        return '';
    }
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (str_contains($value, '&#') || str_contains($value, '&amp;')) {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    return trim($value);
}

/**
 * Parse a currency amount from WPForms fee/total fields only.
 * Rejects phones, dates, AMA numbers, and membership labels like "Adult - $80.00".
 */
function wpforms_application_parse_money(?string $value): ?float
{
    $value = wpforms_application_normalize_currency_text($value);
    if ($value === '') {
        return null;
    }

    // Membership labels like "Adult - $80.00" use parse_dues_from_label() instead.
    if (preg_match('/[A-Za-z]/', $value)) {
        return null;
    }

    if (preg_match('/\$\s*([0-9]{1,4}(?:,[0-9]{3})*(?:\.[0-9]{2})?|[0-9]+(?:\.[0-9]{2})?)/', $value, $m)) {
        $clean = str_replace(',', '', $m[1]);
        $amount = round((float) $clean, 2);
        return wpforms_application_money_amount_is_plausible($amount) ? $amount : null;
    }

    if (preg_match('/^-?[0-9]{1,4}\.[0-9]{2}$/', $value)) {
        $amount = round((float) $value, 2);
        return wpforms_application_money_amount_is_plausible($amount) ? $amount : null;
    }

    if (preg_match('/^0(?:\.0+)?$/', $value)) {
        return 0.0;
    }

    return null;
}

function wpforms_application_money_amount_is_plausible(float $amount): bool
{
    return $amount >= 0.0 && $amount <= 9999.99;
}

/**
 * Normalize WPForms choice values that may arrive as raw option indexes from Automator.
 */
function wpforms_application_normalize_status_choice(?string $value): string
{
    $value = strtolower(trim((string) $value));
    if ($value === '') {
        return '';
    }
    // Form 6569: first radio option index for "New Member" when Automator omits |label.
    if ($value === '1') {
        return 'new member';
    }

    return $value;
}

/**
 * Membership slot from a raw webhook choice (label or WPForms option index 1–4).
 */
function wpforms_application_membership_slot_from_choice(?string $value, array $enabledLabels): ?int
{
    $value = wpforms_application_normalize_currency_text($value);
    if ($value === '') {
        return null;
    }
    $slot = normalizeMembershipTypeSlot($value, $enabledLabels);
    if ($slot !== null) {
        return $slot;
    }
    if (preg_match('/^[1-4]$/', $value)) {
        return (int) $value;
    }

    return null;
}

/**
 * Map stored application season/kind to calculateDues() renewal type.
 */
function wpforms_application_dues_renewal_type(?string $applicationKind, ?string $formSeason): string
{
    if ($applicationKind === 'renewal') {
        return 'on_time';
    }
    if ($formSeason === 'prorated_new') {
        return 'new';
    }

    return 'late';
}

/**
 * Extract dues amount from membership choice labels, e.g. "Adult - $80.00".
 */
function wpforms_application_parse_dues_from_label(?string $value): ?float
{
    $value = wpforms_application_normalize_currency_text($value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/\$\s*([0-9]{1,4}(?:,[0-9]{3})*(?:\.[0-9]{2})?|[0-9]+(?:\.[0-9]{2})?)/', $value, $m)) {
        $clean = str_replace(',', '', $m[1]);
        $amount = round((float) $clean, 2);
        return wpforms_application_money_amount_is_plausible($amount) ? $amount : null;
    }
    return null;
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

function application_payment_breakdown(array $application, ?PDO $pdo = null): array
{
    $raw = [];
    if (!empty($application['raw_payload'])) {
        $decoded = json_decode((string) $application['raw_payload'], true);
        if (is_array($decoded)) {
            $raw = wpforms_application_flatten_payload($decoded);
        }
    }

    $pick = static function (array $keys) use ($raw, $application): string {
        foreach ($keys as $key) {
            if (isset($raw[$key]) && trim((string) $raw[$key]) !== '') {
                return trim((string) $raw[$key]);
            }
        }
        return '';
    };

    $membershipRaw = wpforms_application_normalize_currency_text($pick([
        'Membership Type (Prorated)',
        'Membership Type (Renewal)',
        'Membership Type',
    ]));
    $membershipDues = wpforms_application_parse_dues_from_label($membershipRaw);

    if ($membershipDues === null && $pdo !== null) {
        $labels = enabledMembershipTypeLabels($pdo);
        $slot = wpforms_application_membership_slot_from_choice($membershipRaw, $labels);
        if ($slot === null) {
            $stored = (int) ($application['membership_type_slot'] ?? 0);
            $slot = ($stored >= 1 && $stored <= 4) ? $stored : null;
        }
        if ($slot !== null) {
            $renewalType = wpforms_application_dues_renewal_type(
                $application['application_kind'] ?? null,
                $application['form_season'] ?? null
            );
            $calc = calculateDues($pdo, $slot, $renewalType);
            if ($calc['dues'] > 0) {
                $membershipDues = round($calc['dues'], 2);
            }
        }
    }

    $initiationRaw = $pick(['Initiation Fee', 'Deleted field #163']);
    $processingRaw = $pick(['Processing Fee']);
    $totalRaw      = $pick(['Total (Membership + Fees)']);

    $initiation = wpforms_application_parse_money($initiationRaw);
    $processing = wpforms_application_parse_money($processingRaw);
    $totalPaid  = wpforms_application_parse_money($totalRaw);

    if ($initiation === null && isset($application['payment_initiation']) && $application['payment_initiation'] !== null) {
        $initiation = wpforms_application_parse_money((string) $application['payment_initiation'])
            ?? (wpforms_application_money_amount_is_plausible((float) $application['payment_initiation'])
                ? round((float) $application['payment_initiation'], 2) : null);
    }
    if ($processing === null && isset($application['payment_processing_fee']) && $application['payment_processing_fee'] !== null) {
        $processing = wpforms_application_parse_money((string) $application['payment_processing_fee'])
            ?? (wpforms_application_money_amount_is_plausible((float) $application['payment_processing_fee'])
                ? round((float) $application['payment_processing_fee'], 2) : null);
    }
    if ($totalPaid === null && isset($application['payment_total']) && $application['payment_total'] !== null) {
        $totalPaid = wpforms_application_parse_money((string) $application['payment_total'])
            ?? (wpforms_application_money_amount_is_plausible((float) $application['payment_total'])
                ? round((float) $application['payment_total'], 2) : null);
    }

    $specialCode = $pick(['Special Code (If you have one)', 'Coupon code']);
    $notesText = !empty($application['notes']) ? (string) $application['notes'] : '';
    $complimentaryLabel = application_payment_complimentary_label($notesText !== '' ? $notesText : null);
    if ($specialCode === '' && $notesText !== '') {
        if (preg_match('/Special code:\s*(.+)$/m', $notesText, $m)) {
            $specialCode = trim($m[1]);
        } elseif (preg_match('/Coupon code:\s*(.+)$/m', $notesText, $m)) {
            $specialCode = trim($m[1]);
        }
    }

    $parts = array_values(array_filter([$membershipDues, $initiation, $processing], static fn ($v) => $v !== null));
    $subtotal = count($parts) === 3 ? round($parts[0] + $parts[1] + $parts[2], 2) : null;
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

/**
 * @return array{gateway:?string,transaction_id:?string,total:?float}
 */
function wpforms_application_parse_gateway_info(string $text): array
{
    $gateway = null;
    $transaction = null;
    $total = null;
    if (preg_match('/^Total:\s*([0-9.]+)/mi', $text, $m)) {
        $total = round((float) $m[1], 2);
    }
    if (preg_match('/^Gateway:\s*(.+)$/mi', $text, $m)) {
        $gateway = trim($m[1]);
    }
    if (preg_match('/^Transaction:\s*(.+)$/mi', $text, $m)) {
        $transaction = trim($m[1]);
    }
    return [
        'gateway'        => $gateway,
        'transaction_id' => $transaction,
        'total'          => $total,
    ];
}

/**
 * Map a submission timestamp to the new-member season when form fields are ambiguous.
 * Mirrors WPForms conditional seasons (Jan–Jun regular, Jul–Oct 14 prorated, Oct 15+ renewal).
 *
 * @return 'regular_new'|'prorated_new'|'renewal_window'|null
 */
function wpforms_application_new_member_season_from_date(?string $submittedAt): ?string
{
    if ($submittedAt === null || trim($submittedAt) === '') {
        return null;
    }
    $ts = strtotime($submittedAt);
    if ($ts === false) {
        return null;
    }
    $month = (int) date('n', $ts);
    $day   = (int) date('j', $ts);

    if ($month >= 1 && $month <= 6) {
        return 'regular_new';
    }
    if ($month >= 7 && $month <= 9) {
        return 'prorated_new';
    }
    if ($month === 10 && $day < 15) {
        return 'prorated_new';
    }
    if ($month === 10 || $month === 11 || $month === 12) {
        return 'renewal_window';
    }

    return null;
}

/**
 * @return array{kind:string,season:?string,membership_label:string}
 */
function wpforms_application_infer_kind_season(array $fields, ?string $submittedAt = null): array
{
    $newOrRenewal = wpforms_application_normalize_status_choice($fields['new_or_renewal'] ?? '');
    if ($newOrRenewal === '2') {
        $newOrRenewal = 'renewal';
    }
    $newClosed    = wpforms_application_normalize_status_choice($fields['new_member_closed'] ?? '');
    $renewalType  = trim($fields['membership_type_renewal'] ?? '');
    $proratedType = trim($fields['membership_type_prorated'] ?? '');
    $regularType  = trim($fields['membership_type'] ?? '');

    if ($newOrRenewal === 'renewal') {
        return ['kind' => 'renewal', 'season' => 'renewal_window', 'membership_label' => $renewalType];
    }
    // Prorated membership choice is definitive; check before hidden-field ghosts from Automator.
    if ($proratedType !== '') {
        return ['kind' => 'new', 'season' => 'prorated_new', 'membership_label' => $proratedType];
    }
    if ($newOrRenewal === 'new member') {
        $season = wpforms_application_new_member_season_from_date($submittedAt) ?? 'renewal_window';
        return ['kind' => 'new', 'season' => $season, 'membership_label' => $regularType];
    }
    if ($newClosed === 'new member' || $regularType !== '') {
        $label = $regularType !== '' ? $regularType : $proratedType;
        $season = wpforms_application_new_member_season_from_date($submittedAt)
            ?? ($newClosed === 'new member' ? 'regular_new' : 'regular_new');
        return ['kind' => 'new', 'season' => $season, 'membership_label' => $label];
    }
    return ['kind' => 'unknown', 'season' => null, 'membership_label' => ''];
}

/**
 * Resolve membership type slot for display, re-parsing raw payload when the stored value is missing.
 */
function application_resolve_membership_type_slot(array $application, ?PDO $pdo = null): ?int
{
    $stored = (int) ($application['membership_type_slot'] ?? 0);
    if ($stored >= 1 && $stored <= 4) {
        return $stored;
    }
    if ($pdo === null || empty($application['raw_payload'])) {
        return null;
    }
    $decoded = json_decode((string) $application['raw_payload'], true);
    if (!is_array($decoded)) {
        return null;
    }
    $parsed = wpforms_application_parse_payload($pdo, $decoded);

    return $parsed['membership_type_slot'];
}

/**
 * Re-derive stored application fields from raw_payload (e.g. after parser fixes).
 *
 * @return array{ok:bool, updated:bool, error:?string}
 */
function application_reparse_stored_fields(PDO $pdo, int $applicationId): array
{
    $app = application_fetch($pdo, $applicationId);
    if (!$app) {
        return ['ok' => false, 'updated' => false, 'error' => 'Application not found.'];
    }
    $decoded = json_decode((string) ($app['raw_payload'] ?? ''), true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'updated' => false, 'error' => 'Missing or invalid raw_payload.'];
    }

    $parsed = wpforms_application_parse_payload($pdo, $decoded);
    $verification = application_renewal_verification($pdo, array_merge($app, $parsed));
    if ($verification['adjusted_renewal_type'] !== null) {
        $parsed['suggested_renewal_type'] = $verification['adjusted_renewal_type'];
    }
    $stmt = $pdo->prepare('
        UPDATE member_applications SET
            application_kind = ?,
            form_season = ?,
            suggested_renewal_type = ?,
            suggested_renewal_year = ?,
            membership_type_slot = ?
        WHERE id = ?
    ');
    $stmt->execute([
        $parsed['application_kind'],
        $parsed['form_season'],
        $parsed['suggested_renewal_type'],
        $parsed['suggested_renewal_year'],
        $parsed['membership_type_slot'],
        $applicationId,
    ]);

    return ['ok' => true, 'updated' => $stmt->rowCount() > 0, 'error' => null];
}

function wpforms_application_suggested_renewal_type(string $kind, ?string $season): ?string
{
    if ($kind === 'renewal') {
        return 'on_time';
    }
    if ($kind === 'new' && $season === 'prorated_new') {
        return 'new';
    }
    if ($kind === 'new' && in_array($season, ['regular_new', 'renewal_window'], true)) {
        return 'late';
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

function wpforms_application_suggested_renewal_year(PDO $pdo, ?string $submittedAt): int
{
    if ($submittedAt !== null && $submittedAt !== '') {
        $ts = strtotime($submittedAt);
        if ($ts !== false) {
            $month = (int) date('n', $ts);
            $day   = (int) date('j', $ts);
            $year  = (int) date('Y', $ts);
            $startMonth = renewal_prebook_start_month($pdo);
            $startDay   = renewal_prebook_start_day($pdo);
            $rolledOver = ($month > $startMonth) || ($month === $startMonth && $day >= $startDay);
            return $rolledOver ? $year + 1 : $year;
        }
    }
    return defaultRenewalYear($pdo);
}

/**
 * @return array<string, mixed>
 */
function wpforms_application_parse_payload(PDO $pdo, array $payload): array
{
    $flat    = wpforms_application_flatten_payload($payload);
    $aliases = wpforms_application_field_aliases();
    $fields  = [];
    foreach ($aliases as $internal => $keys) {
        $fields[$internal] = wpforms_application_pick_value($flat, $keys);
    }

    $phones = wpforms_application_pick_phone_values($flat);
    if ($fields['phone'] === '' && isset($phones[0])) {
        $fields['phone'] = $phones[0];
    }
    if ($fields['emergency_contact_phone'] === '' && isset($phones[1])) {
        $fields['emergency_contact_phone'] = $phones[1];
    }

    $submittedAtRaw = $fields['submitted_at'];
    $submittedAtDb  = parseDateForDb($submittedAtRaw);
    if ($submittedAtDb === null && $submittedAtRaw !== '') {
        $ts = strtotime($submittedAtRaw);
        $submittedAtDb = $ts !== false ? date('Y-m-d', $ts) : null;
    }

    $inferred = wpforms_application_infer_kind_season($fields, $submittedAtDb ?? $submittedAtRaw);
    $enabledLabels = enabledMembershipTypeLabels($pdo);
    $membershipSlot = wpforms_application_membership_slot_from_choice($inferred['membership_label'], $enabledLabels);

    $street  = $fields['address_street'];
    $street2 = $fields['address_street2'];
    if ($street === '' && $street2 !== '') {
        $street  = $street2;
        $street2 = '';
    }

    $notesParts = [];
    if ($fields['middle_name'] !== '') {
        $notesParts[] = 'Middle name: ' . $fields['middle_name'];
    }
    if ($fields['special_code'] !== '') {
        $notesParts[] = 'Special code: ' . $fields['special_code'];
    }

    $gatewayParsed = wpforms_application_parse_gateway_info($fields['payment_gateway_info']);
    $paymentTotal = wpforms_application_parse_money($fields['payment_total']);
    if ($paymentTotal === null && $fields['payment_total'] !== '' && preg_match('/^\s*\$?\s*0(?:\.00)?\s*$/', $fields['payment_total'])) {
        $paymentTotal = 0.0;
    }
    if ($paymentTotal === null && $gatewayParsed['total'] !== null) {
        $paymentTotal = $gatewayParsed['total'];
    }
    $paymentInitiation = wpforms_application_parse_money($fields['initiation_fee']);
    $paymentProcessing = wpforms_application_parse_money($fields['processing_fee']);

    $amaNumber = $fields['ama_number'];
    if ($amaNumber !== '') {
        $amaNumber = ama_verify_normalize_number($amaNumber);
    }

    $kind   = $inferred['kind'];
    $season = $inferred['season'];

    $email = $fields['email'] !== '' ? normalize_email($fields['email']) : null;

    return [
        'wpforms_entry_id'             => $fields['wpforms_entry_id'],
        'wpforms_form_id'              => $fields['wpforms_form_id'] !== '' ? (int) $fields['wpforms_form_id'] : WPFORMS_MEMBERSHIP_FORM_ID,
        'submitted_at'                 => $submittedAtDb,
        'application_kind'             => $kind,
        'form_season'                  => $season,
        'suggested_renewal_type'       => wpforms_application_suggested_renewal_type($kind, $season),
        'suggested_renewal_year'       => wpforms_application_suggested_renewal_year($pdo, $submittedAtDb ?? $submittedAtRaw),
        'first_name'                   => $fields['first_name'],
        'last_name'                    => $fields['last_name'],
        'middle_name'                  => $fields['middle_name'] !== '' ? $fields['middle_name'] : null,
        'email'                        => $email,
        'birthday'                     => parseDateForDb($fields['birthday']),
        'phone'                        => $fields['phone'] !== '' ? $fields['phone'] : null,
        'emergency_contact_name'       => $fields['emergency_contact_name'] !== '' ? $fields['emergency_contact_name'] : null,
        'emergency_contact_relationship' => $fields['emergency_contact_relationship'] !== '' ? $fields['emergency_contact_relationship'] : null,
        'emergency_contact_phone'      => $fields['emergency_contact_phone'] !== '' ? $fields['emergency_contact_phone'] : null,
        'address_street'               => $street !== '' ? $street : null,
        'address_street2'              => $street2 !== '' ? $street2 : null,
        'address_city'                 => $fields['address_city'] !== '' ? $fields['address_city'] : null,
        'address_state'                => $fields['address_state'] !== '' ? $fields['address_state'] : null,
        'address_postal_code'          => $fields['address_postal_code'] !== '' ? $fields['address_postal_code'] : null,
        'ama_number'                   => $amaNumber !== '' ? $amaNumber : null,
        'ama_expiration'               => parseDateForDb($fields['ama_expiration']),
        'faa_number'                   => $fields['faa_number'] !== '' ? $fields['faa_number'] : null,
        'faa_expiration'               => parseDateForDb($fields['faa_expiration']),
        'membership_type_slot'         => $membershipSlot,
        'notes'                        => $notesParts !== [] ? implode("\n", $notesParts) : null,
        'payment_total'                => $paymentTotal,
        'payment_initiation'           => $paymentInitiation,
        'payment_processing_fee'       => $paymentProcessing,
        'payment_gateway'              => $gatewayParsed['gateway'],
        'payment_transaction_id'       => $gatewayParsed['transaction_id'],
        'payment_status'               => $fields['payment_status'] !== '' ? $fields['payment_status'] : null,
        'file_ama_verification_url'    => $fields['file_ama_verification_url'] !== '' ? $fields['file_ama_verification_url'] : null,
        'file_faa_registration_url'    => $fields['file_faa_registration_url'] !== '' ? $fields['file_faa_registration_url'] : null,
        'file_badge_photo_url'         => $fields['file_badge_photo_url'] !== '' ? $fields['file_badge_photo_url'] : null,
        'file_signature_url'           => $fields['file_signature_url'] !== '' ? $fields['file_signature_url'] : null,
        'raw_payload'                  => $payload,
    ];
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

/**
 * @return array{ok:bool, application_id:?int, error:?string, duplicate:bool}
 */
function application_receive_webhook(PDO $pdo, array $payload): array
{
    $parsed = wpforms_application_parse_payload($pdo, $payload);

    if ($parsed['first_name'] === '' || $parsed['last_name'] === '') {
        return ['ok' => false, 'application_id' => null, 'error' => 'First and last name are required.', 'duplicate' => false];
    }

    $entryId = trim((string) $parsed['wpforms_entry_id']);
    if ($entryId === '') {
        $entryId = 'auto-' . hash('sha256', json_encode($payload));
    }

    $existing = $pdo->prepare('SELECT id FROM member_applications WHERE wpforms_entry_id = ? LIMIT 1');
    $existing->execute([$entryId]);
    $existingId = $existing->fetchColumn();
    if ($existingId) {
        return ['ok' => true, 'application_id' => (int) $existingId, 'error' => null, 'duplicate' => true];
    }

    $match = member_match_find(
        $pdo,
        $parsed['ama_number'],
        $parsed['first_name'],
        $parsed['last_name'],
        $parsed['email'],
        $parsed['birthday']
    );

    $applicationRow = array_merge($parsed, [
        'matched_member_id' => $match['member_id'],
        'match_confidence'  => $match['confidence'] !== 'none' ? $match['confidence'] : null,
    ]);
    $verification = application_renewal_verification($pdo, $applicationRow);
    if ($verification['adjusted_renewal_type'] !== null) {
        $parsed['suggested_renewal_type'] = $verification['adjusted_renewal_type'];
    }

    $submittedAt = $parsed['submitted_at'];
    if ($submittedAt !== null) {
        $submittedAt .= ' 12:00:00';
    } else {
        $submittedAt = date('Y-m-d H:i:s');
    }

    $stmt = $pdo->prepare('
        INSERT INTO member_applications (
            status, wpforms_entry_id, wpforms_form_id, submitted_at,
            application_kind, form_season, suggested_renewal_type, suggested_renewal_year,
            matched_member_id, match_confidence, match_method,
            first_name, last_name, middle_name, email, birthday, phone,
            emergency_contact_name, emergency_contact_relationship, emergency_contact_phone,
            address_street, address_street2, address_city, address_state, address_postal_code,
            ama_number, ama_expiration, faa_number, faa_expiration, membership_type_slot, notes,
            payment_total, payment_initiation, payment_processing_fee,
            payment_gateway, payment_transaction_id, payment_status,
            file_ama_verification_url, file_faa_registration_url, file_badge_photo_url, file_signature_url,
            raw_payload
        ) VALUES (
            \'pending\', ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?,
            ?
        )
    ');

    $stmt->execute([
        $entryId,
        $parsed['wpforms_form_id'] ?: null,
        $submittedAt,
        $parsed['application_kind'],
        $parsed['form_season'],
        $parsed['suggested_renewal_type'],
        $parsed['suggested_renewal_year'],
        $match['member_id'],
        $match['confidence'] !== 'none' ? $match['confidence'] : null,
        $match['method'],
        $parsed['first_name'],
        $parsed['last_name'],
        $parsed['middle_name'],
        $parsed['email'],
        $parsed['birthday'],
        $parsed['phone'],
        $parsed['emergency_contact_name'],
        $parsed['emergency_contact_relationship'],
        $parsed['emergency_contact_phone'],
        $parsed['address_street'],
        $parsed['address_street2'],
        $parsed['address_city'],
        $parsed['address_state'],
        $parsed['address_postal_code'],
        $parsed['ama_number'],
        $parsed['ama_expiration'],
        $parsed['faa_number'],
        $parsed['faa_expiration'],
        $parsed['membership_type_slot'],
        $parsed['notes'],
        $parsed['payment_total'],
        $parsed['payment_initiation'],
        $parsed['payment_processing_fee'],
        $parsed['payment_gateway'],
        $parsed['payment_transaction_id'],
        $parsed['payment_status'],
        $parsed['file_ama_verification_url'],
        $parsed['file_faa_registration_url'],
        $parsed['file_badge_photo_url'],
        $parsed['file_signature_url'],
        json_encode($parsed['raw_payload'], JSON_UNESCAPED_UNICODE),
    ]);

    $applicationId = (int) $pdo->lastInsertId();
    application_notify_new_submission($pdo, $applicationId);

    return ['ok' => true, 'application_id' => $applicationId, 'error' => null, 'duplicate' => false];
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
