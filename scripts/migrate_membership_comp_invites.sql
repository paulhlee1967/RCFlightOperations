-- Complimentary membership invites (membership_comp_invites table).
-- Idempotent: safe to run multiple times.

CREATE TABLE IF NOT EXISTS `membership_comp_invites` (
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

-- Older installs may have created the table before membership_type existed.
SET @mt_col = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'membership_comp_invites' AND COLUMN_NAME = 'membership_type'
);
SET @sql_mt = IF(
  @mt_col = 0,
  'ALTER TABLE `membership_comp_invites` ADD COLUMN `membership_type` varchar(32) NOT NULL DEFAULT ''free_membership'' AFTER `ama_number`',
  'SELECT 1'
);
PREPARE stmt_mt FROM @sql_mt;
EXECUTE stmt_mt;
DEALLOCATE PREPARE stmt_mt;
