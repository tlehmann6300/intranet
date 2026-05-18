<?php
declare(strict_types=1);

/**
 * VCard Model
 * Manages contact card data stored in the external vCard database.
 *
 * Remote table: vcards_table
 * Columns: id, vorname, nachname, rolle, funktion, telefon, email, linkedin, profilbild, lebenslauf
 */

require_once __DIR__ . '/../database.php';

class VCard {

    /** Table name in the external vCard database */
    private const TABLE = 'vcards_table';

    /**
     * Columns that callers are permitted to read/write.
     * Add or remove columns here as the remote schema evolves.
     */
    private const ALLOWED_FIELDS = [
        'vorname',
        'nachname',
        'titel',
        'rolle',
        'abteilung',
        'funktion',
        'telefon',
        'email',
        'linkedin',
        'profilbild',
        'lebenslauf',
    ];

    /**
     * Get all vCards ordered by last name, then first name.
     *
     * @return array List of vCard records (associative arrays)
     * @throws Exception On database error
     */
    public static function getAll(): array {
        $db = Database::getVCardDB();
        $fields = implode(', ', array_merge(['id'], self::ALLOWED_FIELDS));
        $sql = "SELECT {$fields} FROM " . self::TABLE . " ORDER BY nachname ASC, vorname ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Explizites Mapping  vcards_table.rolle  →  pos.pos_ID.
     *
     * Wo die Rollenbezeichnungen 1:1 auf pos.pos_Beschreibung passen, brauchen
     * wir keinen Eintrag (dann wird per String-Normalisierung gematcht). Diese
     * Liste deckt nur die Fälle ab, in denen die Schreibweisen auseinander-
     * laufen oder die Rolle in pos einen ganz anderen Namen hat.
     *
     * Pflege: einfach Schlüssel (lowercase, getrimmt) → pos_ID ergänzen.
     */
    private const ROLLE_TO_POS_ID = [
        'vorstand intern'              => 1, // Vorstandsvorsitzender (1V)
        'vorstand extern'              => 2, // stellv. Vorstandsvorsitzender (2V)
        'vorstand finanzen und recht'  => 3, // Vorstand für Finanzen & Recht
        'vorstand für finanzen & recht'=> 3,
    ];

    /**
     * Liefert die komplette History (Vorgänger) für eine Rolle.
     *
     * Match-Strategie (in dieser Reihenfolge):
     *   1. Explizites Mapping ROLLE_TO_POS_ID (deckt abweichende Schreibweisen ab).
     *   2. String-Normalisierung („für " entfernt, „&" → „und", lowercase) gegen
     *      pos.pos_Beschreibung.
     *
     * Es werden NUR vorhandene Daten gelesen – nichts geschrieben/gelöscht.
     *
     * @param string $rolle Rollen-Bezeichnung wie in vcards_table.rolle
     * @return array Liste der History-Einträge mit Feldern:
     *               jahr (GJ), vorname, nachname, position, telefon, email, linkedin
     */
    public static function getHistoryByRolle(string $rolle): array {
        $trimmed = trim($rolle);
        if ($trimmed === '') {
            return [];
        }
        $db = Database::getVCardDB();
        $key = mb_strtolower($trimmed);

        // 1) Explizites Mapping → direkter Join auf pos_ID
        if (isset(self::ROLLE_TO_POS_ID[$key])) {
            $posId = (int) self::ROLLE_TO_POS_ID[$key];
            $sql = "
                SELECT h.GJ AS jahr,
                       u.user_Name     AS vorname,
                       u.user_Lastname AS nachname,
                       u.user_Phone    AS telefon,
                       u.user_Email    AS email,
                       u.user_LinkedIn AS linkedin,
                       p.pos_Beschreibung AS position
                FROM historie h
                JOIN user u ON u.user_ID = h.user_ID
                JOIN pos  p ON p.pos_ID  = h.pos_ID
                WHERE h.pos_ID = ?
                ORDER BY h.GJ DESC, u.user_Lastname ASC, u.user_Name ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([$posId]);
            return $stmt->fetchAll();
        }

        // 2) String-Match mit Normalisierung. Wir reduzieren beide Seiten auf
        //    lowercase ohne „für " und mit „&" → „und"; das reicht für die
        //    bekannten Schreibvarianten in pos. REGEXP_REPLACE wird vermieden,
        //    damit das auch auf älteren MariaDB-Versionen läuft.
        $sql = "
            SELECT h.GJ AS jahr,
                   u.user_Name     AS vorname,
                   u.user_Lastname AS nachname,
                   u.user_Phone    AS telefon,
                   u.user_Email    AS email,
                   u.user_LinkedIn AS linkedin,
                   p.pos_Beschreibung AS position
            FROM historie h
            JOIN user u ON u.user_ID = h.user_ID
            JOIN pos  p ON p.pos_ID  = h.pos_ID
            WHERE TRIM(LOWER(REPLACE(REPLACE(p.pos_Beschreibung, '&', 'und'), 'für ', ''))) =
                  TRIM(LOWER(REPLACE(REPLACE(?, '&', 'und'), 'für ', '')))
            ORDER BY h.GJ DESC, u.user_Lastname ASC, u.user_Name ASC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$trimmed]);
        return $stmt->fetchAll();
    }

    /**
     * Get a single vCard by its primary key.
     *
     * @param int $id Record ID
     * @return array|false The vCard record or false if not found
     * @throws Exception On database error
     */
    public static function getById(int $id) {
        $db = Database::getVCardDB();
        $fields = implode(', ', array_merge(['id'], self::ALLOWED_FIELDS));
        $sql = "SELECT {$fields} FROM " . self::TABLE . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * Check if a given Rolle is already assigned to another vCard.
     *
     * Rolle-Strings are compared case-insensitively and after whitespace
     * trimming. Empty roles are never considered duplicates (so multiple
     * vCards without a role are allowed).
     *
     * @param string   $rolle     The role string to test
     * @param int|null $excludeId Optional vCard ID to exclude from the
     *                            check (used during update).
     * @return bool True if another vCard already uses this role.
     */
    public static function isRolleTaken(string $rolle, ?int $excludeId = null): bool {
        $trimmed = trim($rolle);
        if ($trimmed === '') {
            return false;
        }
        $db = Database::getVCardDB();

        if ($excludeId !== null && $excludeId > 0) {
            $sql = "SELECT id FROM " . self::TABLE . "
                    WHERE TRIM(LOWER(rolle)) = LOWER(?) AND id <> ? LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute([$trimmed, $excludeId]);
        } else {
            $sql = "SELECT id FROM " . self::TABLE . "
                    WHERE TRIM(LOWER(rolle)) = LOWER(?) LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute([$trimmed]);
        }

        return (bool) $stmt->fetch();
    }

    /**
     * Update an existing vCard record.
     *
     * Only fields listed in ALLOWED_FIELDS are accepted; any other keys in
     * $data are silently ignored to prevent mass-assignment vulnerabilities.
     *
     * @param int   $id   Record ID
     * @param array $data Associative array of field => value pairs to update
     * @return bool True on success, false if no updatable fields were provided
     * @throws Exception If the record does not exist or a database error occurs
     */
    public static function update(int $id, array $data): bool {
        $db = Database::getVCardDB();

        // Verify the record exists
        $checkStmt = $db->prepare("SELECT id FROM " . self::TABLE . " WHERE id = ?");
        $checkStmt->execute([$id]);
        if (!$checkStmt->fetch()) {
            throw new Exception("VCard-Eintrag nicht gefunden");
        }

        $fields = [];
        $values = [];

        foreach (self::ALLOWED_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $values[] = $id;
        $sql = "UPDATE " . self::TABLE . " SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        return $stmt->execute($values);
    }

    /**
     * Create a new vCard record.
     *
     * Only fields listed in ALLOWED_FIELDS are accepted; any other keys in
     * $data are silently ignored to prevent mass-assignment vulnerabilities.
     *
     * @param array $data Associative array of field => value pairs
     * @return int The ID of the newly created record
     * @throws Exception If no valid fields are provided or a database error occurs
     */
    public static function create(array $data): int {
        $db = Database::getVCardDB();

        $columns = [];
        $placeholders = [];
        $values = [];

        foreach (self::ALLOWED_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $columns[] = $field;
                $placeholders[] = '?';
                $values[] = $data[$field];
            }
        }

        if (empty($columns)) {
            throw new Exception("Keine gültigen Felder für die Erstellung angegeben");
        }

        $sql = "INSERT INTO " . self::TABLE . " (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $db->prepare($sql);
        if (!$stmt->execute($values)) {
            throw new Exception("Datenbankfehler beim Erstellen des VCard-Eintrags");
        }

        return (int) $db->lastInsertId();
    }

    /**
     * Delete a vCard record by its primary key.
     *
     * Vor dem Löschen wird der Eintrag in die `historie`-Tabelle archiviert
     * (sofern Rolle + Person eindeutig auflösbar sind), damit Vorgänger
     * dauerhaft sichtbar bleiben.
     *
     * @param int $id Record ID
     * @return bool True on success
     * @throws Exception If the record does not exist or a database error occurs
     */
    public static function delete(int $id): bool {
        $db = Database::getVCardDB();

        // Vollen Datensatz holen, um ihn vor dem Löschen archivieren zu können.
        $record = self::getById($id);
        if (!$record) {
            throw new Exception("VCard-Eintrag nicht gefunden", 404);
        }

        // Best-Effort-Archivierung in `historie`. Fehler dürfen das Delete NICHT
        // blockieren – die History ist nice-to-have, das Löschen aus der vCards-
        // Verwaltung muss zuverlässig durchgehen.
        try {
            self::archiveToHistory($record);
        } catch (Throwable $e) {
            error_log('VCard::archiveToHistory failed (vcard #' . $id . '): ' . $e->getMessage());
        }

        $stmt = $db->prepare("DELETE FROM " . self::TABLE . " WHERE id = ?");
        if (!$stmt->execute([$id])) {
            throw new Exception("Datenbankfehler beim Löschen des VCard-Eintrags");
        }
        return true;
    }

    /**
     * Schreibt einen vCard-Datensatz als History-Eintrag in `historie`.
     *
     * Erfordert:
     *  - eine Rolle, die per ROLLE_TO_POS_ID oder String-Match auf `pos.pos_ID`
     *    aufgelöst werden kann
     *  - einen Datensatz in `user`, der per E-Mail (bevorzugt) oder Vor-/Nachname
     *    gefunden wird – andernfalls wird die Person NEU in `user` angelegt
     *
     * Doppelte Einträge (gleiche user_ID + pos_ID + GJ) werden übersprungen.
     */
    private static function archiveToHistory(array $record): void {
        $rolle = trim((string)($record['rolle'] ?? ''));
        if ($rolle === '') {
            return; // ohne Rolle keine History
        }

        $db = Database::getVCardDB();
        $posId = self::resolvePosId($rolle, $db);
        if ($posId === null) {
            error_log('VCard::archiveToHistory: pos_ID für Rolle "' . $rolle . '" nicht auflösbar – History-Eintrag übersprungen.');
            return;
        }

        $userId = self::findOrCreateUserForArchive($record, $db);
        if ($userId === null) {
            return;
        }

        $gj = (int) date('Y');

        // Idempotent: existierender Eintrag (user, pos, GJ) wird nicht doppelt geschrieben.
        $dupCheck = $db->prepare("SELECT 1 FROM historie WHERE user_ID = ? AND pos_ID = ? AND GJ = ? LIMIT 1");
        $dupCheck->execute([$userId, $posId, $gj]);
        if ($dupCheck->fetch()) {
            return;
        }

        $insert = $db->prepare("INSERT INTO historie (user_ID, pos_ID, GJ) VALUES (?, ?, ?)");
        $insert->execute([$userId, $posId, $gj]);
    }

    /**
     * Auflösung Rolle → pos_ID. Zunächst über ROLLE_TO_POS_ID, sonst über
     * normalisierten String-Match gegen pos.pos_Beschreibung.
     */
    private static function resolvePosId(string $rolle, PDO $db): ?int {
        $key = mb_strtolower(trim($rolle));
        if (isset(self::ROLLE_TO_POS_ID[$key])) {
            return (int) self::ROLLE_TO_POS_ID[$key];
        }
        $sql = "
            SELECT pos_ID FROM pos
            WHERE TRIM(LOWER(REPLACE(REPLACE(pos_Beschreibung, '&', 'und'), 'für ', ''))) =
                  TRIM(LOWER(REPLACE(REPLACE(?, '&', 'und'), 'für ', '')))
            LIMIT 1
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$rolle]);
        $row = $stmt->fetch();
        return $row ? (int)$row['pos_ID'] : null;
    }

    /**
     * Findet die zur vCard gehörende user_ID in `user`. Reihenfolge:
     *   1. E-Mail (case-insensitive)
     *   2. Vorname + Nachname (case-insensitive)
     *   3. Anlage eines neuen `user`-Eintrags mit den vorhandenen Daten
     */
    private static function findOrCreateUserForArchive(array $record, PDO $db): ?int {
        $email    = trim((string)($record['email']    ?? ''));
        $vorname  = trim((string)($record['vorname']  ?? ''));
        $nachname = trim((string)($record['nachname'] ?? ''));
        $telefon  = trim((string)($record['telefon']  ?? ''));
        $linkedin = trim((string)($record['linkedin'] ?? ''));

        if ($email !== '') {
            $stmt = $db->prepare("SELECT user_ID FROM user WHERE LOWER(user_Email) = LOWER(?) LIMIT 1");
            $stmt->execute([$email]);
            $row = $stmt->fetch();
            if ($row) {
                return (int)$row['user_ID'];
            }
        }

        if ($vorname !== '' && $nachname !== '') {
            $stmt = $db->prepare("
                SELECT user_ID FROM user
                WHERE LOWER(user_Name) = LOWER(?) AND LOWER(user_Lastname) = LOWER(?)
                LIMIT 1
            ");
            $stmt->execute([$vorname, $nachname]);
            $row = $stmt->fetch();
            if ($row) {
                return (int)$row['user_ID'];
            }
        }

        // Niemanden gefunden → neu in `user` anlegen, damit die History trotzdem
        // einen verknüpften Datensatz hat. Vor- und Nachname sind dafür Pflicht.
        if ($vorname === '' || $nachname === '') {
            return null;
        }

        $insert = $db->prepare("
            INSERT INTO user (user_Name, user_Lastname, user_Phone, user_Email, user_LinkedIn)
            VALUES (?, ?, ?, ?, ?)
        ");
        $insert->execute([$vorname, $nachname, $telefon, $email, $linkedin]);
        return (int) $db->lastInsertId();
    }
}
