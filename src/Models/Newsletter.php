<?php

declare(strict_types=1);

namespace App\Models;


/**
 * Newsletter Model
 * Manages the internal newsletter archive (.eml files)
 */

class Newsletter
{
    protected $timestamps = false;

    /** Allowed file extensions for newsletter uploads */
    const ALLOWED_EXTENSIONS = ['eml'];

    /** Maximum upload size in bytes (20 MB) */
    const MAX_FILE_SIZE = 20971520;

    /**
     * Retrieve all newsletters, newest first, with optional keyword search.
     * Uploader names are resolved separately from the user DB to avoid a
     * cross-server JOIN (the news DB and the user DB may reside on different hosts).
     *
     * @param string $search Optional search term (title / month_year).
     * @return array
     */
    public static function getAll(string $search = ''): array {
        $db = \Database::getNewsletterDB();
        if ($search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $like    = '%' . $escaped . '%';
            $stmt    = $db->prepare(
                "SELECT * FROM newsletters
                 WHERE title LIKE ? OR month_year LIKE ?
                 ORDER BY created_at DESC"
            );
            $stmt->execute([$like, $like]);
        } else {
            $stmt = $db->query(
                "SELECT * FROM newsletters ORDER BY created_at DESC"
            );
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return self::enrichWithUploaderNames($rows);
    }

    /**
     * Retrieve a single newsletter by ID.
     * Uploader name is resolved separately from the user DB.
     *
     * @param int $id
     * @return array|false
     */
    public static function getById(int $id) {
        $db   = \Database::getNewsletterDB();
        $stmt = $db->prepare("SELECT * FROM newsletters WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }
        $enriched = self::enrichWithUploaderNames([$row]);
        return $enriched[0];
    }

    /**
     * Resolve uploader first_name / last_name for a list of newsletter rows.
     * Queries the user DB once with an IN() clause to avoid N+1 lookups.
     * Gracefully falls back (leaves names empty) if the user DB is unreachable.
     *
     * @param array $rows
     * @return array
     */
    private static function enrichWithUploaderNames(array $rows): array {
        if (empty($rows)) {
            return $rows;
        }

        // Collect distinct non-null uploader IDs
        $ids = array_values(array_unique(array_filter(
            array_column($rows, 'uploaded_by'),
            static fn($v) => $v !== null && $v > 0
        )));

        $userMap = [];
        if (!empty($ids)) {
            try {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = \Database::getUserDB()->prepare(
                    "SELECT id, first_name, last_name FROM users WHERE id IN ($placeholders)"
                );
                $stmt->execute($ids);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
                    $userMap[(int) $u['id']] = $u;
                }
            } catch (Exception $e) {
                // Non-fatal: uploader names will simply be absent
                error_log('Newsletter: could not resolve uploader names: ' . $e->getMessage());
            }
        }

        foreach ($rows as &$row) {
            $uid = isset($row['uploaded_by']) ? (int) $row['uploaded_by'] : 0;
            $user = isset($userMap[$uid]) ? $userMap[$uid] : null;
            $row['first_name'] = $user['first_name'] ?? null;
            $row['last_name']  = $user['last_name']  ?? null;
        }
        unset($row);

        return $rows;
    }

    /**
     * Persist a new newsletter record.
     *
     * @param array $data {title, month_year, file_path, uploaded_by}
     * @return int  New record ID.
     */
    public static function create(array $data): int {
        $db   = \Database::getNewsletterDB();
        $stmt = $db->prepare(
            "INSERT INTO newsletters
                 (title, month_year, file_path, uploaded_by)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([
            $data['title'],
            $data['month_year'] ?? null,
            $data['file_path'],
            (int) $data['uploaded_by'],
        ]);
        return (int) $db->lastInsertId();
    }

    /**
     * Remove a newsletter record and its associated file from disk.
     *
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool {
        $newsletter = self::getById($id);
        if (!$newsletter) {
            return false;
        }

        // Delete the file from disk first
        $uploadDir = __DIR__ . '/../../uploads/newsletters/';
        $filePath  = realpath($uploadDir . basename($newsletter['file_path']));
        if ($filePath !== false && str_starts_with($filePath, realpath($uploadDir))) {
            @unlink($filePath);
        }

        $db   = \Database::getNewsletterDB();
        $stmt = $db->prepare("DELETE FROM newsletters WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Whether the given role may upload / delete newsletters.
     *
     * Board members and section leads (ressortleiter) have manage rights.
     *
     * @param string $role
     * @return bool
     */
    public static function canManage(string $role): bool {
        return in_array($role, array_merge(\Auth::BOARD_ROLES, ['ressortleiter']), true);
    }

    /**
     * Validate an uploaded file and move it to the newsletters upload folder.
     *
     * @param array $file  $_FILES entry.
     * @return array {success: bool, path?: string, error?: string}
     */
    public static function handleUpload(array $file): array {
        // Basic upload error check
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'Fehler beim Hochladen der Datei (Code ' . $file['error'] . ').'];
        }

        // File size limit
        if ($file['size'] > self::MAX_FILE_SIZE) {
            return ['success' => false, 'error' => 'Die Datei überschreitet die maximale Größe von 20 MB.'];
        }

        // Extension whitelist
        $originalName = $file['name'];
        $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            return ['success' => false, 'error' => 'Nur .eml-Dateien sind erlaubt.'];
        }

        // Generate a secure, unique filename
        $secureFilename = bin2hex(random_bytes(16)) . '.' . $ext;
        $uploadDir      = __DIR__ . '/../../uploads/newsletters/';
        $destination    = $uploadDir . $secureFilename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return ['success' => false, 'error' => 'Die Datei konnte nicht gespeichert werden.'];
        }

        return ['success' => true, 'file_path' => $secureFilename, 'original_filename' => $originalName];
    }
}
