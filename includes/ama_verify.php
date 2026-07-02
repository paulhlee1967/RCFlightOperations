<?php
/**
 * includes/ama_verify.php
 *
 * Shared AMA membership verification (scrapes the public Drupal verify form).
 * Used by api_verify_ama.php.
 */

declare(strict_types=1);

const AMA_VERIFY_PAGE_URL     = 'https://www.modelaircraft.org/membership/verify';
const AMA_VERIFY_AJAX_URL     = 'https://www.modelaircraft.org/membership/verify?ajax_form=1&_wrapper_format=drupal_ajax';
const AMA_VERIFY_FORM_ID      = 'membership_verify_form';
const AMA_VERIFY_USER_AGENT   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 RCFlightOperations/1.5';
const AMA_VERIFY_CACHE_TTL    = 600;
const AMA_VERIFY_CONNECT_SEC  = 8;
const AMA_VERIFY_TIMEOUT_SEC  = 25;

const AMA_VALID_PATTERNS = [
    'is valid until',
    'The Life membership',
    'Temporary Status',
];

const AMA_INVALID_PATTERNS = [
    'Park Pilot',
    'Trial membership',
];

/**
 * Normalize AMA number (digits only).
 */
function ama_verify_normalize_number(string $raw): string
{
    return preg_replace('/[^\d]/', '', trim($raw)) ?? '';
}

/**
 * Normalize last name for lookup.
 */
function ama_verify_normalize_last_name(string $raw): string
{
    $s = trim($raw);
    $s = preg_replace('/\s+/', ' ', $s) ?? '';

    return $s;
}

/**
 * Validate inputs before calling AMA. Returns error message or null if OK.
 */
function ama_verify_validate_inputs(string $amaNumber, string $lastName): ?string
{
    if ($amaNumber === '' || $lastName === '') {
        return 'AMA number and last name are required.';
    }
    if (strlen($amaNumber) < 4 || strlen($amaNumber) > 12) {
        return 'AMA number does not look valid.';
    }
    if (strlen($lastName) > 80) {
        return 'Last name is too long.';
    }

    return null;
}

/**
 * Unified verify result shape for the member edit AMA lookup.
 *
 * @return array{
 *   ok: bool,
 *   status: string,
 *   message: string,
 *   expiration_ymd: ?string,
 *   expiration_mdy: ?string,
 *   life_member: bool,
 *   first_name: ?string,
 *   last_name: ?string,
 *   full_name: ?string
 * }
 */
function ama_verify_membership(string $amaNumber, string $lastName, array $options = []): array
{
    $amaNumber = ama_verify_normalize_number($amaNumber);
    $lastName  = ama_verify_normalize_last_name($lastName);
    $inputErr  = ama_verify_validate_inputs($amaNumber, $lastName);
    if ($inputErr !== null) {
        return ama_verify_result(false, 'invalid_input', $inputErr);
    }

    $allowRetry = !array_key_exists('retry', $options) || $options['retry'] !== false;
    $attempts   = $allowRetry ? 2 : 1;

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $response = ama_verify_http_lookup($amaNumber, $lastName, $attempt > 1);
        if ($response === false) {
            if ($attempt < $attempts) {
                usleep(400000);
                continue;
            }

            return ama_verify_result(false, 'unreachable', 'AMA site unreachable — try again or enter expiry manually.');
        }

        $html   = ama_verify_response_to_html($response);
        $parsed = ama_verify_parse_html($html, $lastName);

        if ($parsed['status'] === 'service_error' && $attempt < $attempts) {
            ama_verify_cache_clear();
            usleep(400000);
            continue;
        }

        return $parsed;
    }

    return ama_verify_result(false, 'unreachable', 'AMA site unreachable — try again or enter expiry manually.');
}

/**
 * @return array{ok:bool,status:string,message:string,expiration_ymd:?string,expiration_mdy:?string,life_member:bool,first_name:?string,last_name:?string,full_name:?string}
 */
