-- Email opt-in preferences for club events and AMA/FAA expiry reminders.
-- Safe to re-run (idempotent column checks).

SET @app_club_col = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member_applications' AND COLUMN_NAME = 'email_opt_in_club_events'
);
SET @sql_app_club = IF(
  @app_club_col = 0,
  'ALTER TABLE `member_applications` ADD COLUMN `email_opt_in_club_events` tinyint(1) NOT NULL DEFAULT 0 COMMENT ''Applicant opted in to club event/announcement emails'' AFTER `email`',
  'SELECT 1'
);
PREPARE stmt_app_club FROM @sql_app_club;
EXECUTE stmt_app_club;
DEALLOCATE PREPARE stmt_app_club;

SET @app_rem_col = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member_applications' AND COLUMN_NAME = 'email_opt_in_expiry_reminders'
);
SET @sql_app_rem = IF(
  @app_rem_col = 0,
  'ALTER TABLE `member_applications` ADD COLUMN `email_opt_in_expiry_reminders` tinyint(1) NOT NULL DEFAULT 0 COMMENT ''Applicant opted in to AMA/FAA expiry reminder emails'' AFTER `email_opt_in_club_events`',
  'SELECT 1'
);
PREPARE stmt_app_rem FROM @sql_app_rem;
EXECUTE stmt_app_rem;
DEALLOCATE PREPARE stmt_app_rem;

SET @mem_club_col = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'email_opt_in_club_events'
);
SET @sql_mem_club = IF(
  @mem_club_col = 0,
  'ALTER TABLE `members` ADD COLUMN `email_opt_in_club_events` tinyint(1) NOT NULL DEFAULT 1 COMMENT ''Club event/announcement emails (Sender campaign channel)'' AFTER `email`',
  'SELECT 1'
);
PREPARE stmt_mem_club FROM @sql_mem_club;
EXECUTE stmt_mem_club;
DEALLOCATE PREPARE stmt_mem_club;

SET @mem_rem_col = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'email_opt_in_expiry_reminders'
);
SET @sql_mem_rem = IF(
  @mem_rem_col = 0,
  'ALTER TABLE `members` ADD COLUMN `email_opt_in_expiry_reminders` tinyint(1) NOT NULL DEFAULT 1 COMMENT ''AMA/FAA expiry reminder emails (Sender transactional channel)'' AFTER `email_opt_in_club_events`',
  'SELECT 1'
);
PREPARE stmt_mem_rem FROM @sql_mem_rem;
EXECUTE stmt_mem_rem;
DEALLOCATE PREPARE stmt_mem_rem;
