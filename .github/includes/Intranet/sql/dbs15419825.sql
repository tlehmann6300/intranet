-- ================================================
-- Inventory Database Setup Script (dbs15419825)
-- ================================================
-- This database handles: Inventory items and
-- rental history for the lending/checkout system
-- ================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
START TRANSACTION;
SET time_zone = "+00:00";

-- ================================================
-- TABLE: inventory_items
-- ================================================
CREATE TABLE IF NOT EXISTS `inventory_items` (
    `id`               INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `name`             VARCHAR(255)   NOT NULL,
    `description`      TEXT           DEFAULT NULL,
    `category`         VARCHAR(100)   DEFAULT NULL    COMMENT 'Kategorie des Gegenstands',
    `location`         VARCHAR(255)   DEFAULT NULL    COMMENT 'Lagerort / Aufbewahrungsort',
    `serial_number`    VARCHAR(100)   DEFAULT NULL    COMMENT 'Seriennummer oder eindeutige Kennung',
    `condition`        ENUM('new','good','fair','poor') NOT NULL DEFAULT 'good' COMMENT 'Zustand des Gegenstands',
    `is_rentable`      TINYINT        NOT NULL DEFAULT 1  COMMENT '1 = ausleihbar, 0 = nicht ausleihbar',
    `image_path`       VARCHAR(500)   DEFAULT NULL    COMMENT 'Pfad zum Bild des Gegenstands',
    `easyverein_item_id` VARCHAR(100) DEFAULT NULL    COMMENT 'Referenz-ID aus EasyVerein',
    `notes`            TEXT           DEFAULT NULL    COMMENT 'Interne Anmerkungen',
    `created_at`       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_category`    (`category`),
    KEY `idx_is_rentable` (`is_rentable`),
    KEY `idx_condition`   (`condition`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Inventargegenstände des Vereins';

-- ================================================
-- TABLE: inventory_rentals
-- ================================================
CREATE TABLE IF NOT EXISTS `inventory_rentals` (
    `id`                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `item_id`              INT UNSIGNED  NOT NULL                     COMMENT 'Referenz auf inventory_items.id',
    `user_id`              INT UNSIGNED  NOT NULL                     COMMENT 'Intranet-Benutzer-ID',
    `easyverein_member_id` VARCHAR(100)  DEFAULT NULL                 COMMENT 'EasyVerein-Mitglieds-ID (falls vorhanden)',
    `status`               ENUM('pending','active','returned','overdue') NOT NULL DEFAULT 'pending' COMMENT 'Status der Ausleihe',
    `start_date`           DATE          NOT NULL                     COMMENT 'Beginn der Ausleihe',
    `end_date`             DATE          DEFAULT NULL                 COMMENT 'Geplantes oder tatsächliches Rückgabedatum',
    `returned_at`          DATETIME      DEFAULT NULL                 COMMENT 'Tatsächlicher Rückgabezeitpunkt',
    `notes`                TEXT          DEFAULT NULL                 COMMENT 'Anmerkungen zur Ausleihe',
    `created_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rental_item`   (`item_id`),
    KEY `idx_rental_user`   (`user_id`),
    KEY `idx_rental_status` (`status`),
    KEY `idx_rental_dates`  (`start_date`, `end_date`),
    CONSTRAINT `fk_rental_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Ausleih-Historie für Inventargegenstände';

COMMIT;