function ama_verify_result(
    bool $ok,
    string $status,
    string $message,
    ?string $expirationYmd = null,
    ?string $expirationMdy = null,
    bool $lifeMember = false,
    ?string $firstName = null,
    ?string $lastName = null,
    ?string $fullName = null
): array {
    return [
        'ok'             => $ok,
        'status'         => $status,
        'message'        => $message,
        'expiration_ymd' => $expirationYmd,
        'expiration_mdy' => $expirationMdy,
        'life_member'    => $lifeMember,
        'first_name'     => $firstName,
        'last_name'      => $lastName,
        'full_name'      => $fullName,
    ];
}

/**
 * Map unified result to api_verify_ama.php JSON (backward compatible).
 */
function ama_verify_to_api_json(array $result): array
{
    return [
        'valid'       => (bool) ($result['ok'] ?? false),
        'message'     => (string) ($result['message'] ?? 'Could not verify.'),
        'expiration'  => $result['expiration_ymd'] ?? null,
        'life_member' => (bool) ($result['life_member'] ?? false),
        'status'      => (string) ($result['status'] ?? 'unknown'),
    ];
}

/**
 * Whether membership expiration meets a required m/d/Y date.
 */
function ama_verify_meets_required_date(?string $expirationMdy, string $requiredMdy): bool
{
    if ($expirationMdy === null || $expirationMdy === '') {
        return false;
    }
    $expire   = DateTime::createFromFormat('m/d/Y', $expirationMdy);
    $required = DateTime::createFromFormat('m/d/Y', $requiredMdy);
    if (!$expire || !$required) {
        return false;
    }

    return $expire >= $required;
}

/**
 * Fetch form_build_id only (health check).
 */
function ama_verify_probe_form_build_id(): ?string
{
    $session = ama_verify_fetch_form_page(null);
    if ($session === null) {
        return null;
    }
    ama_verify_cache_set($session['form_build_id'], $session['cookie_jar']);
    @unlink($session['cookie_jar']);

    return $session['form_build_id'];
}

/**
 * HTTP lookup: try cache POST, else GET+POST with shared cookie jar.
 *
 * @return string|false Raw response body
 */
function ama_verify_http_lookup(string $amaNumber, string $lastName, bool $forceRefresh): string|false
{
    if (!$forceRefresh) {
        $cache = ama_verify_cache_get();
        if ($cache !== null) {
            $jar = ama_verify_temp_cookie_jar();
            file_put_contents($jar, $cache['cookies']);
            $response = ama_verify_post(
                $amaNumber,
                $lastName,
                $cache['form_build_id'],
                $jar
            );
            @unlink($jar);
            if ($response !== false && ama_verify_response_has_signal($response)) {
                return $response;
            }
            ama_verify_cache_clear();
        }
    }

    $session = ama_verify_fetch_form_page(null);
    if ($session === null) {
        ama_verify_log('form page fetch failed — no form_build_id');
        return false;
    }

    $response = ama_verify_post(
        $amaNumber,
        $lastName,
        $session['form_build_id'],
        $session['cookie_jar']
    );

    if ($response !== false && ama_verify_response_has_signal($response)) {
        ama_verify_cache_set($session['form_build_id'], $session['cookie_jar']);
    }

    @unlink($session['cookie_jar']);

    return $response;
}

/**
 * @return array{form_build_id:string,cookie_jar:string}|null
 */
