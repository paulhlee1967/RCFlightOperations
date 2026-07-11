<?php
/**
 * Native membership application: season context, fee quotes, validation, and storage.
 *
 * Replaces external form conditional fields with server-side logic driven by dues_rules
 * and installation renewal-prebook settings.
 */

require_once __DIR__ . '/dues_helpers.php';
require_once __DIR__ . '/installation_config.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/member_import_helpers.php';
require_once __DIR__ . '/ama_verify.php';
require_once __DIR__ . '/sender_net.php';

/** Stripe pass-through: 2.9% + $0.30 per transaction. */
const MEMBERSHIP_STRIPE_PERCENT = 0.029;
const MEMBERSHIP_STRIPE_FIXED   = 0.30;

/** How long an AMA verification gate remains valid for apply submission. */
const MEMBERSHIP_APPLY_AMA_SESSION_TTL = 3600;

/**
 * Minimum AMA expiration (Y-m-d) required to apply on $now.
 * Before renewal pre-book: through 12/31 of the current calendar year.
 * On/after pre-book: through 12/31 of the working renewal year.
 */
function membership_application_ama_minimum_expiry_ymd(PDO $pdo, ?DateTimeInterface $now = null): string
{
    $now = $now ?? new DateTimeImmutable('now');
    $year  = (int) $now->format('Y');
    $month = (int) $now->format('n');
    $day   = (int) $now->format('j');
    $startMonth = renewal_prebook_start_month($pdo);
    $startDay   = renewal_prebook_start_day($pdo);
    $rolledOver = ($month > $startMonth) || ($month === $startMonth && $day >= $startDay);
    $requiredYear = $rolledOver ? $year + 1 : $year;

    return $requiredYear . '-12-31';
}

function membership_application_ama_minimum_expiry_label(PDO $pdo, ?DateTimeInterface $now = null): string
{
    $ymd = membership_application_ama_minimum_expiry_ymd($pdo, $now);
    $formatted = formatDate($ymd);

    return $formatted !== '' ? $formatted : $ymd;
}

function membership_application_ama_meets_minimum_expiry(
    PDO $pdo,
    ?string $expirationYmd,
    bool $lifeMember,
    ?DateTimeInterface $now = null
): bool {
    if ($lifeMember) {
        return true;
    }
    if ($expirationYmd === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expirationYmd)) {
        return false;
    }

    return $expirationYmd >= membership_application_ama_minimum_expiry_ymd($pdo, $now);
}

function membership_application_ymd_to_mdy(?string $ymd): string
{
    if ($ymd === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
        return '';
    }
    $ts = strtotime($ymd);

    return $ts !== false ? date('m/d/Y', $ts) : '';
}

/**
 * Whether the applicant may choose Renewal on the public form.
 *
 * Requires renewal season, a club member matched by AMA #, and current membership
 * for the year before the working renewal year (on-time renewal path).
 *
 * @return array{eligible:bool, member_id:?int, message:string, renewal_year:int}
 */
function membership_application_renewal_eligibility(
    PDO $pdo,
    string $amaNumber,
    string $firstName,
    string $lastName,
    ?DateTimeInterface $now = null
): array {
    require_once __DIR__ . '/membership_status.php';
    require_once __DIR__ . '/validation.php';

    $now = $now ?? new DateTimeImmutable('now');
    $renewalYear = membership_application_suggested_renewal_year($pdo, $now);
    $base = [
        'eligible'     => false,
        'member_id'    => null,
        'renewal_year' => $renewalYear,
        'message'      => '',
    ];

    if (!membership_application_renewal_open($now, $pdo)) {
        $base['message'] = 'Renewals are not open yet for this season.';

        return $base;
    }

    $amaNumber = ama_verify_normalize_number($amaNumber);
    $member = $amaNumber !== '' ? member_find_by_ama_number($pdo, $amaNumber) : null;

    if ($member === null) {
        $base['message'] = 'No current club membership was found for this AMA number. Select New member to apply.';

        return $base;
    }

    $blockMessage = membership_application_club_member_apply_block_message($member);
    if ($blockMessage !== null) {
        $base['member_id'] = (int) $member['id'];
        $base['message'] = $blockMessage;

        return $base;
    }

    $priorYear = $renewalYear - 1;
    $renewedIds = renewedMemberIdsForYear($pdo, $priorYear);
    if (!memberIsCurrent($member, $priorYear, $renewedIds)) {
        $base['member_id'] = (int) $member['id'];
        $base['message'] = 'Our records show you are not a current member for the '
            . $priorYear . ' season. Select New member — initiation fees may apply.';

        return $base;
    }

    $base['eligible'] = true;
    $base['member_id'] = (int) $member['id'];
    $base['message'] = 'You may renew your membership for ' . $renewalYear . '.';

    return $base;
}

/**
 * Block online applications when AMA matches a suspended club member.
 */
function membership_application_club_member_apply_block_message(?array $member): ?string
{
    if ($member === null) {
        return null;
    }
    if (!empty($member['suspended'])) {
        return 'Your club membership is suspended. Contact the membership team before applying online.';
    }

    return null;
}

/**
 * @return array{
 *   ama_number: string,
 *   last_name: string,
 *   first_name: string,
 *   ama_expiration_ymd: ?string,
 *   ama_expiration_mdy: ?string,
 *   life_member: bool,
 *   renewal_eligible: bool,
 *   renewal_eligible_message: string,
 *   renewal_member_id: ?int,
 *   verified_at: int,
 *   token: string
 * }|null
 */
function membership_application_ama_get_session(): ?array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $session = $_SESSION['membership_apply_ama'] ?? null;
    if (!is_array($session) || empty($session['verified_at']) || empty($session['token'])) {
        return null;
    }
    if (time() - (int) $session['verified_at'] > MEMBERSHIP_APPLY_AMA_SESSION_TTL) {
        unset($_SESSION['membership_apply_ama']);

        return null;
    }

    return $session;
}

/**
 * @param array{
 *   ama_number: string,
 *   last_name: string,
 *   first_name: string,
 *   ama_expiration_ymd: ?string,
 *   ama_expiration_mdy: ?string,
 *   ama_expiration_mdy: ?string,
 *   life_member: bool,
 *   renewal_eligible?: bool,
 *   renewal_eligible_message?: string,
 *   renewal_member_id?: ?int
 * } $data
 */
function membership_application_ama_set_session(array $data): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['membership_apply_ama'] = [
        'ama_number'                 => $data['ama_number'],
        'last_name'                  => $data['last_name'],
        'first_name'                 => $data['first_name'],
        'ama_expiration_ymd'         => $data['ama_expiration_ymd'],
        'ama_expiration_mdy'         => $data['ama_expiration_mdy'],
        'life_member'                => (bool) ($data['life_member'] ?? false),
        'renewal_eligible'           => (bool) ($data['renewal_eligible'] ?? false),
        'renewal_eligible_message'   => (string) ($data['renewal_eligible_message'] ?? ''),
        'renewal_member_id'          => isset($data['renewal_member_id']) ? (int) $data['renewal_member_id'] : null,
        'complimentary_member'       => (bool) ($data['complimentary_member'] ?? false),
        'complimentary_member_detail'  => (string) ($data['complimentary_member_detail'] ?? ''),
        'complimentary_member_id'    => isset($data['complimentary_member_id']) ? (int) $data['complimentary_member_id'] : null,
        'verified_at'                => time(),
        'token'                      => bin2hex(random_bytes(16)),
    ];
}

function membership_application_ama_clear_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    unset($_SESSION['membership_apply_ama']);
}

