<?php
/**
 * Sender.net API helpers — promotional email opt-out checks for cron reminders.
 *
 * Uses GET /v2/subscribers/{email} and status.email (marketing channel).
 * Transactional SMTP sends are separate; reminders respect promotional unsubscribe.
 */

const SENDER_NET_API_BASE = 'https://api.sender.net/v2';

/**
 * Load Sender.net settings from system_config with config.php fallback.
 *
 * @return array{api_token: string, unsubscribe_url: string}
 */
function sender_net_load_config(?PDO $pdo = null): array
{
    $defaults = ['api_token' => '', 'unsubscribe_url' => ''];
    $cf       = dirname(__DIR__) . '/config.php';
    if (is_file($cf)) {
        $c = require $cf;
        $block = $c['sender'] ?? [];
        $defaults['api_token']        = trim((string) ($block['api_token'] ?? ''));
        $defaults['unsubscribe_url']  = trim((string) ($block['unsubscribe_url'] ?? ''));
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
        if (trim((string) ($rows['sender_unsubscribe_url'] ?? '')) !== '') {
            $defaults['unsubscribe_url'] = trim((string) $rows['sender_unsubscribe_url']);
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
 * @return array{ok: bool, http_code: int, data: ?array, error: ?string}
 */
function sender_net_fetch_subscriber(string $email, array $config): array
{
    $email = trim($email);
    if ($email === '' || !sender_net_is_configured($config)) {
        return ['ok' => false, 'http_code' => 0, 'data' => null, 'error' => 'Sender.net API token not configured'];
    }

    $url = SENDER_NET_API_BASE . '/subscribers/' . rawurlencode($email);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $config['api_token'],
            'Accept: application/json',
        ],
    ]);

    $body     = curl_exec($ch);
    $errno    = curl_errno($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        return ['ok' => false, 'http_code' => $httpCode, 'data' => null, 'error' => 'cURL error ' . $errno];
    }

    if ($httpCode === 404) {
        return ['ok' => true, 'http_code' => 404, 'data' => null, 'error' => null];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $message = 'HTTP ' . $httpCode;
        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && !empty($decoded['message'])) {
                $message = (string) $decoded['message'];
            }
        }
        return ['ok' => false, 'http_code' => $httpCode, 'data' => null, 'error' => $message];
    }

    $decoded = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
        return ['ok' => false, 'http_code' => $httpCode, 'data' => null, 'error' => 'Unexpected API response'];
    }

    return ['ok' => true, 'http_code' => $httpCode, 'data' => $decoded['data'], 'error' => null];
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
 * Decide if a reminder may be sent to this address.
 *
 * @return array{
 *   send: bool,
 *   reason: string,
 *   subscriber: ?array,
 *   api_error: ?string
 * }
 */
function sender_net_may_email_recipient(string $email, array $config): array
{
    if (!sender_net_is_configured($config)) {
        return [
            'send'       => true,
            'reason'     => 'sender_not_configured',
            'subscriber' => null,
            'api_error'  => null,
        ];
    }

    $result = sender_net_fetch_subscriber($email, $config);
    if (!$result['ok']) {
        return [
            'send'       => false,
            'reason'     => 'api_error',
            'subscriber' => null,
            'api_error'  => $result['error'],
        ];
    }

    if ($result['http_code'] === 404 || $result['data'] === null) {
        return [
            'send'       => true,
            'reason'     => 'not_in_sender',
            'subscriber' => null,
            'api_error'  => null,
        ];
    }

    $active = sender_net_promotional_email_active($result['data']);
    if ($active === true) {
        return [
            'send'       => true,
            'reason'     => 'active',
            'subscriber' => $result['data'],
            'api_error'  => null,
        ];
    }

    $status = strtolower(trim((string) ($result['data']['status']['email'] ?? 'unknown')));

    return [
        'send'       => false,
        'reason'     => 'status_' . $status,
        'subscriber' => $result['data'],
        'api_error'  => null,
    ];
}

/**
 * Build an unsubscribe URL for the email footer (optional template in settings).
 *
 * Template placeholders: {email}, {id}
 */
function sender_net_unsubscribe_url(string $email, ?string $subscriberId, array $config): ?string
{
    $template = trim((string) ($config['unsubscribe_url'] ?? ''));
    if ($template === '') {
        return null;
    }

    $url = str_replace(
        ['{email}', '{id}'],
        [rawurlencode($email), rawurlencode((string) ($subscriberId ?? ''))],
        $template
    );

    return $url !== '' ? $url : null;
}

/**
 * Append unsubscribe line to plain-text body when a URL is available.
 */
function sender_net_append_unsubscribe_text(string $bodyText, ?string $unsubscribeUrl): string
{
    if ($unsubscribeUrl === null || $unsubscribeUrl === '') {
        return $bodyText;
    }

    return rtrim($bodyText) . "\n\nUnsubscribe from club emails: " . $unsubscribeUrl . "\n";
}