function ama_verify_fetch_form_page(?string $cookieJar): ?array
{
    $jar = $cookieJar ?? ama_verify_temp_cookie_jar();
    $ch  = curl_init(AMA_VERIFY_PAGE_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => AMA_VERIFY_CONNECT_SEC,
        CURLOPT_TIMEOUT        => AMA_VERIFY_TIMEOUT_SEC,
        CURLOPT_USERAGENT      => AMA_VERIFY_USER_AGENT,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $html     = curl_exec($ch);
    $errno    = curl_errno($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno || $html === false || $httpCode >= 500) {
        ama_verify_log('GET verify page failed', ['errno' => $errno, 'http' => $httpCode]);
        if ($cookieJar === null) {
            @unlink($jar);
        }
        return null;
    }

    if (!preg_match('/name="form_build_id" value="([^"]+)"/', (string) $html, $m)) {
        ama_verify_log('form_build_id not found in HTML', ['http' => $httpCode, 'len' => strlen((string) $html)]);
        if ($cookieJar === null) {
            @unlink($jar);
        }
        return null;
    }

    return [
        'form_build_id' => $m[1],
        'cookie_jar'    => $jar,
    ];
}

/**
 * @return string|false
 */
function ama_verify_post(string $amaNumber, string $lastName, string $formBuildId, string $cookieJar): string|false
{
    $fields = [
        'ama_number'    => $amaNumber,
        'last_name'     => $lastName,
        'form_build_id' => $formBuildId,
        'form_id'       => AMA_VERIFY_FORM_ID,
    ];

    $ch = curl_init(AMA_VERIFY_AJAX_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => AMA_VERIFY_CONNECT_SEC,
        CURLOPT_TIMEOUT        => AMA_VERIFY_TIMEOUT_SEC,
        CURLOPT_USERAGENT      => AMA_VERIFY_USER_AGENT,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $errno    = curl_errno($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno || $response === false) {
        ama_verify_log('POST verify failed', ['errno' => $errno, 'http' => $httpCode]);
        return false;
    }
    if ($httpCode >= 500 || $httpCode === 429) {
        ama_verify_log('POST verify bad HTTP status', ['http' => $httpCode]);
        return false;
    }

    return (string) $response;
}

function ama_verify_response_has_signal(string $response): bool
{
    $html = ama_verify_response_to_html($response);
    if ($html === '') {
        return false;
    }
    if (str_contains($html, 'No match found')) {
        return true;
    }
    foreach (array_merge(AMA_VALID_PATTERNS, AMA_INVALID_PATTERNS) as $p) {
        if (str_contains($html, $p)) {
            return true;
        }
    }

    return false;
}

/**
 * Extract searchable HTML from Drupal AJAX JSON or raw HTML.
 */
function ama_verify_response_to_html(string $response): string
{
    $trim = trim($response);
    if ($trim === '') {
        return '';
    }
    if ($trim[0] === '[' || $trim[0] === '{') {
        $decoded = json_decode($trim, true);
        if (is_array($decoded)) {
            return ama_verify_collect_html_from_json($decoded);
        }
    }

    return $response;
}

/**
 * @param mixed $node
 */
function ama_verify_collect_html_from_json($node): string
{
    $parts = [];
    if (is_array($node)) {
        if (isset($node['data']) && is_string($node['data'])) {
            $parts[] = $node['data'];
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $chunk = ama_verify_collect_html_from_json($value);
                if ($chunk !== '') {
                    $parts[] = $chunk;
                }
            }
        }
    }

    return implode("\n", $parts);
}

/**
 * Parse AMA verify HTML into a unified result (testable).
 *
 * @return array{ok:bool,status:string,message:string,expiration_ymd:?string,expiration_mdy:?string,life_member:bool,first_name:?string,last_name:?string,full_name:?string}
 */
function ama_verify_parse_html(string $html, string $submittedLastName = ''): array
{
    if ($html === '') {
        return ama_verify_result(false, 'service_error', 'AMA lookup returned an empty response — try again later.');
    }

    if (str_contains($html, 'No match found')) {
        return ama_verify_result(false, 'no_match', 'No match found for this AMA number and last name.');
    }

    $hasValid = false;
    foreach (AMA_VALID_PATTERNS as $p) {
        if (str_contains($html, $p)) {
            $hasValid = true;
            break;
        }
    }
    $hasInvalid = false;
    foreach (AMA_INVALID_PATTERNS as $p) {
        if (str_contains($html, $p)) {
            $hasInvalid = true;
            break;
        }
    }

    if (!$hasValid || $hasInvalid) {
        return ama_verify_result(
            false,
            'invalid_type',
            'Membership could not be verified or is not a full membership.'
        );
    }

    $isLifetime = str_contains($html, 'The Life membership');
    $expireMdy  = null;

    if ($isLifetime) {
        $d         = new DateTime();
        $d->modify('+100 years');
        $expireMdy = $d->format('m/d/Y');
    } elseif (preg_match('/valid until (.*?)\./', $html, $m)) {
        $expireMdy = trim(stripslashes($m[1]));
    }

    $firstName = null;
    $fullName  = null;
    if (preg_match('/membership for (.*?) - AMA #/', $html, $nameMatch)) {
        $fullName = trim($nameMatch[1]);
        if ($submittedLastName !== '' && $fullName !== '') {
            $lastUpper = strtoupper($submittedLastName);
            $pos       = strpos($fullName, $lastUpper);
            if ($pos !== false) {
                $firstName = ucfirst(strtolower(trim(substr($fullName, 0, $pos))));
            }
        }
    }

    $lastNameOut = $submittedLastName !== ''
        ? ucfirst(strtolower(trim($submittedLastName)))
        : null;

    $expireYmd = null;
    if ($expireMdy !== null && $expireMdy !== '') {
        $dt = DateTime::createFromFormat('m/d/Y', $expireMdy);
        $expireYmd = $dt ? $dt->format('Y-m-d') : null;
    }

    $message = $isLifetime ? 'Life membership verified.' : 'Membership verified.';
    if ($expireYmd === null && $expireMdy === null) {
        $message = 'Verified.';
    }

    return ama_verify_result(
        true,
        'valid',
        $message,
        $expireYmd,
        $expireMdy,
        $isLifetime,
        $firstName,
        $lastNameOut,
        $fullName
    );
}

/** @return ?array{form_build_id:string,cookies:string,expires_at:int} */
function ama_verify_cache_get(): ?array
{
    $cache = null;
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['ama_verify_cache'])) {
        $cache = $_SESSION['ama_verify_cache'];
    }
    if (!is_array($cache)) {
        $file = ama_verify_cache_file_path();
        if (is_readable($file)) {
            $raw = json_decode((string) file_get_contents($file), true);
            if (is_array($raw)) {
                $cache = $raw;
            }
        }
    }
    if (!is_array($cache) || empty($cache['form_build_id']) || empty($cache['expires_at'])) {
        return null;
    }
    if ((int) $cache['expires_at'] < time()) {
        ama_verify_cache_clear();
        return null;
    }

    return [
        'form_build_id' => (string) $cache['form_build_id'],
        'cookies'       => (string) ($cache['cookies'] ?? ''),
        'expires_at'    => (int) $cache['expires_at'],
    ];
}

