-- Rename user roles (2026 permission model).
-- Safe to re-run: maps old slugs only when they still exist.

UPDATE `users` SET `role` = 'manager' WHERE `role` = 'editor';
UPDATE `users` SET `role` = 'staff' WHERE `role` = 'treasurer';
UPDATE `users` SET `role` = 'report_viewer' WHERE `role` = 'viewer';

ALTER TABLE `users` MODIFY `role` varchar(32) NOT NULL DEFAULT 'manager';
