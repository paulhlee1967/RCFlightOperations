#!/usr/bin/env php
<?php
/**
 * Verify database has all tables and columns expected by schema_full.sql (single-club).
 * Usage: php scripts/verify_db.php
 * Exit code 0 = OK, 1 = missing items.
 */

require_once __DIR__ . '/../includes/cli_only_script.php';
flightops_require_cli();

$baseDir = dirname(__DIR__);
$config = require $baseDir . '/config.php';
$db = $config['db'];
$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $db['host'], $db['name'], $db['charset'] ?? 'utf8mb4');

try {
    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetch();
}

function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    return (bool) $stmt->fetch();
}

$expectedTables = [
    'club', 'users', 'members',
    'payments', 'dues_rules', 'badge_templates',
    'incidents', 'incident_photos',
    'member_fulfillments', 'member_membership_years',
    'system_config', 'operator_messages',
    'audit_log', 'login_attempts', 'password_reset_tokens', 'password_reset_ip_events',
];
$expectedColumns = [
    'club' => [
        'id', 'name', 'logo_path', 'favicon_path', 'color_primary', 'color_primary_dark',
        'color_bg', 'color_muted', 'color_text',
        'membership_type1_label', 'membership_type2_label', 'membership_type3_label', 'membership_type4_label',
        'membership_type1_enabled', 'membership_type2_enabled', 'membership_type3_enabled', 'membership_type4_enabled',
        'created_at',
    ],
    'users' => ['id', 'email', 'password_hash', 'name', 'role', 'active', 'created_at'],
    'members' => [
        'id', 'title', 'first_name', 'last_name', 'email', 'phone', 'birthday', 'photo_path', 'notes',
        'date_joined', 'membership_type_slot', 'membership_renewal_year', 'inactive', 'suspended', 'life_member', 'free_membership',
        'gate_key_number', 'badge_printed_at', 'ama_number', 'ama_expiration', 'ama_life_member', 'faa_number', 'faa_expiration', 'faa_card_path',
        'emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_phone',
        'address_street', 'address_street2', 'address_city', 'address_state', 'address_postal_code',
        'created_at', 'updated_at',
    ],
    'payments' => ['id', 'member_id', 'paid_at', 'year', 'amount_dues', 'amount_initiation', 'amount_late_fee', 'comp', 'created_at'],
    'dues_rules' => ['id', 'membership_type_slot', 'annual_dues', 'prorated_dues', 'initiation_fee', 'prorate_start_month', 'prorate_end_month'],
    'badge_templates' => ['id', 'name', 'template_data', 'is_default', 'updated_at'],
    'incidents' => [
        'id', 'incident_date', 'location', 'incident_type',
        'severity', 'status', 'member_id', 'description', 'action_taken',
        'ama_reported', 'ama_report_ref', 'reported_by', 'created_at', 'updated_at',
    ],
    'incident_photos' => [
        'id', 'incident_id', 'file_path', 'original_filename', 'created_at',
    ],
    'member_fulfillments' => ['id', 'member_id', 'year', 'processed_at', 'processed_by', 'renewal_type', 'card_printed_at', 'card_printed_by', 'mailer_printed_at', 'mailer_printed_by', 'created_at', 'updated_at'],
    'member_membership_years' => ['id', 'member_id', 'year', 'recorded_at', 'source'],
    'system_config' => ['config_key', 'config_value', 'updated_at'],
    'operator_messages' => ['id', 'subject', 'body', 'sent_to_count', 'target', 'sent_at'],
    'audit_log'       => ['id', 'user_id', 'action', 'target_type', 'target_id', 'detail', 'created_at'],
    'login_attempts'  => ['email', 'failed_count', 'locked_until', 'updated_at'],
    'password_reset_tokens' => ['token_hash', 'email', 'expires_at', 'created_at'],
    'password_reset_ip_events' => ['id', 'ip', 'created_at'],
];

$missing = [];
foreach ($expectedTables as $table) {
    if (!tableExists($pdo, $table)) {
        $missing[] = "Table: $table";
        continue;
    }
    foreach ($expectedColumns[$table] as $col) {
        if (!columnExists($pdo, $table, $col)) {
            $missing[] = "Column: $table.$col";
        }
    }
}

if (count($missing) > 0) {
    echo "Missing in database:\n";
    foreach ($missing as $m) {
        echo "  - $m\n";
    }
    echo "\nImport schema_full.sql into your database (see START_HERE.md).\n";
    exit(1);
}

echo "Database OK: all expected tables and columns present.\n";
exit(0);