function membership_application_ama_rate_limit_check(PDO $pdo, string $clientIp): bool
{
    if ($clientIp === '') {
        return true;
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS membership_apply_ama_ip_events (
            id int unsigned NOT NULL AUTO_INCREMENT,
            ip varchar(45) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ip_created (ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec('DELETE FROM membership_apply_ama_ip_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 25 HOUR)');
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM membership_apply_ama_ip_events
             WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
        );
        $stmt->execute([$clientIp]);
        if ((int) $stmt->fetchColumn() >= 15) {
            return false;
        }
        $pdo->prepare('INSERT INTO membership_apply_ama_ip_events (ip) VALUES (?)')->execute([$clientIp]);
    } catch (Throwable $e) {
    }

    return true;
}

/**
 * Verify AMA membership for the public apply gate and store session on success.
 *
 * @return array{ok:bool, error:?string, data:array<string,mixed>}
 */
function membership_application_ama_verify_for_apply(PDO $pdo, string $amaNumber, string $lastName): array
{
    $amaNumber = ama_verify_normalize_number($amaNumber);
    $lastName  = ama_verify_normalize_last_name($lastName);
    $inputErr  = ama_verify_validate_inputs($amaNumber, $lastName);
    if ($inputErr !== null) {
        return ['ok' => false, 'error' => $inputErr, 'data' => []];
    }

    $result = ama_verify_membership($amaNumber, $lastName);
    if (!($result['ok'] ?? false)) {
        return [
            'ok'    => false,
            'error' => (string) ($result['message'] ?? 'AMA membership could not be verified.'),
            'data'  => ['status' => $result['status'] ?? 'unknown'],
        ];
    }

    $expYmd = $result['expiration_ymd'] ?? null;
    $lifeMember = (bool) ($result['life_member'] ?? false);
    if (!$lifeMember && ($expYmd === null || $expYmd === '')) {
        return [
            'ok'    => false,
            'error' => 'AMA did not return a membership expiration date.',
            'data'  => [],
        ];
    }

    if (!membership_application_ama_meets_minimum_expiry($pdo, $expYmd, $lifeMember)) {
        $minLabel = membership_application_ama_minimum_expiry_label($pdo);

        return [
            'ok'    => false,
            'error' => 'AMA membership must be valid through at least ' . $minLabel . '.',
            'data'  => ['minimum_expiration' => membership_application_ama_minimum_expiry_ymd($pdo)],
        ];
    }

    $firstName = trim((string) ($result['first_name'] ?? ''));
    $verifiedLast = trim((string) ($result['last_name'] ?? $lastName));
    if ($firstName === '') {
        return ['ok' => false, 'error' => 'AMA did not return a member name.', 'data' => []];
    }

    $sessionData = [
        'ama_number'         => $amaNumber,
        'last_name'          => $verifiedLast,
        'first_name'         => $firstName,
        'ama_expiration_ymd' => $expYmd,
        'ama_expiration_mdy' => $result['expiration_mdy'] ?? membership_application_ymd_to_mdy($expYmd),
        'life_member'        => $lifeMember,
    ];
    $renewalEligibility = membership_application_renewal_eligibility($pdo, $amaNumber, $firstName, $verifiedLast);
    $sessionData['renewal_eligible'] = $renewalEligibility['eligible'];
    $sessionData['renewal_eligible_message'] = $renewalEligibility['message'];
    $sessionData['renewal_member_id'] = $renewalEligibility['member_id'];

    $clubMember = $amaNumber !== '' ? member_find_by_ama_number($pdo, $amaNumber) : null;
    $blockMessage = membership_application_club_member_apply_block_message($clubMember);
    if ($blockMessage !== null) {
        return ['ok' => false, 'error' => $blockMessage, 'data' => []];
    }

    $complimentaryLabels = [];
    if ($clubMember !== null) {
        if (!empty($clubMember['life_member'])) {
            $complimentaryLabels[] = 'life member';
        }
        if (!empty($clubMember['free_membership'])) {
            $complimentaryLabels[] = 'free membership';
        }
    }
    $sessionData['complimentary_member'] = $complimentaryLabels !== [];
    $sessionData['complimentary_member_detail'] = $complimentaryLabels !== [] ? implode(', ', $complimentaryLabels) : '';
    $sessionData['complimentary_member_id'] = $clubMember !== null ? (int) $clubMember['id'] : null;

    membership_application_ama_set_session($sessionData);

    return [
        'ok'    => true,
        'error' => null,
        'data'  => [
            'first_name'       => $firstName,
            'last_name'        => $verifiedLast,
            'ama_number'       => $amaNumber,
            'ama_expiration'   => $sessionData['ama_expiration_mdy'],
            'life_member'      => $lifeMember,
            'renewal_eligible' => $renewalEligibility['eligible'],
            'renewal_message'  => $renewalEligibility['message'],
            'complimentary_member' => $sessionData['complimentary_member'],
            'message'          => (string) ($result['message'] ?? 'AMA membership verified.'),
        ],
    ];
}

/**
 * Ensure posted AMA fields match a recent verification session.
 */
function membership_application_ama_assert_verified(array $clean): ?string
{
    $session = membership_application_ama_get_session();
    if ($session === null) {
        return 'AMA membership must be verified before you can submit the application.';
    }

    $postedAma = ama_verify_normalize_number((string) ($clean['ama_number'] ?? ''));
    if ($postedAma !== (string) $session['ama_number']) {
        return 'AMA verification does not match the submitted AMA number.';
    }

    $postedLast = ama_verify_normalize_last_name((string) ($clean['last_name'] ?? ''));
    $sessionLast = ama_verify_normalize_last_name((string) $session['last_name']);
    if (strcasecmp($postedLast, $sessionLast) !== 0) {
        return 'AMA verification does not match the submitted last name.';
    }

    $postedFirst = trim((string) ($clean['first_name'] ?? ''));
    if (strcasecmp($postedFirst, (string) $session['first_name']) !== 0) {
        return 'AMA verification does not match the submitted first name.';
    }

    $postedExp = (string) ($clean['ama_expiration'] ?? '');
    $sessionExp = (string) ($session['ama_expiration_ymd'] ?? '');
    if ($sessionExp !== '' && $postedExp !== $sessionExp) {
        return 'AMA verification does not match the submitted expiration date.';
    }

    return null;
}

/**
 * Coupon codes that waive online payment.
 *
 * @return array<string, array{waive_payment:bool,label?:string}>
 */
function membership_application_coupon_catalog(): array
{
    return [
        'TWRCLR593'   => ['waive_payment' => true],
        'CABIN100854' => ['waive_payment' => true],
        'GATEZERO377' => ['waive_payment' => true],
        'PAULTEST'    => ['waive_payment' => true],
    ];
}

function membership_application_normalize_coupon(?string $code): string
{
    return strtoupper(trim((string) $code));
}

/**
 * @return array{waive_payment:bool,label:?string}|null
 */
function membership_application_lookup_coupon(?string $code): ?array
{
    $code = membership_application_normalize_coupon($code);
    if ($code === '') {
        return null;
    }
    $catalog = membership_application_coupon_catalog();

    return $catalog[$code] ?? null;
}

/**
 * Whether the applicant qualifies for complimentary (zero-dollar) online payment.
 *
 * Priority: club member flag, then staff comp invite, then legacy coupon code.
 *
 * @return array{
 *   waive_payment: bool,
 *   reason: ?string,
 *   message: ?string,
 *   member_id: ?int,
 *   comp_invite_id: ?int,
 *   coupon: ?string,
 *   detail: ?string
 * }
 */
function membership_application_complimentary_status(
    PDO $pdo,
    ?string $amaNumber,
    ?string $email,
    ?string $couponCode = null,
    ?DateTimeInterface $now = null
): array {
    require_once __DIR__ . '/membership_comp_invites.php';

    $base = [
        'waive_payment'  => false,
        'reason'         => null,
        'message'        => null,
        'member_id'      => null,
        'comp_invite_id' => null,
        'coupon'         => null,
        'detail'         => null,
    ];

    $ama = ama_verify_normalize_number((string) $amaNumber);
    if ($ama !== '') {
        $member = member_find_by_ama_number($pdo, $ama);
        if ($member !== null && membership_application_club_member_apply_block_message($member) === null
            && (!empty($member['free_membership']) || !empty($member['life_member']))) {
            $labels = [];
            if (!empty($member['life_member'])) {
                $labels[] = 'life member';
            }
            if (!empty($member['free_membership'])) {
                $labels[] = 'free membership';
            }
            $base['waive_payment'] = true;
            $base['reason'] = 'member_flag';
            $base['member_id'] = (int) $member['id'];
            $base['detail'] = implode(', ', $labels);
            $base['message'] = 'Complimentary membership (' . $base['detail'] . ') — no online payment required.';

            return $base;
        }
    }

    $invite = membership_comp_invite_find_matching($pdo, $ama, (string) $email, $now);
    if ($invite !== null) {
        $base['waive_payment'] = true;
        $base['reason'] = 'comp_invite';
        $base['comp_invite_id'] = (int) $invite['id'];
        $typeLabel = membership_comp_invite_type_label($invite['membership_type'] ?? '');
        $base['detail'] = 'comp invite #' . (int) $invite['id'] . ' (' . $typeLabel . ')';
        $base['message'] = 'Complimentary membership invite on file (' . $typeLabel . ') — no online payment required.';

        return $base;
    }

    $coupon = membership_application_lookup_coupon($couponCode);
    $couponNormalized = membership_application_normalize_coupon($couponCode);
    if ($coupon !== null && !empty($coupon['waive_payment'])) {
        $base['waive_payment'] = true;
        $base['reason'] = 'coupon';
        $base['coupon'] = $couponNormalized !== '' ? $couponNormalized : null;
        $base['message'] = 'Coupon applied — no online payment required.';
    }

    return $base;
}

/**
 * Processing fee passed to applicant so club receives exact subtotal after Stripe fees.
 */
function membership_application_stripe_processing_fee(float $subtotal): float
{
    if ($subtotal <= 0) {
        return 0.0;
    }
    $gross = ($subtotal + MEMBERSHIP_STRIPE_FIXED) / (1 - MEMBERSHIP_STRIPE_PERCENT);

    return round($gross - $subtotal, 2);
}

/**
 * Whether renewal applications are accepted (renewal pre-book window).
 */
function membership_application_renewal_open(DateTimeInterface $now, PDO $pdo): bool
{
    $month = (int) $now->format('n');
    $day   = (int) $now->format('j');
    $startMonth = renewal_prebook_start_month($pdo);
    $startDay   = renewal_prebook_start_day($pdo);

    if ($month > $startMonth) {
        return true;
    }
    if ($month === $startMonth && $day >= $startDay) {
        return true;
    }

    return false;
}

/**
 * New-member season for a submission date.
 *
 * @return 'regular_new'|'prorated_new'|'renewal_window'
 */
function membership_application_new_member_season(DateTimeInterface $now, PDO $pdo): string
{
    $month = (int) $now->format('n');
    $day   = (int) $now->format('j');

    $rules = duesRules($pdo);
    $prorateStart = 7;
    $prorateEnd   = 10;
    foreach ($rules as $rule) {
        $prorateStart = (int) ($rule['prorate_start_month'] ?? $prorateStart);
        $prorateEnd   = (int) ($rule['prorate_end_month'] ?? $prorateEnd);
        break;
    }

    if ($month < $prorateStart) {
        return 'regular_new';
    }
    if ($month > $prorateEnd) {
        return membership_application_renewal_open($now, $pdo) ? 'renewal_window' : 'regular_new';
    }
    if ($month === $prorateEnd) {
        $startDay = renewal_prebook_start_day($pdo);
        if ($day >= $startDay) {
            return 'renewal_window';
        }
    }

    return 'prorated_new';
}

/**
 * Map application kind + season to calculateDues() renewal type.
 */
function membership_application_dues_renewal_type(string $kind, string $season): string
{
    if ($kind === 'renewal') {
        return 'on_time';
    }
    if ($season === 'prorated_new') {
        return 'new';
    }

    return 'late';
}

/**
 * Suggested staff renewal type after approval.
 */
function membership_application_suggested_renewal_type(string $kind, string $season): ?string
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
 * Working renewal year for an application submitted at $now.
 */
function membership_application_suggested_renewal_year(PDO $pdo, DateTimeInterface $now): int
{
    $month = (int) $now->format('n');
    $day   = (int) $now->format('j');
    $year  = (int) $now->format('Y');
    $startMonth = renewal_prebook_start_month($pdo);
    $startDay   = renewal_prebook_start_day($pdo);
    $rolledOver = ($month > $startMonth) || ($month === $startMonth && $day >= $startDay);

    return $rolledOver ? $year + 1 : $year;
}

/**
 * Page context: available application kinds and membership type pricing.
 *
 * @return array{
 *   renewal_open: bool,
 *   default_kind: string,
 *   season: string,
 *   renewal_year: int,
 *   membership_types: list<array{slot:int,label:string,dues:float,initiation:float,renewal_dues:float}>
 * }
 */
function membership_application_context(PDO $pdo, ?DateTimeInterface $now = null): array
{
    $now = $now ?? new DateTimeImmutable('now');
    $renewalOpen = membership_application_renewal_open($now, $pdo);
    $season = membership_application_new_member_season($now, $pdo);
    $defaultKind = $renewalOpen ? 'renewal' : 'new';
    $rules = duesRules($pdo);
    $labels = enabledMembershipTypeLabels($pdo);
    $newRenewalType = membership_application_dues_renewal_type('new', $season);

    $types = [];
    foreach ($labels as $slot => $label) {
        $newCalc = calculateDues($pdo, (int) $slot, $newRenewalType, $rules);
        $renewalCalc = calculateDues($pdo, (int) $slot, 'on_time', $rules);
        $types[] = [
            'slot'         => (int) $slot,
            'label'        => $label,
            'dues'         => round($newCalc['dues'], 2),
            'initiation'   => round($newCalc['init'], 2),
            'renewal_dues' => round($renewalCalc['dues'], 2),
        ];
    }

    return [
        'renewal_open'     => $renewalOpen,
        'default_kind'     => $defaultKind,
        'season'           => $season,
        'renewal_year'     => membership_application_suggested_renewal_year($pdo, $now),
        'membership_types' => $types,
    ];
}

/**
 * Fee breakdown for a submission.
 *
 * @return array{
 *   kind: string,
 *   season: string,
 *   slot: int,
 *   dues: float,
 *   initiation: float,
 *   processing_fee: float,
 *   subtotal: float,
 *   total: float,
 *   coupon: ?string,
 *   coupon_applied: bool,
 *   waive_payment: bool,
 *   complimentary_reason: ?string,
 *   complimentary_message: ?string,
 *   comp_invite_id: ?int,
 *   renewal_type: string,
 *   renewal_year: int
 * }
 */
function membership_application_quote(
    PDO $pdo,
    string $kind,
    int $slot,
    ?string $couponCode = null,
    ?DateTimeInterface $now = null,
    ?array $applicant = null
): array {
    $now = $now ?? new DateTimeImmutable('now');
    $kind = strtolower(trim($kind));
    if (!in_array($kind, ['new', 'renewal'], true)) {
        $kind = 'new';
    }
    if ($kind === 'renewal' && !membership_application_renewal_open($now, $pdo)) {
        $kind = 'new';
    }

    $season = $kind === 'renewal' ? 'renewal_window' : membership_application_new_member_season($now, $pdo);
    $renewalType = membership_application_dues_renewal_type($kind, $season);
    $calc = calculateDues($pdo, $slot, $renewalType);
    $dues = round($calc['dues'], 2);
    $initiation = round($calc['init'], 2);
    $subtotal = round($dues + $initiation, 2);

    $amaNumber = (string) ($applicant['ama_number'] ?? '');
    $email = (string) ($applicant['email'] ?? '');
    if ($amaNumber === '') {
        $amaSession = membership_application_ama_get_session();
        if ($amaSession !== null) {
            $amaNumber = (string) ($amaSession['ama_number'] ?? '');
        }
    }

    $complimentary = membership_application_complimentary_status($pdo, $amaNumber, $email, $couponCode, $now);
    $waive = $complimentary['waive_payment'];
    $processing = $waive ? 0.0 : membership_application_stripe_processing_fee($subtotal);
    $total = $waive ? 0.0 : round($subtotal + $processing, 2);
    $couponNormalized = membership_application_normalize_coupon($couponCode);

    return [
        'kind'                   => $kind,
        'season'                 => $season,
        'slot'                   => $slot,
        'dues'                   => $dues,
        'initiation'             => $initiation,
        'processing_fee'         => $processing,
        'subtotal'               => $subtotal,
        'total'                  => $total,
        'coupon'                 => $complimentary['coupon'] ?? ($couponNormalized !== '' ? $couponNormalized : null),
        'coupon_applied'         => $waive && ($complimentary['reason'] ?? '') === 'coupon',
        'waive_payment'          => $waive,
        'complimentary_reason'   => $complimentary['reason'],
        'complimentary_message'  => $complimentary['message'],
        'complimentary_detail'   => $complimentary['detail'],
        'comp_invite_id'         => $complimentary['comp_invite_id'],
        'renewal_type'           => membership_application_suggested_renewal_type($kind, $season) ?? 'new',
        'renewal_year'           => membership_application_suggested_renewal_year($pdo, $now),
    ];
}

/**
 * HMAC token for public confirmation links.
 */
function membership_application_confirmation_token(int $applicationId, string $secret): string
{
    return hash_hmac('sha256', (string) $applicationId, $secret);
}

function membership_application_verify_confirmation_token(int $applicationId, string $token, string $secret): bool
{
    $expected = membership_application_confirmation_token($applicationId, $secret);

    return $token !== '' && hash_equals($expected, $token);
}

/**
 * Signing secret for confirmation URLs and signed tokens.
 */
function membership_application_signing_secret(PDO $pdo): string
{
    require_once __DIR__ . '/app_signing_secret.php';

    return app_signing_secret($pdo);
}

/**
 * @return array{errors: array<string,string>, clean: array<string,mixed>}
 */
function membership_application_validate_input(PDO $pdo, array $post, array $files, array $context): array
{
    $errors = [];
    $clean  = [];

    $amaGateError = membership_application_ama_assert_verified([
        'ama_number'     => $post['ama_number'] ?? '',
        'last_name'      => $post['last_name'] ?? '',
        'first_name'     => $post['first_name'] ?? '',
        'ama_expiration' => parseDateForDb(trim((string) ($post['ama_expiration'] ?? ''))),
    ]);
    if ($amaGateError !== null) {
        $errors['ama_verify'] = $amaGateError;
    }

    $clean['first_name'] = trim((string) ($post['first_name'] ?? ''));
    if ($clean['first_name'] === '') {
        $errors['first_name'] = 'First name is required.';
    }
    $clean['middle_name'] = trim((string) ($post['middle_name'] ?? ''));
    $clean['last_name'] = trim((string) ($post['last_name'] ?? ''));
    if ($clean['last_name'] === '') {
        $errors['last_name'] = 'Last name is required.';
    }

    $rawEmail = trim((string) ($post['email'] ?? ''));
    [$emailOk, $emailErr] = validate_email($rawEmail);
    if (!$emailOk) {
        $errors['email'] = $emailErr;
    } else {
        $clean['email'] = normalize_email($rawEmail);
    }

    $clean['phone'] = trim((string) ($post['phone'] ?? ''));
    if ($clean['phone'] === '') {
        $errors['phone'] = 'Phone is required.';
    }

    foreach (['address_street' => 'Street address', 'address_city' => 'City', 'address_state' => 'State', 'address_postal_code' => 'ZIP code'] as $field => $label) {
        $clean[$field] = trim((string) ($post[$field] ?? ''));
        if ($clean[$field] === '') {
            $errors[$field] = $label . ' is required.';
        }
    }
    $clean['address_street2'] = trim((string) ($post['address_street2'] ?? ''));

    $rawBirthday = trim((string) ($post['birthday'] ?? ''));
    $parsedBirthday = parseDateForDb($rawBirthday);
    if ($parsedBirthday === null) {
        $errors['birthday'] = $rawBirthday === '' ? 'Date of birth is required.' : 'Enter a valid date of birth (MM/DD/YYYY).';
    } else {
        $clean['birthday'] = $parsedBirthday;
    }

    $clean['emergency_contact_name'] = trim((string) ($post['emergency_contact_name'] ?? ''));
    $clean['emergency_contact_relationship'] = trim((string) ($post['emergency_contact_relationship'] ?? ''));
    $clean['emergency_contact_phone'] = trim((string) ($post['emergency_contact_phone'] ?? ''));

    $clean['ama_number'] = ama_verify_normalize_number(trim((string) ($post['ama_number'] ?? '')));
    if ($clean['ama_number'] === '') {
        $errors['ama_number'] = 'AMA number is required.';
    } else {
        $clubMember = member_find_by_ama_number($pdo, $clean['ama_number']);
        $blockMessage = membership_application_club_member_apply_block_message($clubMember);
        if ($blockMessage !== null) {
            $errors['ama_number'] = $blockMessage;
        }
    }

    $rawAmaExp = trim((string) ($post['ama_expiration'] ?? ''));
    $parsedAmaExp = parseDateForDb($rawAmaExp);
    if ($parsedAmaExp === null) {
        $errors['ama_expiration'] = $rawAmaExp === '' ? 'AMA expiration is required.' : 'Enter a valid AMA expiration (MM/DD/YYYY).';
    } else {
        $clean['ama_expiration'] = $parsedAmaExp;
    }

    $clean['faa_number'] = trim((string) ($post['faa_number'] ?? ''));
    if ($clean['faa_number'] === '') {
        $errors['faa_number'] = 'FAA registration number is required.';
    }

    $rawFaaExp = trim((string) ($post['faa_expiration'] ?? ''));
    $parsedFaaExp = parseDateForDb($rawFaaExp);
    if ($parsedFaaExp === null) {
        $errors['faa_expiration'] = $rawFaaExp === '' ? 'FAA registration expiration is required.' : 'Enter a valid FAA expiration (MM/DD/YYYY).';
    } else {
        $clean['faa_expiration'] = $parsedFaaExp;
        if (strtotime($parsedFaaExp) < strtotime('today')) {
            $errors['faa_expiration'] = 'FAA registration must not be expired.';
        }
    }

    $kind = strtolower(trim((string) ($post['application_kind'] ?? '')));
    if (!in_array($kind, ['new', 'renewal'], true)) {
        $errors['application_kind'] = 'Select new member or renewal.';
    } elseif ($kind === 'renewal' && empty($context['renewal_open'])) {
        $errors['application_kind'] = 'Renewals are not open yet for this season.';
    } elseif ($kind === 'renewal') {
        $amaSession = membership_application_ama_get_session();
        $renewalCheck = membership_application_renewal_eligibility(
            $pdo,
            (string) ($post['ama_number'] ?? $amaSession['ama_number'] ?? ''),
            (string) ($post['first_name'] ?? $amaSession['first_name'] ?? ''),
            (string) ($post['last_name'] ?? $amaSession['last_name'] ?? '')
        );
        if (!$renewalCheck['eligible']) {
            $errors['application_kind'] = $renewalCheck['message'];
        } else {
            $clean['application_kind'] = $kind;
        }
    } else {
        $clean['application_kind'] = $kind;
    }

    $slot = (int) ($post['membership_type_slot'] ?? 0);
    $enabled = enabledMembershipTypeLabels($pdo);
    if ($slot < 1 || $slot > 4 || !isset($enabled[$slot])) {
        $errors['membership_type_slot'] = 'Select a membership type.';
    } else {
        $clean['membership_type_slot'] = $slot;
    }

    if (empty($post['terms'])) {
        $errors['terms'] = 'You must agree to the club terms.';
    }

    if (($clean['application_kind'] ?? '') === 'new') {
        if (empty($files['badge_photo']['tmp_name']) || !is_uploaded_file((string) $files['badge_photo']['tmp_name'])) {
            $errors['badge_photo'] = 'Badge photo is required for new members.';
        }
    }

    if (empty($files['faa_card']['tmp_name']) || !is_uploaded_file((string) $files['faa_card']['tmp_name'])) {
        $errors['faa_card'] = 'FAA registration file is required.';
    }

    $signature = trim((string) ($post['signature_data'] ?? ''));
    if ($signature === '' || !str_starts_with($signature, 'data:image/')) {
        $errors['signature'] = 'Signature is required.';
    } else {
        $clean['signature_data'] = $signature;
    }

    $clean['coupon_code'] = membership_application_normalize_coupon($post['coupon_code'] ?? '');

    $clean['email_opt_in_club_events'] = email_opt_in_from_post($post['email_opt_in_club_events'] ?? null);
    $clean['email_opt_in_expiry_reminders'] = email_opt_in_from_post($post['email_opt_in_expiry_reminders'] ?? null);

    return ['errors' => $errors, 'clean' => $clean];
}

/**
 * @return array{ok:bool, error:?string, relative_path:?string}
 */
function membership_application_save_uploaded_image(array $file, string $destPath, array $allowedMimes, int $maxBytes = 5242880): array
{
    if (empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
        return ['ok' => false, 'error' => 'No file uploaded.', 'relative_path' => null];
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        return ['ok' => false, 'error' => 'File exceeds the 5 MB size limit.', 'relative_path' => null];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string) $file['tmp_name']);
    if (!isset($allowedMimes[$mime])) {
        return ['ok' => false, 'error' => 'Invalid file type.', 'relative_path' => null];
    }

    $dir = dirname($destPath);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Could not create upload directory.', 'relative_path' => null];
    }

    if (!move_uploaded_file((string) $file['tmp_name'], $destPath)) {
        return ['ok' => false, 'error' => 'Could not save uploaded file.', 'relative_path' => null];
    }

    return ['ok' => true, 'error' => null, 'relative_path' => null];
}

