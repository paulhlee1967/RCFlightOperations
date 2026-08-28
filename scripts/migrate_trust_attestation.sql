-- TRUST attestation on membership applications.
-- Safe to re-run: skips if the column already exists.

SET @app_trust_col = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member_applications' AND COLUMN_NAME = 'trust_attestation'
);
SET @sql_app_trust = IF(
  @app_trust_col = 0,
  'ALTER TABLE `member_applications` ADD COLUMN `trust_attestation` tinyint(1) NOT NULL DEFAULT 0 COMMENT ''Applicant certified TRUST completion and will carry proof when flying'' AFTER `faa_expiration`',
  'SELECT 1'
);
PREPARE stmt_app_trust FROM @sql_app_trust;
EXECUTE stmt_app_trust;
DEALLOCATE PREPARE stmt_app_trust;
