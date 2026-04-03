<?php
/**
 * Database Connection Handler
 *
 * Verwaltet Verbindungen zu allen Datenbanken des Projekts.
 *
 * Singleton-Prinzip: Pro Request wird für jede Datenbank exakt EINE
 * PDO-Instanz erzeugt und danach wiederverwendet (lazy initialisation über
 * statische Eigenschaften).  Keine öffentliche Instanziierung nötig – alle
 * Methoden sind statisch.
 *
 * Fehler-Logging: Über eine injizierbare PSR-3-Logger-Instanz
 * (Psr\Log\LoggerInterface).  Wird kein Logger gesetzt, greift der
 * NullLogger, der Einträge still verwirft.  Für persistentes File-Logging
 * kann ein FileLogger injiziert werden:
 *
 *   Database::setLogger(new FileLogger(__DIR__ . '/../logs/db.log'));
 */

require_once __DIR__ . '/../config/config.php';

// Lade Composer-Autoloader, falls noch nicht geschehen (für PSR-Log-Klassen)
$_dbAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($_dbAutoload) && !class_exists('Psr\\Log\\NullLogger')) {
    require_once $_dbAutoload;
}
unset($_dbAutoload);

// FileLogger für file-basiertes Logging laden
require_once __DIR__ . '/../src/FileLogger.php';

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Database {
    // -------------------------------------------------------------------------
    // Singleton-Verbindungen (eine Instanz pro Datenbank pro Request)
    // -------------------------------------------------------------------------
    /** @var PDO|null */
    private static $userConnection = null;
    /** @var PDO|null */
    private static $contentConnection = null;
    /** @var PDO|null */
    private static $rechConnection = null;
    /** @var PDO|null */
    private static $inventoryConnection = null;
    /** @var PDO|null */
    private static $newsConnection = null;
    /** @var PDO|null */
    private static $vcardConnection = null;

    // -------------------------------------------------------------------------
    // Schema-Migrations-Flags (je einmal pro Request ausführen)
    // -------------------------------------------------------------------------
    /** @var bool Tracks whether content-DB schema migration has run this request */
    private static $contentMigrated = false;
    /** @var bool Tracks whether user-DB schema migration has run this request */
    private static $userMigrated = false;
    /** @var bool Tracks whether news-DB schema migration has run this request */
    private static $newsMigrated = false;
    /** @var bool Tracks whether vCard-DB schema migration has run this request */
    private static $vcardMigrated = false;

    // -------------------------------------------------------------------------
    // PSR-3 Logger
    // -------------------------------------------------------------------------
    /** @var LoggerInterface|null Injizierter Logger; null = NullLogger-Fallback */
    private static $logger = null;

    /** Nicht instanziierbar – alle Methoden sind statisch. */
    private function __construct() {}

    /**
     * Setzt den PSR-3-Logger, der für alle Datenbankfehler genutzt wird.
     * Wird diese Methode nicht aufgerufen, werden Fehler still verworfen (NullLogger).
     *
     * Beispiel:
     *   Database::setLogger(new FileLogger(__DIR__ . '/../logs/db.log'));
     */
    public static function setLogger(LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    /**
     * Gibt den aktiven Logger zurück.  Fällt auf NullLogger zurück, falls
     * noch keiner gesetzt wurde.
     */
    private static function getLogger(): LoggerInterface
    {
        if (self::$logger === null) {
            self::$logger = new NullLogger();
        }
        return self::$logger;
    }

    /**
     * Standard-PDO-Optionen, die auf allen Verbindungen gesetzt werden.
     *
     * PDO::ATTR_EMULATE_PREPARES = false erzwingt serverseitige Prepared
     * Statements – bessere Security (Typ-sicheres Binding) und Performance.
     *
     * @return array<int, mixed>
     */
    private static function defaultOptions(): array
    {
        return [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
    }

    /**
     * Get User Database Connection
     *
     * @return PDO Database connection instance (Singleton pro Request)
     * @throws Exception If database connection fails
     */
    public static function getUserDB(): PDO
    {
        if (self::$userConnection === null) {
            try {
                self::$userConnection = new PDO(
                    "mysql:host=" . DB_USER_HOST . ";dbname=" . DB_USER_NAME . ";charset=utf8mb4",
                    DB_USER_USER,
                    DB_USER_PASS,
                    self::defaultOptions()
                );
            } catch (PDOException $e) {
                self::getLogger()->error(
                    'User-DB Verbindung fehlgeschlagen: [{code}] {message}',
                    ['code' => $e->getCode(), 'message' => $e->getMessage()]
                );
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
     *
     * @return PDO Database connection instance (Singleton pro Request)
     * @throws Exception If database connection fails
     */
    public static function getContentDB(): PDO
    {
        if (self::$contentConnection === null) {
            try {
                self::$contentConnection = new PDO(
                    "mysql:host=" . DB_CONTENT_HOST . ";dbname=" . DB_CONTENT_NAME . ";charset=utf8mb4",
                    DB_CONTENT_USER,
                    DB_CONTENT_PASS,
                    self::defaultOptions()
                );
            } catch (PDOException $e) {
                self::getLogger()->error(
                    'Content-DB Verbindung fehlgeschlagen: [{code}] {message}',
                    ['code' => $e->getCode(), 'message' => $e->getMessage()]
                );
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
                    self::getLogger()->info(
                        "User schema migration applied: added column '{column}' to users",
                        ['column' => $column]
                    );
                }
            } catch (PDOException $e) {
                self::getLogger()->warning(
                    "User schema migration skipped for column '{column}': {message}",
                    ['column' => $column, 'message' => $e->getMessage()]
                );
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
                    self::getLogger()->info(
                        "Content schema migration applied: added column '{column}' to alumni_profiles",
                        ['column' => $column]
                    );
                }
            } catch (PDOException $e) {
                // Table may not exist yet on a brand-new install, or the DB user may
                // lack ALTER TABLE permission.  Log and continue – the existing
                // query-level fallbacks in Alumni/Member models will still protect
                // against hard failures.
                self::getLogger()->warning(
                    "Content schema migration skipped for column '{column}': {message}",
                    ['column' => $column, 'message' => $e->getMessage()]
                );
            }
        }

        // Create the audit_log table if it does not exist yet
        try {
            $stmt = $db->prepare(
                "SELECT TABLE_NAME
                 FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'audit_log'"
            );
            $stmt->execute();
            if (!$stmt->fetch()) {
                $db->exec(
                    "CREATE TABLE `audit_log` (
                        `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `user_id`     INT UNSIGNED    DEFAULT NULL    COMMENT 'Intranet-Benutzer-ID aus der Session; NULL bei System-Aktionen',
                        `table_name`  VARCHAR(100)    NOT NULL        COMMENT 'Name der geänderten Tabelle',
                        `record_id`   INT UNSIGNED    DEFAULT NULL    COMMENT 'Primärschlüssel des geänderten Datensatzes',
                        `column_name` VARCHAR(100)    NOT NULL        COMMENT 'Name der geänderten Spalte',
                        `old_value`   TEXT            DEFAULT NULL    COMMENT 'Wert vor der Änderung',
                        `new_value`   TEXT            DEFAULT NULL    COMMENT 'Wert nach der Änderung',
                        `ip_address`  VARCHAR(45)     DEFAULT NULL    COMMENT 'IP-Adresse des Aufrufers (IPv4 oder IPv6)',
                        `created_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Zeitpunkt der Änderung',
                        PRIMARY KEY (`id`),
                        KEY `idx_user_id`    (`user_id`),
                        KEY `idx_table_record` (`table_name`, `record_id`),
                        KEY `idx_created_at` (`created_at`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    COMMENT='Audit-Log: protokolliert Datenänderungen nach Benutzer, Tabelle und Spalte'"
                );
                self::getLogger()->info("Content schema migration applied: created table 'audit_log'");
            }
        } catch (PDOException $e) {
            self::getLogger()->warning(
                "Content schema migration skipped for table 'audit_log': {message}",
                ['message' => $e->getMessage()]
            );
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
                self::getLogger()->info("Content schema migration applied: created table 'newsletters'");
            }
        } catch (PDOException $e) {
            self::getLogger()->warning(
                "Content schema migration skipped for table 'newsletters': {message}",
                ['message' => $e->getMessage()]
            );
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
                self::getLogger()->info("News schema migration applied: created table 'newsletters'");
            }
        } catch (PDOException $e) {
            self::getLogger()->warning(
                "News schema migration skipped for table 'newsletters': {message}",
                ['message' => $e->getMessage()]
            );
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
     * @return PDO Database connection instance (Singleton pro Request)
     * @throws Exception If database connection fails
     */
    public static function getRechDB(): PDO
    {
        if (self::$rechConnection === null) {
            try {
                self::$rechConnection = new PDO(
                    "mysql:host=" . DB_RECH_HOST . ";port=" . DB_RECH_PORT . ";dbname=" . DB_RECH_NAME . ";charset=utf8mb4",
                    DB_RECH_USER,
                    DB_RECH_PASS,
                    self::defaultOptions()
                );
            } catch (PDOException $e) {
                self::getLogger()->error(
                    'Rech-DB Verbindung fehlgeschlagen: [{code}] {message}',
                    ['code' => $e->getCode(), 'message' => $e->getMessage()]
                );
                throw new Exception("Database connection failed");
            }
        }
        return self::$rechConnection;
    }

    /**
     * Get Inventory Database Connection
     *
     * @return PDO Database connection instance (Singleton pro Request)
     * @throws Exception If database connection fails
     */
    public static function getInventoryDB(): PDO
    {
        if (self::$inventoryConnection === null) {
            try {
                self::$inventoryConnection = new PDO(
                    "mysql:host=" . DB_INVENTORY_HOST . ";port=" . DB_INVENTORY_PORT . ";dbname=" . DB_INVENTORY_NAME . ";charset=utf8mb4",
                    DB_INVENTORY_USER,
                    DB_INVENTORY_PASS,
                    self::defaultOptions()
                );
            } catch (PDOException $e) {
                self::getLogger()->error(
                    'Inventory-DB Verbindung fehlgeschlagen: [{code}] {message}',
                    ['code' => $e->getCode(), 'message' => $e->getMessage()]
                );
                throw new Exception("Database connection failed");
            }
        }
        return self::$inventoryConnection;
    }

    /**
     * Get News Database Connection
     *
     * @return PDO Database connection instance (Singleton pro Request)
     * @throws Exception If database connection fails
     */
    public static function getNewsDB(): PDO
    {
        if (self::$newsConnection === null) {
            try {
                self::$newsConnection = new PDO(
                    "mysql:host=" . DB_NEWS_HOST . ";dbname=" . DB_NEWS_NAME . ";charset=utf8mb4",
                    DB_NEWS_USER,
                    DB_NEWS_PASS,
                    self::defaultOptions()
                );
            } catch (PDOException $e) {
                self::getLogger()->error(
                    'News-DB Verbindung fehlgeschlagen: [{code}] {message}',
                    ['code' => $e->getCode(), 'message' => $e->getMessage()]
                );
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
     * @param string $name Connection name ('user', 'content', 'rech', 'invoice', 'newsletter', 'inventory', 'news', or 'vcard')
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
            case 'vcard':
                return self::getVCardDB();
            default:
                throw new Exception("Invalid connection name: $name");
        }
    }

    /**
     * Ensure the vcards_table exists in the vCard database.
     * Runs at most once per request. Safe to call even when the table already exists.
     */
    private static function migrateVCardSchema(PDO $db): void {
        try {
            $stmt = $db->prepare(
                "SELECT TABLE_NAME
                 FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'vcards_table'"
            );
            $stmt->execute();
            if (!$stmt->fetch()) {
                $db->exec(
                    "CREATE TABLE `vcards_table` (
                        `id`         INT UNSIGNED   NOT NULL AUTO_INCREMENT,
                        `vorname`    VARCHAR(100)   DEFAULT NULL,
                        `nachname`   VARCHAR(100)   DEFAULT NULL,
                        `rolle`      VARCHAR(50)    DEFAULT NULL COMMENT 'Hierarchische Rolle (z.B. Vorstand, Ressortleitung)',
                        `funktion`   VARCHAR(255)   DEFAULT NULL,
                        `telefon`    VARCHAR(50)    DEFAULT NULL,
                        `email`      VARCHAR(255)   DEFAULT NULL,
                        `linkedin`   VARCHAR(500)   DEFAULT NULL,
                        `profilbild` VARCHAR(500)   DEFAULT NULL,
                        PRIMARY KEY (`id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    COMMENT='Kontaktkarten (vCards)'"
                );
                self::getLogger()->info("VCard schema migration applied: created table 'vcards_table'");
            }
        } catch (PDOException $e) {
            self::getLogger()->warning(
                "VCard schema migration skipped for table 'vcards_table': {message}",
                ['message' => $e->getMessage()]
            );
        }
    }

    /**
     * Get vCard Database Connection (external)
     *
     * @return PDO Database connection instance (Singleton pro Request)
     * @throws Exception If database connection fails
     */
    public static function getVCardDB(): PDO
    {
        if (self::$vcardConnection === null) {
            try {
                self::$vcardConnection = new PDO(
                    "mysql:host=" . DB_VCARD_HOST . ";dbname=" . DB_VCARD_NAME . ";charset=utf8mb4",
                    DB_VCARD_USER,
                    DB_VCARD_PASS,
                    self::defaultOptions()
                );
            } catch (PDOException $e) {
                self::getLogger()->error(
                    'VCard-DB Verbindung fehlgeschlagen: [{code}] {message}',
                    ['code' => $e->getCode(), 'message' => $e->getMessage()]
                );
                throw new Exception("Database connection failed");
            }
        }
        if (!self::$vcardMigrated) {
            self::migrateVCardSchema(self::$vcardConnection);
            self::$vcardMigrated = true;
        }
        return self::$vcardConnection;
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
        self::$vcardConnection = null;
        self::$userMigrated = false;
        self::$contentMigrated = false;
        self::$newsMigrated = false;
        self::$vcardMigrated = false;
    }
}