/**
 * @return array{ok:bool, error:?string, relative_path:?string}
 */
function membership_application_save_signature_png(string $dataUrl, string $destPath): array
{
    if (!preg_match('#^data:image/(png|jpeg);base64,(.+)$#', $dataUrl, $m)) {
        return ['ok' => false, 'error' => 'Invalid signature data.', 'relative_path' => null];
    }
    $binary = base64_decode($m[2], true);
    if ($binary === false || strlen($binary) > 5242880) {
        return ['ok' => false, 'error' => 'Invalid signature image.', 'relative_path' => null];
    }

    $dir = dirname($destPath);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Could not create upload directory.', 'relative_path' => null];
    }
    if (file_put_contents($destPath, $binary) === false) {
        return ['ok' => false, 'error' => 'Could not save signature.', 'relative_path' => null];
    }

    return ['ok' => true, 'error' => null, 'relative_path' => null];
}

function membership_application_uploads_dir(int $applicationId): string
{
    return dirname(__DIR__) . '/uploads/applications/' . $applicationId;
}

function membership_application_relative_upload_path(int $applicationId, string $filename): string
{
    return 'uploads/applications/' . $applicationId . '/' . $filename;
}

function membership_application_absolute_upload_path(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if (str_contains($relativePath, '..')) {
        return '';
    }

    return dirname(__DIR__) . '/' . $relativePath;
}

