<?php
/**
 * Sender.net API helpers — promotional email opt-out checks for cron reminders.
 *
 * Uses GET/POST /v2/subscribers, then sends reminders via POST /v2/message/send so
 * per-recipient {{ unsubscribe_link }} / {{ unsubscribe_text }} Liquid tags work.
 */

const SENDER_NET_API_BASE = 'https://api.sender.net/v2';

/**
 * Normalize email for Sender.net (lowercase — avoids duplicate subscribers by case).
 */
function sender_net_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

/**
 * Load Sender.net settings from system_config with config.php fallback.
 *
 * @return array{api_token: string, group_id: string}
 */
function sender_net_load_config(?PDO $pdo = null): array
{
    $defaults = ['api_token' => '', 'group_id' => ''];
    $cf       = dirname(__DIR__) . '/config.php';
    if (is_file($cf)) {
        $c = require $cf;
        $block = $c['sender'] ?? [];
        $defaults['api_token'] = trim((string) ($block['api_token'] ?? ''));
        $defaults['group_id']  = trim((string) ($block['group_id'] ?? ''));
    }

    if ($pdo === null) {
        return $defaults;
    }

    try {
        require_once __DIR__ . '/installation_config.php';
        $rows = installation_load_system_config($pdo);
        if (trim((string) ($rows['sender_api_token'] ?? '')) !== '') {
            $defaults['api_token'] = trim((string) $rows['sender_api_token']);
        }
        if (trim((string) ($rows['sender_group_id'] ?? '')) !== '') {
            $defaults['group_id'] = trim((string) $rows['sender_group_id']);
        }
    } catch (Throwable $e) {
    }

    return $defaults;
}

function sender_net_is_configured(array $config): bool
{
    return ($config['api_token'] ?? '') !== '';
}

/**
 * @return array{ok: bool, http_code: int, body: ?array, error: ?string}
 */
function sender_net_api_request(string $method, string $path, array $config, ?array $body = null): array
{
    if (!sender_net_is_configured($config)) {
        return ['ok' => false, 'http_code' => 0, 'body' => null, 'error' => 'Sender.net API token not configured'];
    }

    $url = SENDER_NET_API_BASE . $path;
    $ch  = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $config['api_token'],
        'Accept: application/json',
    ];

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
    ];

    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }

    curl_setopt_array($ch, $opts);

    $raw      = curl_exec($ch);
    $errno    = curl_errno($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        return ['ok' => false, 'http_code' => $httpCode, 'body' => null, 'error' => 'cURL error ' . $errno];
    }

    $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        $decoded = null;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $message = 'HTTP ' . $httpCode;
        if (is_array($decoded) && !empty($decoded['message'])) {
            $message = (string) $decoded['message'];
        }
        return ['ok' => false, 'http_code' => $httpCode, 'body' => $decoded, 'error' => $message];
    }

    return ['ok' => true, 'http_code' => $httpCode, 'body' => $decoded, 'error' => null];
}

/**
 * @return array{ok: bool, http_code: int, data: ?array, error: ?string}
 */
function sender_net_fetch_subscriber(string $email, array $config): array
{
    $email = sender_net_normalize_email($email);
    if ($email === '') {
        return ['ok' => false, 'http_code' => 0, 'data' => null, 'error' => 'Empty email'];
    }

    $result = sender_net_api_request('GET', '/subscribers/' . rawurlencode($email), $config);
    if (!$result['ok']) {
        if ($result['http_code'] === 404) {
            return ['ok' => true, 'http_code' => 404, 'data' => null, 'error' => null];
        }
        return ['ok' => false, 'http_code' => $result['http_code'], 'data' => null, 'error' => $result['error']];
    }

    $data = $result['body']['data'] ?? null;
    if (!is_array($data)) {
        return ['ok' => false, 'http_code' => $result['http_code'], 'data' => null, 'error' => 'Unexpected API response'];
    }

    return ['ok' => true, 'http_code' => $result['http_code'], 'data' => $data, 'error' => null];
}

/**
 * @return array{ok: bool, data: ?array, error: ?string, created: bool}
 */
