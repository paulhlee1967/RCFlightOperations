<?php
/**
 * memberapp_precheck.php
 *
 * Single-entry precheck script for the WordPress/WPForms membership flow.
 *
 * Responsibilities:
 *  1) Verify AMA membership is valid through 12/31 of the target membership year
 *     by querying the official AMA website.
 *  2) Look up the member in the RCFlightOps membership app database and decide
 *     which flow to show in WPForms:
 *       - renew_on_time (current member, renewal window open)
 *       - renew_late    (member exists but lapsed, or renewal window closed but late/new renewals allowed)
 *       - new_member    (no member match)
 *       - blocked       (inactive/suspended)
 *  3) Redirect to the WPForms form with query-string dynamic population fields.
 *
 * This file is intended to be deployed under your WordPress host (e.g. /public_html/_verify-ama/)
 * and must be configured with the absolute path to the membership app's config.php.
 */

// ============================================================================
// CONFIGURATION AND INITIALIZATION
// ============================================================================

// IMPORTANT: set this to the real filesystem path on your server.
// Example: /home/pvmaccom/rcflightops.pvmac.com/config.php
const MEMBERAPP_CONFIG_PATH = '/home/pvmaccom/rcflightops.pvmac.com/config.php';

require_once dirname(MEMBERAPP_CONFIG_PATH) . '/includes/ama_verify.php';

// WPForms IDs (dynamic field population keys). Update to match your form.
const WPF_FORM_ID = '6569';
const WPF_FIELD_FIRST = '14_First';
const WPF_FIELD_LAST = '14_Last';
const WPF_FIELD_AMA = '27';
const WPF_FIELD_AMA_EXP = '156';

// Add these fields to your WPForms form (hidden is fine) and set their IDs here.
// Example values to store: renew_on_time | renew_late | new_member | blocked
const WPF_FIELD_FLOW = null;      // e.g. '200'
const WPF_FIELD_TARGET_YEAR = null; // e.g. '201'
const WPF_FIELD_MEMBER_ID = null; // e.g. '202'

// Where to send the user when verification/precheck fails.
const FAIL_URL = '/ama-cannot-be-verified';

// Retrieve values from the URL (your WP page posts these)
$amaNumberRaw = (string)($_GET['amanumber'] ?? '');
$lastNameRaw  = (string)($_GET['lastname'] ?? '');

$amaNumber = ama_verify_normalize_number($amaNumberRaw);
$lastName  = ama_verify_normalize_last_name($lastNameRaw);

if ($amaNumber === '' || $lastName === '') {
    header('Location: ' . FAIL_URL);
    exit;
}

// ============================================================================
// DATE CALCULATION LOGIC
// ============================================================================

/**
 * Calculate the required expiration date for AMA memberships
 * Memberships must expire AFTER this date to be considered valid
 *
 * Logic: If current date is after October 14th (i.e. on/after 10/15),
 *        required date is end of next year
 *        Otherwise, required date is end of current year
 */

[$targetMembershipYear, $renewalWindowOpen] = determineTargetYearAndWindow(new DateTimeImmutable('now'));
$requiredDateString = '12/31/' . (string)$targetMembershipYear;

// ============================================================================
// MAIN EXECUTION LOGIC
// ============================================================================

// Verify AMA membership through target year end
$amaVerify = ama_verify_membership($amaNumber, $lastName);
if (
    empty($amaVerify['ok'])
    || !ama_verify_meets_required_date($amaVerify['expiration_mdy'] ?? null, $requiredDateString)
    || empty($amaVerify['first_name'])
) {
    header('Location: ' . FAIL_URL);
    exit;
}

$amaResult = [
    'first_name'         => (string) $amaVerify['first_name'],
    'last_name'          => (string) ($amaVerify['last_name'] ?? $lastName),
    'ama_expiration_mdy' => (string) $amaVerify['expiration_mdy'],
];

// Member database precheck (exists/current/lapsed/etc.)
$memberPrecheck = null;
try {
    $memberPrecheck = precheckMemberInAppDb(
        MEMBERAPP_CONFIG_PATH,
        $amaNumber,
        $lastName,
        (int)date('Y'),
        $renewalWindowOpen
    );
} catch (Throwable $e) {
    // Fail closed. If the membership DB can't be consulted, do not allow proceeding.
    error_log('memberapp_precheck: DB precheck failed: ' . $e->getMessage());
    header('Location: ' . FAIL_URL);
    exit;
}

// Build redirect to WPForms, passing dynamic population fields.
$redirectParams = [
    wpfKey(WPF_FORM_ID, WPF_FIELD_FIRST)   => $amaResult['first_name'],
    wpfKey(WPF_FORM_ID, WPF_FIELD_LAST)    => $amaResult['last_name'],
    wpfKey(WPF_FORM_ID, WPF_FIELD_AMA)     => $amaNumber,
    wpfKey(WPF_FORM_ID, WPF_FIELD_AMA_EXP) => $amaResult['ama_expiration_mdy'],
];