function membership_application_is_local_upload_path(string $path): bool
{
    $path = ltrim(str_replace('\\', '/', trim($path)), '/');

    return str_starts_with($path, 'uploads/applications/');
}

/** Staff URL to view a stored application file (local uploads are not web-public). */
function application_file_serve_url(int $applicationId, string $kind): string
{
    return 'application_file.php?application_id=' . $applicationId . '&kind=' . rawurlencode($kind);
}

/**
 * Link for an application attachment — local path or legacy external URL.
 */
function application_file_href(array $application, string $kind): string
{
    $column = match ($kind) {
        'badge'     => 'file_badge_photo_url',
        'faa'       => 'file_faa_registration_url',
        'signature' => 'file_signature_url',
        'ama'       => 'file_ama_verification_url',
        default     => '',
    };
    if ($column === '') {
        return '';
    }
    $path = trim((string) ($application[$column] ?? ''));
    if ($path === '') {
        return '';
    }
    if (membership_application_is_local_upload_path($path)) {
        return application_file_serve_url((int) ($application['id'] ?? 0), $kind);
    }

    return $path;
}

/**
 * Resolve absolute filesystem path for a stored application file kind.
 *
 * @return array{ok:bool, path:?string, mime:?string, filename:?string}
 */
function application_file_resolve(PDO $pdo, int $applicationId, string $kind): array
{
    require_once __DIR__ . '/member_applications.php';

    $app = application_fetch($pdo, $applicationId);
    if ($app === null) {
        return ['ok' => false, 'path' => null, 'mime' => null, 'filename' => null];
    }

    $column = match ($kind) {
        'badge'     => 'file_badge_photo_url',
        'faa'       => 'file_faa_registration_url',
        'signature' => 'file_signature_url',
        'ama'       => 'file_ama_verification_url',
        default     => '',
    };
    if ($column === '') {
        return ['ok' => false, 'path' => null, 'mime' => null, 'filename' => null];
    }

    $relative = trim((string) ($app[$column] ?? ''));
    if ($relative === '') {
        return ['ok' => false, 'path' => null, 'mime' => null, 'filename' => null];
    }

    if (!membership_application_is_local_upload_path($relative)) {
        return ['ok' => false, 'path' => null, 'mime' => null, 'filename' => null];
    }

    $absolute = membership_application_absolute_upload_path($relative);
    $uploadsBase = realpath(dirname(__DIR__) . '/uploads');
    $resolved = $absolute !== '' ? realpath($absolute) : false;
    if (
        $resolved === false
        || $uploadsBase === false
        || !is_file($resolved)
        || !is_readable($resolved)
        || !str_starts_with($resolved, $uploadsBase . DIRECTORY_SEPARATOR)
    ) {
        return ['ok' => false, 'path' => null, 'mime' => null, 'filename' => null];
    }

    $ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
    $mimes = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'pdf'  => 'application/pdf',
    ];

    return [
        'ok'       => true,
        'path'     => $resolved,
        'mime'     => $mimes[$ext] ?? 'application/octet-stream',
        'filename' => 'application_' . $applicationId . '_' . $kind . ($ext ? '.' . $ext : ''),
    ];
}

