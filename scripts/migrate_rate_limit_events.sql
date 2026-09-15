-- Rate-limit counter table (also created at runtime). Safe to re-run.

CREATE TABLE IF NOT EXISTS `rate_limit_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `endpoint` varchar(100) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `endpoint_ip_created` (`endpoint`, `ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
