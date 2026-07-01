-- =============================================================================
-- RC Flight Operations — FULL schema (fresh install, single club)
--
-- This file contains the full, ready-to-import schema (initial schema + embedded upgrades).
--
-- Use it on a blank MySQL/MariaDB database so the app is ready in one import.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Begin: embedded base schema
-- -----------------------------------------------------------------------------
-- RC Flight Operations – single-club deployment
-- Run this on a blank MySQL database after creating DB + user in cPanel.

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

-- ---------------------------------------------------------------------------
-- App users (volunteers/admins who log in)
-- ---------------------------------------------------------------------------
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL DEFAULT '',
  `name` varchar(255) NOT NULL DEFAULT '',
  `role` varchar(32) NOT NULL DEFAULT 'editor',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Members (one row per person; single-valued fields only)
-- ---------------------------------------------------------------------------
CREATE TABLE `members` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(32) DEFAULT NULL,
  `first_name` varchar(255) NOT NULL DEFAULT '',
  `last_name` varchar(255) NOT NULL DEFAULT '',
  `email` varchar(255) DEFAULT NULL,
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
  `faa_number` varchar(64) DEFAULT NULL,
  `faa_expiration` date DEFAULT NULL,
  `emergency_contact_name` varchar(255) DEFAULT NULL,
  `emergency_contact_relationship` varchar(64) DEFAULT NULL,
  `emergency_contact_phone` varchar(64) DEFAULT NULL,
  `allow_email` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Club emails, reminders, report emails',
  `allow_postal` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Postal mailings (newsletters, packets)',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `renewal` (`membership_renewal_year`),
  KEY `name_sort` (`last_name`,`first_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Phones: type (Home/Work/Cell/Other) + number per row
-- ---------------------------------------------------------------------------
CREATE TABLE `member_phones` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `member_id` int unsigned NOT NULL,
  `type` enum('Home','Work','Cell','Other') NOT NULL DEFAULT 'Home',
  `number` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  CONSTRAINT `member_phones_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Addresses: type (Home/Work/Other) + full address per row
-- ---------------------------------------------------------------------------
CREATE TABLE `member_addresses` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `member_id` int unsigned NOT NULL,
  `type` enum('Home','Work','Other') NOT NULL DEFAULT 'Home',
  `street` varchar(255) DEFAULT NULL,
  `street2` varchar(255) DEFAULT NULL,
  `city` varchar(128) DEFAULT NULL,
  `state` varchar(64) DEFAULT NULL,
  `postal_code` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  CONSTRAINT `member_addresses_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Payments (one row per payment event)
-- ---------------------------------------------------------------------------
CREATE TABLE `payments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `member_id` int unsigned NOT NULL,
  `paid_at` date NOT NULL,
  `year` smallint unsigned NOT NULL,
  `amount_dues` decimal(10,2) NOT NULL DEFAULT 0.00,
  `amount_initiation` decimal(10,2) NOT NULL DEFAULT 0.00,
  `amount_late_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `comp` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `year_idx` (`year`),
  CONSTRAINT `payments_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Dues rules (per membership type slot)
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- Badge template (Fabric.js canvas JSON + background, back HTML)
-- ---------------------------------------------------------------------------
CREATE TABLE `badge_templates` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL DEFAULT 'Default' COMMENT 'Display name shown in the designer/print pickers',
  `template_data` longtext NOT NULL COMMENT 'JSON: canvas, backgroundPath, orientation, backOrientation, backHtml',
  `is_default` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Auto-selected design in badge print (only one row should be 1)',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Incidents (safety/field incidents)
-- ---------------------------------------------------------------------------
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

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- Seed data: one club row, one admin user (password set via set_password.php)
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- Migration: member_fulfillments table
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `member_fulfillments` (
  `id`               int unsigned  NOT NULL AUTO_INCREMENT,
  `member_id`        int unsigned  NOT NULL,
  `year`             smallint unsigned NOT NULL COMMENT 'Membership year this fulfillment is for',
  `processed_at`     datetime      DEFAULT NULL COMMENT 'When the renewal/signup was recorded',
  `processed_by`     int unsigned  DEFAULT NULL COMMENT 'users.id of staff who recorded it',
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

-- ---------------------------------------------------------------------------
-- Historical membership: who was a current member for each calendar year
-- (frozen when recorded — survives renewal year updates on the member row)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `member_membership_years` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `member_id` int unsigned NOT NULL,
  `year` smallint unsigned NOT NULL COMMENT 'Calendar year the member was current',
  `recorded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `source` varchar(32) NOT NULL DEFAULT 'renewal' COMMENT 'renewal | edit | import | backfill | snapshot',
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_year` (`member_id`, `year`),
  KEY `year_idx` (`year`),
  CONSTRAINT `mmy_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Migration: login_attempts for brute-force protection
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `email`         varchar(255) NOT NULL,
  `failed_count`  int unsigned NOT NULL DEFAULT 0,
  `locked_until`  datetime DEFAULT NULL,
  `updated_at`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Audit log (lightweight activity trail)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_log` (
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

-- ---------------------------------------------------------------------------
-- Password reset tokens (forgot password flow)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `token_hash` varchar(64)  NOT NULL,
  `email`      varchar(255) NOT NULL,
  `expires_at` datetime    NOT NULL,
  `created_at` datetime    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`token_hash`),
  KEY `email_expires` (`email`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Password reset IP rate limiting (forgot_password.php)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_reset_ip_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ip_created` (`ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- End: embedded base schema
-- -----------------------------------------------------------------------------

-- -----------------------------------------------------------------------------
-- Begin: embedded upgrade v1.0 additions
-- -----------------------------------------------------------------------------
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `system_config` (
  `config_key`   varchar(64)   NOT NULL,
  `config_value` text          DEFAULT NULL,
  `updated_at`   datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `system_config` (`config_key`, `config_value`) VALUES
  ('app_name',        'RC Flight Operations'),
  ('support_email',   ''),
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
  ('reports_accurate_from_year', '2027');

CREATE TABLE IF NOT EXISTS `operator_messages` (
  `id`              int unsigned  NOT NULL AUTO_INCREMENT,
  `subject`         varchar(255)  NOT NULL,
  `body`            text          NOT NULL,
  `sent_to_count`   int unsigned  NOT NULL DEFAULT 0 COMMENT 'Number of admin addresses emailed',
  `target`          varchar(32)   NOT NULL DEFAULT 'all' COMMENT 'all',
  `sent_at`         datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Drop the legacy soft-delete columns on existing databases (payments are
-- now hard-deleted; the action is captured in audit_log instead).
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'payments'
      AND COLUMN_NAME  = 'voided_at'
);
SET @sql = IF(
    @col_exists = 1,
    'ALTER TABLE `payments` DROP COLUMN `voided_at`, DROP COLUMN `voided_by`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;
-- -----------------------------------------------------------------------------
-- End: embedded upgrade v1.0 additions
-- -----------------------------------------------------------------------------

-- -----------------------------------------------------------------------------
-- Begin: embedded upgrade v1.1 membership type slots (safe re-run)
-- -----------------------------------------------------------------------------
SET FOREIGN_KEY_CHECKS = 0;

SET @t_has_m1_label = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'club' AND COLUMN_NAME = 'membership_type1_label'
);
SET @t_sql = IF(
  @t_has_m1_label = 0,
  'ALTER TABLE `club`
    ADD COLUMN `membership_type1_label` varchar(64) NOT NULL DEFAULT ''Adult'',
    ADD COLUMN `membership_type2_label` varchar(64) NOT NULL DEFAULT ''Youth'',
    ADD COLUMN `membership_type3_label` varchar(64) NOT NULL DEFAULT ''Senior'',
    ADD COLUMN `membership_type4_label` varchar(64) NOT NULL DEFAULT ''Spouse'',
    ADD COLUMN `membership_type1_enabled` tinyint(1) NOT NULL DEFAULT 1,
    ADD COLUMN `membership_type2_enabled` tinyint(1) NOT NULL DEFAULT 1,
    ADD COLUMN `membership_type3_enabled` tinyint(1) NOT NULL DEFAULT 1,
    ADD COLUMN `membership_type4_enabled` tinyint(1) NOT NULL DEFAULT 1',
  'SELECT 1'
);
PREPARE stmt FROM @t_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @m_has_slot = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'membership_type_slot'
);
SET @m_sql = IF(
  @m_has_slot = 0,
  'ALTER TABLE `members` ADD COLUMN `membership_type_slot` tinyint unsigned DEFAULT NULL COMMENT ''1-4 (club-labeled)''',
  'SELECT 1'
);
PREPARE stmt FROM @m_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @m_has_legacy = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'membership_type'
);
SET @m_migrate_sql = IF(
  @m_has_legacy = 1,
  '
  UPDATE `members`
  SET `membership_type_slot` = CASE
    WHEN `membership_type` = ''Adult''  THEN 1
    WHEN `membership_type` = ''Youth''  THEN 2
    WHEN `membership_type` = ''Senior'' THEN 3
    WHEN `membership_type` = ''Spouse'' THEN 4
    ELSE NULL
  END
  ',
  'SELECT 1'
);
PREPARE stmt FROM @m_migrate_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @d_has_slot = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dues_rules' AND COLUMN_NAME = 'membership_type_slot'
);
SET @d_sql = IF(
  @d_has_slot = 0,
  'ALTER TABLE `dues_rules` ADD COLUMN `membership_type_slot` tinyint unsigned DEFAULT NULL COMMENT ''1-4 (club-labeled)''',
  'SELECT 1'
);
PREPARE stmt FROM @d_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @d_has_legacy = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dues_rules' AND COLUMN_NAME = 'membership_type'
);
SET @d_migrate_sql = IF(
  @d_has_legacy = 1,
  '
  UPDATE `dues_rules`
  SET `membership_type_slot` = CASE
    WHEN `membership_type` = ''Adult''  THEN 1
    WHEN `membership_type` = ''Youth''  THEN 2
    WHEN `membership_type` = ''Senior'' THEN 3
    WHEN `membership_type` = ''Spouse'' THEN 4
    ELSE 1
  END
  ',
  'SELECT 1'
);
PREPARE stmt FROM @d_migrate_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @d_change_legacy_nullable = IF(
  @d_has_legacy = 1,
  'ALTER TABLE `dues_rules` MODIFY `membership_type` enum(''Adult'',''Youth'',''Senior'',''Spouse'') DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @d_change_legacy_nullable;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'dues_rules'
    AND INDEX_NAME = 'type_slot'
);
SET @idx_sql = IF(
  @idx_exists = 0,
  'ALTER TABLE `dues_rules` ADD UNIQUE KEY `type_slot` (`membership_type_slot`)',
  'SELECT 1'
);
PREPARE stmt FROM @idx_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;
-- -----------------------------------------------------------------------------
-- End: embedded upgrade v1.1 membership type slots
-- -----------------------------------------------------------------------------

