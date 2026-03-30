<?php
declare(strict_types=1);

/**
 * VCard Model
 * Manages contact card data stored in the external vCard database.
 *
 * Typical vCard fields: Vorname (first_name), Nachname (last_name),
 * Telefon (phone), Email (email), LinkedIn (linkedin_url), Bild (image_path).
 */

require_once __DIR__ . '/../database.php';

class VCard {

    /** Table name in the external vCard database */
    private const TABLE = 'vcards';

    /**
     * Columns that callers are permitted to read/write.
     * Add or remove columns here as the remote schema evolves.
     */
    private const ALLOWED_FIELDS = [
        'first_name',
        'last_name',
        'phone',
        'email',
        'linkedin_url',
        'image_path',
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
        $sql = "SELECT {$fields} FROM " . self::TABLE . " ORDER BY last_name ASC, first_name ASC";
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
        $db = Database::getVCardDB();
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
}
