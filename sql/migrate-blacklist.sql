-- Migration: community IMEI blacklist reports.
--
-- A signed-in customer can report an IMEI (e.g. stolen / bad-debt). The
-- report stores the IMEI, its TAC and serial number. When anyone later
-- checks the same IMEI, the lookup response carries a blacklist warning
-- so phone shops on the platform can avoid taking in flagged devices.
--
-- UNIQUE(imei, reported_by) keeps one active report per user per IMEI, so
-- the "reports" count reflects distinct reporters, not spam. Admins can
-- flip status to 'dismissed' to clear a false report.
--
-- Idempotent (CREATE TABLE IF NOT EXISTS); safe on MySQL 8.4 + MariaDB.

USE `imei_checker`;

CREATE TABLE IF NOT EXISTS `blacklist_reports` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `imei`          CHAR(15) NOT NULL,
    `tac`           CHAR(8) DEFAULT NULL,
    `serial_number` VARCHAR(64) DEFAULT NULL,
    `reported_by`   BIGINT UNSIGNED DEFAULT NULL,
    `reason`        VARCHAR(255) DEFAULT NULL,
    `status`        ENUM('active','dismissed') NOT NULL DEFAULT 'active',
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_imei_reporter` (`imei`, `reported_by`),
    KEY `idx_imei_status` (`imei`, `status`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_blreport_user` FOREIGN KEY (`reported_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
