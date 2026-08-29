-- TRUST on member records + Stripe/waived payments into the ledger.
-- Safe to re-run.

-- members.trust_attestation
SET @m_trust = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'trust_attestation'
);
SET @sql_m_trust = IF(
  @m_trust = 0,
  'ALTER TABLE `members` ADD COLUMN `trust_attestation` tinyint(1) NOT NULL DEFAULT 0 COMMENT ''Member certified TRUST completion'' AFTER `faa_card_path`',
  'SELECT 1'
);
PREPARE stmt_m_trust FROM @sql_m_trust;
EXECUTE stmt_m_trust;
DEALLOCATE PREPARE stmt_m_trust;

SET @m_trust_at = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'trust_attested_at'
);
SET @sql_m_trust_at = IF(
  @m_trust_at = 0,
  'ALTER TABLE `members` ADD COLUMN `trust_attested_at` datetime DEFAULT NULL COMMENT ''When TRUST was first certified on this record'' AFTER `trust_attestation`',
  'SELECT 1'
);
PREPARE stmt_m_trust_at FROM @sql_m_trust_at;
EXECUTE stmt_m_trust_at;
DEALLOCATE PREPARE stmt_m_trust_at;

-- Copy TRUST from approved applications onto the matched/created member.
UPDATE members m
INNER JOIN member_applications a ON a.approved_member_id = m.id
SET
  m.trust_attestation = 1,
  m.trust_attested_at = COALESCE(m.trust_attested_at, a.reviewed_at, a.submitted_at, a.created_at)
WHERE a.status = 'approved'
  AND a.trust_attestation = 1
  AND m.trust_attestation = 0;

-- payments.amount_processing_fee (Stripe processing fee)
SET @p_fee = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'amount_processing_fee'
);
SET @sql_p_fee = IF(
  @p_fee = 0,
  'ALTER TABLE `payments` ADD COLUMN `amount_processing_fee` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `amount_late_fee`',
  'SELECT 1'
);
PREPARE stmt_p_fee FROM @sql_p_fee;
EXECUTE stmt_p_fee;
DEALLOCATE PREPARE stmt_p_fee;

SET @p_app = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'application_id'
);
SET @sql_p_app = IF(
  @p_app = 0,
  'ALTER TABLE `payments` ADD COLUMN `application_id` int unsigned DEFAULT NULL COMMENT ''Source online application, if recorded on approve'' AFTER `comp`',
  'SELECT 1'
);
PREPARE stmt_p_app FROM @sql_p_app;
EXECUTE stmt_p_app;
DEALLOCATE PREPARE stmt_p_app;

SET @p_txn = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'payment_transaction_id'
);
SET @sql_p_txn = IF(
  @p_txn = 0,
  'ALTER TABLE `payments` ADD COLUMN `payment_transaction_id` varchar(128) DEFAULT NULL COMMENT ''Stripe PaymentIntent id when paid online'' AFTER `application_id`',
  'SELECT 1'
);
PREPARE stmt_p_txn FROM @sql_p_txn;
EXECUTE stmt_p_txn;
DEALLOCATE PREPARE stmt_p_txn;

SET @p_app_idx = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND INDEX_NAME = 'uniq_payments_application'
);
SET @sql_p_app_idx = IF(
  @p_app_idx = 0,
  'ALTER TABLE `payments` ADD UNIQUE KEY `uniq_payments_application` (`application_id`)',
  'SELECT 1'
);
PREPARE stmt_p_app_idx FROM @sql_p_app_idx;
EXECUTE stmt_p_app_idx;
DEALLOCATE PREPARE stmt_p_app_idx;