/**
 * Ensure member_applications.status accepts pending_payment (idempotent).
 */
function membership_application_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM member_applications LIKE 'status'");
        $col = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if ($col && isset($col['Type']) && !str_contains((string) $col['Type'], 'pending_payment')) {
            $pdo->exec("ALTER TABLE member_applications MODIFY status ENUM('pending_payment','pending','approved','rejected') NOT NULL DEFAULT 'pending'");
        }
    } catch (Throwable $e) {
        // Table may not exist on very old installs.
    }

    membership_application_ensure_email_opt_in_schema($pdo);
}

/**
 * Idempotent columns for optional email opt-in on applications and members.
 */
function membership_application_ensure_email_opt_in_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $columns = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM member_applications');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[(string) ($row['Field'] ?? '')] = true;
        }
        if (!isset($columns['email_opt_in_club_events'])) {
            $pdo->exec("ALTER TABLE member_applications ADD COLUMN email_opt_in_club_events tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Applicant opted in to club event/announcement emails' AFTER email");
        }
        if (!isset($columns['email_opt_in_expiry_reminders'])) {
            $pdo->exec("ALTER TABLE member_applications ADD COLUMN email_opt_in_expiry_reminders tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Applicant opted in to AMA/FAA expiry reminder emails' AFTER email_opt_in_club_events");
        }
    } catch (Throwable $e) {
    }

    try {
        $columns = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM members');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[(string) ($row['Field'] ?? '')] = true;
        }
        if (!isset($columns['email_opt_in_club_events'])) {
            $pdo->exec("ALTER TABLE members ADD COLUMN email_opt_in_club_events tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Club event/announcement emails (Sender campaign channel)' AFTER email");
        }
        if (!isset($columns['email_opt_in_expiry_reminders'])) {
            $pdo->exec("ALTER TABLE members ADD COLUMN email_opt_in_expiry_reminders tinyint(1) NOT NULL DEFAULT 1 COMMENT 'AMA/FAA expiry reminder emails (Sender transactional channel)' AFTER email_opt_in_club_events");
        }
    } catch (Throwable $e) {
    }
}

