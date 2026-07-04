<?php
/**
 * Sender.net API helpers — promotional email opt-out checks for cron reminders.
 *
 * Uses GET/POST /v2/subscribers, POST /v2/message/send for reminders, and a signed
 * app unsubscribe URL (Sender's {{unsubscribe_link}} is not auto-filled on raw sends).
 */

const SENDER_NET_API_BASE = 'https://api.sender.net/v2';

require_once __DIR__ . '/helpers.php';

/**
 * Normalize email for Sender.net (lowercase — avoids duplicate subscribers by case).
 */
function sender_net_normalize_email(string $email): string
{
    return normalize_email($email);
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
 * Whether a subscriber record is already in the configured Sender group.
 */
function sender_net_subscriber_in_group(?array $subscriberData, string $groupId): bool
{
    if ($subscriberData === null || $groupId === '') {
        return false;
    }

    foreach ($subscriberData['subscriber_tags'] ?? [] as $tag) {
        if (!is_array($tag)) {
            continue;
        }
        if (trim((string) ($tag['id'] ?? '')) === $groupId) {
            return true;
        }
    }

    return false;
}

/**
 * Add one subscriber to a Sender group (POST /v2/subscribers/groups/{groupId}).
 *
 * @return array{ok: bool, error: ?string}
 */
function sender_net_add_subscriber_to_group(string $email, string $groupId, array $config): array
{
    $email = sender_net_normalize_email($email);
    $groupId = trim($groupId);
    if ($email === '' || $groupId === '') {
        return ['ok' => false, 'error' => 'Missing email or group ID'];
    }

    $result = sender_net_api_request(
        'POST',
        '/subscribers/groups/' . rawurlencode($groupId),
        $config,
        [
            'subscribers'        => [$email],
            'trigger_automation' => false,
        ]
    );

    if (!$result['ok']) {
        return ['ok' => false, 'error' => $result['error']];
    }

    return ['ok' => true, 'error' => null];
}

/**
 * Ensure the configured members group includes this subscriber (new or existing).
 */
function sender_net_ensure_subscriber_group(string $email, ?array $subscriberData, array $config): void
{
    $groupId = trim((string) ($config['group_id'] ?? ''));
    if ($groupId === '' || sender_net_subscriber_in_group($subscriberData, $groupId)) {
        return;
    }

    sender_net_add_subscriber_to_group($email, $groupId, $config);
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
        sender_net_ensure_subscriber_group($normalized, $fetch['data'], $config);

        return ['ok' => true, 'subscriber' => $fetch['data'], 'error' => null, 'created' => false];
    }

    if (!$createIfMissing) {
        return ['ok' => true, 'subscriber' => null, 'error' => null, 'created' => false];
    }

    $create = sender_net_create_subscriber($normalized, $firstName, $lastName, $config);
    if ($create['ok']) {
        sender_net_ensure_subscriber_group($normalized, $create['data'], $config);

        return ['ok' => true, 'subscriber' => $create['data'], 'error' => null, 'created' => true];
    }

    // Race or duplicate — try fetch again after failed create.
    $retry = sender_net_fetch_subscriber($normalized, $config);
    if ($retry['ok'] && $retry['data'] !== null) {
        sender_net_ensure_subscriber_group($normalized, $retry['data'], $config);

        return ['ok' => true, 'subscriber' => $retry['data'], 'error' => null, 'created' => false];
    }

    return ['ok' => false, 'subscriber' => null, 'error' => $create['error'], 'created' => false];
}

/**
 * Whether status.email (campaigns / promotional) allows sends.
 *
 * @return bool|null true = active, false = blocked, null = unknown (not in Sender)
 */
function sender_net_promotional_email_active(?array $subscriberData): ?bool
{
    return sender_net_subscriber_channel_active($subscriberData, 'email');
}

/**
 * Whether status.temail (transactional) allows sends.
 *
 * @return bool|null true = active, false = blocked, null = unknown
 */
function sender_net_transactional_email_active(?array $subscriberData): ?bool
{
    return sender_net_subscriber_channel_active($subscriberData, 'temail');
}

/**
 * @return bool|null true = active, false = blocked, null = unknown
 */
function sender_net_subscriber_channel_active(?array $subscriberData, string $channel): ?bool
{
    if ($subscriberData === null) {
        return null;
    }

    $status = strtolower(trim((string) ($subscriberData['status'][$channel] ?? '')));
    if ($status === 'active') {
        return true;
    }
    if ($status === '') {
        return null;
    }

    return false;
}

/**
 * Whether AMA/FAA reminders may be sent (transactional / reminder channel only).
 *
 * Newsletter and campaign opt-out in Sender is separate and does not block reminders.
 *
 * @return bool|null true = ok, false = blocked, null = unknown subscriber
 */
