<?php
declare(strict_types=1);

/**
 * Member Model
 * Handles the logic for the Active Member Directory
 */

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/Alumni.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../helpers.php';

class Member {
    
    /**
     * Active member roles (excludes 'alumni', 'alumni_vorstand', 'ehrenmitglied')
     * Includes all board role variants: 'vorstand_finanzen', 'vorstand_intern', 'vorstand_extern'
     * Plus: 'ressortleiter' (Ressortleiter), 'mitglied' (Mitglied), 'anwaerter' (Anwärter)
     */
    const ACTIVE_ROLES = ['vorstand_finanzen', 'vorstand_intern', 'vorstand_extern', 'ressortleiter', 'mitglied', 'anwaerter'];
    
    /**
     * Get all active members with optional search and role filtering
     * 
     * @param string|null $search Optional search term for first_name, last_name, company, or industry
     * @param string|null $filterRole Optional role filter (e.g., 'candidate', 'member', 'board', etc.)
     * @return array Array of active member profiles with user information
     */
    public static function getAllActive(?string $search = null, ?string $filterRole = null): array {
        $userDb = Database::getUserDB();
        $contentDb = Database::getContentDB();
        
        // Step 1: Get alumni profiles from Content DB with search filters
        $whereClauses = [];
        $params = [];
        
        // Add search filter if provided
        if ($search !== null && $search !== '') {
            $whereClauses[] = "(ap.first_name LIKE ? OR ap.last_name LIKE ? OR ap.company LIKE ? OR ap.industry LIKE ? OR ap.study_program LIKE ? OR ap.skills LIKE ?)";
            $searchTerm = '%' . $search . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
        
        // Query to get all alumni profiles
        $sql = "
            SELECT 
                ap.user_id,
                ap.id as profile_id,
                ap.first_name,
                ap.last_name,
                ap.mobile_phone,
                ap.linkedin_url,
                ap.xing_url,
                ap.industry,
                ap.company,
                ap.position,
                ap.study_program,
                ap.semester,
                ap.angestrebter_abschluss,
                ap.degree,
                ap.graduation_year,
                ap.skills,
                ap.image_path,
                ap.created_at,
                ap.updated_at
            FROM alumni_profiles ap
            " . $whereSQL . "
            ORDER BY ap.last_name ASC, ap.first_name ASC
        ";
        
        try {
            $stmt = $contentDb->prepare($sql);
            $stmt->execute($params);
            $profiles = $stmt->fetchAll();
        } catch (PDOException $e) {
            // SQLSTATE 42S22 = unknown column; fall back if skills column doesn't exist yet
            if ($e->getCode() === '42S22' && strpos($e->getMessage(), 'skills') !== false) {
                $sql = str_replace('ap.skills,', 'NULL AS skills,', $sql);
                if ($search !== null && $search !== '') {
                    $sql = preg_replace('/\s*OR ap\.skills LIKE \?/', '', $sql);
                    array_pop($params);
                }
                $stmt = $contentDb->prepare($sql);
                $stmt->execute($params);
                $profiles = $stmt->fetchAll();
            } else {
                throw $e;
            }
        }
        
        if (empty($profiles)) {
            return [];
        }
        
        // Step 2: Collect all user_ids from profiles
        $userIds = array_column($profiles, 'user_id');
        
        // Step 3: Query User DB to get user information (email, role) for these user_ids
        // Apply role filter at this stage
        $userWhereClauses = ["u.id IN (" . implode(',', array_fill(0, count($userIds), '?')) . ")"];
        $userParams = $userIds;
        
        // Add role filter for ACTIVE_ROLES (using placeholders for safety)
        $rolePlaceholders = implode(',', array_fill(0, count(self::ACTIVE_ROLES), '?'));
        $userWhereClauses[] = "u.role IN ($rolePlaceholders)";
        $userParams = array_merge($userParams, self::ACTIVE_ROLES);
        
        // Add specific role filter if provided
        if ($filterRole !== null && $filterRole !== '') {
            $userWhereClauses[] = "u.role = ?";
            $userParams[] = $filterRole;
        }
        
        $userWhereSQL = implode(' AND ', $userWhereClauses);
        
        $userSql = "
            SELECT 
                u.id,
                u.email,
                u.role,
                u.entra_roles,
                u.entra_photo_path,
                u.avatar_path,
                u.job_title,
                u.created_at,
                u.privacy_hide_email
            FROM users u
            WHERE " . $userWhereSQL . "
        ";
        
        try {
            $userStmt = $userDb->prepare($userSql);
            $userStmt->execute($userParams);
            $users = $userStmt->fetchAll();
        } catch (PDOException $e) {
            // SQLSTATE 42S22 = unknown column; fall back if column doesn't exist yet
            if ($e->getCode() === '42S22') {
                foreach (['u.entra_photo_path,' => 'NULL AS entra_photo_path,', 'u.avatar_path,' => 'NULL AS avatar_path,'] as $col => $fallback) {
                    if (strpos($e->getMessage(), trim($col, 'u.,')) !== false) {
                        $userSql = str_replace($col, $fallback, $userSql);
                    }
                }
                $userStmt = $userDb->prepare($userSql);
                $userStmt->execute($userParams);
                $users = $userStmt->fetchAll();
            } else {
                throw $e;
            }
        }
        
        // Step 4: Create a map of user_id => user data
        $userMap = [];
        foreach ($users as $user) {
            $userMap[$user['id']] = $user;
        }
        
        // Step 5: Merge profiles with user data
        $result = [];
        foreach ($profiles as $profile) {
            $userId = $profile['user_id'];
            
            // Only include if user exists and meets criteria
            if (isset($userMap[$userId])) {
                $user = $userMap[$userId];
                
                // Merge user data into profile
                $profile['email'] = $user['email'];
                $profile['role'] = $user['role'];
                $profile['entra_roles'] = $user['entra_roles'] ?? null;
                $profile['entra_photo_path'] = $user['entra_photo_path'] ?? null;
                $profile['avatar_path'] = $user['avatar_path'] ?? null;
                $profile['job_title'] = $user['job_title'] ?? null;
                $profile['user_created_at'] = $user['created_at'];
                $profile['privacy_hide_email'] = $user['privacy_hide_email'] ?? 0;
                
                // Resolve display_role: prefer Entra display names, fall back to internal role label
                $displayRole = null;
                if (!empty($user['entra_roles'])) {
                    $entraRolesArray = json_decode($user['entra_roles'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($entraRolesArray) && !empty($entraRolesArray)) {
                        $displayNames = [];
                        foreach ($entraRolesArray as $entraRole) {
                            if (is_array($entraRole) && isset($entraRole['displayName'])) {
                                // displayName is already human-readable from Microsoft Graph
                                $displayNames[] = $entraRole['displayName'];
                            } elseif (is_array($entraRole) && isset($entraRole['id'])) {
                                // Translate Entra role ID (GUID) to German label
                                $displayNames[] = Auth::getRoleLabel($entraRole['id']);
                            } elseif (is_string($entraRole)) {
                                // Legacy string format – may be a GUID or a display name
                                $displayNames[] = Auth::getRoleLabel($entraRole);
                            }
                        }
                        if (!empty($displayNames)) {
                            $displayRole = implode(', ', $displayNames);
                        }
                    }
                }
                $profile['display_role'] = $displayRole ?? Auth::getRoleLabel($user['role']);
                
                $result[] = $profile;
            }
        }
        
        return $result;
    }
    
    /**
     * Get statistics of member counts per role
     * Returns count of members per role for active roles only (not alumni)
     * 
     * @return array Associative array with role names as keys and counts as values
     */
    public static function getStatistics(): array {
        $db = Database::getUserDB();
        
        $roleList = "'" . implode("', '", self::ACTIVE_ROLES) . "'";
        $sql = "
            SELECT role, COUNT(*) as count
            FROM users
            WHERE role IN ($roleList)
            GROUP BY role
            ORDER BY role ASC
        ";
        
        $stmt = $db->prepare($sql);
        
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        // Convert to associative array with role as key
        $statistics = [];
        foreach ($results as $row) {
            $statistics[$row['role']] = (int)$row['count'];
        }
        
        return $statistics;
    }
    
    /**
     * Get profile by user ID
     * Note: Uses alumni_profiles table as this is the central profile table for all users
     * 
     * @param int $userId The user ID
     * @return array|false Profile data or false if not found
     */
    public static function getProfileByUserId(int $userId) {
        $db = Database::getContentDB();
        $sql = "
            SELECT id, user_id, first_name, last_name, email, mobile_phone, 
                   linkedin_url, xing_url, industry, company, position, 
                   study_program, semester, angestrebter_abschluss, 
                   degree, graduation_year, skills, cv_path,
                   image_path, last_verified_at, last_reminder_sent_at, created_at, updated_at
            FROM alumni_profiles 
            WHERE user_id = ?
        ";
        try {
            $stmt = $db->prepare($sql);
        } catch (PDOException $pdoEx) {
            // SQLSTATE 42S22 = unknown column; replace ALL optional columns with NULL as fallback
            if ($pdoEx->getCode() === '42S22') {
                foreach (['skills', 'cv_path'] as $col) {
                    $sql = str_replace($col . ',', 'NULL AS ' . $col . ',', $sql);
                }
                $stmt = $db->prepare($sql);
            } else {
                throw $pdoEx;
            }
        }
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }
    
    /**
     * Update an existing member profile
     * Note: Uses alumni_profiles table as this is the central profile table for all users
     * 
     * @param int $userId The user ID
     * @param array $data Profile data to update
     * @return bool True on success
     * @throws Exception On database error
     */
    public static function update(int $userId, array $data): bool {
        // Check permissions
        require_once __DIR__ . '/../../src/Auth.php';
        if (!Auth::check()) {
            throw new Exception("Keine Berechtigung zum Aktualisieren des Mitgliederprofils");
        }
        
        $currentUser = Auth::user();
        $currentRole = $currentUser['role'] ?? '';
        
        // Members and candidates can update their own profile
        // Board roles (all types) and resortleiter can update any profile
        if (in_array($currentRole, ['mitglied', 'anwaerter'])) {
            if ($currentUser['id'] !== $userId) {
                throw new Exception("Keine Berechtigung zum Aktualisieren anderer Mitgliederprofile");
            }
        } elseif (!in_array($currentRole, array_merge(Auth::BOARD_ROLES, ['ressortleiter']))) {
            throw new Exception("Keine Berechtigung zum Aktualisieren des Mitgliederprofils");
        }
        
        $db = Database::getContentDB();
        
        // Check if profile exists
        $checkStmt = $db->prepare("SELECT id FROM alumni_profiles WHERE user_id = ?");
        $checkStmt->execute([$userId]);
        if (!$checkStmt->fetch()) {
            throw new Exception("Profil nicht gefunden");
        }
        
        $fields = [];
        $values = [];
        
        $allowedFields = [
            'first_name', 'last_name', 'email', 'mobile_phone',
            'linkedin_url', 'xing_url', 'industry', 'company', 
            'position', 'image_path', 'study_program', 'semester', 
            'angestrebter_abschluss', 'degree', 'graduation_year', 'skills', 'cv_path'
        ];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return true; // No fields to update
        }
        
        $values[] = $userId;
        // Uses alumni_profiles table as this is the central profile table for all users
        $sql = "UPDATE alumni_profiles SET " . implode(', ', $fields) . " WHERE user_id = ?";
        
        try {
            $stmt = $db->prepare($sql);
            return $stmt->execute($values);
        } catch (PDOException $e) {
            // SQLSTATE 42S22 = unknown column; remove ALL optional columns from UPDATE as fallback
            if ($e->getCode() === '42S22') {
                foreach (['skills', 'cv_path'] as $optCol) {
                    $optIdx = array_search($optCol . ' = ?', $fields);
                    if ($optIdx !== false) {
                        array_splice($fields, $optIdx, 1);
                        array_splice($values, $optIdx, 1);
                    }
                }
                if (empty($fields)) {
                    return true;
                }
                $sql = "UPDATE alumni_profiles SET " . implode(', ', $fields) . " WHERE user_id = ?";
                $stmt = $db->prepare($sql);
                return $stmt->execute($values);
            }
            throw $e;
        }
    }
    
    /**
     * Get the profile image URL with fallback to default image
     *
     * @param string|null $avatarPath  The avatar_path from users table (may be custom_* or entra_*)
     * @return string URL-ready image path
     */
    public static function getImageUrl(?string $avatarPath): string {
        return getProfileImageUrl($avatarPath);
    }

    /**
     * Update or create member profile (upsert)
     * Note: Uses alumni_profiles table as this is the central profile table for all users
     * 
     * @param int $userId The user ID
     * @param array $data Profile data to upsert
     * @return bool True on success
     * @throws Exception On database error
     */
    public static function updateProfile(int $userId, array $data): bool {
        // Check if profile exists
        $existing = self::getProfileByUserId($userId);
        
        if ($existing) {
            return self::update($userId, $data);
        } else {
            // Create new profile - delegate to Alumni::create since it uses the same table
            $data['user_id'] = $userId;
            return Alumni::create($data);
        }
    }
}
