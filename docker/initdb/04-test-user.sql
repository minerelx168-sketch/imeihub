-- Dev-only test user seeded by the Docker stack.
--
-- email     test@example.com
-- password  test123        (bcrypt cost 12)
-- credit    $100.00 ADJUSTMENT row, cached_balance matched
--
-- The ADJUSTMENT ledger row is what makes the balance real - the
-- runtime always recomputes balance from SUM(credit_transactions);
-- cached_balance is just a fast-path cache. Both are set here so
-- /api/services/use.php can spend right away without waiting for
-- a top-up flow.
--
-- This file lives under docker/initdb/ so it only runs in the dev
-- container's first-init phase. Production deployments should not
-- ship this user.

USE `imei_checker`;

-- 1. The user. Idempotent via UNIQUE KEY on email; the ON DUPLICATE
--    clause refreshes the password hash + verification timestamp so a
--    `docker compose down -v` followed by `up` always ends at the
--    documented test creds.
INSERT INTO `users` (`email`, `name`, `email_verified`, `password_hash`, `cached_balance`)
VALUES (
    'test@example.com',
    'Test User',
    NOW(),
    '$2y$12$.dJc14a21Lz08PaTfO9JT.nZO9tprCkMc0tZRz.zCYr0TKy0DVwZW',  -- bcrypt('test123')
    100.00
)
ON DUPLICATE KEY UPDATE
    `password_hash`  = VALUES(`password_hash`),
    `email_verified` = VALUES(`email_verified`),
    `cached_balance` = GREATEST(`cached_balance`, 100.00);

-- 2. The matching ledger row. Insert-then-noop pattern keyed on
--    (reference_type, reference_id) so a re-run does not double up.
INSERT INTO `credit_transactions`
    (`user_id`, `amount`, `type`, `reference_type`, `reference_id`, `balance_after`, `description`)
SELECT u.id, 100.00, 'ADJUSTMENT', 'Admin', 'docker-seed-initial', 100.00,
       'Docker dev seed: initial test credit for test@example.com'
FROM `users` u
WHERE u.email = 'test@example.com'
  AND NOT EXISTS (
      SELECT 1 FROM `credit_transactions`
      WHERE reference_type = 'Admin' AND reference_id = 'docker-seed-initial'
  );