/**
 * Sync application email preferences to Sender.net (best-effort; logs warnings).
 */
function membership_application_sync_sender_email_preferences(PDO $pdo, array $application, string $phase): void
{
    require_once __DIR__ . '/sender_net.php';
    require_once __DIR__ . '/app_log.php';

    $email = trim((string) ($application['email'] ?? ''));
    if ($email === '') {
        return;
    }

    $senderCfg = sender_net_load_config($pdo);
    if (!sender_net_is_configured($senderCfg)) {
        return;
    }

    $firstName = trim((string) ($application['first_name'] ?? ''));
    $lastName = trim((string) ($application['last_name'] ?? ''));
    $clubEvents = !empty($application['email_opt_in_club_events']);
    $expiryReminders = !empty($application['email_opt_in_expiry_reminders']);

    if ($phase === 'submit' && $clubEvents) {
        $result = sender_net_subscribe_club_events($email, $firstName, $lastName, $senderCfg);
        if (!$result['ok'] && empty($result['skipped'])) {
            flightops_log('WARN', 'membership_application: Sender club events subscribe failed', [
                'email' => $email,
                'error' => $result['error'],
            ], 'web');
        }
    }

    if ($phase === 'approve') {
        if ($clubEvents) {
            $result = sender_net_subscribe_club_events($email, $firstName, $lastName, $senderCfg);
            if (!$result['ok'] && empty($result['skipped'])) {
                flightops_log('WARN', 'membership_application: Sender club events subscribe failed on approve', [
                    'email' => $email,
                    'error' => $result['error'],
                ], 'web');
            }
        }

        $result = sender_net_apply_expiry_reminder_preference(
            $email,
            $firstName,
            $lastName,
            $senderCfg,
            $expiryReminders
        );
        if (!$result['ok'] && empty($result['skipped'])) {
            flightops_log('WARN', 'membership_application: Sender expiry reminder preference failed', [
                'email'          => $email,
                'opt_in'         => $expiryReminders,
                'error'          => $result['error'],
            ], 'web');
        }
    }
}

/**
 * Persist uploads for an application row.
 *
 * @return array{ok:bool, errors: array<string,string>, paths: array<string,?string>}
 */
function membership_application_store_files(PDO $pdo, int $applicationId, array $clean, array $files, string $kind): array
{
    require_once __DIR__ . '/member_save.php';

    $errors = [];
    $paths = [
        'file_badge_photo_url'      => null,
        'file_faa_registration_url' => null,
        'file_signature_url'        => null,
    ];
    $baseDir = membership_application_uploads_dir($applicationId);
    $photoMimes = member_photo_allowed_mimes();
    $faaMimes = member_faa_card_allowed_mimes();

    if ($kind === 'new' && !empty($files['badge_photo']['tmp_name'])) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file((string) $files['badge_photo']['tmp_name']);
        $ext = $photoMimes[$mime] ?? 'jpg';
        $dest = $baseDir . '/badge.' . $ext;
        $saved = membership_application_save_uploaded_image($files['badge_photo'], $dest, $photoMimes);
        if (!$saved['ok']) {
            $errors['badge_photo'] = $saved['error'] ?? 'Could not save badge photo.';
        } else {
            $paths['file_badge_photo_url'] = membership_application_relative_upload_path($applicationId, 'badge.' . $ext);
        }
    }

    if (!empty($files['faa_card']['tmp_name'])) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file((string) $files['faa_card']['tmp_name']);
        $ext = $faaMimes[$mime] ?? 'jpg';
        $dest = $baseDir . '/faa.' . $ext;
        $saved = membership_application_save_uploaded_image($files['faa_card'], $dest, $faaMimes);
        if (!$saved['ok']) {
            $errors['faa_card'] = $saved['error'] ?? 'Could not save FAA registration.';
        } else {
            $paths['file_faa_registration_url'] = membership_application_relative_upload_path($applicationId, 'faa.' . $ext);
        }
    }

    if (!empty($clean['signature_data'])) {
        $dest = $baseDir . '/signature.png';
        $saved = membership_application_save_signature_png((string) $clean['signature_data'], $dest);
        if (!$saved['ok']) {
            $errors['signature'] = $saved['error'] ?? 'Could not save signature.';
        } else {
            $paths['file_signature_url'] = membership_application_relative_upload_path($applicationId, 'signature.png');
        }
    }

    return ['ok' => $errors === [], 'errors' => $errors, 'paths' => $paths];
}

/**
 * @return array{
 *   ok: bool,
 *   application_id: ?int,
 *   client_secret: ?string,
 *   confirmation_token: ?string,
 *   errors: array<string,string>,
 *   error: ?string,
 *   waive_payment: bool
 * }
 */
