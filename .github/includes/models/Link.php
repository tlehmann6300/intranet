<?php
/**
 * Link Model
 * Manages useful links stored in the database
 */

class Link {

    /**
     * Get all links ordered alphabetically by title, with optional search filter
     *
     * @param string $search Optional search term to filter by title or description
     * @return array Array of links
     */
    public static function getAll($search = '') {
        $db = Database::getContentDB();
        if ($search !== '') {
            $stmt = $db->prepare("SELECT id, title, url, description, icon, sort_order FROM links WHERE title LIKE ? OR description LIKE ? ORDER BY title ASC");
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $like = '%' . $escaped . '%';
            $stmt->execute([$like, $like]);
        } else {
            $stmt = $db->query("SELECT id, title, url, description, icon, sort_order FROM links ORDER BY title ASC");
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a single link by ID
     *
     * @param int $id Link ID
     * @return array|false Link data or false if not found
     */
    public static function getById($id) {
        $db = Database::getContentDB();
        $stmt = $db->prepare("SELECT id, title, url, description, icon, sort_order FROM links WHERE id = ?");
        $stmt->execute([(int)$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Create a new link
     *
     * @param array $data Link data (title, url, description, icon, sort_order, created_by)
     * @return int The ID of the newly created link
     */
    public static function create($data) {
        $db = Database::getContentDB();
        $stmt = $db->prepare(
            "INSERT INTO links (title, url, description, icon, sort_order, created_by)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $data['title'],
            $data['url'],
            $data['description'] ?? null,
            $data['icon'] ?? 'fas fa-link',
            (int)($data['sort_order'] ?? 0),
            $data['created_by'] ?? null,
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Update an existing link
     *
     * @param int $id Link ID
     * @param array $data Fields to update (title, url, description, icon, sort_order)
     * @return bool Success status
     */
    public static function update($id, $data) {
        $db = Database::getContentDB();
        $allowedFields = ['title', 'url', 'description', 'icon', 'sort_order'];
        $fields = [];
        $values = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $fields[] = "$key = ?";
                $values[] = $value;
            }
        }
        if (empty($fields)) {
            return false;
        }
        $values[] = (int)$id;
        $stmt = $db->prepare("UPDATE links SET " . implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($values);
    }

    /**
     * Delete a link by ID
     *
     * @param int $id Link ID
     * @return bool Success status
     */
    public static function delete($id) {
        $db = Database::getContentDB();
        $stmt = $db->prepare("DELETE FROM links WHERE id = ?");
        return $stmt->execute([(int)$id]);
    }

    /**
     * Check if the given role is allowed to manage links (create/edit/delete)
     *
     * @param string $userRole User role
     * @return bool
     */
    public static function canManage($userRole) {
        return in_array($userRole, Auth::BOARD_ROLES);
    }
}
