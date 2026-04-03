<?php
/**
 * Auditable Trait
 *
 * Protokolliert Datenänderungen automatisch in der Tabelle `audit_log`.
 * Für jede geänderte Spalte wird ein eigener Eintrag geschrieben mit:
 *   – User-ID aus der Session ($_SESSION['user_id'])
 *   – Tabellenname und Datensatz-ID
 *   – Spaltenname sowie alter und neuer Wert
 *   – IP-Adresse des Aufrufers
 *   – Zeitstempel (durch DEFAULT CURRENT_TIMESTAMP in der DB)
 *
 * Verwendung in einer Model-Klasse:
 *
 *   class Event {
 *       use Auditable;
 *
 *       public static function update(int $id, array $data): bool {
 *           $old = self::getById($id);
 *           $db  = Database::getContentDB();
 *           // … UPDATE ausführen …
 *           self::auditUpdate('events', $id, $data, $old ?: []);
 *           return true;
 *       }
 *   }
 *
 * Direkte Nutzung ohne vorherigen Fetch:
 *
 *   self::logAudit('events', 42, 'title', 'Altes Event', 'Neues Event');
 */
trait Auditable
{
    /**
     * Vergleicht $newData mit $oldData und schreibt für jede tatsächlich
     * geänderte Spalte einen Eintrag in audit_log.
     *
     * @param string       $table   Name der geänderten Tabelle
     * @param int          $id      Primärschlüssel des geänderten Datensatzes
     * @param array<string, mixed> $newData  Neue Werte (können ein Subset der Spalten sein)
     * @param array<string, mixed> $oldData  Aktuelle Werte aus der Datenbank
     */
    protected static function auditUpdate(string $table, int $id, array $newData, array $oldData): void
    {
        foreach ($newData as $column => $newValue) {
            $oldValue = $oldData[$column] ?? null;

            // Nur tatsächliche Änderungen protokollieren.
            // Expliziter Vergleich statt String-Cast, damit null und '' nicht
            // fälschlicherweise als gleich bewertet werden.
            $oldNorm = ($oldValue === null) ? null : (string)$oldValue;
            $newNorm = ($newValue === null) ? null : (string)$newValue;
            if ($oldNorm === $newNorm) {
                continue;
            }

            self::logAudit($table, $id, $column, $oldValue, $newValue);
        }
    }

    /**
     * Schreibt einen einzelnen Audit-Eintrag in die Tabelle `audit_log`.
     *
     * Schlägt das Schreiben fehl (z. B. Tabelle existiert noch nicht), wird
     * der Fehler still verworfen, damit die eigentliche Anwendungslogik nicht
     * unterbrochen wird.
     *
     * @param string      $table     Name der geänderten Tabelle
     * @param int         $recordId  Primärschlüssel des geänderten Datensatzes
     * @param string      $column    Name der geänderten Spalte
     * @param mixed       $oldValue  Alter Wert (null = Spalte war leer/nicht vorhanden)
     * @param mixed       $newValue  Neuer Wert (null = Spalte wurde geleert)
     */
    protected static function logAudit(string $table, int $recordId, string $column, $oldValue, $newValue): void
    {
        try {
            $db     = Database::getContentDB();
            $userId = $_SESSION['user_id'] ?? null;
            $ip     = $_SERVER['REMOTE_ADDR'] ?? null;

            $stmt = $db->prepare(
                "INSERT INTO audit_log
                     (user_id, table_name, record_id, column_name, old_value, new_value, ip_address)
                 VALUES
                     (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $userId,
                $table,
                $recordId,
                $column,
                $oldValue !== null ? (string)$oldValue : null,
                $newValue !== null ? (string)$newValue : null,
                $ip,
            ]);
        } catch (Exception $e) {
            // Audit-Fehler dürfen die Hauptanwendung nicht unterbrechen
            error_log('Auditable::logAudit failed for ' . $table . '.' . $column . ': ' . $e->getMessage());
        }
    }
}
