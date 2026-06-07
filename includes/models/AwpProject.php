<?php
/**
 * AwpProject – Datenmodell für AWP-Anwärter-Projekte & -Bewerbungen.
 *
 * Liest/schreibt die Tabellen awp_projekte, bewerber, projekt_bewerbungen.
 * Diese Tabellen werden vom öffentlichen Karriere-Portal (Ordner `karriere/`)
 * befüllt und hier im Intranet verwaltet. Beide Anwendungen verbinden sich auf
 * DIESELBE Datenbank – die in der Intranet-.env als DB_KARRIERE_* hinterlegte
 * Karriere-Datenbank.
 *
 * Alle Zugriffe ausschließlich über Prepared Statements.
 */

require_once __DIR__ . '/../database.php'; // lädt config.php → _env()

class AwpProject
{
    private static ?PDO $karrierePdo = null;

    /** Liefert die Verbindung zur gemeinsamen Karriere-/AWP-Datenbank. */
    private static function db(): PDO
    {
        if (self::$karrierePdo instanceof PDO) {
            return self::$karrierePdo;
        }

        $host = _env('DB_KARRIERE_HOST');
        $port = _env('DB_KARRIERE_PORT', '3306');
        $name = _env('DB_KARRIERE_NAME');
        $user = _env('DB_KARRIERE_USER');
        $pass = _env('DB_KARRIERE_PASS');

        if ($host === '' || $user === '') {
            throw new RuntimeException('Karriere-DB ist nicht konfiguriert (DB_KARRIERE_* in .env fehlen).');
        }

        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        // DB-Name automatisch ermitteln, falls nicht gesetzt (IONOS: 1 User = 1 DB).
        if ($name === '') {
            $boot = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port), $user, $pass, $opts);
            $name = (string) ($boot->query(
                "SELECT schema_name FROM information_schema.schemata
                 WHERE schema_name NOT IN ('information_schema','performance_schema','mysql','sys')
                 ORDER BY schema_name LIMIT 1"
            )->fetchColumn() ?: '');
            $boot = null;
            if ($name === '') {
                throw new RuntimeException('Keine zugängliche Karriere-Datenbank gefunden.');
            }
        }

        self::$karrierePdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name),
            $user, $pass, $opts
        );
        return self::$karrierePdo;
    }

    /**
     * Stellt sicher, dass die drei AWP-Tabellen existieren (idempotent).
     * So funktioniert das Modul auch ohne manuellen SQL-Import.
     */
    public static function ensureSchema(): void
    {
        $db = self::db();
        $db->exec(
            "CREATE TABLE IF NOT EXISTS awp_projekte (
                id INT AUTO_INCREMENT PRIMARY KEY,
                titel VARCHAR(255) NOT NULL,
                beschreibung TEXT NOT NULL,
                teamgroesse INT NOT NULL,
                qm_person_id INT,
                projektleiter_id INT,
                kunde VARCHAR(255),
                projekt_bild VARCHAR(255),
                projekt_datei VARCHAR(255),
                status ENUM('offen','geschlossen') DEFAULT 'offen',
                erstellt_am TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS bewerber (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL,
                telefon VARCHAR(50),
                alter_jahre INT,
                geburtsdatum DATE,
                studiengang VARCHAR(255),
                semester INT,
                profilbild_pfad VARCHAR(255),
                lebenslauf_pdf_pfad VARCHAR(255),
                lebenslauf_text TEXT,
                beworben_am TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS awp_settings (
                setting_key   VARCHAR(64) PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL,
                updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS projekt_bewerbungen (
                id INT AUTO_INCREMENT PRIMARY KEY,
                bewerber_id INT,
                projekt_id INT,
                motivationsschreiben TEXT NOT NULL,
                status ENUM('eingegangen','zugeordnet','abgelehnt') DEFAULT 'eingegangen',
                FOREIGN KEY (bewerber_id) REFERENCES bewerber(id) ON DELETE CASCADE,
                FOREIGN KEY (projekt_id)  REFERENCES awp_projekte(id) ON DELETE CASCADE,
                INDEX idx_projekt (projekt_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /* ── Globale Einstellungen (awp_settings) ────────────────────────────── */

    public static function getSetting(string $key, string $default = ''): string
    {
        try {
            $stmt = self::db()->prepare("SELECT setting_value FROM awp_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $val = $stmt->fetchColumn();
            return $val === false ? $default : (string) $val;
        } catch (Throwable $e) {
            return $default;
        }
    }

    public static function setSetting(string $key, string $value): void
    {
        $stmt = self::db()->prepare(
            "INSERT INTO awp_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $stmt->execute([$key, $value]);
    }

    /** Ist die Bewerbungsphase aktuell geöffnet? (Standard: offen) */
    public static function applicationsOpen(): bool
    {
        return self::getSetting('applications_open', '1') === '1';
    }

    public static function setApplicationsOpen(bool $open): void
    {
        self::setSetting('applications_open', $open ? '1' : '0');
    }

    /* ── Projekte ────────────────────────────────────────────────────────── */

    public static function allProjects(): array
    {
        return self::db()->query(
            "SELECT * FROM awp_projekte ORDER BY status ASC, erstellt_am DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getProject(int $id): ?array
    {
        $stmt = self::db()->prepare("SELECT * FROM awp_projekte WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Legt ein AWP-Projekt an.
     * @param array $data titel, beschreibung, teamgroesse, qm_person_id,
     *                    projektleiter_id, kunde, projekt_bild, projekt_datei
     * @return int neue Projekt-ID
     */
    public static function createProject(array $data): int
    {
        $db = self::db();
        $stmt = $db->prepare(
            "INSERT INTO awp_projekte
                (titel, beschreibung, teamgroesse, qm_person_id, projektleiter_id,
                 kunde, projekt_bild, projekt_datei, status)
             VALUES (:titel, :beschreibung, :teamgroesse, :qm, :leiter, :kunde, :bild, :datei, 'offen')"
        );
        $stmt->execute([
            ':titel'        => $data['titel'],
            ':beschreibung' => $data['beschreibung'],
            ':teamgroesse'  => (int) $data['teamgroesse'],
            ':qm'           => $data['qm_person_id'] !== null ? (int) $data['qm_person_id'] : null,
            ':leiter'       => $data['projektleiter_id'] !== null ? (int) $data['projektleiter_id'] : null,
            ':kunde'        => $data['kunde'] ?: null,
            ':bild'         => $data['projekt_bild'] ?: null,
            ':datei'        => $data['projekt_datei'] ?: null,
        ]);
        return (int) $db->lastInsertId();
    }

    /**
     * Aktualisiert ein bestehendes AWP-Projekt. Bild/Datei werden nur
     * überschrieben, wenn neue Pfade übergeben werden (sonst beibehalten).
     */
    public static function updateProject(int $id, array $data): bool
    {
        $sql = "UPDATE awp_projekte SET
                    titel = :titel, beschreibung = :beschreibung, teamgroesse = :teamgroesse,
                    qm_person_id = :qm, projektleiter_id = :leiter, kunde = :kunde";
        $params = [
            ':titel'        => $data['titel'],
            ':beschreibung' => $data['beschreibung'],
            ':teamgroesse'  => (int) $data['teamgroesse'],
            ':qm'           => $data['qm_person_id'] !== null ? (int) $data['qm_person_id'] : null,
            ':leiter'       => $data['projektleiter_id'] !== null ? (int) $data['projektleiter_id'] : null,
            ':kunde'        => $data['kunde'] ?: null,
            ':id'           => $id,
        ];
        if (!empty($data['projekt_bild'])) {
            $sql .= ", projekt_bild = :bild";
            $params[':bild'] = $data['projekt_bild'];
        }
        if (!empty($data['projekt_datei'])) {
            $sql .= ", projekt_datei = :datei";
            $params[':datei'] = $data['projekt_datei'];
        }
        $sql .= " WHERE id = :id";
        $stmt = self::db()->prepare($sql);
        return $stmt->execute($params);
    }

    public static function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, ['offen', 'geschlossen'], true)) {
            return false;
        }
        $stmt = self::db()->prepare("UPDATE awp_projekte SET status = ? WHERE id = ?");
        return $stmt->execute([$status, $id]);
    }

    /* ── Bewerbungen ─────────────────────────────────────────────────────── */

    /**
     * Liefert alle Bewerbungen, gruppiert nach Projekt-ID.
     * @return array<int, array{projekt:array, bewerbungen:array}>
     */
    public static function applicationsByProject(): array
    {
        $stmt = self::db()->query(
            "SELECT pb.id AS bewerbung_id, pb.projekt_id, pb.motivationsschreiben, pb.status AS bewerbung_status,
                    b.id AS bewerber_id, b.name, b.email, b.telefon, b.alter_jahre, b.geburtsdatum,
                    b.studiengang, b.semester, b.profilbild_pfad, b.lebenslauf_pdf_pfad, b.lebenslauf_text,
                    b.beworben_am, p.titel AS projekt_titel
             FROM projekt_bewerbungen pb
             JOIN bewerber b      ON b.id = pb.bewerber_id
             JOIN awp_projekte p  ON p.id = pb.projekt_id
             ORDER BY p.titel ASC, b.name ASC"
        );
        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (int) $row['projekt_id'];
            if (!isset($grouped[$pid])) {
                $grouped[$pid] = ['titel' => $row['projekt_titel'], 'bewerbungen' => []];
            }
            $grouped[$pid]['bewerbungen'][] = $row;
        }
        return $grouped;
    }

    public static function getApplication(int $bewerbungId): ?array
    {
        $stmt = self::db()->prepare(
            "SELECT pb.*, b.name, b.email, p.titel AS projekt_titel, p.kunde
             FROM projekt_bewerbungen pb
             JOIN bewerber b     ON b.id = pb.bewerber_id
             JOIN awp_projekte p ON p.id = pb.projekt_id
             WHERE pb.id = ?"
        );
        $stmt->execute([$bewerbungId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function setApplicationStatus(int $bewerbungId, string $status): bool
    {
        if (!in_array($status, ['eingegangen', 'zugeordnet', 'abgelehnt'], true)) {
            return false;
        }
        $stmt = self::db()->prepare("UPDATE projekt_bewerbungen SET status = ? WHERE id = ?");
        return $stmt->execute([$status, $bewerbungId]);
    }
}
