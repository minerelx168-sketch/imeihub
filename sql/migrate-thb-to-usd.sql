-- ONE-TIME MIGRATION: convert existing THB-denominated amounts to USD.
--
-- Run this exactly once, on a deployment that already has live data in
-- THB, BEFORE the application code is upgraded to the USD release.
-- Idempotency: tracks "did this migration run?" with a marker row in
-- service_prices (code = '_MIGRATION_THB_TO_USD').
--
-- Rate: 36 THB / 1 USD (adjust the SET below if needed).
-- Wallet rounding: 2 decimals (cents).
-- Service prices: at least $0.50 to keep them charge-worthy.
--
-- AFTER running:
--   1. Re-run sql/seed.sql to refresh the catalog at the new USD prices
--      (the seed uses ON DUPLICATE KEY UPDATE so it overwrites whatever
--      this migration set).
--   2. Verify with: php scripts/reconcile-balance.php
--
-- If you have no prior data (fresh install) just run sql/seed.sql; you
-- do NOT need this file.

USE `imei_checker`;

START TRANSACTION;

-- Refuse to run twice.
SET @already := (
    SELECT COUNT(*) FROM `service_prices` WHERE `code` = '_MIGRATION_THB_TO_USD'
);
SET @msg := IF(@already > 0,
    'ALREADY RAN: _MIGRATION_THB_TO_USD marker exists. Aborting.',
    'ok');
SELECT @msg AS status;

-- Stop the transaction here if already ran. (signal isn't allowed at the
-- top level on every MySQL; this SELECT serves as a visible breadcrumb,
-- the GREATEST(...) below would still no-op anyway.)
SET @rate := 36;

-- Wallet balances
UPDATE `users`
SET `cached_balance` = ROUND(`cached_balance` / @rate, 2)
WHERE @already = 0;

-- Ledger (every row keeps its sign; amount and balance_after both scale)
UPDATE `credit_transactions`
SET `amount`        = ROUND(`amount`        / @rate, 2),
    `balance_after` = ROUND(`balance_after` / @rate, 2)
WHERE @already = 0;

-- Top-up orders + flip currency code
UPDATE `topup_orders`
SET `amount`   = ROUND(`amount` / @rate, 2),
    `currency` = 'USD'
WHERE @already = 0 AND `currency` = 'THB';

-- Service usage rows
UPDATE `service_usages`
SET `cost` = ROUND(`cost` / @rate, 2)
WHERE @already = 0;

-- Service catalog (floor at $0.50 so things stay charge-worthy)
UPDATE `service_prices`
SET `cost` = GREATEST(0.50, ROUND(`cost` / @rate, 2))
WHERE @already = 0;

-- Marker so a repeat run no-ops.
INSERT INTO `service_prices` (`code`, `name`, `description`, `cost`, `active`)
VALUES ('_MIGRATION_THB_TO_USD',
        'Migration marker - do not delete',
        CONCAT('Converted ledger from THB to USD at ', @rate, ' THB/USD on ', NOW()),
        0.00, 0)
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

COMMIT;

SELECT 'Migration complete. Reconcile with: php scripts/reconcile-balance.php' AS status;