function ama_verify_cache_set(string $formBuildId, string $cookieJarPath): void
{
    $cookies = is_readable($cookieJarPath) ? (string) file_get_contents($cookieJarPath) : '';
    $payload = [
        'form_build_id' => $formBuildId,
        'cookies'       => $cookies,
        'expires_at'    => time() + AMA_VERIFY_CACHE_TTL,
    ];

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['ama_verify_cache'] = $payload;
    }

    $file = ama_verify_cache_file_path();
    $dir  = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($file, json_encode($payload));
}

function ama_verify_cache_clear(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        unset($_SESSION['ama_verify_cache']);
    }
    $file = ama_verify_cache_file_path();
    if (is_file($file)) {
        @unlink($file);
    }
}

function ama_verify_cache_file_path(): string
{
    $root = dirname(__DIR__);

    return $root . '/uploads/.ama_verify_cache.json';
}

function ama_verify_temp_cookie_jar(): string
{
    $path = tempnam(sys_get_temp_dir(), 'ama_ck_');
    if ($path === false) {
        return sys_get_temp_dir() . '/ama_ck_' . uniqid('', true) . '.txt';
    }

    return $path;
}

/**
 * @param array<string, mixed> $context
 */
function ama_verify_log(string $message, array $context = []): void
{
    $suffix = $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
    error_log('ama_verify: ' . $message . $suffix);
}
