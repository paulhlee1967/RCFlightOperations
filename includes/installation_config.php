<?php
/**
 * System settings helpers: tabs, system_config load/save, mail config.
 *
 * Club-facing keys (contacts, renewal season, reports year) are edited on
 * Configuration. This page owns mail transport, payments, scheduled mail, and status.
 */

/**
 * System page tabs (id => label), display order.
 *
 * @return array<string, string>
 */
function installation_tabs(): array
{
    return [
        'status'    => 'Status',
        'email'     => 'Email',
        'payments'  => 'Payments',
        'scheduled' => 'Scheduled mail',
    ];
}

/**
 * Normalize a requested tab id to a known tab (default: status).
 * Older bookmarks (general, tools, applications, board_packet, roster_digest) still open.
 */
function installation_normalize_tab(string $tab): string
{
    $tab = trim($tab);
    $aliases = [
        'general'       => 'status',
        'tools'         => 'status',
        'applications'  => 'payments',
        'board_packet'  => 'scheduled',
        'roster_digest' => 'scheduled',
    ];
    if (isset($aliases[$tab])) {
        $tab = $aliases[$tab];
    }
    $tabs = installation_tabs();

    return array_key_exists($tab, $tabs) ? $tab : 'status';
}

/**
 * system_config keys owned by one System save action.
 * Board packet and weekly roster stay separate so saving one does not clear the other.
 * The visible "scheduled" tab has no keys of its own.
 *
 * @return list<string>
 */
function installation_config_group_keys(string $group): array
{
    return match ($group) {
        'status' => [
            'maintenance_mode',
        ],
        'payments' => [
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
        'roster_digest' => [
            'current_members_digest_enabled',
            'current_members_digest_weekday',
            'current_members_digest_recipients',
        ],
        default => [],
    };
}

/**
 * Resolve a posted config value for a known system_config key.
 */
function installation_posted_config_value(string $key, array $post): string
{
    return match ($key) {
        'smtp_port' => (string) max(1, min(65535, (int) ($post['smtp_port'] ?? 587))),
        'maintenance_mode' => empty($post['maintenance_mode']) ? '0' : '1',
        'stripe_test_mode' => empty($post['stripe_test_mode']) ? '0' : '1',
        'board_packet_enabled' => empty($post['board_packet_enabled']) ? '0' : '1',
        'board_packet_send_day' => (string) max(1, min(28, (int) ($post['board_packet_send_day'] ?? 1))),
        'board_packet_recipients' => trim((string) ($post['board_packet_recipients'] ?? '')),
        'current_members_digest_enabled' => empty($post['current_members_digest_enabled']) ? '0' : '1',
        'current_members_digest_weekday' => (string) max(1, min(7, (int) ($post['current_members_digest_weekday'] ?? 1))),
        'current_members_digest_recipients' => trim((string) ($post['current_members_digest_recipients'] ?? '')),
        'renewal_prebook_start_month' => (string) max(1, min(12, (int) ($post['renewal_prebook_start_month'] ?? 10))),
        'renewal_prebook_start_day' => (string) renewal_prebook_clamp_day(
            (int) ($post['renewal_prebook_start_month'] ?? 10),
            (int) ($post['renewal_prebook_start_day'] ?? 15)
        ),
        'reports_accurate_from_year' => (string) max(2000, min(2100, (int) ($post['reports_accurate_from_year'] ?? 2027))),
        default => trim((string) ($post[$key] ?? '')),
    };
}

/**
 * Days in a calendar month. February allows 29 so a leap-day season start can be stored.
 */
function renewal_prebook_days_in_month(int $month): int
{
    $days = [1 => 31, 2 => 29, 3 => 31, 4 => 30, 5 => 31, 6 => 30, 7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31];
    $month = max(1, min(12, $month));

    return $days[$month];
}

function renewal_prebook_clamp_day(int $month, int $day): int
{
    return max(1, min(renewal_prebook_days_in_month($month), $day));
}

/**
 * Load/save system_config keys and build mail config for installation / SMTP UI.
 * Used by system.php (club admin).
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

function month_short_name(int $month): string
{
    $names = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'];

    return $names[$month] ?? '';
}

/**
 * Configurable windows used for application-season and on-time renewal labels.
 *
 * @return array{prebook_month:int, prebook_day:int, prorate_start:int, prorate_end:int}
 */
function renewal_season_windows(?PDO $pdo = null): array
{
    $windows = [
        'prebook_month' => 10,
        'prebook_day'   => 15,
        'prorate_start' => 7,
        'prorate_end'   => 10,
    ];
    if ($pdo === null) {
        return $windows;
    }
    $windows['prebook_month'] = renewal_prebook_start_month($pdo);
    $windows['prebook_day'] = renewal_prebook_start_day($pdo);
    if (function_exists('duesRules')) {
        foreach (duesRules($pdo) as $rule) {
            $windows['prorate_start'] = (int) ($rule['prorate_start_month'] ?? $windows['prorate_start']);
            $windows['prorate_end'] = (int) ($rule['prorate_end_month'] ?? $windows['prorate_end']);
            break;
        }
    }

    return $windows;
}

/** e.g. "Oct 15–Dec 31" */
function renewal_on_time_window_label(?PDO $pdo = null, ?array $windows = null): string
{
    $w = $windows ?? renewal_season_windows($pdo);
    $month = month_short_name((int) $w['prebook_month']);
    $day = (int) $w['prebook_day'];

    return $month . ' ' . $day . '–Dec 31';
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

/** Whether the weekly Current Members PDF email is enabled. Default false. */
function current_members_digest_enabled(PDO $pdo): bool
{
    try {
        $stmt = $pdo->prepare('SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1');
        $stmt->execute(['current_members_digest_enabled']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return isset($row['config_value']) && $row['config_value'] === '1';
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * ISO weekday (1 = Monday … 7 = Sunday) for the weekly roster email. Default Monday.
 */
function current_members_digest_weekday(PDO $pdo): int
{
    $default = 1;
    try {
        $stmt = $pdo->prepare('SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1');
        $stmt->execute(['current_members_digest_weekday']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['config_value']) && $row['config_value'] !== '') {
            $day = (int) $row['config_value'];
            if ($day >= 1 && $day <= 7) {
                return $day;
            }
        }
    } catch (Throwable $e) {
    }

    return $default;
}

/**
 * Configured weekly roster recipients.
 *
 * @return array<int, string>
 */
function current_members_digest_recipients(PDO $pdo): array
{
    try {
        $stmt = $pdo->prepare('SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1');
        $stmt->execute(['current_members_digest_recipients']);
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
