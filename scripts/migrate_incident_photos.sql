-- Migration: incident photo attachments
-- Idempotent: safe to run multiple times.

CREATE TABLE IF NOT EXISTS `incident_photos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `incident_id` int unsigned NOT NULL,
  `file_path` varchar(512) NOT NULL DEFAULT '',
  `original_filename` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_incident_photos_incident` (`incident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
