-- Migration: enable async (DHRU) services in service_usages.
--
-- The PHP flow (1-60s) closes a usage in a single HTTP request:
--   PENDING -> SUCCESS / FAILED / REFUNDED
--
-- The DHRU flow (1-5+ min) submits an order, gets back a provider
-- reference ID, and we then poll for completion:
--   PENDING -> PROCESSING -> SUCCESS / FAILED / REFUNDED
--
-- This migration:
--   1. Adds PROCESSING to the status enum.
--   2. Adds provider_order_id to track the DHRU reference id.
--   3. Adds last_polled_at to debounce polling (do not hammer the
--      provider; default debounce is 5 seconds in PHP).
--   4. Adds an index on (status, last_polled_at) so a cron worker
--      can efficiently sweep PROCESSING rows.
--
-- Portable + idempotent across MySQL (incl. 8.4) and MariaDB. MySQL does
-- NOT support `ALTER TABLE ... ADD COLUMN/KEY IF NOT EXISTS`, so each
-- additive step is guarded with an INFORMATION_SCHEMA check executed via
-- a prepared statement. Safe to re-run, and a harmless no-op when the
-- columns already exist (e.g. on a fresh DB created from schema.sql).

USE `imei_checker`;

-- 1. Ensure PROCESSING is in the status enum. MODIFY is idempotent and
--    portable, so it needs no guard.
ALTER TABLE `service_usages`
    MODIFY COLUMN `status`
        ENUM('PENDING','PROCESSING','SUCCESS','FAILED','REFUNDED')
        NOT NULL DEFAULT 'PENDING';

-- 2. provider_order_id (DHRU reference id).
SET @needs_col := (
    SELECT COUNT(*) = 0 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name   = 'service_usages'
      AND column_name  = 'provider_order_id'
);
SET @ddl := IF(@needs_col,
    'ALTER TABLE `service_usages` ADD COLUMN `provider_order_id` VARCHAR(64) DEFAULT NULL AFTER `output`',
    'SELECT 1');
PREPARE _mig FROM @ddl; EXECUTE _mig; DEALLOCATE PREPARE _mig;

-- 3. last_polled_at (poll debounce timestamp).
SET @needs_col := (
    SELECT COUNT(*) = 0 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name   = 'service_usages'
      AND column_name  = 'last_polled_at'
);
SET @ddl := IF(@needs_col,
    'ALTER TABLE `service_usages` ADD COLUMN `last_polled_at` TIMESTAMP(3) NULL DEFAULT NULL AFTER `created_at`',
    'SELECT 1');
PREPARE _mig FROM @ddl; EXECUTE _mig; DEALLOCATE PREPARE _mig;

-- 4. Sweep index for the cron worker (scripts/poll-dhru-orders.php).
--    Only rows that are PROCESSING get scanned.
SET @needs_idx := (
    SELECT COUNT(*) = 0 FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name   = 'service_usages'
      AND index_name   = 'idx_status_polled'
);
SET @ddl := IF(@needs_idx,
    'ALTER TABLE `service_usages` ADD KEY `idx_status_polled` (`status`, `last_polled_at`)',
    'SELECT 1');
PREPARE _mig FROM @ddl; EXECUTE _mig; DEALLOCATE PREPARE _mig;
