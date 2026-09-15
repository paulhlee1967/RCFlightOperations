-- Reusable campaign discount codes for public apply.php.
-- Idempotent: safe to run multiple times.

CREATE TABLE IF NOT EXISTS `membership_discount_codes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL,
  `discount_type` varchar(16) NOT NULL DEFAULT 'amount',
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `applies_to` varchar(16) NOT NULL DEFAULT 'both',
  `notes` text DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime DEFAULT NULL,
  `disabled_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_discount_code` (`code`),
  KEY `idx_discount_codes_active` (`active`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