-- -----------------------------------------------------------------------------
-- Migration: communication preferences (allow_email, allow_postal on members)
-- -----------------------------------------------------------------------------
SET @allow_email_col = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'members'
    AND COLUMN_NAME  = 'allow_email'
);
SET @sql_allow = IF(
  @allow_email_col = 0,
  'ALTER TABLE `members` ADD COLUMN `allow_email` tinyint(1) NOT NULL DEFAULT 1 COMMENT ''Club emails, reminders, report emails'' AFTER `emergency_contact_phone`, ADD COLUMN `allow_postal` tinyint(1) NOT NULL DEFAULT 1 COMMENT ''Postal mailings (newsletters, packets)'' AFTER `allow_email`',
  'SELECT 1'
);
PREPARE stmt_allow FROM @sql_allow;
EXECUTE stmt_allow;
DEALLOCATE PREPARE stmt_allow;

-- -----------------------------------------------------------------------------
-- Migration: multiple badge designs + board-member flag
--   * badge_templates gains name, is_default, is_board_default
--   * members gains is_board_member
-- -----------------------------------------------------------------------------
SET @bt_name_col = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'badge_templates' AND COLUMN_NAME = 'name'
);
SET @sql_bt = IF(
  @bt_name_col = 0,
  'ALTER TABLE `badge_templates`
     ADD COLUMN `name` varchar(100) NOT NULL DEFAULT ''Default'' AFTER `id`,
     ADD COLUMN `is_default` tinyint(1) NOT NULL DEFAULT 0 AFTER `template_data`,
     ADD COLUMN `is_board_default` tinyint(1) NOT NULL DEFAULT 0 AFTER `is_default`',
  'SELECT 1'
);
PREPARE stmt_bt FROM @sql_bt;
EXECUTE stmt_bt;
DEALLOCATE PREPARE stmt_bt;