function sender_net_may_send_reminder(?array $subscriberData): ?bool
{
    return sender_net_transactional_email_active($subscriberData);
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

    $maySend = sender_net_may_send_reminder($subscriber);
    if ($maySend !== false) {
        return [
            'send'             => true,
            'reason'           => $ensure['created'] ? 'created_active' : 'active',
            'subscriber'       => $subscriber,
            'api_error'        => null,
            'normalized_email' => $normalized,
            'created'          => $ensure['created'],
        ];
    }

    $txStatus = strtolower(trim((string) ($subscriber['status']['temail'] ?? 'unknown')));
    if ($txStatus === '') {
        $txStatus = 'unknown';
    }
    $reason = 'status_temail_' . $txStatus;

    return [
        'send'             => false,
        'reason'           => $reason,
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
 * Liquid variables for Sender transactional sends (matches Sender preset field names).
 *
 * @return array<string, string>
 */
function sender_net_liquid_variables(
    string $email,
    ?string $firstName = null,
    ?string $lastName = null
): array {
    $vars = [
        'email' => sender_net_normalize_email($email),
    ];
    $first = trim((string) $firstName);
    $last  = trim((string) $lastName);
    if ($first !== '') {
        $vars['firstname'] = $first;
    }
    if ($last !== '') {
        $vars['lastname'] = $last;
    }

    return $vars;
}

/**
 * HMAC signing secret for reminder unsubscribe links (webhook secret, else API token).
 */
function sender_net_unsubscribe_signing_secret(PDO $pdo, array $config): string
{
    require_once __DIR__ . '/application_webhook_config.php';
    $webhook = application_webhook_secret($pdo);
    if ($webhook !== '') {
        return $webhook;
    }

    return trim((string) ($config['api_token'] ?? ''));
}

function sender_net_unsubscribe_sign_token(string $email, string $secret): string
{
    $email = sender_net_normalize_email($email);
    if ($email === '' || $secret === '') {
        return '';
    }

    return hash_hmac('sha256', $email, $secret);
}

function sender_net_unsubscribe_verify_token(string $email, string $token, string $secret): bool
{
    $expected = sender_net_unsubscribe_sign_token($email, $secret);
    if ($expected === '' || $token === '') {
        return false;
    }

    return hash_equals($expected, $token);
}

/**
 * Per-recipient unsubscribe URL on this app (PATCHes Sender on confirm).
 */
function sender_net_app_unsubscribe_url(
    string $email,
    PDO $pdo,
    ?array $appConfig,
    array $senderCfg
): ?string {
    $email = sender_net_normalize_email($email);
    if ($email === '') {
        return null;
    }

    require_once __DIR__ . '/email_urls.php';
    $base = email_public_base_url($appConfig);
    if ($base === null) {
        return null;
    }

    $secret = sender_net_unsubscribe_signing_secret($pdo, $senderCfg);
    if ($secret === '') {
        return null;
    }

    $token = sender_net_unsubscribe_sign_token($email, $secret);

    return $base . '/unsubscribe.php?email=' . rawurlencode($email)
        . '&token=' . rawurlencode($token);
}

/**
 * Set unsubscribe_url (or fallback notice) on reminder template vars for Sender sends.
 *
 * @param array<string, mixed> $vars
 */
function sender_net_set_reminder_unsubscribe_vars(
    array &$vars,
    string $recipientEmail,
    PDO $pdo,
    array $appConfig,
    array $senderCfg
): void {
    $vars['use_sender_api'] = true;
    $vars['app_config']    = $appConfig;

    $url = sender_net_app_unsubscribe_url($recipientEmail, $pdo, $appConfig, $senderCfg);
    if ($url !== null) {
        $vars['unsubscribe_url'] = $url;
        unset($vars['show_unsubscribe_notice'], $vars['use_sender_unsubscribe_liquid']);
    } else {
        $vars['show_unsubscribe_notice'] = true;
    }
}

/**
 * Mark a subscriber unsubscribed from transactional (reminder) email in Sender.net.
 *
 * Does not change campaign / newsletter status (subscriber_status).
 *
 * @return array{ok: bool, error: ?string}
 */
function sender_net_unsubscribe_subscriber(string $email, array $config): array
{
    $email = sender_net_normalize_email($email);
    if ($email === '') {
        return ['ok' => false, 'error' => 'Empty email'];
    }

    $result = sender_net_api_request('PATCH', '/subscribers/' . rawurlencode($email), $config, [
        'transactional_email_status' => 'UNSUBSCRIBED',
        'trigger_automation'         => false,
    ]);

    if (!$result['ok']) {
        return ['ok' => false, 'error' => $result['error']];
    }

    return ['ok' => true, 'error' => null];
}

/**
 * Normalize Liquid tags for Sender API (no spaces in {{tags}}).
 */
function sender_net_prepare_html_for_api(string $html): string
{
    return preg_replace('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', '{{$1}}', $html) ?? $html;
}

/**
 * Build the Sender transactional API request (path, URL, JSON body).
 *
 * @param array{email: string, name?: string} $from
 * @param array<string, string> $variables
 * @return array{method: string, path: string, url: string, payload: array<string, mixed>}
 */
function sender_net_build_transactional_request(
    string $toEmail,
    string $toName,
    string $subject,
    string $html,
    ?string $text,
    array $from,
    array $config,
    array $variables = []
): array {
    $toEmail = sender_net_normalize_email($toEmail);

    $fromEmail = trim((string) ($from['email'] ?? ''));
    $fromName  = trim((string) ($from['name'] ?? ''));

    $html = sender_net_prepare_html_for_api($html);

    $payload = [
        'from'    => ['email' => $fromEmail, 'name' => $fromName !== '' ? $fromName : $fromEmail],
        'to'      => ['email' => $toEmail, 'name' => $toName !== '' ? $toName : $toEmail],
        'subject' => $subject,
        'html'    => $html,
    ];
    if ($text !== null && $text !== '') {
        $payload['text'] = $text;
    }
    if ($variables !== []) {
        $payload['variables'] = $variables;
    }

    $path = '/message/send';

    return [
        'method'  => 'POST',
        'path'    => $path,
        'url'     => SENDER_NET_API_BASE . $path,
        'payload' => $payload,
    ];
}

/**
 * Write a Sender API request dump for support/debug (token redacted).
 *
 * @param array<string, mixed> $meta  e.g. template_key, dry_run
 */
function sender_net_dump_transactional_request(array $request, string $filePath, array $meta = []): bool
{
    $bodyJson = json_encode($request['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($bodyJson === false) {
        return false;
    }

    $curl = 'curl -X POST "' . $request['url'] . '" \\' . "\n"
        . '  -H "Authorization: Bearer YOUR_API_TOKEN" \\' . "\n"
        . '  -H "Content-Type: application/json" \\' . "\n"
        . '  -H "Accept: application/json" \\' . "\n"
        . "  -d '" . str_replace("'", "'\\''", json_encode($request['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "'";

    $dump = [
        'dumped_at_utc' => gmdate('c'),
        'meta'          => $meta,
        'request'       => [
            'method'  => $request['method'],
            'url'     => $request['url'],
            'path'    => $request['path'],
            'headers' => [
                'Authorization' => 'Bearer [REDACTED — set on server]',
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body' => $request['payload'],
        ],
        'curl_example' => $curl,
    ];

    $dir = dirname($filePath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $json = json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents($filePath, $json . "\n") !== false;
}

/**
 * Send a reminder via Sender transactional API (per-recipient unsubscribe Liquid tags).
 *
 * @param array{email: string, name?: string} $from
 * @param array<string, string> $variables
 * @return array{ok: bool, error: ?string, email_id: ?string}
 */
function sender_net_send_transactional(
    string $toEmail,
    string $toName,
    string $subject,
    string $html,
    ?string $text,
    array $from,
    array $config,
    array $variables = []
): array {
    $toEmail = sender_net_normalize_email($toEmail);
    if ($toEmail === '') {
        return ['ok' => false, 'error' => 'Empty recipient', 'email_id' => null];
    }

    $fromEmail = trim((string) ($from['email'] ?? ''));
    if ($fromEmail === '') {
        return ['ok' => false, 'error' => 'Missing from address', 'email_id' => null];
    }

    $request = sender_net_build_transactional_request(
        $toEmail,
        $toName,
        $subject,
        $html,
        $text,
        $from,
        $config,
        $variables
    );

    $result = sender_net_api_request('POST', $request['path'], $config, $request['payload']);
    if (!$result['ok']) {
        return ['ok' => false, 'error' => $result['error'], 'email_id' => null];
    }

    $emailId = isset($result['body']['emailId']) ? (string) $result['body']['emailId'] : null;

    return ['ok' => true, 'error' => null, 'email_id' => $emailId];
}

/**
 * Plain-text footer line with the app unsubscribe URL.
 */
function sender_net_unsubscribe_plain_text_line(string $unsubscribeUrl = ''): string
{
    $unsubscribeUrl = trim($unsubscribeUrl);
    if ($unsubscribeUrl !== '') {
        return "\n\nTo unsubscribe from AMA/FAA expiry reminders only, visit:\n{$unsubscribeUrl}\n"
            . "Newsletters and general club notices are managed separately.\n";
    }

    return "\n\nTo unsubscribe from AMA/FAA expiry reminders only, use the link in the HTML version of this message.\n";
}
