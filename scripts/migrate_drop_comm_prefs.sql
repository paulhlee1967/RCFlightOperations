-- Drop allow_email and allow_postal from members (opt-out managed outside the app).
-- Safe to re-run.

SET @allow_email_col = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'allow_email'
);
SET @sql_drop_allow_email = IF(
  @allow_email_col > 0,
  'ALTER TABLE `members` DROP COLUMN `allow_email`',
  'SELECT 1'
);
PREPARE stmt_drop_allow_email FROM @sql_drop_allow_email;
EXECUTE stmt_drop_allow_email;
DEALLOCATE PREPARE stmt_drop_allow_email;

SET @allow_postal_col = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'allow_postal'
);
SET @sql_drop_allow_postal = IF(
  @allow_postal_col > 0,
  'ALTER TABLE `members` DROP COLUMN `allow_postal`',
  'SELECT 1'
);
PREPARE stmt_drop_allow_postal FROM @sql_drop_allow_postal;
EXECUTE stmt_drop_allow_postal;
DEALLOCATE PREPARE stmt_drop_allow_postal;
