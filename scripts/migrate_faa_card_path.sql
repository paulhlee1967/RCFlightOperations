-- Add members.faa_card_path for FAA card attachments (PDF/JPG/PNG).
-- Safe to re-run: skips if the column already exists.

SET @faa_card_col = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'members'
    AND COLUMN_NAME  = 'faa_card_path'
);
SET @sql_faa_card = IF(
  @faa_card_col = 0,
  'ALTER TABLE `members` ADD COLUMN `faa_card_path` varchar(512) DEFAULT NULL AFTER `faa_expiration`',
  'SELECT 1'
);
PREPARE stmt_faa_card FROM @sql_faa_card;
EXECUTE stmt_faa_card;
DEALLOCATE PREPARE stmt_faa_card;
