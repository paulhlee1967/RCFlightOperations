-- Migration: monthly board packet automatic delivery
-- Idempotent: safe to run multiple times.

CREATE TABLE IF NOT EXISTS `board_packet_deliveries` (
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

INSERT IGNORE INTO `system_config` (`config_key`, `config_value`) VALUES
  ('board_packet_enabled', '0'),
  ('board_packet_send_day', '1'),
  ('board_packet_recipients', '');
