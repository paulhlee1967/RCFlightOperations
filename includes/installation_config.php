<?php
/**
 * Installation settings helpers: tabs, system_config load/save, mail config.
 */

/**
 * Installation page tabs (id => label), display order.
 *
 * @return array<string, string>
 */
function installation_tabs(): array
{
    return [
        'general'      => 'General',
        'applications' => 'Applications',
        'email'        => 'Email',
        'board_packet' => 'Board packet',
        'tools'        => 'Tools & status',
    ];
}

/**
 * Normalize a requested tab id to a known tab (default: general).
 */
function installation_normalize_tab(string $tab): string
{
    $tab = trim($tab);
    $tabs = installation_tabs();

    return array_key_exists($tab, $tabs) ? $tab : 'general';
}

/**
 * system_config keys owned by an Installation settings tab.
 *
 * @return list<string>
 */
function installation_tab_config_keys(string $tab): array
{
    return match (installation_normalize_tab($tab)) {
        'general' => [
            'app_name',
            'support_email',
            'membership_email',
            'renewal_prebook_start_month',
            'renewal_prebook_start_day',
            'reports_accurate_from_year',
            'maintenance_mode',
        ],
        'applications' => [
            'app_secret',
            'stripe_publishable_key',
            'stripe_secret_key',
            'stripe_webhook_secret',
            'stripe_test_mode',
        ],
        'email' => [
            'sender_api_token',
            'sender_group_id',
            'smtp_host',
            'smtp_port',
            'smtp_encryption',
            'smtp_username',
            'smtp_password',
            'smtp_from_email',
            'smtp_from_name',
        ],
        'board_packet' => [
            'board_packet_enabled',
            'board_packet_send_day',
            'board_packet_recipients',
        ],
        default => [],
    };
}

/**
 * Load/save system_config keys and build mail config for installation / SMTP UI.
 * Used by installation.php (club admin).
 */

function installation_load_system_config(PDO $pdo): array {
    try {
        return $pdo->query('SELECT config_key, config_value FROM system_config')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function installation_save_config_key(PDO $pdo, string $key, string $value): void {
    $pdo->prepare('INSERT INTO system_config (config_key, config_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), updated_at = NOW()')->execute([$key, $value]);
}

/**
 * First calendar month (1–12) when the club “pre-books” into the next renewal year for
 * default year pickers. Default 10 (October): current month >= that month uses next calendar year.
 */
function renewal_prebook_start_month(PDO $pdo): int {
    $default = 10;
    try {
        $stmt = $pdo->prepare(
            'SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1'
        );
        $stmt->execute(['renewal_prebook_start_month']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['config_value']) && $row['config_value'] !== '') {
            $t = (int) $row['config_value'];
            if ($t >= 1 && $t <= 12) {
                return $t;
            }
        }
    } catch (Throwable $e) {
    }
    return $default;
}

/**
 * Day of the pre-book start month (1–31) when the club begins the next renewal year.
 * Default 15 (e.g. October 15): on/after this day in the start month, default year
 * pickers and "not yet renewed" roll forward to the next calendar year.
 */
function renewal_prebook_start_day(PDO $pdo): int {
    $default = 15;
    try {
        $stmt = $pdo->prepare(
            'SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1'
        );
        $stmt->execute(['renewal_prebook_start_day']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['config_value']) && $row['config_value'] !== '') {
            $t = (int) $row['config_value'];
            if ($t >= 1 && $t <= 31) {
                return $t;
            }
        }
    } catch (Throwable $e) {
    }
    return $default;
}

/**
 * First membership year for which the club has complete, trustworthy data.
 * Reports flag years before this as reconstructed/approximate. Default 2027.
 */
function reports_accurate_from_year(PDO $pdo): int {
    $default = 2027;
    try {
        $stmt = $pdo->prepare(
            'SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1'
        );
        $stmt->execute(['reports_accurate_from_year']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['config_value']) && $row['config_value'] !== '') {
            $t = (int) $row['config_value'];
            if ($t >= 2000 && $t <= 2100) {
                return $t;
            }
        }
    } catch (Throwable $e) {
    }
    return $default;
}

/**
 * Inbox for membership staff notifications (new applications, member self-updates).
 * Prefers membership_email, then support_email, then the first active admin user email.
 *
 * @param array<string, string> $sysConfig
 * @param PDO|null $pdo When set, used for admin-user fallback
 */
