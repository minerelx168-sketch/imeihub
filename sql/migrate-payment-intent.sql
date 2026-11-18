-- Migration: store the Stripe PaymentIntent id on top-up orders.
--
-- topup_orders.provider_charge_id holds the Checkout *session* id (cs_...),
-- but a `charge.refunded` webhook only carries the PaymentIntent (pi_...) /
-- charge (ch_...) id - so refunds never matched an order. This adds a column
-- to record the PaymentIntent at payment time so refunds can be reconciled.
--
-- Portable + idempotent across MySQL (incl. 8.4) and MariaDB (no
-- ADD COLUMN/KEY IF NOT EXISTS on MySQL, so guard via INFORMATION_SCHEMA).

USE `imei_checker`;

-- column
SET @needs_col := (
    SELECT COUNT(*) = 0 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name   = 'topup_orders'
      AND column_name  = 'provider_payment_intent'
);
SET @ddl := IF(@needs_col,
    'ALTER TABLE `topup_orders` ADD COLUMN `provider_payment_intent` VARCHAR(255) DEFAULT NULL AFTER `provider_charge_id`',
    'SELECT 1');
PREPARE _mig FROM @ddl; EXECUTE _mig; DEALLOCATE PREPARE _mig;

-- index for refund lookups
SET @needs_idx := (
    SELECT COUNT(*) = 0 FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name   = 'topup_orders'
      AND index_name   = 'idx_payment_intent'
);
SET @ddl := IF(@needs_idx,
    'ALTER TABLE `topup_orders` ADD KEY `idx_payment_intent` (`provider_payment_intent`)',
    'SELECT 1');
PREPARE _mig FROM @ddl; EXECUTE _mig; DEALLOCATE PREPARE _mig;

-- record (harmless if scripts/migrate.sh also records it)
CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `version`    VARCHAR(191) NOT NULL,
    `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('payment-intent');