-- Make the oldest existing design the default (only if no default is set yet).
SET @bt_has_default = (SELECT COUNT(*) FROM `badge_templates` WHERE `is_default` = 1);
SET @bt_min_id = (SELECT MIN(`id`) FROM `badge_templates`);
SET @sql_bt_def = IF(
  @bt_has_default = 0 AND @bt_min_id IS NOT NULL,
  CONCAT('UPDATE `badge_templates` SET `is_default` = 1 WHERE `id` = ', @bt_min_id),
  'SELECT 1'
);
PREPARE stmt_bt_def FROM @sql_bt_def;
EXECUTE stmt_bt_def;
DEALLOCATE PREPARE stmt_bt_def;

SET @board_col = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'is_board_member'
);
SET @sql_board = IF(
  @board_col = 0,
  'ALTER TABLE `members` ADD COLUMN `is_board_member` tinyint(1) NOT NULL DEFAULT 0 COMMENT ''Board members can be auto-assigned a different badge design'' AFTER `free_membership`',
  'SELECT 1'
);
PREPARE stmt_board FROM @sql_board;
EXECUTE stmt_board;
DEALLOCATE PREPARE stmt_board;

-- -----------------------------------------------------------------------------
-- Migration: dues in dues_rules only — backfill from legacy club columns, then drop
-- -----------------------------------------------------------------------------
SET @club_dues_col = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'club' AND COLUMN_NAME = 'dues_adult_regular'
);

