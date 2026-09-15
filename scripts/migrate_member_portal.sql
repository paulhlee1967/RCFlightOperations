-- Migration: member magic-link self-service portal
-- Idempotent: safe to run multiple times.

CREATE TABLE IF NOT EXISTS `member_magic_links` (
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
