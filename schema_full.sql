-- =============================================================================
-- RC Flight Operations — current schema (fresh install, single club)
--
-- Import this file on a blank MySQL/MariaDB database.
-- Existing installs: do not re-import. Run scripts/migrate_*.sql in DEPLOY.md
-- order, then php scripts/verify_db.php.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- Club (single row: branding, theme, membership type labels)
-- ---------------------------------------------------------------------------
CREATE TABLE `club` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `logo_path` varchar(512) DEFAULT NULL,
  `favicon_path` varchar(512) DEFAULT NULL,
  `color_primary` varchar(7) DEFAULT '#6f7c3d',
  `color_primary_dark` varchar(7) DEFAULT '#556030',
  `color_bg` varchar(7) DEFAULT '#f3efe4',
  `color_muted` varchar(7) DEFAULT '#665e52',
  `color_text` varchar(7) DEFAULT '#252018',
  `membership_type1_label` varchar(64) NOT NULL DEFAULT 'Adult',
  `membership_type2_label` varchar(64) NOT NULL DEFAULT 'Youth',
  `membership_type3_label` varchar(64) NOT NULL DEFAULT 'Senior',
  `membership_type4_label` varchar(64) NOT NULL DEFAULT 'Spouse',
  `membership_type1_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `membership_type2_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `membership_type3_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `membership_type4_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL DEFAULT '',
  `name` varchar(255) NOT NULL DEFAULT '',
  `role` varchar(32) NOT NULL DEFAULT 'manager',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `members` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(32) DEFAULT NULL,
  `first_name` varchar(255) NOT NULL DEFAULT '',
  `last_name` varchar(255) NOT NULL DEFAULT '',
  `email` varchar(255) DEFAULT NULL,
  `email_opt_in_club_events` tinyint(1) NOT NULL DEFAULT 1,
  `email_opt_in_expiry_reminders` tinyint(1) NOT NULL DEFAULT 1,
  `phone` varchar(64) DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `photo_path` varchar(512) DEFAULT NULL,
  `notes` text,
  `date_joined` date DEFAULT NULL,
  `membership_type_slot` tinyint unsigned DEFAULT NULL COMMENT '1-4 (club-labeled)',
  `membership_renewal_year` smallint unsigned DEFAULT NULL,
  `inactive` tinyint(1) NOT NULL DEFAULT 0,
  `suspended` tinyint(1) NOT NULL DEFAULT 0,
  `life_member` tinyint(1) NOT NULL DEFAULT 0,
  `free_membership` tinyint(1) NOT NULL DEFAULT 0,
  `gate_key_number` varchar(32) DEFAULT NULL,
  `badge_printed_at` datetime DEFAULT NULL,
  `ama_number` varchar(64) DEFAULT NULL,
  `ama_expiration` date DEFAULT NULL,
  `ama_life_member` tinyint(1) NOT NULL DEFAULT 0,
  `faa_number` varchar(64) DEFAULT NULL COMMENT 'Historical; UI no longer collects FAA registration',
  `faa_expiration` date DEFAULT NULL,
  `faa_card_path` varchar(512) DEFAULT NULL,
  `trust_attestation` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Member certified TRUST completion',
  `trust_attested_at` datetime DEFAULT NULL,
  `emergency_contact_name` varchar(255) DEFAULT NULL,
  `emergency_contact_relationship` varchar(64) DEFAULT NULL,
  `emergency_contact_phone` varchar(64) DEFAULT NULL,
  `address_street` varchar(255) DEFAULT NULL,
  `address_street2` varchar(255) DEFAULT NULL,
  `address_city` varchar(128) DEFAULT NULL,
  `address_state` varchar(64) DEFAULT NULL,
  `address_postal_code` varchar(32) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `renewal` (`membership_renewal_year`),
  KEY `name_sort` (`last_name`,`first_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `member_id` int unsigned NOT NULL,
  `paid_at` date NOT NULL,
  `year` smallint unsigned NOT NULL,
  `amount_dues` decimal(10,2) NOT NULL DEFAULT 0.00,
  `amount_initiation` decimal(10,2) NOT NULL DEFAULT 0.00,
  `amount_late_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `amount_processing_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `comp` tinyint(1) NOT NULL DEFAULT 0,
  `application_id` int unsigned DEFAULT NULL COMMENT 'Source online application, if recorded on approve',
  `payment_transaction_id` varchar(128) DEFAULT NULL COMMENT 'Stripe PaymentIntent id when paid online',
  `ledger_status` varchar(16) NOT NULL DEFAULT 'recorded' COMMENT 'recorded or refunded',
  `amount_refunded` decimal(10,2) NOT NULL DEFAULT 0.00,
  `stripe_refund_id` varchar(128) DEFAULT NULL,
  `refunded_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `year_idx` (`year`),
  UNIQUE KEY `uniq_payments_application` (`application_id`),
  CONSTRAINT `payments_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `dues_rules` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `membership_type_slot` tinyint unsigned NOT NULL COMMENT '1-4 (club-labeled)',
  `annual_dues` decimal(10,2) NOT NULL DEFAULT 0.00,
  `prorated_dues` decimal(10,2) NOT NULL DEFAULT 0.00,
  `initiation_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `prorate_start_month` tinyint unsigned DEFAULT 7,
  `prorate_end_month` tinyint unsigned DEFAULT 10,
  PRIMARY KEY (`id`),
  UNIQUE KEY `type_slot` (`membership_type_slot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `badge_templates` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL DEFAULT 'Default',
  `template_data` longtext NOT NULL COMMENT 'JSON: canvas, backgroundPath, orientation, backOrientation, backHtml',
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `incidents` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `incident_date` date NOT NULL,
  `location` varchar(255) NOT NULL DEFAULT '',
  `incident_type` varchar(64) NOT NULL DEFAULT 'other',
  `severity` varchar(32) NOT NULL DEFAULT 'minor',
  `status` varchar(32) NOT NULL DEFAULT 'open',
  `member_id` int unsigned DEFAULT NULL,
  `description` text NOT NULL,
  `action_taken` text DEFAULT NULL,
  `ama_reported` tinyint(1) NOT NULL DEFAULT 0,
  `ama_report_ref` varchar(64) DEFAULT NULL,
  `reported_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `incident_date` (`incident_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `incident_photos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `incident_id` int unsigned NOT NULL,
  `file_path` varchar(512) NOT NULL DEFAULT '',
  `original_filename` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_incident_photos_incident` (`incident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `member_fulfillments` (
  `id`               int unsigned  NOT NULL AUTO_INCREMENT,
  `member_id`        int unsigned  NOT NULL,
  `year`             smallint unsigned NOT NULL,
  `processed_at`     datetime      DEFAULT NULL,
  `processed_by`     int unsigned  DEFAULT NULL,
  `renewal_type`     varchar(32)   DEFAULT NULL COMMENT 'new | on_time | late | complementary',
  `card_printed_at`  datetime      DEFAULT NULL,
  `card_printed_by`  int unsigned  DEFAULT NULL,
  `mailer_printed_at` datetime     DEFAULT NULL,
  `mailer_printed_by` int unsigned DEFAULT NULL,
  `created_at`       datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_year` (`member_id`, `year`),
  KEY `year_idx` (`year`),
  CONSTRAINT `mf_member`  FOREIGN KEY (`member_id`)  REFERENCES `members` (`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `member_membership_years` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `member_id` int unsigned NOT NULL,
  `year` smallint unsigned NOT NULL,
  `recorded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `source` varchar(32) NOT NULL DEFAULT 'renewal' COMMENT 'renewal | edit | import | backfill | snapshot',
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_year` (`member_id`, `year`),
  KEY `year_idx` (`year`),
  CONSTRAINT `mmy_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `login_attempts` (
  `email`         varchar(255) NOT NULL,
  `failed_count`  int unsigned NOT NULL DEFAULT 0,
  `locked_until`  datetime DEFAULT NULL,
  `updated_at`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_log` (
  `id`         int unsigned NOT NULL AUTO_INCREMENT,
  `user_id`    int unsigned NOT NULL DEFAULT 0,
  `action`     varchar(64)  NOT NULL,
  `target_type` varchar(32) NOT NULL DEFAULT '',
  `target_id`  int unsigned NOT NULL DEFAULT 0,
  `detail`     varchar(1024) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `created` (`created_at`),
  KEY `target` (`target_type`, `target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `password_reset_tokens` (
  `token_hash` varchar(64)  NOT NULL,
  `email`      varchar(255) NOT NULL,
  `expires_at` datetime    NOT NULL,
  `created_at` datetime    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`token_hash`),
  KEY `email_expires` (`email`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `password_reset_ip_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ip_created` (`ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `rate_limit_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `endpoint` varchar(100) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `endpoint_ip_created` (`endpoint`, `ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Native /apply.php queue. wpforms_entry_id is the unique submission reference
-- (legacy column name from the retired WPForms intake).
CREATE TABLE `member_applications` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `status` enum('pending_payment','pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `wpforms_entry_id` varchar(64) NOT NULL COMMENT 'Unique submission reference',
  `wpforms_form_id` int unsigned DEFAULT NULL COMMENT 'Unused; kept for historical rows',
  `submitted_at` datetime DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` int unsigned DEFAULT NULL,
  `approved_member_id` int unsigned DEFAULT NULL,
  `application_kind` varchar(16) NOT NULL DEFAULT 'unknown',
  `form_season` varchar(32) DEFAULT NULL,
  `suggested_renewal_type` varchar(16) DEFAULT NULL,
  `suggested_renewal_year` smallint unsigned DEFAULT NULL,
  `matched_member_id` int unsigned DEFAULT NULL,
  `match_confidence` varchar(16) DEFAULT NULL,
  `match_method` varchar(32) DEFAULT NULL,
  `first_name` varchar(255) NOT NULL DEFAULT '',
  `last_name` varchar(255) NOT NULL DEFAULT '',
  `middle_name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `email_opt_in_club_events` tinyint(1) NOT NULL DEFAULT 0,
  `email_opt_in_expiry_reminders` tinyint(1) NOT NULL DEFAULT 0,
  `birthday` date DEFAULT NULL,
  `phone` varchar(64) DEFAULT NULL,
  `emergency_contact_name` varchar(255) DEFAULT NULL,
  `emergency_contact_relationship` varchar(64) DEFAULT NULL,
  `emergency_contact_phone` varchar(64) DEFAULT NULL,
  `address_street` varchar(255) DEFAULT NULL,
  `address_street2` varchar(255) DEFAULT NULL,
  `address_city` varchar(128) DEFAULT NULL,
  `address_state` varchar(64) DEFAULT NULL,
  `address_postal_code` varchar(32) DEFAULT NULL,
  `ama_number` varchar(64) DEFAULT NULL,
  `ama_expiration` date DEFAULT NULL,
  `faa_number` varchar(64) DEFAULT NULL,
  `faa_expiration` date DEFAULT NULL,
  `trust_attestation` tinyint(1) NOT NULL DEFAULT 0,
  `membership_type_slot` tinyint unsigned DEFAULT NULL,
  `notes` text,
  `payment_total` decimal(10,2) DEFAULT NULL,
  `payment_initiation` decimal(10,2) DEFAULT NULL,
  `payment_processing_fee` decimal(10,2) DEFAULT NULL,
  `payment_gateway` varchar(64) DEFAULT NULL,
  `payment_transaction_id` varchar(128) DEFAULT NULL,
  `payment_status` varchar(32) DEFAULT NULL,
  `file_ama_verification_url` varchar(512) DEFAULT NULL,
  `file_faa_registration_url` varchar(512) DEFAULT NULL,
  `file_badge_photo_url` varchar(512) DEFAULT NULL,
  `file_signature_url` varchar(512) DEFAULT NULL,
  `raw_payload` json DEFAULT NULL,
  `rejection_reason` text,
  `latest_info_request_message` text DEFAULT NULL,
  `latest_info_request_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wpforms_entry_id` (`wpforms_entry_id`),
  KEY `status` (`status`),
  KEY `matched_member_id` (`matched_member_id`),
  KEY `approved_member_id` (`approved_member_id`),
  CONSTRAINT `member_applications_matched_member` FOREIGN KEY (`matched_member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `member_applications_approved_member` FOREIGN KEY (`approved_member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `membership_comp_invites` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) DEFAULT NULL,
  `ama_number` varchar(32) DEFAULT NULL,
  `membership_type` varchar(32) NOT NULL DEFAULT 'free_membership',
  `notes` text DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime DEFAULT NULL,
  `redeemed_at` datetime DEFAULT NULL,
  `redeemed_application_id` int unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_comp_invites_email` (`email`),
  KEY `idx_comp_invites_ama` (`ama_number`),
  KEY `idx_comp_invites_active` (`redeemed_at`, `cancelled_at`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `member_application_emails` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int unsigned NOT NULL,
  `email_type` enum('received','approved','request_info') NOT NULL,
  `idempotency_key` varchar(128) NOT NULL,
  `recipient` varchar(255) NOT NULL DEFAULT '',
  `subject` varchar(255) NOT NULL DEFAULT '',
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_idempotency_key` (`idempotency_key`),
  KEY `idx_application_emails_app` (`application_id`),
  KEY `idx_application_emails_type_status` (`email_type`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `member_application_info_requests` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int unsigned NOT NULL,
  `message` text NOT NULL,
  `requested_by` int unsigned NOT NULL,
  `dedup_key` varchar(64) NOT NULL,
  `requested_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_info_request_dedup` (`dedup_key`),
  KEY `idx_info_requests_application` (`application_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `board_packet_deliveries` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `month` varchar(7) NOT NULL COMMENT 'YYYY-MM calendar month',
  `recipients` text NOT NULL,
  `status` enum('claimed','sending','sent','failed') NOT NULL DEFAULT 'claimed',
  `error_message` text DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_board_packet_month` (`month`),
  KEY `idx_board_packet_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `member_magic_links` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `member_id` int unsigned NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `requested_ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_member_magic_token` (`token_hash`),
  KEY `idx_member_magic_member` (`member_id`),
  KEY `idx_member_magic_expires` (`expires_at`),
  CONSTRAINT `member_magic_links_member`
    FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `system_config` (
  `config_key`   varchar(64)   NOT NULL,
  `config_value` text          DEFAULT NULL,
  `updated_at`   datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `operator_messages` (
  `id`              int unsigned  NOT NULL AUTO_INCREMENT,
  `subject`         varchar(255)  NOT NULL,
  `body`            text          NOT NULL,
  `sent_to_count`   int unsigned  NOT NULL DEFAULT 0,
  `target`          varchar(32)   NOT NULL DEFAULT 'all',
  `sent_at`         datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO `club` (`id`, `name`, `color_primary`, `color_primary_dark`, `color_bg`, `color_muted`, `color_text`, `membership_type1_label`, `membership_type2_label`, `membership_type3_label`, `membership_type4_label`, `membership_type1_enabled`, `membership_type2_enabled`, `membership_type3_enabled`, `membership_type4_enabled`) VALUES (1, 'RC Flight Operations', '#6f7c3d', '#556030', '#f3efe4', '#665e52', '#252018', 'Adult', 'Youth', 'Senior', 'Spouse', 1, 1, 1, 1);

INSERT INTO `dues_rules` (`membership_type_slot`, `annual_dues`, `prorated_dues`, `initiation_fee`, `prorate_start_month`, `prorate_end_month`) VALUES
(1, 160.00, 80.00, 50.00, 7, 10),
(2,  20.00, 20.00,  0.00, 7, 10),
(3,  20.00, 20.00,  0.00, 7, 10),
(4,  20.00, 20.00,  0.00, 7, 10);

INSERT INTO `users` (`email`, `password_hash`, `name`, `role`) VALUES
('admin@yourclub.local', '', 'Club Admin', 'admin');
-- Run scripts/set_password.php after first deploy to set the admin password.

INSERT INTO `badge_templates` (`name`, `template_data`, `is_default`) VALUES ('Default', '{}', 1);

INSERT INTO `system_config` (`config_key`, `config_value`) VALUES
  ('app_name',        'RC Flight Operations'),
  ('support_email',   ''),
  ('membership_email', ''),
  ('smtp_host',       ''),
  ('smtp_port',       '587'),
  ('smtp_encryption', 'tls'),
  ('smtp_username',   ''),
  ('smtp_password',   ''),
  ('smtp_from_email', ''),
  ('smtp_from_name',  ''),
  ('maintenance_mode','0'),
  ('renewal_prebook_start_month', '10'),
  ('renewal_prebook_start_day', '15'),
  ('reports_accurate_from_year', '2027'),
  ('application_webhook_secret', ''),
  ('stripe_publishable_key', ''),
  ('stripe_secret_key', ''),
  ('stripe_webhook_secret', ''),
  ('stripe_test_mode', '0'),
  ('board_packet_enabled', '0'),
  ('board_packet_send_day', '1'),
  ('board_packet_recipients', '');