SET @sql_dr_slot1 = IF(
  @club_dues_col > 0,
  'INSERT INTO `dues_rules` (`membership_type_slot`, `annual_dues`, `prorated_dues`, `initiation_fee`, `prorate_start_month`, `prorate_end_month`)
   SELECT 1, c.`dues_adult_regular`, c.`dues_adult_prorated`, c.`dues_initiation`, 7, 10
     FROM `club` c WHERE c.`id` = 1
      AND NOT EXISTS (SELECT 1 FROM `dues_rules` d WHERE d.`membership_type_slot` = 1)',
  'SELECT 1'
);
PREPARE stmt_dr1 FROM @sql_dr_slot1;
EXECUTE stmt_dr1;
DEALLOCATE PREPARE stmt_dr1;

SET @sql_dr_slot2 = IF(
  @club_dues_col > 0,
  'INSERT INTO `dues_rules` (`membership_type_slot`, `annual_dues`, `prorated_dues`, `initiation_fee`, `prorate_start_month`, `prorate_end_month`)
   SELECT 2, c.`dues_reduced`, c.`dues_reduced`, 0, 7, 10
     FROM `club` c WHERE c.`id` = 1
      AND NOT EXISTS (SELECT 1 FROM `dues_rules` d WHERE d.`membership_type_slot` = 2)',
  'SELECT 1'
);
PREPARE stmt_dr2 FROM @sql_dr_slot2;
EXECUTE stmt_dr2;
DEALLOCATE PREPARE stmt_dr2;

SET @sql_dr_slot3 = IF(
  @club_dues_col > 0,
  'INSERT INTO `dues_rules` (`membership_type_slot`, `annual_dues`, `prorated_dues`, `initiation_fee`, `prorate_start_month`, `prorate_end_month`)
   SELECT 3, c.`dues_reduced`, c.`dues_reduced`, 0, 7, 10
     FROM `club` c WHERE c.`id` = 1
      AND NOT EXISTS (SELECT 1 FROM `dues_rules` d WHERE d.`membership_type_slot` = 3)',
  'SELECT 1'
);
PREPARE stmt_dr3 FROM @sql_dr_slot3;
EXECUTE stmt_dr3;
DEALLOCATE PREPARE stmt_dr3;

SET @sql_dr_slot4 = IF(
  @club_dues_col > 0,
  'INSERT INTO `dues_rules` (`membership_type_slot`, `annual_dues`, `prorated_dues`, `initiation_fee`, `prorate_start_month`, `prorate_end_month`)
   SELECT 4, c.`dues_reduced`, c.`dues_reduced`, 0, 7, 10
     FROM `club` c WHERE c.`id` = 1
      AND NOT EXISTS (SELECT 1 FROM `dues_rules` d WHERE d.`membership_type_slot` = 4)',
  'SELECT 1'
);
PREPARE stmt_dr4 FROM @sql_dr_slot4;
EXECUTE stmt_dr4;
DEALLOCATE PREPARE stmt_dr4;

SET @sql_drop_club_dues = IF(
  @club_dues_col > 0,
  'ALTER TABLE `club`
     DROP COLUMN `dues_adult_regular`,
     DROP COLUMN `dues_adult_prorated`,
     DROP COLUMN `dues_initiation`,
     DROP COLUMN `dues_reduced`',
  'SELECT 1'
);
PREPARE stmt_drop_dues FROM @sql_drop_club_dues;
EXECUTE stmt_drop_dues;
DEALLOCATE PREPARE stmt_drop_dues;

-- -----------------------------------------------------------------------------
-- Migration: drop unused board-member badge columns (removed from app in 1.5)
-- -----------------------------------------------------------------------------
SET @board_member_col = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'is_board_member'
);
SET @sql_drop_board_member = IF(
  @board_member_col > 0,
  'ALTER TABLE `members` DROP COLUMN `is_board_member`',
  'SELECT 1'
);
PREPARE stmt_drop_board_member FROM @sql_drop_board_member;
EXECUTE stmt_drop_board_member;
DEALLOCATE PREPARE stmt_drop_board_member;

SET @board_default_col = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'badge_templates' AND COLUMN_NAME = 'is_board_default'
);
SET @sql_drop_board_default = IF(
  @board_default_col > 0,
  'ALTER TABLE `badge_templates` DROP COLUMN `is_board_default`',
  'SELECT 1'
);
PREPARE stmt_drop_board_default FROM @sql_drop_board_default;
EXECUTE stmt_drop_board_default;
DEALLOCATE PREPARE stmt_drop_board_default;
