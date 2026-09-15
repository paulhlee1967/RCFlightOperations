-- ============================================================================
-- Migration: multiple badge designs + board-member flag
--
-- Safe to run on an EXISTING database. This file ONLY adds the new columns;
-- it does NOT create/drop any tables, so it won't conflict with data you
-- already have. It is idempotent — running it more than once is harmless.
--
--   * badge_templates gains: name, is_default, is_board_default
--   * members         gains: is_board_member
--
-- Usage:
--   mysql -u <user> -p <database> < scripts/migrate_badge_designs.sql
-- (the -p flag prompts you for the password; <database> is the DB name)
-- ============================================================================

-- ── badge_templates: name, is_default, is_board_default ─────────────────────
SET @bt_name_col = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'badge_templates' AND COLUMN_NAME = 'name'
);
SET @sql_bt = IF(
  @bt_name_col = 0,
  'ALTER TABLE `badge_templates`
     ADD COLUMN `name` varchar(100) NOT NULL DEFAULT ''Default'' AFTER `id`,
     ADD COLUMN `is_default` tinyint(1) NOT NULL DEFAULT 0 AFTER `template_data`,
     ADD COLUMN `is_board_default` tinyint(1) NOT NULL DEFAULT 0 AFTER `is_default`',
  'SELECT 1'
);
PREPARE stmt_bt FROM @sql_bt;
EXECUTE stmt_bt;
DEALLOCATE PREPARE stmt_bt;

-- Promote the oldest existing design to "default" if none is set yet.
SET @bt_has_default = (SELECT COUNT(*) FROM `badge_templates` WHERE `is_default` = 1);
SET @bt_min_id = (SELECT MIN(`id`) FROM `badge_templates`);
SET @sql_bt_def = IF(
  @bt_has_default = 0 AND @bt_min_id IS NOT NULL,
  CONCAT('UPDATE `badge_templates` SET `is_default` = 1 WHERE `id` = ', @bt_min_id),
  'SELECT 1'
);
PREPARE stmt_bt_def FROM @sql_bt_def;
EXECUTE stmt_bt_def;
DEALLOCATE PREPARE stmt_bt_def;

-- ── members: is_board_member ────────────────────────────────────────────────
SET @board_col = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'is_board_member'
);
SET @sql_board = IF(
  @board_col = 0,
  'ALTER TABLE `members` ADD COLUMN `is_board_member` tinyint(1) NOT NULL DEFAULT 0 COMMENT ''Board members can be auto-assigned a different badge design'' AFTER `free_membership`',
  'SELECT 1'
);
PREPARE stmt_board FROM @sql_board;
EXECUTE stmt_board;
DEALLOCATE PREPARE stmt_board;
