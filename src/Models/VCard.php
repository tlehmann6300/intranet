<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * VCard Model
 * Manages contact card data stored in the external vCard database.
 *
 * Remote table: vcards_table
 * Columns: id, vorname, nachname, rolle, funktion, telefon, email, linkedin, profilbild
 */

class VCard extends Model
{
    protected $connection = 'vcard';
    protected $table = 'vcards_table';
    protected static $unguarded = true;
    protected $timestamps = false;

    /** Table name in the external vCard database */
    private const TABLE = 'vcards_table';

    /**
     * Columns that callers are permitted to read/write.
     * Add or remove columns here as the remote schema evolves.
     */
    private const ALLOWED_FIELDS = [
        'vorname',
        'nachname',
        'rolle',
        'funktion',
        'telefon',
        'email',
        'linkedin',
        'profilbild',
    ];

    /**
     * Get all vCards ordered by last name, then first name.
     *
     * @return array List of vCard records (associative arrays)
     * @throws Exception On database error
     */
    public static function getAll(): array {
        $db = \Database::getVCardDB();
        $fields = implode(', ', array_merge(['id'], self::ALLOWED_FIELDS));
        $sql = "SELECT {$fields} FROM " . self::TABLE . " ORDER BY nachname ASC, vorname ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute();
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
        $db = \Database::getVCardDB();
        $fields = implode(', ', array_merge(['id'], self::ALLOWED_FIELDS));
        $sql = "SELECT {$fields} FROM " . self::TABLE . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch();
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
        $db = \Database::getVCardDB();

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
        $db = \Database::getVCardDB();

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
     * @param int $id Record ID
     * @return bool True on success
     * @throws Exception If the record does not exist or a database error occurs
     */
    public static function delete(int $id): bool {
        $db = \Database::getVCardDB();

        // Verify the record exists
        $checkStmt = $db->prepare("SELECT id FROM " . self::TABLE . " WHERE id = ?");
        $checkStmt->execute([$id]);
        if (!$checkStmt->fetch()) {
            throw new Exception("VCard-Eintrag nicht gefunden", 404);
        }

        $stmt = $db->prepare("DELETE FROM " . self::TABLE . " WHERE id = ?");
        if (!$stmt->execute([$id])) {
            throw new Exception("Datenbankfehler beim Löschen des VCard-Eintrags");
        }
        return true;
    }
}
