-- Migration: admin role on users.
--
-- Adds users.is_admin (0/1). Admin-only pages gate on this flag via
-- includes/admin.php; the login/session flow is untouched.
--
-- Portable + idempotent across MySQL 8.4 and MariaDB (MySQL has no
-- ADD COLUMN IF NOT EXISTS, so guard with INFORMATION_SCHEMA).

USE `imei_checker`;

SET @needs_col := (
    SELECT COUNT(*) = 0 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name   = 'users'
      AND column_name  = 'is_admin'
);
SET @ddl := IF(@needs_col,
    'ALTER TABLE `users` ADD COLUMN `is_admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `password_hash`',
    'SELECT 1');
PREPARE _mig FROM @ddl; EXECUTE _mig; DEALLOCATE PREPARE _mig;

-- banned_at: set when an admin bans the account. Enforced in
-- includes/auth.php (a banned user's session resolves to "logged out").
SET @needs_ban := (
    SELECT COUNT(*) = 0 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name   = 'users'
      AND column_name  = 'banned_at'
);
SET @ddl_ban := IF(@needs_ban,
    'ALTER TABLE `users` ADD COLUMN `banned_at` TIMESTAMP NULL DEFAULT NULL AFTER `is_admin`',
    'SELECT 1');
PREPARE _mig_ban FROM @ddl_ban; EXECUTE _mig_ban; DEALLOCATE PREPARE _mig_ban;

-- Grant admin to the operator's designated account. Change/add emails as
-- needed; re-running is harmless.
UPDATE `users` SET `is_admin` = 1 WHERE `email` = 'admin@imeihub.net';
