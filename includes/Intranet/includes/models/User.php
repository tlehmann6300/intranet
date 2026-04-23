<?php
/**
 * User Model
 * Manages user data and operations
 */

class User {
    
    /**
     * Email change token expiration time in hours
     */
    const EMAIL_CHANGE_TOKEN_EXPIRATION_HOURS = 24;
    
    /**
     * Get user by ID
     */
    public static function getById($id) {
        $db = Database::getUserDB();
        $sql = "SELECT id, email, role, entra_roles, entra_photo_path, avatar_path, use_custom_avatar, tfa_enabled, is_alumni_validated, last_login, created_at, about_me, gender, birthday, show_birthday, job_title, company, first_name, last_name, privacy_hide_email, privacy_hide_phone, privacy_hide_career FROM users WHERE id = ?";
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute([$id]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            // SQLSTATE 42S22 = unknown column; fall back for columns that may not exist yet
            if ($e->getCode() === '42S22') {
                foreach (['entra_photo_path', 'avatar_path', 'privacy_hide_email', 'privacy_hide_phone', 'privacy_hide_career', 'use_custom_avatar'] as $col) {
                    if (strpos($e->getMessage(), $col) !== false) {
                        $sql = str_replace($col . ',', 'NULL AS ' . $col . ',', $sql);
                        $sql = str_replace(', ' . $col, ', NULL AS ' . $col, $sql);
                    }
                }
                $stmt = $db->prepare($sql);
                $stmt->execute([$id]);
                return $stmt->fetch();
            }
            throw $e;
        }
    }

    /**
     * Find user by ID (alias for getById for compatibility)
     */
    public static function findById($id) {
        return self::getById($id);
    }

    /**
     * Get user by email
     */
    public static function getByEmail($email) {
        $db = Database::getUserDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch();
    }

    /**
     * Create new user
     */
    public static function create($email, $password, $role = 'mitglied') {
        $db = Database::getUserDB();
        $passwordHash = password_hash($password, HASH_ALGO);
        
        // Alumni users need manual board approval, so is_alumni_validated is set to FALSE (0)
        // Non-alumni users don't require validation, so it's set to TRUE (1) by default
        // This allows the isAlumniValidated() check to work correctly for all users
        $isAlumniValidated = ($role === 'alumni') ? 0 : 1;
        
        // New users need to complete their profile (first_name + last_name)
        $profileComplete = 0;
        
        $stmt = $db->prepare("INSERT INTO users (email, password, role, is_alumni_validated, profile_complete) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$email, $passwordHash, $role, $isAlumniValidated, $profileComplete]);
        
        return $db->lastInsertId();
    }

    /**
     * Update user
     */
    public static function update($id, $data) {
        $db = Database::getUserDB();
        $fields = [];
        $values = [];
        
        foreach ($data as $key => $value) {
            $fields[] = "$key = ?";
            $values[] = $value;
        }
        
        $values[] = $id;
        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?";
        
        $stmt = $db->prepare($sql);
        return $stmt->execute($values);
    }

    /**
     * Update user profile fields (about_me, gender, birthday)
     * @param int $id The user ID
     * @param array $data Profile data to update (about_me, gender, birthday)
     * @return bool Returns true on success
     */
    public static function updateProfile($id, $data) {
        $db = Database::getUserDB();
        $fields = [];
        $values = [];
        
        // Only allow specific profile fields to be updated
        $allowedFields = ['about_me', 'gender', 'birthday', 'show_birthday', 'job_title', 'company', 'privacy_hide_email', 'privacy_hide_phone', 'privacy_hide_career'];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return true; // No fields to update
        }
        
        $values[] = $id;
        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?";
        
        $stmt = $db->prepare($sql);
        return $stmt->execute($values);
    }

