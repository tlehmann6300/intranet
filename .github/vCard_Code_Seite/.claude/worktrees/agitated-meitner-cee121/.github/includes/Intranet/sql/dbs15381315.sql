-- ================================================
-- News Database Setup Script (dbs15381315)
-- ================================================
-- This database handles: News items and content
-- that are used for the newsletter
-- ================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
START TRANSACTION;
SET time_zone = "+00:00";

-- ================================================
-- TABLE: news_items
-- ================================================
CREATE TABLE IF NOT EXISTS `news_items` (
    `id`            INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `title`         VARCHAR(255)   NOT NULL                        COMMENT 'Titel der News',
    `summary`       TEXT           DEFAULT NULL                    COMMENT 'Kurze Zusammenfassung / Vorschautext für den Newsletter',
    `content`       TEXT           NOT NULL                        COMMENT 'Vollständiger Inhalt der News',
    `image_path`    VARCHAR(500)   DEFAULT NULL                    COMMENT 'Pfad zum Beitragsbild',
    `external_link` VARCHAR(500)   DEFAULT NULL                    COMMENT 'Optionaler externer Link zum vollständigen Artikel',
    `category`      VARCHAR(100)   DEFAULT NULL                    COMMENT 'Kategorie der News',
    `author_id`     INT UNSIGNED   DEFAULT NULL                    COMMENT 'Intranet-Benutzer-ID des Verfassers (Referenz auf users.id in dbs15253086; kein FK möglich wegen getrennter Datenbanken)',
    `status`        ENUM('draft','published') NOT NULL DEFAULT 'draft' COMMENT 'Veröffentlichungsstatus',
    `published_at`  DATETIME       DEFAULT NULL                    COMMENT 'Zeitpunkt der Veröffentlichung',
    `created_at`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_status`       (`status`),
    KEY `idx_author_id`    (`author_id`),
    KEY `idx_category`     (`category`),
    KEY `idx_published_at` (`published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='News-Inhalte für den Newsletter';

-- ================================================
-- TABLE: newsletter_editions
-- ================================================
CREATE TABLE IF NOT EXISTS `newsletter_editions` (
    `id`            INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `title`         VARCHAR(255)   NOT NULL                        COMMENT 'Titel der Newsletter-Ausgabe',
    `month_year`    VARCHAR(20)    DEFAULT NULL                    COMMENT 'Ausgabemonat/-jahr, z. B. März 2025',
    `status`        ENUM('draft','published','sent') NOT NULL DEFAULT 'draft' COMMENT 'Status der Newsletter-Ausgabe',
    `created_by`    INT UNSIGNED   DEFAULT NULL                    COMMENT 'Intranet-Benutzer-ID des Erstellers (Referenz auf users.id in dbs15253086; kein FK möglich wegen getrennter Datenbanken)',
    `sent_at`       DATETIME       DEFAULT NULL                    COMMENT 'Zeitpunkt des Versands',
    `created_at`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_status`     (`status`),
    KEY `idx_month_year` (`month_year`),
    KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Newsletter-Ausgaben (Editionen), die aus News-Inhalten zusammengestellt werden';

-- ================================================
-- TABLE: newsletter_edition_items
-- ================================================
CREATE TABLE IF NOT EXISTS `newsletter_edition_items` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `edition_id`   INT UNSIGNED NOT NULL                           COMMENT 'Referenz auf newsletter_editions.id',
    `news_item_id` INT UNSIGNED NOT NULL                           COMMENT 'Referenz auf news_items.id',
    `sort_order`   SMALLINT UNSIGNED NOT NULL DEFAULT 0            COMMENT 'Reihenfolge des Beitrags in der Ausgabe',
    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_edition_item` (`edition_id`, `news_item_id`),
    KEY `idx_edition_id`   (`edition_id`),
    KEY `idx_news_item_id` (`news_item_id`),
    CONSTRAINT `fk_nei_edition`   FOREIGN KEY (`edition_id`)   REFERENCES `newsletter_editions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_nei_news_item` FOREIGN KEY (`news_item_id`) REFERENCES `news_items`          (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Zuordnung von News-Inhalten zu Newsletter-Ausgaben';

COMMIT;
