-- Application status emails and staff information-request history.
-- Safe to re-run (idempotent).

CREATE TABLE IF NOT EXISTS `member_application_emails` (
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

CREATE TABLE IF NOT EXISTS `member_application_info_requests` (
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

SET @latest_info_msg_col = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member_applications' AND COLUMN_NAME = 'latest_info_request_message'
);
SET @sql_latest_info_msg = IF(
  @latest_info_msg_col = 0,
  'ALTER TABLE `member_applications` ADD COLUMN `latest_info_request_message` text DEFAULT NULL AFTER `rejection_reason`',
  'SELECT 1'
);
PREPARE stmt_latest_info_msg FROM @sql_latest_info_msg;
EXECUTE stmt_latest_info_msg;
DEALLOCATE PREPARE stmt_latest_info_msg;

SET @latest_info_at_col = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member_applications' AND COLUMN_NAME = 'latest_info_request_at'
);
SET @sql_latest_info_at = IF(
  @latest_info_at_col = 0,
  'ALTER TABLE `member_applications` ADD COLUMN `latest_info_request_at` datetime DEFAULT NULL AFTER `latest_info_request_message`',
  'SELECT 1'
);
PREPARE stmt_latest_info_at FROM @sql_latest_info_at;
EXECUTE stmt_latest_info_at;
DEALLOCATE PREPARE stmt_latest_info_at;