    /**
     * Delete user and associated alumni profile
     */
    public static function delete($id) {
        // Remove alumni profile from content DB first (ignore errors if not present)
        try {
            $contentDb = Database::getContentDB();
            $stmt = $contentDb->prepare("DELETE FROM alumni_profiles WHERE user_id = ?");
            $stmt->execute([$id]);
        } catch (Exception $e) {
            error_log('Failed to delete alumni profile for user ' . $id . ': ' . $e->getMessage());
        }

        $db = Database::getUserDB();
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Get all users
     */
    public static function getAll($role = null) {
        $db = Database::getUserDB();
        
        $sql = $role
            ? "SELECT id, email, first_name, last_name, role, user_type, tfa_enabled, is_alumni_validated, last_login, created_at, entra_roles, entra_photo_path, azure_oid, is_locked_permanently, locked_until, avatar_path, use_custom_avatar FROM users WHERE role = ? ORDER BY created_at DESC"
            : "SELECT id, email, first_name, last_name, role, user_type, tfa_enabled, is_alumni_validated, last_login, created_at, entra_roles, entra_photo_path, azure_oid, is_locked_permanently, locked_until, avatar_path, use_custom_avatar FROM users ORDER BY created_at DESC";
        try {
            if ($role) {
                $stmt = $db->prepare($sql);
                $stmt->execute([$role]);
            } else {
                $stmt = $db->query($sql);
            }
        } catch (PDOException $e) {
            // SQLSTATE 42S22 = unknown column; fall back for columns that may not exist yet.
            // Replace ALL potentially-missing columns at once so a single retry suffices
            // even when multiple new columns are absent (e.g. schema not yet migrated).
            $fallbackCols = ['entra_photo_path', 'is_locked_permanently', 'locked_until', 'avatar_path', 'use_custom_avatar'];
            $msg = $e->getMessage();
            $isKnownMissingCol = $e->getCode() === '42S22' && array_filter($fallbackCols, fn($c) => strpos($msg, $c) !== false);
            if ($isKnownMissingCol) {
                foreach ($fallbackCols as $col) {
                    $sql = preg_replace('/\b' . preg_quote($col, '/') . '\b/', 'NULL AS ' . $col, $sql);
                }
                if ($role) {
                    $stmt = $db->prepare($sql);
                    $stmt->execute([$role]);
                } else {
                    $stmt = $db->query($sql);
                }
            } else {
                throw $e;
            }
        }
        
        return $stmt->fetchAll();
    }

    /**
     * Enable 2FA for user
     */
    public static function enable2FA($userId, $secret) {
        $db = Database::getUserDB();
        $stmt = $db->prepare("UPDATE users SET tfa_secret = ?, tfa_enabled = 1 WHERE id = ?");
        return $stmt->execute([$secret, $userId]);
    }

    /**
     * Disable 2FA for user
     */
    public static function disable2FA($userId) {
        $db = Database::getUserDB();
        $stmt = $db->prepare("UPDATE users SET tfa_secret = NULL, tfa_enabled = 0 WHERE id = ?");
        return $stmt->execute([$userId]);
    }

    /**
     * Change password
     */
    public static function changePassword($userId, $newPassword) {
        $db = Database::getUserDB();
        $passwordHash = password_hash($newPassword, HASH_ALGO);
        
        $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
        return $stmt->execute([$passwordHash, $userId]);
    }

    /**
     * Update user email
     * @param int $userId The ID of the user whose email should be updated
     * @param string $newEmail The new email address
     * @return bool Returns true on success
     * @throws Exception If the email is already in use by another user or invalid
     */
    public static function updateEmail($userId, $newEmail) {
        $db = Database::getUserDB();
        
        // Validate email format
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Ungültige E-Mail-Adresse');
        }
        
        // Check if email is already used by another user
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$newEmail, $userId]);
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            throw new Exception('E-Mail bereits vergeben');
        }
        
        // Update the email
        $stmt = $db->prepare("UPDATE users SET email = ? WHERE id = ?");
        $result = $stmt->execute([$newEmail, $userId]);
        
        // Check if the update actually affected a row
        if ($result && $stmt->rowCount() > 0) {
            return true;
        }
        
        // If no rows were affected, the user ID doesn't exist
        throw new Exception('Benutzer nicht gefunden');
    }
    
    /**
     * Get all users subscribed to the blog newsletter
     * @return array List of users with id, email, first_name fields
     */
    public static function getNewsletterSubscribers() {
        $db = Database::getUserDB();
        $stmt = $db->query("SELECT id, email, first_name FROM users WHERE blog_newsletter = 1 AND deleted_at IS NULL");
        return $stmt->fetchAll();
    }
    
    /**
     * Update theme preference for user
     * @param int $userId The ID of the user
     * @param string $theme The theme preference ('auto', 'light', or 'dark')
     * @return bool Returns true on success
     */
    public static function updateThemePreference($userId, $theme) {
        $db = Database::getUserDB();
        
        // Validate theme value
        if (!in_array($theme, ['auto', 'light', 'dark'])) {
            return false;
        }
        
        $stmt = $db->prepare("
            UPDATE users 
            SET theme_preference = ? 
            WHERE id = ?
        ");
        
        return $stmt->execute([$theme, $userId]);
    }
    
    /**
     * Create email change request with token
     * @param int $userId The ID of the user requesting email change
     * @param string $newEmail The new email address
     * @return string The generated token
     * @throws Exception If email is invalid or already in use
     */
    public static function createEmailChangeRequest($userId, $newEmail) {
        $db = Database::getUserDB();
        
        // Validate email format
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Ungültige E-Mail-Adresse');
        }
        
        // Check if email is already used by another user
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$newEmail, $userId]);
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            throw new Exception('E-Mail bereits vergeben');
        }
        
        // Generate token
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + (self::EMAIL_CHANGE_TOKEN_EXPIRATION_HOURS * 60 * 60));
        
        // Delete any existing email change requests for this user
        $stmt = $db->prepare("DELETE FROM email_change_requests WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        // Insert new request
        $stmt = $db->prepare("
            INSERT INTO email_change_requests (user_id, new_email, token, expires_at)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $newEmail, $token, $expiresAt]);
        
        return $token;
    }
    
    /**
     * Confirm email change with token
     * @param string $token The confirmation token
     * @return bool Returns true on success
     * @throws Exception If token is invalid or expired
     */
    public static function confirmEmailChange($token) {
        $db = Database::getUserDB();
        
        // Find request by token
        $stmt = $db->prepare("
            SELECT user_id, new_email, expires_at 
            FROM email_change_requests 
            WHERE token = ?
        ");
        $stmt->execute([$token]);
        $request = $stmt->fetch();
        
        if (!$request) {
            throw new Exception('Ungültiger Bestätigungslink');
        }
        
        // Check if expired
        if (strtotime($request['expires_at']) < time()) {
            throw new Exception('Bestätigungslink ist abgelaufen');
        }
        
        // Check if email is still available
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$request['new_email'], $request['user_id']]);
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            throw new Exception('E-Mail bereits vergeben');
        }
        
        // Update user email
        $stmt = $db->prepare("UPDATE users SET email = ? WHERE id = ?");
        $result = $stmt->execute([$request['new_email'], $request['user_id']]);
        
        if (!$result) {
            throw new Exception('Fehler beim Aktualisieren der E-Mail-Adresse');
        }
        
        // Delete the request
        $stmt = $db->prepare("DELETE FROM email_change_requests WHERE token = ?");
        $stmt->execute([$token]);
        
        return true;
    }

    /**
     * Get the profile picture URL for a user using users.avatar_path as the single source of truth.
     *
     * Hierarchy:
     *  1. If avatar_path (from users table) is set AND the file physically exists → return the path
     *     (works for both custom_* user uploads and entra_* cached Entra ID photos)
     *  2. Otherwise (avatar_path is empty/NULL or the file is missing) → return the default image
     *
     * @param int        $userId   Local database user ID
     * @param array|null $userData Pre-fetched user row (must contain avatar_path).
     *                             Pass null to have the row fetched automatically.
     * @return string              URL-ready relative image path
     */
    public static function getProfilePictureUrl(int $userId, ?array $userData = null): string {
        require_once __DIR__ . '/../helpers.php';
        $default = defined('DEFAULT_PROFILE_IMAGE') ? DEFAULT_PROFILE_IMAGE : 'assets/img/default_profil.png';

        // Use pre-fetched data if available, otherwise query users.avatar_path
        $avatarPath = null;
        if ($userData !== null && array_key_exists('avatar_path', $userData)) {
            $avatarPath = $userData['avatar_path'];
        } else {
            try {
                $db   = Database::getUserDB();
                $stmt = $db->prepare("SELECT avatar_path FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $row  = $stmt->fetch();
                $avatarPath = $row ? ($row['avatar_path'] ?? null) : null;
            } catch (Exception $e) {
                error_log('[getProfilePictureUrl] Failed to fetch avatar_path for user ' . $userId . ': ' . $e->getMessage());
            }
        }

        // Return the path only if it is set and the file physically exists on the server
        $resolved = resolveImagePath($avatarPath);
        if ($resolved !== null) {
            return $resolved;
        }

        // Guaranteed fallback: default profile image
        return $default;
    }

    /**
     * Save a profile photo fetched from Entra ID to disk and record its path.
     *
     * The photo is stored in uploads/profile_photos/ using a deterministic filename
     * derived from the user ID so it gets overwritten on re-sync without accumulating
     * orphaned files.
     *
     * @param int    $userId     Local database user ID
     * @param string $photoData  Raw binary content of the photo from Microsoft Graph
     * @return string|null       Relative path stored in the database, or null on failure
     */
    public static function cacheEntraPhoto(int $userId, string $photoData): ?string {
        error_log('[cacheEntraPhoto] Project root (dirname(__DIR__, 2)): ' . dirname(__DIR__, 2) . ' for user ' . $userId);

        if (empty($photoData)) {
            return null;
        }

        // Enforce 5 MB size limit to prevent memory exhaustion
        if (strlen($photoData) > 5242880) {
            error_log('[cacheEntraPhoto] Entra photo for user ' . $userId . ' exceeds 5 MB limit, skipping.');
            return null;
        }

        // Write to a temp file to detect the actual MIME type safely
        $tmpFile = tempnam(sys_get_temp_dir(), 'entra_photo_');
        if ($tmpFile === false) {
            return null;
        }

        try {
            $bytesWritten = file_put_contents($tmpFile, $photoData);
            error_log('[cacheEntraPhoto] file_put_contents to temp file ' . $tmpFile . ': ' . ($bytesWritten !== false ? 'success (' . $bytesWritten . ' bytes)' : 'FAILED') . ' for user ' . $userId);
            if ($bytesWritten === false) {
                return null;
            }

            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $tmpFile);
            finfo_close($finfo);

            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            if (!in_array($mimeType, $allowedMimes, true)) {
                return null;
            }

            if (@getimagesize($tmpFile) === false) {
                return null;
            }

            $extMap    = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            $ext       = $extMap[$mimeType] ?? 'jpg';
            $uploadDir = dirname(__DIR__, 2) . '/uploads/profile_photos/';

            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                    error_log('[cacheEntraPhoto] Failed to create upload directory ' . $uploadDir . ' for user ' . $userId);
                    return null;
                }
            }

            if (!is_writable($uploadDir)) {
                error_log('[cacheEntraPhoto] Upload directory ' . $uploadDir . ' is not writable for user ' . $userId);
                return null;
            }

            $filename   = 'entra_' . $userId . '.' . $ext;
            $uploadPath = $uploadDir . $filename;

            error_log('[cacheEntraPhoto] Writing photo for user ' . $userId . ' to physical path: ' . $uploadPath);
            $copyResult = copy($tmpFile, $uploadPath);
            error_log('[cacheEntraPhoto] copy to ' . $uploadPath . ': ' . ($copyResult ? 'success' : 'FAILED') . ' for user ' . $userId);
            if (!chmod($uploadPath, 0644)) {
                error_log('[cacheEntraPhoto] Failed to chmod ' . $uploadPath . ' for user ' . $userId);
            }
            if (!$copyResult) {
                return null;
            }

            $projectRoot  = dirname(__DIR__, 2);
            $relativePath = str_replace('\\', '/', substr($uploadPath, strlen($projectRoot) + 1));
            error_log('[cacheEntraPhoto] relativePath written to DB: ' . $relativePath . ' for user ' . $userId);

            // Persist the path in the users table.
            // Always update entra_photo_path. Also update avatar_path unless the user has
            // disabled Entra photo sync by uploading a custom photo (use_custom_avatar = 1).
            $db   = Database::getUserDB();
            $stmt = $db->prepare("UPDATE users SET entra_photo_path = ? WHERE id = ?");
            $stmt->execute([$relativePath, $userId]);

            // Only overwrite avatar_path when the user has not uploaded their own photo
            $avatarStmt = $db->prepare("SELECT use_custom_avatar FROM users WHERE id = ?");
            $avatarStmt->execute([$userId]);
            $avatarFetched = $avatarStmt->fetch();
            $useCustom     = (int) ($avatarFetched['use_custom_avatar'] ?? 0);
            if ($useCustom === 0) {
                $db->prepare("UPDATE users SET avatar_path = ? WHERE id = ?")
                   ->execute([$relativePath, $userId]);
            }

            return $relativePath;

        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Import an Entra user into the local database.
     * If a user with the given azure_oid or email already exists, an exception is thrown.
     *
     * @param string $azureOid    Microsoft Object ID (OID) from Entra
     * @param string $displayName Display name from Entra
     * @param string $email       Primary e-mail address
     * @param string $role        Internal role key (e.g. 'mitglied', 'ressortleiter')
     * @param string $userType    'member' or 'guest'
     * @return int                New user's database ID
     * @throws Exception          If user already exists or INSERT fails
     */
    public static function importFromEntra(
        string $azureOid,
        string $displayName,
        string $email,
        string $role,
        string $userType = 'member'
    ): int {
        $db = Database::getUserDB();

        // Duplicate check by azure_oid
        $chk = $db->prepare("SELECT id FROM users WHERE azure_oid = ? LIMIT 1");
        $chk->execute([$azureOid]);
        if ($chk->fetchColumn()) {
            throw new Exception('Benutzer mit dieser Entra-ID existiert bereits im Intranet.');
        }

        // Duplicate check by e-mail
        $chk = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $chk->execute([$email]);
        if ($chk->fetchColumn()) {
            throw new Exception('Ein Benutzer mit dieser E-Mail-Adresse existiert bereits.');
        }

        // Split display name into first / last
        $parts     = explode(' ', trim($displayName), 2);
        $firstName = $parts[0] ?? '';
        $lastName  = $parts[1] ?? '';

        // Random placeholder password (user will always log in via Entra SSO)
        $randomPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

        $stmt = $db->prepare("
            INSERT INTO users
                (email, password, role, azure_oid, user_type,
                 first_name, last_name, profile_complete, is_alumni_validated)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0)
        ");
        $stmt->execute([$email, $randomPassword, $role, $azureOid, $userType, $firstName, $lastName]);
        $userId = (int) $db->lastInsertId();

        if ($userId === 0) {
            throw new Exception('Benutzer konnte nicht in der Datenbank angelegt werden.');
        }

        return $userId;
    }

    /**
     * Reset 2FA for a user
     */
    public static function reset2FA(int $userId): bool {
        $db   = Database::getUserDB();
        $stmt = $db->prepare("UPDATE users SET tfa_secret = NULL, tfa_enabled = 0, tfa_failed_attempts = 0, tfa_locked_until = NULL WHERE id = ?");
        return $stmt->execute([$userId]);
    }

    /**
     * Set alumni validation status
     */
    public static function setAlumniValidated(int $userId, int $isValidated): bool {
        $db   = Database::getUserDB();
        $stmt = $db->prepare("UPDATE users SET is_alumni_validated = ? WHERE id = ?");
        return $stmt->execute([$isValidated, $userId]);
    }
}
