-- Migration: structural anti-double-credit guard on the credit ledger.
--
-- Adds UNIQUE (reference_type, reference_id, type) so the same source event can
-- never be booked twice (one TOPUP per order, one USAGE/REFUND per usage, etc.).
-- TOPUP+BONUS (same TopUpOrder ref) and USAGE+REFUND (same ServiceUsage ref)
-- coexist because they differ by `type`. NULL reference_id rows are exempt
-- (MySQL treats NULLs as distinct), which is fine - those are manual entries.
--
-- PRE-FLIGHT: run `php scripts/check-ledger-dupes.php` first. If duplicate
-- non-NULL tuples already exist this ALTER fails (clearly) and nothing changes;
-- resolve them (append an offsetting ADJUSTMENT - never DELETE ledger rows),
-- then re-run.
--
-- Portable + idempotent across MySQL (incl. 8.4) and MariaDB.

USE `imei_checker`;

SET @needs_idx := (
    SELECT COUNT(*) = 0 FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name   = 'credit_transactions'
      AND index_name   = 'uniq_ledger_ref'
);
SET @ddl := IF(@needs_idx,
    'ALTER TABLE `credit_transactions` ADD UNIQUE KEY `uniq_ledger_ref` (`reference_type`, `reference_id`, `type`)',
    'SELECT 1');
PREPARE _mig FROM @ddl; EXECUTE _mig; DEALLOCATE PREPARE _mig;

-- record
CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `version`    VARCHAR(191) NOT NULL,
    `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('ledger-unique');
