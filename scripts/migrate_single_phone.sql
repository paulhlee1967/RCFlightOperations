-- Migrate member_phones rows into members.phone, then drop member_phones.
-- Safe to re-run: skips if member_phones is already gone.

SET @members_phone_col = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'phone'
);
SET @sql_members_phone = IF(
  @members_phone_col = 0,
  'ALTER TABLE `members` ADD COLUMN `phone` varchar(64) DEFAULT NULL AFTER `email`',
  'SELECT 1'
);
PREPARE stmt_members_phone FROM @sql_members_phone;
EXECUTE stmt_members_phone;
DEALLOCATE PREPARE stmt_members_phone;

SET @member_phones_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member_phones'
);
SET @sql_migrate_phones = IF(
  @member_phones_exists > 0,
  'UPDATE `members` m
     SET m.`phone` = (
       SELECT mp.`number` FROM `member_phones` mp
        WHERE mp.`member_id` = m.`id`
          AND mp.`number` IS NOT NULL AND TRIM(mp.`number`) != ''''
        ORDER BY FIELD(mp.`type`, ''Cell'', ''Home'', ''Work'', ''Other''), mp.`id`
        LIMIT 1
     )
   WHERE m.`phone` IS NULL OR TRIM(m.`phone`) = ''''',
  'SELECT 1'
);
PREPARE stmt_migrate_phones FROM @sql_migrate_phones;
EXECUTE stmt_migrate_phones;
DEALLOCATE PREPARE stmt_migrate_phones;

SET @sql_drop_member_phones = IF(
  @member_phones_exists > 0,
  'DROP TABLE `member_phones`',
  'SELECT 1'
);
PREPARE stmt_drop_member_phones FROM @sql_drop_member_phones;
EXECUTE stmt_drop_member_phones;
DEALLOCATE PREPARE stmt_drop_member_phones;
