-- Stripe refunds on the payments ledger (do not hard-delete Stripe rows).
-- Safe to re-run.

SET @p_status = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'ledger_status'
);
SET @sql_p_status = IF(
    @p_status = 0,
    'ALTER TABLE `payments` ADD COLUMN `ledger_status` varchar(16) NOT NULL DEFAULT ''recorded'' COMMENT ''recorded or refunded'' AFTER `payment_transaction_id`',
    'SELECT 1'
);
PREPARE stmt_p_status FROM @sql_p_status;
EXECUTE stmt_p_status;
DEALLOCATE PREPARE stmt_p_status;

SET @p_refund_amt = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'amount_refunded'
);
SET @sql_p_refund_amt = IF(
    @p_refund_amt = 0,
    'ALTER TABLE `payments` ADD COLUMN `amount_refunded` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `ledger_status`',
    'SELECT 1'
);
PREPARE stmt_p_refund_amt FROM @sql_p_refund_amt;
EXECUTE stmt_p_refund_amt;
DEALLOCATE PREPARE stmt_p_refund_amt;

SET @p_refund_id = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'stripe_refund_id'
);
SET @sql_p_refund_id = IF(
    @p_refund_id = 0,
    'ALTER TABLE `payments` ADD COLUMN `stripe_refund_id` varchar(128) DEFAULT NULL AFTER `amount_refunded`',
    'SELECT 1'
);
PREPARE stmt_p_refund_id FROM @sql_p_refund_id;
EXECUTE stmt_p_refund_id;
DEALLOCATE PREPARE stmt_p_refund_id;

SET @p_refund_at = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'refunded_at'
);
SET @sql_p_refund_at = IF(
    @p_refund_at = 0,
    'ALTER TABLE `payments` ADD COLUMN `refunded_at` datetime DEFAULT NULL AFTER `stripe_refund_id`',
    'SELECT 1'
);
PREPARE stmt_p_refund_at FROM @sql_p_refund_at;
EXECUTE stmt_p_refund_at;
DEALLOCATE PREPARE stmt_p_refund_at;