function sender_net_create_subscriber(
    string $email,
    ?string $firstName,
    ?string $lastName,
    array $config
): array {
    $email = sender_net_normalize_email($email);
    if ($email === '') {
        return ['ok' => false, 'data' => null, 'error' => 'Empty email', 'created' => false];
    }

    $payload = [
        'email'              => $email,
        'trigger_automation' => false,
    ];
    if ($firstName !== null && trim($firstName) !== '') {
        $payload['firstname'] = trim($firstName);
    }
    if ($lastName !== null && trim($lastName) !== '') {
        $payload['lastname'] = trim($lastName);
    }
    $groupId = trim((string) ($config['group_id'] ?? ''));
    if ($groupId !== '') {
        $payload['groups'] = [$groupId];
    }

    $result = sender_net_api_request('POST', '/subscribers', $config, $payload);
    if (!$result['ok']) {
        // Subscriber may already exist (e.g. different casing) — caller can re-fetch.
        return ['ok' => false, 'data' => null, 'error' => $result['error'], 'created' => false];
    }

    $data = $result['body']['data'] ?? null;
    if (!is_array($data)) {
        return ['ok' => false, 'data' => null, 'error' => 'Unexpected API response', 'created' => false];
    }

    return ['ok' => true, 'data' => $data, 'error' => null, 'created' => true];
}

/**
 * Ensure a lowercase subscriber exists in Sender.net; fetch or create as needed.
 *
 * @return array{ok: bool, subscriber: ?array, error: ?string, created: bool}
 */
function sender_net_ensure_subscriber(
    string $email,
    ?string $firstName,
    ?string $lastName,
    array $config,
    bool $createIfMissing = true
): array {
    $normalized = sender_net_normalize_email($email);
    if ($normalized === '') {
        return ['ok' => false, 'subscriber' => null, 'error' => 'Empty email', 'created' => false];
    }

    $fetch = sender_net_fetch_subscriber($normalized, $config);
    if (!$fetch['ok']) {
        return ['ok' => false, 'subscriber' => null, 'error' => $fetch['error'], 'created' => false];
    }
    if ($fetch['data'] !== null) {
        return ['ok' => true, 'subscriber' => $fetch['data'], 'error' => null, 'created' => false];
    }

    if (!$createIfMissing) {
        return ['ok' => true, 'subscriber' => null, 'error' => null, 'created' => false];
    }

    $create = sender_net_create_subscriber($normalized, $firstName, $lastName, $config);
    if ($create['ok']) {
        return ['ok' => true, 'subscriber' => $create['data'], 'error' => null, 'created' => true];
    }

    // Race or duplicate — try fetch again after failed create.
    $retry = sender_net_fetch_subscriber($normalized, $config);
    if ($retry['ok'] && $retry['data'] !== null) {
        return ['ok' => true, 'subscriber' => $retry['data'], 'error' => null, 'created' => false];
    }

    return ['ok' => false, 'subscriber' => null, 'error' => $create['error'], 'created' => false];
}

/**
 * Whether status.email allows promotional / club reminder sends.
 *
 * @return bool|null true = active, false = blocked, null = unknown (not in Sender)
 */
function sender_net_promotional_email_active(?array $subscriberData): ?bool
{
    if ($subscriberData === null) {
        return null;
    }

    $status = strtolower(trim((string) ($subscriberData['status']['email'] ?? '')));
    if ($status === 'active') {
        return true;
    }
    if ($status === '') {
        return null;
    }

    return false;
}

/**
 * Ensure subscriber exists (lowercase) and decide if a reminder may be sent.
 *
 * @return array{
 *   send: bool,
 *   reason: string,
 *   subscriber: ?array,
 *   api_error: ?string,
 *   normalized_email: string,
 *   created: bool
 * }
 */
