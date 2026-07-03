-- Migrate member_addresses rows into members.address_* columns, then drop member_addresses.
-- Safe to re-run: skips if member_addresses is already gone.

SET @addr_street_col = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'address_street'
);
SET @sql_addr_cols = IF(
  @addr_street_col = 0,
  'ALTER TABLE `members`
     ADD COLUMN `address_street` varchar(255) DEFAULT NULL AFTER `emergency_contact_phone`,
     ADD COLUMN `address_street2` varchar(255) DEFAULT NULL AFTER `address_street`,
     ADD COLUMN `address_city` varchar(128) DEFAULT NULL AFTER `address_street2`,
     ADD COLUMN `address_state` varchar(64) DEFAULT NULL AFTER `address_city`,
     ADD COLUMN `address_postal_code` varchar(32) DEFAULT NULL AFTER `address_state`',
  'SELECT 1'
);
PREPARE stmt_addr_cols FROM @sql_addr_cols;
EXECUTE stmt_addr_cols;
DEALLOCATE PREPARE stmt_addr_cols;

SET @member_addresses_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member_addresses'
);
SET @sql_migrate_addrs = IF(
  @member_addresses_exists > 0,
  'UPDATE `members` m
     SET m.`address_street` = (
           SELECT ma.`street` FROM `member_addresses` ma
            WHERE ma.`member_id` = m.`id`
            ORDER BY FIELD(ma.`type`, ''Home'', ''Work'', ''Other''), ma.`id`
            LIMIT 1
         ),
         m.`address_street2` = (
           SELECT ma.`street2` FROM `member_addresses` ma
            WHERE ma.`member_id` = m.`id`
            ORDER BY FIELD(ma.`type`, ''Home'', ''Work'', ''Other''), ma.`id`
            LIMIT 1
         ),
         m.`address_city` = (
           SELECT ma.`city` FROM `member_addresses` ma
            WHERE ma.`member_id` = m.`id`
            ORDER BY FIELD(ma.`type`, ''Home'', ''Work'', ''Other''), ma.`id`
            LIMIT 1
         ),
         m.`address_state` = (
           SELECT ma.`state` FROM `member_addresses` ma
            WHERE ma.`member_id` = m.`id`
            ORDER BY FIELD(ma.`type`, ''Home'', ''Work'', ''Other''), ma.`id`
            LIMIT 1
         ),
         m.`address_postal_code` = (
           SELECT ma.`postal_code` FROM `member_addresses` ma
            WHERE ma.`member_id` = m.`id`
            ORDER BY FIELD(ma.`type`, ''Home'', ''Work'', ''Other''), ma.`id`
            LIMIT 1
         )
   WHERE EXISTS (SELECT 1 FROM `member_addresses` ma WHERE ma.`member_id` = m.`id`)',
  'SELECT 1'
);
PREPARE stmt_migrate_addrs FROM @sql_migrate_addrs;
EXECUTE stmt_migrate_addrs;
DEALLOCATE PREPARE stmt_migrate_addrs;

SET @sql_drop_member_addresses = IF(
  @member_addresses_exists > 0,
  'DROP TABLE `member_addresses`',
  'SELECT 1'
);
PREPARE stmt_drop_member_addresses FROM @sql_drop_member_addresses;
EXECUTE stmt_drop_member_addresses;
DEALLOCATE PREPARE stmt_drop_member_addresses;
