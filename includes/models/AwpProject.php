<?php
/**
 * AwpProject – Datenmodell für AWP-Anwärter-Projekte & -Bewerbungen.
 *
 * Liest/schreibt die Tabellen awp_projekte, bewerber, projekt_bewerbungen.
 * Diese Tabellen werden vom öffentlichen Karriere-Portal (Ordner `karriere/`)
 * befüllt und hier im Intranet verwaltet. Beide Anwendungen müssen auf
 * DIESELBE Datenbank zeigen – hier wird die Content-DB des Intranets genutzt.
 *
 * Alle Zugriffe ausschließlich über Prepared Statements.
 */

require_once __DIR__ . '/../database.php';

class AwpProject
{
    /** Liefert die gemeinsame DB-Verbindung (Content-DB). */
    private static function db(): PDO
    {
        return Database::getContentDB();
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