function sender_net_prepare_recipient(
    string $email,
    ?string $firstName,
    ?string $lastName,
    array $config,
    bool $createIfMissing = true
): array {
    $normalized = sender_net_normalize_email($email);
    if ($normalized === '') {
        return [
            'send'             => false,
            'reason'           => 'empty_email',
            'subscriber'       => null,
            'api_error'        => null,
            'normalized_email' => '',
            'created'          => false,
        ];
    }

    if (!sender_net_is_configured($config)) {
        return [
            'send'             => true,
            'reason'           => 'sender_not_configured',
            'subscriber'       => null,
            'api_error'        => null,
            'normalized_email' => $normalized,
            'created'          => false,
        ];
    }

    $ensure = sender_net_ensure_subscriber($normalized, $firstName, $lastName, $config, $createIfMissing);
    if (!$ensure['ok']) {
        return [
            'send'             => false,
            'reason'           => 'api_error',
            'subscriber'       => null,
            'api_error'        => $ensure['error'],
            'normalized_email' => $normalized,
            'created'          => false,
        ];
    }

    $subscriber = $ensure['subscriber'];
    if ($subscriber === null) {
        return [
            'send'             => true,
            'reason'           => 'not_in_sender',
            'subscriber'       => null,
            'api_error'        => null,
            'normalized_email' => $normalized,
            'created'          => false,
        ];
    }

    $active = sender_net_promotional_email_active($subscriber);
    if ($active === true) {
        return [
            'send'             => true,
            'reason'           => $ensure['created'] ? 'created_active' : 'active',
            'subscriber'       => $subscriber,
            'api_error'        => null,
            'normalized_email' => $normalized,
            'created'          => $ensure['created'],
        ];
    }

    $status = strtolower(trim((string) ($subscriber['status']['email'] ?? 'unknown')));

    return [
        'send'             => false,
        'reason'           => 'status_' . $status,
        'subscriber'       => $subscriber,
        'api_error'        => null,
        'normalized_email' => $normalized,
        'created'          => $ensure['created'],
    ];
}

/** @deprecated Use sender_net_prepare_recipient() */
function sender_net_may_email_recipient(string $email, array $config): array
{
    $prep = sender_net_prepare_recipient($email, null, null, $config);
    return [
        'send'       => $prep['send'],
        'reason'     => $prep['reason'],
        'subscriber' => $prep['subscriber'],
        'api_error'  => $prep['api_error'],
    ];
}

/**
 * Send a reminder via Sender transactional API (per-recipient unsubscribe Liquid tags).
 *
 * @param array{email: string, name?: string} $from
 * @return array{ok: bool, error: ?string, email_id: ?string}
 */
function sender_net_send_transactional(
    string $toEmail,
    string $toName,
    string $subject,
    string $html,
    ?string $text,
    array $from,
    array $config
): array {
    $toEmail = sender_net_normalize_email($toEmail);
    if ($toEmail === '') {
        return ['ok' => false, 'error' => 'Empty recipient', 'email_id' => null];
    }

    $fromEmail = trim((string) ($from['email'] ?? ''));
    $fromName  = trim((string) ($from['name'] ?? ''));
    if ($fromEmail === '') {
        return ['ok' => false, 'error' => 'Missing from address', 'email_id' => null];
    }

    $payload = [
        'from'    => ['email' => $fromEmail, 'name' => $fromName !== '' ? $fromName : $fromEmail],
        'to'      => ['email' => $toEmail, 'name' => $toName !== '' ? $toName : $toEmail],
        'subject' => $subject,
        'html'    => $html,
    ];
    if ($text !== null && $text !== '') {
        $payload['text'] = $text;
    }

    $result = sender_net_api_request('POST', '/message/send', $config, $payload);
    if (!$result['ok']) {
        return ['ok' => false, 'error' => $result['error'], 'email_id' => null];
    }

    $emailId = isset($result['body']['emailId']) ? (string) $result['body']['emailId'] : null;

    return ['ok' => true, 'error' => null, 'email_id' => $emailId];
}

/**
 * Plain-text footer line when Sender Liquid tags are used in HTML (no static URL).
 */
function sender_net_unsubscribe_plain_text_line(): string
{
    return "\n\nTo unsubscribe from club emails, use the link in the HTML version of this message.\n";
}
