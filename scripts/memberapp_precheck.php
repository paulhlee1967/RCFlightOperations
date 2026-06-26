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

$amaNumber = normalizeAmaNumber($amaNumberRaw);
$lastName  = normalizeName($lastNameRaw);

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

// Get the form build ID from AMA website
$formBuildID = getFormBuildID();
if ($formBuildID === null) {
    header('Location: ' . FAIL_URL);
    exit;
}

// Check the membership status
$amaResult = verifyAmaMembership($amaNumber, $lastName, $formBuildID, $requiredDateString);
if ($amaResult === null) {
    header('Location: ' . FAIL_URL);
    exit;
}

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

function normalizeAmaNumber(string $s): string {
    $s = trim($s);
    // AMA numbers are digits; remove spaces and dashes to be forgiving.
    $s = preg_replace('/[^\d]/', '', $s) ?? '';
    return $s;
}

function normalizeName(string $s): string {
    $s = trim($s);
    // Collapse internal whitespace.
    $s = preg_replace('/\s+/', ' ', $s) ?? '';
    return $s;
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
 * Retrieves the form_build_id from the AMA membership verification page
 *
 * This ID is dynamically generated by the AMA website and is required
 * for the AJAX form submission to work properly.
 *
 * @return string|null The form_build_id or null if not found
 */
function getFormBuildID() {
    // URL of the AMA membership verification page
    $url = "https://www.modelaircraft.org/membership/verify";

    // Fetch the page content
    $html_content = @file_get_contents($url);

    if ($html_content === false) {
        return null;
    }

    // Search for the form_build_id value using regex
    $pattern = '/name="form_build_id" value="([^"]+)"/';
    $matches = [];

    if (preg_match($pattern, $html_content, $matches)) {
        $formBuildID = $matches[1]; // The value captured by the regular expression
        return $formBuildID;
    } else {
        echo "Sorry, there's been an error. Please contact the site administrator.<br>";
        return null;
    }
}

/**
 * Checks for a valid AMA membership by querying the AMA website
 *
 * @param string $amaNumber The AMA membership number
 * @param string $lastName The member's last name
 * @param string $formBuildID The form build ID from the AMA website
 * @param string $requiredDateString The required expiration date
 * @return array{first_name:string,last_name:string,ama_expiration_mdy:string}|null Returns membership data if valid, null if invalid
 */
function verifyAmaMembership($amaNumber, $lastName, $formBuildID, $requiredDateString): ?array {
    $url = 'https://www.modelaircraft.org/membership/verify?ajax_form=1&_wrapper_format=drupal_ajax';

    // Prepare form data for submission
    $fields = [
        'ama_number'    => $amaNumber,
        'last_name'     => $lastName,
        'form_build_id' => $formBuildID,
        'form_id'       => 'membership_verify_form'
    ];

    // Initialize cURL session
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Add timeout for better error handling
    curl_setopt($ch, CURLOPT_USERAGENT, 'rcflightops-membership-precheck/1.0');

    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    // Check for cURL errors
    if ($response === false) {
        error_log('memberapp_precheck: AMA verify curl failed: ' . $curlErr);
        return null;
    }

    // If no match found, return null (no valid membership)
    $error_response = "No match found";
    if (str_contains($response, $error_response)) {
        return null;
    }

    // Parse the membership response
    $validMembershipPatterns = [
        "is valid until",
        "The Life membership",
        "Temporary Status"
    ];

    $invalidMembershipPatterns = [
        "Park Pilot",
        "Trial membership"
    ];

    // Check if response contains valid membership indicators
    $hasValidPattern = false;
    foreach ($validMembershipPatterns as $pattern) {
        if (strpos($response, $pattern) !== false) {
            $hasValidPattern = true;
            break;
        }
    }

    // Check if response contains invalid membership indicators
    $hasInvalidPattern = false;
    foreach ($invalidMembershipPatterns as $pattern) {
        if (strpos($response, $pattern) !== false) {
            $hasInvalidPattern = true;
            break;
        }
    }

    // Process valid membership
    if ($hasValidPattern && !$hasInvalidPattern) {
        // Determine if this is a lifetime membership
        $isLifetimeMember = (strpos($response, "The Life membership") !== false);

        // Extract member name from response
        $regex = '/membership for (.*?) - AMA #/';
        preg_match($regex, $response, $matchNameArray);
        $fullName = $matchNameArray[1] ?? '';
        if ($fullName === '') {
            return null;
        }

        // Parse first and last names
        $lastNameUpper = strtoupper($lastName);
        $lastNamePosition = strpos($fullName, $lastNameUpper);
        if ($lastNamePosition === false) {
            // If AMA returns an unexpected name format, fail closed.
            return null;
        }
        $firstName = substr($fullName, 0, $lastNamePosition);

        // Normalize name formatting (Title Case)
        $firstName = ucfirst(strtolower(trim($firstName)));
        $lastName = ucfirst(strtolower(trim($lastName)));

        // Handle expiration date
        $expireDate = new DateTime();
        $expireDate->modify('+100 years'); // Default for lifetime members
        $expireDateString = $expireDate->format("m/d/Y");

        // For non-lifetime members, extract actual expiration date
        if (!$isLifetimeMember) {
            $regex = "/valid until (.*?)\./";
            preg_match($regex, $response, $expireDateArray);
            $expireDateString = stripslashes($expireDateArray[1] ?? '');
            if ($expireDateString === '') {
                return null;
            }
        }

        // Create DateTime objects for comparison
        $expireDate = DateTime::createFromFormat('m/d/Y', $expireDateString);
        $requiredDate = DateTime::createFromFormat('m/d/Y', $requiredDateString);

        // Validate date parsing
        if ($expireDate && $requiredDate) {
            // Check if membership is still valid
            if ($expireDate >= $requiredDate) {
                return [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'ama_expiration_mdy' => $expireDateString,
                ];
            }
        }
    }

    // Handle invalid memberships (Park Pilot, Trial, etc.)
    return null;
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