function membership_application_submit(PDO $pdo, array $post, array $files, ?DateTimeInterface $now = null): array
{
    membership_application_ensure_schema($pdo);
    require_once __DIR__ . '/member_applications.php';
    require_once __DIR__ . '/member_match.php';
    require_once __DIR__ . '/stripe_config.php';

    $now = $now ?? new DateTimeImmutable('now');
    $context = membership_application_context($pdo, $now);
    $validated = membership_application_validate_input($pdo, $post, $files, $context);
    if ($validated['errors'] !== []) {
        return [
            'ok'                 => false,
            'application_id'     => null,
            'client_secret'      => null,
            'confirmation_token' => null,
            'errors'             => $validated['errors'],
            'error'              => 'Please correct the highlighted fields.',
            'waive_payment'      => false,
        ];
    }

    $clean = $validated['clean'];
    $kind = (string) $clean['application_kind'];
    $slot = (int) $clean['membership_type_slot'];
    $quote = membership_application_quote($pdo, $kind, $slot, $clean['coupon_code'], $now, [
        'ama_number' => $clean['ama_number'],
        'email'      => $clean['email'],
    ]);

    $notes = [];
    if ($clean['middle_name'] !== '') {
        $notes[] = 'Middle name: ' . $clean['middle_name'];
    }
    if ($quote['coupon'] !== null && ($quote['complimentary_reason'] ?? '') === 'coupon') {
        $notes[] = 'Coupon code: ' . $quote['coupon'];
    }
    if (($quote['complimentary_reason'] ?? '') === 'member_flag') {
        $detail = trim((string) ($quote['complimentary_detail'] ?? 'complimentary member'));
        $notes[] = 'Complimentary: ' . $detail . ' (member record)';
    }
    if (($quote['complimentary_reason'] ?? '') === 'comp_invite' && !empty($quote['comp_invite_id'])) {
        $inviteId = (int) $quote['comp_invite_id'];
        $typeLabel = '';
        if (preg_match('/\((free membership|life member)\)\s*$/i', (string) ($quote['complimentary_detail'] ?? ''), $m)) {
            $typeLabel = strtolower($m[1]);
        }
        $notes[] = 'Complimentary invite #' . $inviteId . ($typeLabel !== '' ? ' (' . $typeLabel . ')' : '');
    }

    $submissionRef = 'native-' . bin2hex(random_bytes(16));
    $submittedAt = $now->format('Y-m-d H:i:s');
    $status = $quote['waive_payment'] ? 'pending' : 'pending_payment';
    $paymentStatus = $quote['waive_payment'] ? 'waived' : 'pending';

    $match = member_match_find(
        $pdo,
        $clean['ama_number'],
        $clean['first_name'],
        $clean['last_name'],
        $clean['email'],
        $clean['birthday']
    );

    $applicationRow = [
        'status'                       => $status,
        'wpforms_entry_id'             => $submissionRef,
        'submitted_at'                 => $submittedAt,
        'application_kind'             => $kind,
        'form_season'                  => $quote['season'],
        'suggested_renewal_type'       => $quote['renewal_type'],
        'suggested_renewal_year'       => $quote['renewal_year'],
        'matched_member_id'            => $match['member_id'],
        'match_confidence'             => $match['confidence'] !== 'none' ? $match['confidence'] : null,
        'match_method'                 => $match['method'],
        'first_name'                   => $clean['first_name'],
        'last_name'                    => $clean['last_name'],
        'middle_name'                  => $clean['middle_name'] !== '' ? $clean['middle_name'] : null,
        'email'                        => $clean['email'],
        'email_opt_in_club_events'     => (int) $clean['email_opt_in_club_events'],
        'email_opt_in_expiry_reminders'=> (int) $clean['email_opt_in_expiry_reminders'],
        'birthday'                     => $clean['birthday'],
        'phone'                        => $clean['phone'],
        'emergency_contact_name'       => $clean['emergency_contact_name'] !== '' ? $clean['emergency_contact_name'] : null,
        'emergency_contact_relationship' => $clean['emergency_contact_relationship'] !== '' ? $clean['emergency_contact_relationship'] : null,
        'emergency_contact_phone'      => $clean['emergency_contact_phone'] !== '' ? $clean['emergency_contact_phone'] : null,
        'address_street'               => $clean['address_street'],
        'address_street2'              => $clean['address_street2'] !== '' ? $clean['address_street2'] : null,
        'address_city'                 => $clean['address_city'],
        'address_state'                => $clean['address_state'],
        'address_postal_code'          => $clean['address_postal_code'],
        'ama_number'                   => $clean['ama_number'],
        'ama_expiration'               => $clean['ama_expiration'],
        'faa_number'                   => $clean['faa_number'],
        'faa_expiration'               => $clean['faa_expiration'],
        'membership_type_slot'         => $slot,
        'notes'                        => $notes !== [] ? implode("\n", $notes) : null,
        'payment_total'                => $quote['total'],
        'payment_initiation'           => $quote['initiation'],
        'payment_processing_fee'       => $quote['processing_fee'],
        'payment_gateway'              => $quote['waive_payment'] ? null : 'Stripe',
        'payment_status'               => $paymentStatus,
        'raw_payload'                  => json_encode(['source' => 'native', 'quote' => $quote], JSON_UNESCAPED_UNICODE),
    ];

    $verification = application_renewal_verification($pdo, $applicationRow);
    if ($verification['adjusted_renewal_type'] !== null) {
        $applicationRow['suggested_renewal_type'] = $verification['adjusted_renewal_type'];
    }

    $stmt = $pdo->prepare('
        INSERT INTO member_applications (
            status, wpforms_entry_id, wpforms_form_id, submitted_at,
            application_kind, form_season, suggested_renewal_type, suggested_renewal_year,
            matched_member_id, match_confidence, match_method,
            first_name, last_name, middle_name, email, email_opt_in_club_events, email_opt_in_expiry_reminders,
            birthday, phone,
            emergency_contact_name, emergency_contact_relationship, emergency_contact_phone,
            address_street, address_street2, address_city, address_state, address_postal_code,
            ama_number, ama_expiration, faa_number, faa_expiration, membership_type_slot, notes,
            payment_total, payment_initiation, payment_processing_fee,
            payment_gateway, payment_transaction_id, payment_status,
            file_ama_verification_url, file_faa_registration_url, file_badge_photo_url, file_signature_url,
            raw_payload
        ) VALUES (
            ?, ?, NULL, ?,
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?,
            ?, NULL, ?,
            NULL, NULL, NULL, NULL,
            ?
        )
    ');

    $stmt->execute([
        $applicationRow['status'],
        $applicationRow['wpforms_entry_id'],
        $applicationRow['submitted_at'],
        $applicationRow['application_kind'],
        $applicationRow['form_season'],
        $applicationRow['suggested_renewal_type'],
        $applicationRow['suggested_renewal_year'],
        $applicationRow['matched_member_id'],
        $applicationRow['match_confidence'],
        $applicationRow['match_method'],
        $applicationRow['first_name'],
        $applicationRow['last_name'],
        $applicationRow['middle_name'],
        $applicationRow['email'],
        $applicationRow['email_opt_in_club_events'],
        $applicationRow['email_opt_in_expiry_reminders'],
        $applicationRow['birthday'],
        $applicationRow['phone'],
        $applicationRow['emergency_contact_name'],
        $applicationRow['emergency_contact_relationship'],
        $applicationRow['emergency_contact_phone'],
        $applicationRow['address_street'],
        $applicationRow['address_street2'],
        $applicationRow['address_city'],
        $applicationRow['address_state'],
        $applicationRow['address_postal_code'],
        $applicationRow['ama_number'],
        $applicationRow['ama_expiration'],
        $applicationRow['faa_number'],
        $applicationRow['faa_expiration'],
        $applicationRow['membership_type_slot'],
        $applicationRow['notes'],
        $applicationRow['payment_total'],
        $applicationRow['payment_initiation'],
        $applicationRow['payment_processing_fee'],
        $applicationRow['payment_gateway'],
        $applicationRow['payment_status'],
        $applicationRow['raw_payload'],
    ]);

    $applicationId = (int) $pdo->lastInsertId();
    $fileResult = membership_application_store_files($pdo, $applicationId, $clean, $files, $kind);
    if (!$fileResult['ok']) {
        $pdo->prepare('DELETE FROM member_applications WHERE id = ?')->execute([$applicationId]);

        return [
            'ok'                 => false,
            'application_id'     => null,
            'client_secret'      => null,
            'confirmation_token' => null,
            'errors'             => $fileResult['errors'],
            'error'              => 'Could not save uploaded files.',
            'waive_payment'      => false,
        ];
    }

    $pdo->prepare('
        UPDATE member_applications SET
            file_badge_photo_url = ?,
            file_faa_registration_url = ?,
            file_signature_url = ?
        WHERE id = ?
    ')->execute([
        $fileResult['paths']['file_badge_photo_url'],
        $fileResult['paths']['file_faa_registration_url'],
        $fileResult['paths']['file_signature_url'],
        $applicationId,
    ]);

    $secret = membership_application_signing_secret($pdo);
    $confirmToken = membership_application_confirmation_token($applicationId, $secret);

    if ($quote['waive_payment']) {
        if (($quote['complimentary_reason'] ?? '') === 'comp_invite' && !empty($quote['comp_invite_id'])) {
            require_once __DIR__ . '/membership_comp_invites.php';
            if (!membership_comp_invite_redeem($pdo, (int) $quote['comp_invite_id'], $applicationId)) {
                $pdo->prepare('DELETE FROM member_applications WHERE id = ?')->execute([$applicationId]);

                return [
                    'ok'                 => false,
                    'application_id'     => null,
                    'client_secret'      => null,
                    'confirmation_token' => null,
                    'errors'             => [],
                    'error'              => 'Your complimentary membership invite is no longer available. Contact the club membership team.',
                    'waive_payment'      => false,
                ];
            }
        }

        membership_application_finalize_submission($pdo, $applicationId, null);

        return [
            'ok'                 => true,
            'application_id'     => $applicationId,
            'client_secret'      => null,
            'confirmation_token' => $confirmToken,
            'errors'             => [],
            'error'              => null,
            'waive_payment'      => true,
        ];
    }

    if (!stripe_is_configured($pdo)) {
        $pdo->prepare('DELETE FROM member_applications WHERE id = ?')->execute([$applicationId]);

        return [
            'ok'                 => false,
            'application_id'     => $applicationId,
            'client_secret'      => null,
            'confirmation_token' => null,
            'errors'             => [],
            'error'              => 'Online payment is not configured. Contact the club.',
            'waive_payment'      => false,
        ];
    }

    $client = stripe_client($pdo);
    if ($client === null) {
        $pdo->prepare('DELETE FROM member_applications WHERE id = ?')->execute([$applicationId]);

        return [
            'ok'                 => false,
            'application_id'     => $applicationId,
            'client_secret'      => null,
            'confirmation_token' => null,
            'errors'             => [],
            'error'              => 'Payment system unavailable.',
            'waive_payment'      => false,
        ];
    }

    $amountCents = (int) round($quote['total'] * 100);
    if ($amountCents < 50) {
        membership_application_finalize_submission($pdo, $applicationId, null);

        return [
            'ok'                 => true,
            'application_id'     => $applicationId,
            'client_secret'      => null,
            'confirmation_token' => $confirmToken,
            'errors'             => [],
            'error'              => null,
            'waive_payment'      => true,
        ];
    }

    try {
        $intent = $client->paymentIntents->create([
            'amount'               => $amountCents,
            'currency'             => 'usd',
            'automatic_payment_methods' => ['enabled' => true],
            'metadata'             => [
                'application_id' => (string) $applicationId,
                'submission_ref' => $submissionRef,
            ],
            'receipt_email'        => $clean['email'],
            'description'          => 'Membership Dues',
        ]);
    } catch (Throwable $e) {
        error_log('membership_application_submit stripe error: ' . $e->getMessage());

        return [
            'ok'                 => false,
            'application_id'     => $applicationId,
            'client_secret'      => null,
            'confirmation_token' => null,
            'errors'             => [],
            'error'              => 'Could not start payment. Try again or contact the club.',
            'waive_payment'      => false,
        ];
    }

    $pdo->prepare('UPDATE member_applications SET payment_transaction_id = ? WHERE id = ?')
        ->execute([$intent->id ?? '', $applicationId]);

    return [
        'ok'                 => true,
        'application_id'     => $applicationId,
        'client_secret'      => $intent->client_secret ?? null,
        'confirmation_token' => $confirmToken,
        'errors'             => [],
        'error'              => null,
        'waive_payment'      => false,
    ];
}

