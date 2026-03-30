<?php
/**
 * Database Connection Handler
 * Manages connections to both User and Content databases
 */

require_once __DIR__ . '/../config/config.php';

class Database {
    private static $userConnection = null;
    private static $contentConnection = null;
    private static $rechConnection = null;
    private static $inventoryConnection = null;
    private static $newsConnection = null;
    /** @var bool Tracks whether content-DB schema migration has run this request */
    private static $contentMigrated = false;
    /** @var bool Tracks whether user-DB schema migration has run this request */
    private static $userMigrated = false;
    /** @var bool Tracks whether news-DB schema migration has run this request */
    private static $newsMigrated = false;

    /**
     * Get User Database Connection
     */
    public static function getUserDB() {
        if (self::$userConnection === null) {
            try {
                self::$userConnection = new PDO(
                    "mysql:host=" . DB_USER_HOST . ";dbname=" . DB_USER_NAME . ";charset=utf8mb4",
                    DB_USER_USER,
                    DB_USER_PASS,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false
                    ]
                );
            } catch (PDOException $e) {
                error_log("Verbindung fehlgeschlagen: " . $e->getCode());
                throw new Exception("Database connection failed");
            }
        }
        if (!self::$userMigrated) {
            self::migrateUserSchema(self::$userConnection);
            self::$userMigrated = true;
        }
        return self::$userConnection;
    }

    /**
     * Get Content Database Connection
     */
    public static function getContentDB() {
        if (self::$contentConnection === null) {
            try {
                self::$contentConnection = new PDO(
                    "mysql:host=" . DB_CONTENT_HOST . ";dbname=" . DB_CONTENT_NAME . ";charset=utf8mb4",
                    DB_CONTENT_USER,
                    DB_CONTENT_PASS,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false
                    ]
                );
            } catch (PDOException $e) {
                error_log("Verbindung fehlgeschlagen: " . $e->getCode());
                throw new Exception("Database connection failed");
            }
        }
        if (!self::$contentMigrated) {
            self::migrateContentSchema(self::$contentConnection);
            self::$contentMigrated = true;
        }
        return self::$contentConnection;
    }

    /**
     * Ensure optional columns added after the initial deployment exist in the users table.
     * Runs at most once per request. Safe to call even when the table already has the columns.
     */
    private static function migrateUserSchema(PDO $db): void {
        $pending = [
            'entra_photo_path'  => "ALTER TABLE users ADD COLUMN entra_photo_path VARCHAR(500) DEFAULT NULL COMMENT 'Cached profile photo path fetched from Microsoft Entra ID'",
            'avatar_path'       => "ALTER TABLE users ADD COLUMN avatar_path VARCHAR(500) DEFAULT NULL COMMENT 'Active profile photo path; NULL = default avatar, custom_* = manually uploaded, uploads/profile_photos/entra_* = synced from Entra ID'",
            'use_custom_avatar' => "ALTER TABLE users ADD COLUMN use_custom_avatar TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = user uploaded own photo (Entra photo sync disabled), 0 = Entra photo is used'",
        ];
        foreach ($pending as $column => $alterSql) {
            try {
                $stmt = $db->prepare(
                    "SELECT COLUMN_NAME
                     FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'users'
                       AND COLUMN_NAME  = ?"
                );
                $stmt->execute([$column]);
                if (!$stmt->fetch()) {
                    $db->exec($alterSql);
                    error_log("User schema migration applied: added column '$column' to users");
                }
            } catch (PDOException $e) {
                error_log("User schema migration skipped for column '$column': " . $e->getMessage());
            }
        }
    }

    /**
     * Ensure optional columns added after the initial deployment exist in alumni_profiles,
     * and that the newsletters table exists in the content database.
     * Runs at most once per request. Safe to call even when the table already has the columns.
     */
    private static function migrateContentSchema(PDO $db): void {
        // Columns to add if they are missing, keyed by column name
        $pending = [
            'skills'  => "ALTER TABLE alumni_profiles ADD COLUMN skills TEXT DEFAULT NULL COMMENT 'Comma-separated list of skills/competencies' AFTER bio",
            'cv_path' => "ALTER TABLE alumni_profiles ADD COLUMN cv_path VARCHAR(500) DEFAULT NULL COMMENT 'Path to uploaded CV/resume PDF' AFTER skills",
        ];
        foreach ($pending as $column => $alterSql) {
            try {
                $stmt = $db->prepare(
                    "SELECT COLUMN_NAME
                     FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'alumni_profiles'
                       AND COLUMN_NAME  = ?"
                );
                $stmt->execute([$column]);
                if (!$stmt->fetch()) {
                    $db->exec($alterSql);
                    error_log("Content schema migration applied: added column '$column' to alumni_profiles");
                }
            } catch (PDOException $e) {
                // Table may not exist yet on a brand-new install, or the DB user may
                // lack ALTER TABLE permission.  Log and continue – the existing
                // query-level fallbacks in Alumni/Member models will still protect
                // against hard failures.
                error_log("Content schema migration skipped for column '$column': " . $e->getMessage());
            }
        }

        // Create the newsletters table if it does not exist yet
        try {
            $stmt = $db->prepare(
                "SELECT TABLE_NAME
                 FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'newsletters'"
            );
            $stmt->execute();
            if (!$stmt->fetch()) {
                $db->exec(
                    "CREATE TABLE `newsletters` (
                        `id`          INT UNSIGNED   NOT NULL AUTO_INCREMENT,
                        `title`       VARCHAR(255)   NOT NULL,
                        `month_year`  VARCHAR(20)    DEFAULT NULL    COMMENT 'Versandmonat/-jahr, z. B. März 2025',
                        `file_path`   VARCHAR(500)   NOT NULL        COMMENT 'Gespeicherter Dateiname im Upload-Verzeichnis',
                        `uploaded_by` INT UNSIGNED   DEFAULT NULL    COMMENT 'Intranet-Benutzer-ID des Hochladenden',
                        `created_at`  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`),
                        KEY `idx_month_year`  (`month_year`),
                        KEY `idx_uploaded_by` (`uploaded_by`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    COMMENT='Internes Newsletter-Archiv (.eml Dateien)'"
                );
                error_log("Content schema migration applied: created table 'newsletters'");
            }
        } catch (PDOException $e) {
            error_log("Content schema migration skipped for table 'newsletters': " . $e->getMessage());
        }
    }

    /**
     * Ensure the newsletters table exists in the news database.
     * Runs at most once per request.
     */
    private static function migrateNewsSchema(PDO $db): void {
        try {
            $stmt = $db->prepare(
                "SELECT TABLE_NAME
                 FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'newsletters'"
            );
            $stmt->execute();
            if (!$stmt->fetch()) {
                $db->exec(
                    "CREATE TABLE `newsletters` (
                        `id`          INT UNSIGNED   NOT NULL AUTO_INCREMENT,
                        `title`       VARCHAR(255)   NOT NULL,
                        `month_year`  VARCHAR(20)    DEFAULT NULL    COMMENT 'Versandmonat/-jahr, z. B. März 2025',
                        `file_path`   VARCHAR(500)   NOT NULL        COMMENT 'Gespeicherter Dateiname im Upload-Verzeichnis',
                        `uploaded_by` INT UNSIGNED   DEFAULT NULL    COMMENT 'Intranet-Benutzer-ID des Hochladenden',
                        `created_at`  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`),
                        KEY `idx_month_year`  (`month_year`),
                        KEY `idx_uploaded_by` (`uploaded_by`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    COMMENT='Internes Newsletter-Archiv (.eml Dateien)'"
                );
                error_log("News schema migration applied: created table 'newsletters'");
            }
        } catch (PDOException $e) {
            error_log("News schema migration skipped for table 'newsletters': " . $e->getMessage());
        }
    }

    /**
     * Get Newsletter Database Connection
     * Newsletters are stored in the dedicated news database (DB_NEWS_*).
     *
     * @return PDO Database connection instance
     * @throws Exception If database connection fails
     */
    public static function getNewsletterDB() {
        return self::getNewsDB();
    }

    /**
     * Get Invoice/Rech Database Connection
     * 
     * @return PDO Database connection instance
     * @throws Exception If database connection fails
     */
    public static function getRechDB() {
        if (self::$rechConnection === null) {
            try {
                self::$rechConnection = new PDO(
                    "mysql:host=" . DB_RECH_HOST . ";port=" . DB_RECH_PORT . ";dbname=" . DB_RECH_NAME . ";charset=utf8mb4",
                    DB_RECH_USER,
                    DB_RECH_PASS,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false
                    ]
                );
            } catch (PDOException $e) {
                error_log("Verbindung fehlgeschlagen: " . $e->getCode());
                throw new Exception("Database connection failed");
            }
        }
        return self::$rechConnection;
    }

    /**
     * Get Inventory Database Connection
     *
     * @return PDO Database connection instance
     * @throws Exception If database connection fails
     */
    public static function getInventoryDB() {
        if (self::$inventoryConnection === null) {
            try {
                self::$inventoryConnection = new PDO(
                    "mysql:host=" . DB_INVENTORY_HOST . ";port=" . DB_INVENTORY_PORT . ";dbname=" . DB_INVENTORY_NAME . ";charset=utf8mb4",
                    DB_INVENTORY_USER,
                    DB_INVENTORY_PASS,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false
                    ]
                );
            } catch (PDOException $e) {
                error_log("Verbindung fehlgeschlagen: " . $e->getCode());
                throw new Exception("Database connection failed");
            }
        }
        return self::$inventoryConnection;
    }

    /**
     * Get News Database Connection
     *
     * @return PDO Database connection instance
     * @throws Exception If database connection fails
     */
    public static function getNewsDB() {
        if (self::$newsConnection === null) {
            try {
                self::$newsConnection = new PDO(
                    "mysql:host=" . DB_NEWS_HOST . ";dbname=" . DB_NEWS_NAME . ";charset=utf8mb4",
                    DB_NEWS_USER,
                    DB_NEWS_PASS,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false
                    ]
                );
            } catch (PDOException $e) {
                error_log("Verbindung fehlgeschlagen: " . $e->getCode());
                throw new Exception("Database connection failed");
            }
        }
        if (!self::$newsMigrated) {
            self::migrateNewsSchema(self::$newsConnection);
            self::$newsMigrated = true;
        }
        return self::$newsConnection;
    }

    /**
     * Get database connection by name
     * 
     * @param string $name Connection name ('user', 'content', 'rech', 'invoice', 'newsletter', 'inventory', or 'news')
     * @return PDO Database connection
     * @throws Exception If connection name is invalid
     */
    public static function getConnection($name) {
        switch ($name) {
            case 'user':
                return self::getUserDB();
            case 'content':
                return self::getContentDB();
            case 'newsletter':
                return self::getNewsletterDB();
            case 'rech':
            case 'invoice':
                return self::getRechDB();
            case 'inventory':
                return self::getInventoryDB();
            case 'news':
                return self::getNewsDB();
            default:
                throw new Exception("Invalid connection name: $name");
        }
    }

    /**
     * Close all database connections
     */
    public static function closeAll() {
        self::$userConnection = null;
        self::$contentConnection = null;
        self::$rechConnection = null;
        self::$inventoryConnection = null;
        self::$newsConnection = null;
        self::$userMigrated = false;
        self::$contentMigrated = false;
        self::$newsMigrated = false;
    }
}
