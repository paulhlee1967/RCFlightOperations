-- Migration: weekly Current Members PDF email
-- Idempotent: safe to run multiple times.

CREATE TABLE IF NOT EXISTS `current_members_digest_deliveries` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `week` varchar(8) NOT NULL COMMENT 'ISO week YYYY-Www',
  `report_year` smallint unsigned NOT NULL,
  `recipients` text NOT NULL,
  `status` enum('claimed','sending','sent','failed') NOT NULL DEFAULT 'claimed',
  `error_message` text DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_current_members_digest_week` (`week`),
  KEY `idx_current_members_digest_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `system_config` (`config_key`, `config_value`) VALUES
  ('current_members_digest_enabled', '0'),
  ('current_members_digest_weekday', '1'),
  ('current_members_digest_recipients', '');