/**
 * Mark application paid / ready for staff review and send notifications.
 */
function membership_application_finalize_submission(PDO $pdo, int $applicationId, ?string $paymentIntentId): bool
{
    require_once __DIR__ . '/member_applications.php';
    require_once __DIR__ . '/mail.php';
    require_once __DIR__ . '/installation_config.php';

    $app = application_fetch($pdo, $applicationId);
    if ($app === null) {
        return false;
    }
    if (($app['status'] ?? '') === 'pending') {
        return true;
    }

    membership_application_ama_clear_session();

    $pdo->prepare('
        UPDATE member_applications SET
            status = \'pending\',
            payment_status = ?,
            payment_transaction_id = COALESCE(?, payment_transaction_id),
            payment_gateway = \'Stripe\'
        WHERE id = ?
    ')->execute([
        $paymentIntentId ? 'succeeded' : ($app['payment_status'] ?? 'waived'),
        $paymentIntentId,
        $applicationId,
    ]);

    application_notify_new_submission($pdo, $applicationId);
    membership_application_send_applicant_confirmation($pdo, $applicationId);

    $app = application_fetch($pdo, $applicationId);
    if ($app !== null) {
        membership_application_sync_sender_email_preferences($pdo, $app, 'submit');
    }

    return true;
}

function membership_application_send_applicant_confirmation(PDO $pdo, int $applicationId): void
{
    require_once __DIR__ . '/member_applications.php';
    require_once __DIR__ . '/mail.php';
    require_once __DIR__ . '/installation_config.php';

    $app = application_fetch($pdo, $applicationId);
    if ($app === null || empty($app['email'])) {
        return;
    }

    $name = trim((string) $app['first_name']);
    $total = isset($app['payment_total']) ? number_format((float) $app['payment_total'], 2) : '0.00';
    $address = trim(implode(', ', array_filter([
        $app['address_street'] ?? '',
        $app['address_city'] ?? '',
        $app['address_state'] ?? '',
        $app['address_postal_code'] ?? '',
    ])));

    $subject = 'PVMAC membership application received';
    $body = '<p>Hi ' . htmlspecialchars($name) . ',</p>'
        . '<p>We received your membership application and will review it shortly.</p>'
        . '<p><strong>Total paid:</strong> $' . htmlspecialchars($total) . '</p>'
        . '<p><strong>Badge mailing address:</strong><br>' . nl2br(htmlspecialchars($address)) . '</p>'
        . '<p>Thanks for applying,<br>PVMAC</p>';

    $config = installation_load_system_config($pdo);
    $mailConfig = installation_mail_config($pdo, $config);
    send_mail((string) $app['email'], $subject, $body, strip_tags($body), $mailConfig);
}

/**
 * If payment succeeded at Stripe but webhook has not run yet, finalize now.
 */
function membership_application_try_finalize_from_stripe(PDO $pdo, int $applicationId): void
{
    require_once __DIR__ . '/member_applications.php';
    require_once __DIR__ . '/stripe_config.php';

    $app = application_fetch($pdo, $applicationId);
    if ($app === null || ($app['status'] ?? '') !== 'pending_payment') {
        return;
    }

    $intentId = trim((string) ($app['payment_transaction_id'] ?? ''));
    if ($intentId === '') {
        return;
    }

    $client = stripe_client($pdo);
    if ($client === null) {
        return;
    }

    try {
        $intent = $client->paymentIntents->retrieve($intentId);
        if (($intent->status ?? '') === 'succeeded') {
            membership_application_finalize_submission($pdo, $applicationId, $intentId);
        }
    } catch (Throwable $e) {
        error_log('membership_application_try_finalize_from_stripe: ' . $e->getMessage());
    }
}
