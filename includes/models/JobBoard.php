<?php
/**
 * JobBoard Model
 * Manages job and internship listings with cross-database user integration
 */

class JobBoard {

    /**
     * Allowed search types for listings
     */
    public const SEARCH_TYPES = [
        'Festanstellung',
        'Werksstudententätigkeit',
        'Praxissemester',
        'Praktikum',
    ];

    /**
     * Get all job board listings with optional type filter and pagination
     *
     * @param int $limit  Maximum number of records to fetch
     * @param int $offset Pagination offset
     * @param string|null $filterType Optional search_type filter
     * @return array
     */
    public static function getAll(int $limit, int $offset, ?string $filterType = null): array {
        $db = Database::getContentDB();

        $sql = "SELECT id, user_id, title, search_type, description, pdf_path, created_at
                FROM job_board
                WHERE 1=1";

        $params = [];

        if ($filterType !== null) {
            $sql .= " AND search_type = ?";
            $params[] = $filterType;
        }

        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a single listing by ID
     *
     * @param int $id
     * @return array|null
     */
    public static function getById(int $id): ?array {
        $db = Database::getContentDB();
        $stmt = $db->prepare("SELECT id, user_id, title, search_type, description, pdf_path, created_at FROM job_board WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Create a new listing
     *
     * @param array $data Keys: user_id, title, search_type, description, pdf_path (optional)
     * @return int|false New record ID or false on failure
     */
    public static function create(array $data) {
        $db = Database::getContentDB();
        $stmt = $db->prepare(
            "INSERT INTO job_board (user_id, title, search_type, description, pdf_path)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            (int)$data['user_id'],
            $data['title'],
            $data['search_type'],
            $data['description'],
            $data['pdf_path'] ?? null,
        ]);
        if ($stmt->rowCount() > 0) {
            return (int)$db->lastInsertId();
        }
        return false;
    }

    /**
     * Update a listing by ID (only by owner)
     *
     * @param int $id
     * @param int $userId
     * @param array $data Keys: title, search_type, description, pdf_path (optional, null to keep existing)
     * @param bool $clearPdf Set to true to explicitly set pdf_path to NULL
     * @return bool
     */
    public static function updateByOwner(int $id, int $userId, array $data, bool $clearPdf = false): bool {
        $db = Database::getContentDB();

        if ($clearPdf || array_key_exists('pdf_path', $data)) {
            $stmt = $db->prepare(
                "UPDATE job_board SET title = ?, search_type = ?, description = ?, pdf_path = ?
                 WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([
                $data['title'],
                $data['search_type'],
                $data['description'],
                $clearPdf ? null : ($data['pdf_path'] ?? null),
                $id,
                $userId,
            ]);
        } else {
            $stmt = $db->prepare(
                "UPDATE job_board SET title = ?, search_type = ?, description = ?
                 WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([
                $data['title'],
                $data['search_type'],
                $data['description'],
                $id,
                $userId,
            ]);
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a listing by ID (only by owner)
     *
     * @param int $id
     * @param int $userId
     * @return bool
     */
    public static function deleteByOwner(int $id, int $userId): bool {
        $db = Database::getContentDB();
        $stmt = $db->prepare("DELETE FROM job_board WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Count total listings with optional type filter
     *
     * @param string|null $filterType
     * @return int
     */
    public static function count(?string $filterType = null): int {
        $db = Database::getContentDB();
        $sql = "SELECT COUNT(*) FROM job_board WHERE 1=1";
        $params = [];

        if ($filterType !== null) {
            $sql .= " AND search_type = ?";
            $params[] = $filterType;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
}