if (WPF_FIELD_FLOW !== null) {
    $redirectParams[wpfKey(WPF_FORM_ID, WPF_FIELD_FLOW)] = $memberPrecheck['flow'];
}
if (WPF_FIELD_TARGET_YEAR !== null) {
    $redirectParams[wpfKey(WPF_FORM_ID, WPF_FIELD_TARGET_YEAR)] = (string)$targetMembershipYear;
}
if (WPF_FIELD_MEMBER_ID !== null && $memberPrecheck['member_id'] !== null) {
    $redirectParams[wpfKey(WPF_FORM_ID, WPF_FIELD_MEMBER_ID)] = (string)$memberPrecheck['member_id'];
}

$newLocation = '/online-membership-application?' . http_build_query($redirectParams);
header('Location: ' . $newLocation);
exit;

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

function normalizeName(string $s): string {
    return ama_verify_normalize_last_name($s);
}

function wpfKey(string $formId, string $fieldId): string {
    return 'wpf' . $formId . '_' . $fieldId;
}

/**
 * Determine the membership year being sold and whether the renewal window is open.
 *
 * Your rule: membership signup/renewal window begins 10/15.
 *  - If today is on/after 10/15, memberships sold are for next year and renewals are open.
 *  - Otherwise, memberships sold are for current year and renewals are closed (new/late only).
 *
 * @return array{0:int,1:bool} [targetMembershipYear, renewalWindowOpen]
 */
function determineTargetYearAndWindow(DateTimeImmutable $now): array {
    $year  = (int)$now->format('Y');
    $month = (int)$now->format('n'); // 1-12
    $day   = (int)$now->format('j'); // 1-31

    $renewalWindowOpen = ($month > 10) || ($month === 10 && $day >= 15);
    $targetMembershipYear = $renewalWindowOpen ? ($year + 1) : $year;
    return [$targetMembershipYear, $renewalWindowOpen];
}

/**
 * Query the RCFlightOps membership database and determine which WPForms flow applies.
 *
 * @return array{flow:string,member_id:int|null,is_current:bool,matched:bool}
 */
function precheckMemberInAppDb(
    string $configPath,
    string $amaNumber,
    string $lastName,
    int $currentCalendarYear,
    bool $renewalWindowOpen
): array {
    if (!is_file($configPath)) {
        throw new RuntimeException('Missing membership app config.php at ' . $configPath);
    }

    $config = require $configPath;
    if (!is_array($config) || !isset($config['db']) || !is_array($config['db'])) {
        throw new RuntimeException('Invalid membership app config.php format');
    }

    $db  = $config['db'];
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $db['host'],
        $db['name'],
        $db['charset'] ?? 'utf8mb4'
    );

    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Prefer lookup by AMA number (more stable than email; already collected).
    $stmt = $pdo->prepare('
        SELECT id, first_name, last_name, ama_number, membership_renewal_year, inactive, suspended
        FROM members
        WHERE ama_number = ?
        LIMIT 5
    ');
    $stmt->execute([$amaNumber]);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        return [
            'flow' => 'new_member',
            'member_id' => null,
            'is_current' => false,
            'matched' => false,
        ];
    }

    // Filter by last name match (case-insensitive, trimmed).
    $ln = mb_strtolower(trim($lastName));
    $matches = array_values(array_filter($rows, function ($r) use ($ln) {
        $dbLast = mb_strtolower(trim((string)($r['last_name'] ?? '')));
        return $dbLast !== '' && $dbLast === $ln;
    }));

    if (count($matches) !== 1) {
        // Ambiguous or mismatch: fail closed to avoid renewing the wrong person.
        return [
            'flow' => 'blocked',
            'member_id' => null,
            'is_current' => false,
            'matched' => false,
        ];
    }

    $m = $matches[0];
    $inactive  = !empty($m['inactive']);
    $suspended = !empty($m['suspended']);
    if ($inactive || $suspended) {
        return [
            'flow' => 'blocked',
            'member_id' => (int)$m['id'],
            'is_current' => false,
            'matched' => true,
        ];
    }

    $renewalYear = (int)($m['membership_renewal_year'] ?? 0);
    $isCurrent = $renewalYear >= $currentCalendarYear;

    // Your policy: renewals (on-time) are only open during the window (10/15–12/31).
    // Lapsed members should be treated as late/new renewals.
    $flow = 'renew_late';
    if ($renewalWindowOpen && $isCurrent) {
        $flow = 'renew_on_time';
    } elseif (!$isCurrent) {
        $flow = 'renew_late';
    } else {
        // Current member but outside renewal window: treat as blocked (prevents early renewals).
        $flow = 'blocked';
    }

    return [
        'flow' => $flow,
        'member_id' => (int)$m['id'],
        'is_current' => $isCurrent,
        'matched' => true,
    ];
}
?>