function application_notify_recipient_email(array $sysConfig, ?PDO $pdo = null): string
{
    $membership = trim((string) ($sysConfig['membership_email'] ?? ''));
    if ($membership !== '' && filter_var($membership, FILTER_VALIDATE_EMAIL)) {
        return $membership;
    }

    $support = trim((string) ($sysConfig['support_email'] ?? ''));
    if ($support !== '' && filter_var($support, FILTER_VALIDATE_EMAIL)) {
        return $support;
    }

    if ($pdo instanceof PDO) {
        try {
            $email = $pdo->query(
                'SELECT email FROM users
                 WHERE role = \'admin\' AND COALESCE(active, 1) = 1 AND email != \'\'
                 ORDER BY id ASC
                 LIMIT 1'
            )->fetchColumn();
            $email = trim((string) $email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        } catch (Throwable $e) {
        }
    }

    return '';
}

/** Whether automatic monthly board packet email is enabled. Default false. */
function board_packet_enabled(PDO $pdo): bool
{
    try {
        $stmt = $pdo->prepare('SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1');
        $stmt->execute(['board_packet_enabled']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return isset($row['config_value']) && $row['config_value'] === '1';
    } catch (Throwable $e) {
        return false;
    }
}

/** Day of month (1–28) for automatic board packet send. Default 1. */
function board_packet_send_day(PDO $pdo): int
{
    $default = 1;
    try {
        $stmt = $pdo->prepare('SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1');
        $stmt->execute(['board_packet_send_day']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['config_value']) && $row['config_value'] !== '') {
            $t = (int) $row['config_value'];
            if ($t >= 1 && $t <= 28) {
                return $t;
            }
        }
    } catch (Throwable $e) {
    }

    return $default;
}

/**
 * Configured automatic board packet recipients (comma/semicolon list).
 *
 * @return array<int, string>
 */
function board_packet_recipients(PDO $pdo): array
{
    try {
        $stmt = $pdo->prepare('SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1');
        $stmt->execute(['board_packet_recipients']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $raw = trim((string) ($row['config_value'] ?? ''));
        if ($raw === '') {
            return [];
        }
        require_once __DIR__ . '/report_email_html.php';

        return report_email_parse_addresses($raw);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Raw configured recipient string (for forms and delivery log).
 */
function board_packet_recipients_raw(PDO $pdo): string
{
    try {
        $stmt = $pdo->prepare('SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1');
        $stmt->execute(['board_packet_recipients']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return trim((string) ($row['config_value'] ?? ''));
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Effective outbound mail settings: DB system_config overrides config.php email block.
 */
function installation_mail_config(PDO $pdo, ?array $sysConfig = null): array {
    $sysConfig = $sysConfig ?? installation_load_system_config($pdo);
    $defaults  = [];
    $cf        = dirname(__DIR__) . '/config.php';
    if (is_file($cf)) {
        $c = require $cf;
        $defaults = $c['email'] ?? [];
    }

    $host = $sysConfig['smtp_host'] ?? ($defaults['smtp']['host'] ?? '');
    if ($host === '' || $host === null) {
        return [
            'driver'       => 'mail',
            'from_address' => $sysConfig['smtp_from_email'] ?? ($defaults['from_address'] ?? ''),
            'from_name'    => $sysConfig['smtp_from_name'] ?? ($defaults['from_name'] ?? 'RC Flight Operations'),
        ];
    }

    return [
        'driver'       => 'smtp',
        'from_address' => $sysConfig['smtp_from_email'] ?? ($defaults['from_address'] ?? ''),
        'from_name'    => $sysConfig['smtp_from_name'] ?? ($defaults['from_name'] ?? 'RC Flight Operations'),
        'smtp' => [
            'host'       => $host,
            'port'       => (int) ($sysConfig['smtp_port'] ?? ($defaults['smtp']['port'] ?? 587)),
            'encryption' => $sysConfig['smtp_encryption'] ?? ($defaults['smtp']['encryption'] ?? 'tls'),
            'username'   => $sysConfig['smtp_username'] ?? ($defaults['smtp']['username'] ?? ''),
            'password'   => $sysConfig['smtp_password'] ?? ($defaults['smtp']['password'] ?? ''),
        ],
    ];
}
