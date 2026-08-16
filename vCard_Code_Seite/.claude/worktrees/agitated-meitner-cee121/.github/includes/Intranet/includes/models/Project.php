<?php
/**
 * Project Model
 * Manages project data and operations with security controls
 */

class Project {
    
    /**
     * Upload directory for project documentation
     */
    private const DOCUMENTATION_UPLOAD_DIR = 'uploads/projects/';

    // Default image used when no project image has been uploaded
    const DEFAULT_IMAGE = 'assets/img/ibc_logo_original.webp';

    /**
     * Get the image URL for a project, falling back to the default image if none is set.
     *
     * @param string|null $imagePath Stored image path from the database
     * @return string URL-ready image path
     */
    public static function getImageUrl(?string $imagePath): string {
        return !empty($imagePath) ? $imagePath : self::DEFAULT_IMAGE;
    }
    
    /**
     * Allowed MIME types for documentation uploads (PDF)
     */
    private const ALLOWED_DOC_MIME_TYPES = [
        'application/pdf'
    ];
    
    /**
     * Allowed file extensions for documentation uploads (whitelist)
     */
    private const ALLOWED_DOC_EXTENSIONS = [
        'pdf'
    ];
    
    /**
     * Maximum file size for documentation (10MB)
     */
    private const MAX_DOC_FILE_SIZE = 10485760;
    
    /**
     * Handle PDF documentation upload
     * 
     * @param array $file The $_FILES array element
     * @return array ['success' => bool, 'path' => string|null, 'error' => string|null]
     */
    public static function handleDocumentationUpload($file) {
        // Check if file was uploaded
        if (!isset($file) || !isset($file['error'])) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Keine Datei hochgeladen'
            ];
        }

        // UPLOAD_ERR_INI_SIZE / UPLOAD_ERR_FORM_SIZE must be caught before inspecting
        // $file['size'], because PHP reports size as 0 when the limit is exceeded.
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Datei ist zu groß. Maximum: 10MB'
            ];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Upload-Fehler (Code: ' . (int)$file['error'] . ')'
            ];
        }

        // Validate file size
        if ($file['size'] > self::MAX_DOC_FILE_SIZE) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Datei ist zu groß. Maximum: 10MB'
            ];
        }
        
        // Validate file extension using strict whitelist (use basename() to prevent path traversal)
        $originalExtension = strtolower(pathinfo(basename($file['name']), PATHINFO_EXTENSION));
        if (!in_array($originalExtension, self::ALLOWED_DOC_EXTENSIONS)) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Ungültige Dateiendung. Nur PDF-Dateien sind erlaubt.'
            ];
        }
        
        // Validate MIME type using finfo_file() - NOT $_FILES['type'] which can be faked
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, self::ALLOWED_DOC_MIME_TYPES)) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Ungültiger Dateityp. Nur PDF-Dateien sind erlaubt. Erkannt: ' . $mimeType
            ];
        }
        
        // Determine upload directory
        $uploadDir = __DIR__ . '/../../' . self::DOCUMENTATION_UPLOAD_DIR;
        
        // Ensure upload directory exists and is writable
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                return [
                    'success' => false,
                    'path' => null,
                    'error' => 'Upload-Verzeichnis konnte nicht erstellt werden'
                ];
            }
        }
        
        if (!is_writable($uploadDir)) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Upload-Verzeichnis ist nicht beschreibbar'
            ];
        }
        
        // Generate secure random filename with timestamp for tracking
        $timestamp = date('Ymd_His');
        $randomFilename = 'project_doc_' . $timestamp . '_' . bin2hex(random_bytes(8)) . '.pdf';
        $uploadPath = $uploadDir . $randomFilename;
        
        // Move uploaded file to destination
        if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Fehler beim Hochladen der Datei'
            ];
        }
        
        // Set proper permissions
        chmod($uploadPath, 0644);
        
        // Return relative path for database storage
        $relativePath = rtrim(self::DOCUMENTATION_UPLOAD_DIR, '/') . '/' . $randomFilename;
        
        return [
            'success' => true,
            'path' => $relativePath,
            'error' => null
        ];
    }
    
    /**
     * Handle documentation upload from $_FILES and update data array
     * 
     * @param array $data Data array to update with documentation path
     * @return void
     * @throws Exception If upload fails
     */
    private static function processDocumentationUpload(&$data) {
        if (isset($_FILES['documentation']) && $_FILES['documentation']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploadResult = self::handleDocumentationUpload($_FILES['documentation']);
            if ($uploadResult['success']) {
                $data['documentation'] = $uploadResult['path'];
            } else {
                throw new Exception($uploadResult['error']);
            }
        }
    }
    
    /**
     * Create a new project
     */
    public static function create($data) {
        $db = Database::getContentDB();
        
        // Handle documentation upload if provided in $_FILES
        self::processDocumentationUpload($data);
        
        $stmt = $db->prepare("
            INSERT INTO projects (
                title, 
                description, 
                client_name, 
                client_contact_details, 
                priority, 
                type,
                status, 
                max_consultants,
                requires_application,
                start_date, 
                end_date, 
                image_path,
                documentation,
                created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['title'] ?? '',
            $data['description'] ?? null,
            $data['client_name'] ?? null,
            $data['client_contact_details'] ?? null,
            $data['priority'] ?? 'medium',
            $data['type'] ?? 'internal',
            $data['status'] ?? 'draft',
            $data['max_consultants'] ?? null,
            $data['requires_application'] ?? 1,
            $data['start_date'] ?? null,
            $data['end_date'] ?? null,
            $data['image_path'] ?? null,
            $data['documentation'] ?? null,
            $data['created_by'] ?? null
        ]);
        
        $projectId = $db->lastInsertId();
        
        // Send notifications if project is not a draft
        $status = $data['status'] ?? 'draft';
        if ($status !== 'draft') {
            self::sendNewProjectNotifications($projectId, $data);
        }
        
        return $projectId;
    }
    
    /**
     * Send notifications to users who want to be notified about new projects
     * 
     * @param int $projectId The project ID
     * @param array $projectData The project data
     */
    private static function sendNewProjectNotifications($projectId, $projectData) {
        try {
            // Get users who want to be notified about new projects
            $userDB = Database::getUserDB();
            $stmt = $userDB->prepare("
                SELECT id, email 
                FROM users 
                WHERE notify_new_projects = 1
            ");
            $stmt->execute();
            $users = $stmt->fetchAll();
            
            if (empty($users)) {
                return; // No users to notify
            }
            
            // Load MailService
            require_once __DIR__ . '/../../src/MailService.php';
            
            // Prepare email content
            $projectTitle = htmlspecialchars($projectData['title'] ?? 'Neues Projekt');
            $projectType = $projectData['type'] ?? 'internal';
            $projectTypeLabel = $projectType === 'internal' ? 'Intern' : 'Extern';
            
            $bodyContent = '<p>Ein neues Projekt wurde veröffentlicht:</p>';
            $bodyContent .= '<div style="background-color: #f9fafb; padding: 20px; border-radius: 8px; margin: 20px 0;">';
            $bodyContent .= '<h3 style="color: #4a7c2f; margin: 0 0 10px 0;">' . $projectTitle . '</h3>';
            $bodyContent .= '<p style="margin: 5px 0; color: #1f2937;"><strong>Typ:</strong> ' . $projectTypeLabel . '</p>';
            
            if (!empty($projectData['description'])) {
                $description = htmlspecialchars(substr($projectData['description'], 0, 200));
                if (strlen($projectData['description']) > 200) {
                    $description .= '...';
                }
                $bodyContent .= '<p style="margin: 5px 0; color: #1f2937;"><strong>Beschreibung:</strong> ' . $description . '</p>';
            }
            
            if (!empty($projectData['start_date'])) {
                $bodyContent .= '<p style="margin: 5px 0; color: #1f2937;"><strong>Start:</strong> ' . date('d.m.Y', strtotime($projectData['start_date'])) . '</p>';
            }
            
            $bodyContent .= '</div>';
            $bodyContent .= '<p>Klicken Sie auf den Button unten, um das Projekt anzusehen und sich zu bewerben.</p>';
            
            // Create CTA button - use BASE_URL if available
            $baseUrl = defined('BASE_URL') ? BASE_URL : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            $projectUrl = rtrim($baseUrl, '/') . '/pages/projects/view.php?id=' . $projectId;
            
            $callToAction = '<a href="' . $projectUrl . '" style="display: inline-block; background-color: #6D9744; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: bold; margin: 20px 0;">Projekt ansehen</a>';
            
            // Build complete HTML email using template
            $htmlBody = MailService::getTemplate('Neues Projekt', $bodyContent, $callToAction);
            
            // Send email to each user
            foreach ($users as $user) {
                try {
                    MailService::sendEmail(
                        $user['email'],
                        'Neues Projekt: ' . $projectTitle,
                        $htmlBody
                    );
                } catch (Exception $e) {
                    // Log error but continue with other users
                    error_log('Failed to send new project notification to ' . $user['email'] . ': ' . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            // Log error but don't fail project creation
            error_log('Failed to send new project notifications: ' . $e->getMessage());
        }
    }
    
    /**
     * Update an existing project
     */
    public static function update($id, $data) {
        $db = Database::getContentDB();
        
        // Handle documentation upload if provided in $_FILES
        self::processDocumentationUpload($data);
        
        // Get old status to check if we're publishing
        $oldProject = self::getById($id);
        $wasPublishing = $oldProject && $oldProject['status'] === 'draft' && 
                        isset($data['status']) && $data['status'] !== 'draft';
        
        $fields = [];
        $values = [];
        
        $allowedFields = [
            'title', 'description', 'client_name', 'client_contact_details',
            'priority', 'type', 'status', 'max_consultants', 'requires_application',
            'start_date', 'end_date', 'image_path', 'documentation', 'feedback_contact_user_id'
        ];
        
        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $fields[] = "$key = ?";
                $values[] = $value;
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $values[] = $id;
        $sql = "UPDATE projects SET " . implode(', ', $fields) . " WHERE id = ?";
        
        $stmt = $db->prepare($sql);
        $result = $stmt->execute($values);
        
        // Send notifications if we're publishing a draft
        if ($result && $wasPublishing) {
            $updatedProject = self::getById($id);
            if ($updatedProject) {
                self::sendNewProjectNotifications($id, $updatedProject);
            }
        }
        
        return $result;
    }
    
    /**
     * Get all projects with status filtering and permission checks
     * 
     * @param string|null $status Optional status filter. If null, excludes 'draft' by default
     * @param string|null $userRole User role for permission checks
     * @return array List of projects
     */
    public static function getAll($status = null, $userRole = null) {
        $db = Database::getContentDB();
        
        // Determine if user has manage_projects permission (manager level or higher)
        $hasManagePermission = false;
        if ($userRole !== null) {
            $roleHierarchy = [
                'alumni' => 1,
                'mitglied' => 1,
                'manager' => 2,
                'alumni_vorstand' => 3,
                'board' => 3,
                'admin' => 4
            ];
            $hasManagePermission = isset($roleHierarchy[$userRole]) && $roleHierarchy[$userRole] >= 2;
        }
        
        // Build query based on status and permissions
        if ($status !== null) {
            // Specific status requested
            // Only allow draft status if user has manage_projects permission
            if ($status === 'draft' && !$hasManagePermission) {
                // Log unauthorized access attempt for security audit
                error_log("Unauthorized draft project access attempt - Role: " . ($userRole ?? 'unknown'));
                return []; // Return empty array if trying to access draft without permission
            }
            
            $stmt = $db->prepare("SELECT * FROM projects WHERE status = ? ORDER BY created_at DESC");
            $stmt->execute([$status]);
        } else {
            // No specific status - default behavior
            if ($hasManagePermission) {
                // Manager+ can see all projects including drafts
                $stmt = $db->query("SELECT * FROM projects ORDER BY created_at DESC");
            } else {
                // Regular users see everything except drafts
                $stmt = $db->query("SELECT * FROM projects WHERE status != 'draft' ORDER BY created_at DESC");
            }
        }
        
        $projects = $stmt->fetchAll();

        // Apply default image fallback to each project
        foreach ($projects as &$project) {
            $project['image_path'] = self::getImageUrl($project['image_path']);
        }
        unset($project);

        return $projects;
    }
    
    /**
     * Get project by ID
     */
    public static function getById($id) {
        $db = Database::getContentDB();
        $stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->execute([$id]);
        $project = $stmt->fetch();

        if ($project) {
            // Apply default image fallback
            $project['image_path'] = self::getImageUrl($project['image_path']);
        }

        return $project;
    }
    
    /**
     * Filter sensitive data based on user role and project assignment
     * 
     * Removes client_name and client_contact_details if:
     * - User role is not 'board' or 'manager' AND
     * - User is not assigned to the project
     * - Alumni never see client data
     * 
     * @param array $project Project data
     * @param string $userRole User role (board, manager, member, alumni)
     * @param int $userId User ID
     * @return array Filtered project data
     */
    public static function filterSensitiveData($project, $userRole, $userId) {
        if (!$project) {
            return $project;
        }
        
        // Alumni should never see client data
        if ($userRole === 'alumni') {
            unset($project['client_name']);
            unset($project['client_contact_details']);
            return $project;
        }
        
        // Board roles and manager can always see client data
        if (in_array($userRole, array_merge(Auth::BOARD_ROLES, ['manager']))) {
            return $project;
        }
        
        // Check if user is assigned to this project
        $db = Database::getContentDB();
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM project_assignments 
            WHERE project_id = ? AND user_id = ?
        ");
        $stmt->execute([$project['id'], $userId]);
        $result = $stmt->fetch();
        
        // If user is assigned to the project, they can see client data
        if ($result && $result['count'] > 0) {
            return $project;
        }
        
        // Otherwise, remove sensitive data
        unset($project['client_name']);
        unset($project['client_contact_details']);
        
        return $project;
    }
    
    /**
     * Apply for a project
     * 
     * @param int $projectId Project ID
     * @param int $userId User ID
     * @param array $data Application data (motivation, experience_count)
     * @return int Application ID
     */
    public static function apply($projectId, $userId, $data) {
        $db = Database::getContentDB();
        
        // Check if application already exists
        $stmt = $db->prepare("
            SELECT id FROM project_applications 
            WHERE project_id = ? AND user_id = ?
        ");
        $stmt->execute([$projectId, $userId]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            throw new Exception("Application already exists for this project");
        }
        
        $stmt = $db->prepare("
            INSERT INTO project_applications (
                project_id, 
                user_id, 
                motivation, 
                experience_count, 
                status
            ) VALUES (?, ?, ?, ?, 'pending')
        ");
        
        $stmt->execute([
            $projectId,
            $userId,
            $data['motivation'] ?? null,
            $data['experience_count'] ?? 0
        ]);
        
        return $db->lastInsertId();
    }
    
    /**
     * Assign a member to a project
     * 
     * @param int $projectId Project ID
     * @param int $userId User ID
     * @param string $role Assignment role (lead, member)
     * @return int Assignment ID
     */
    public static function assignMember($projectId, $userId, $role = 'member') {
        $db = Database::getContentDB();
        
        // Check if assignment already exists
        $stmt = $db->prepare("
            SELECT id FROM project_assignments 
            WHERE project_id = ? AND user_id = ?
        ");
        $stmt->execute([$projectId, $userId]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Update existing assignment
            $stmt = $db->prepare("
                UPDATE project_assignments 
                SET role = ? 
                WHERE project_id = ? AND user_id = ?
            ");
            $stmt->execute([$role, $projectId, $userId]);
            return $existing['id'];
        }
        
        // Create new assignment
        $stmt = $db->prepare("
            INSERT INTO project_assignments (
                project_id, 
                user_id, 
                role
            ) VALUES (?, ?, ?)
        ");
        
        $stmt->execute([
            $projectId,
            $userId,
            $role
        ]);
        
        return $db->lastInsertId();
    }
    
    /**
     * Get applications for a project
     * Note: Access control must be enforced at the API/controller layer.
     * This method should only be called after verifying the user has admin/board privileges.
     * 
     * @param int $projectId Project ID
     * @return array Applications with user information
     */
    public static function getApplications($projectId) {
        $db = Database::getContentDB();
        
        $stmt = $db->prepare("
            SELECT 
                pa.id,
                pa.project_id,
                pa.user_id,
                pa.motivation,
                pa.experience_count,
                pa.status,
                pa.created_at
            FROM project_applications pa
            WHERE pa.project_id = ?
            ORDER BY pa.created_at DESC
        ");
        
        $stmt->execute([$projectId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get user's application for a specific project
     * 
     * @param int $projectId Project ID
     * @param int $userId User ID
     * @return array|false Application data or false if not found
     */
    public static function getUserApplication($projectId, $userId) {
        $db = Database::getContentDB();
        
        $stmt = $db->prepare("
            SELECT 
                id,
                project_id,
                user_id,
                motivation,
                experience_count,
                status,
                created_at
            FROM project_applications
            WHERE project_id = ? AND user_id = ?
        ");
        
        $stmt->execute([$projectId, $userId]);
        return $stmt->fetch();
    }
    
    /**
     * Check if a user has the 'lead' role in a project
     * 
     * @param int $projectId Project ID
     * @param int $userId User ID
     * @return bool True if user is a lead, false otherwise
     */
    public static function isLead($projectId, $userId) {
        $db = Database::getContentDB();
        
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM project_assignments
            WHERE project_id = ? AND user_id = ? AND role = 'lead'
        ");
        
        $stmt->execute([$projectId, $userId]);
        $result = $stmt->fetch();
        
        return $result && $result['count'] > 0;
    }
    
    /**
     * Get all lead user IDs for a project
     * 
     * @param int $projectId Project ID
     * @return array Array of user IDs who are leads
     */
    public static function getProjectLeads($projectId) {
        $db = Database::getContentDB();
        
        $stmt = $db->prepare("
            SELECT user_id
            FROM project_assignments
            WHERE project_id = ? AND role = 'lead'
        ");
        
        $stmt->execute([$projectId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    /**
     * Get the current team size for a project
     * 
     * @param int $projectId Project ID
     * @return int Number of assigned team members
     */
    public static function getTeamSize($projectId) {
        $db = Database::getContentDB();
        
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM project_assignments
            WHERE project_id = ?
        ");
        
        $stmt->execute([$projectId]);
        $result = $stmt->fetch();
        
        return $result ? intval($result['count']) : 0;
    }

    /**
     * Get all participants (assigned members) for a project with user details
     *
     * @param int $projectId Project ID
     * @return array Assignments with basic user info (user_id, role, first_name, last_name)
     */
    public static function getParticipants($projectId) {
        $contentDb = Database::getContentDB();
        $userDb = Database::getUserDB();

        $stmt = $contentDb->prepare("
            SELECT user_id, role, created_at
            FROM project_assignments
            WHERE project_id = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$projectId]);
        $assignments = $stmt->fetchAll();

        if (empty($assignments)) {
            return [];
        }

        $userIds = array_column($assignments, 'user_id');
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));

        // Get emails and privacy settings from user database
        $stmt = $userDb->prepare("SELECT id AS user_id, email, privacy_hide_email FROM users WHERE id IN ($placeholders)");
        $stmt->execute($userIds);
        $userMap = [];
        foreach ($stmt->fetchAll() as $u) {
            $userMap[$u['user_id']] = $u;
        }

        // Get profile names from content database
        $stmt = $contentDb->prepare("SELECT user_id, first_name, last_name FROM alumni_profiles WHERE user_id IN ($placeholders)");
        $stmt->execute($userIds);
        $profileMap = [];
        foreach ($stmt->fetchAll() as $p) {
            $profileMap[$p['user_id']] = $p;
        }

        $participants = [];
        foreach ($assignments as $assignment) {
            $uid = $assignment['user_id'];
            $userData = $userMap[$uid] ?? [];
            $email = $userData['email'] ?? '';
            $hideEmail = !empty($userData['privacy_hide_email']);
            $firstName = $profileMap[$uid]['first_name'] ?? '';
            $lastName = $profileMap[$uid]['last_name'] ?? '';

            if (empty($firstName) && empty($lastName)) {
                // Only use email local part as display name if the user has not hidden their email
                if (!$hideEmail && !empty($email) && strpos($email, '@') !== false) {
                    $firstName = explode('@', $email)[0];
                } else {
                    $firstName = 'Benutzer';
                }
            }

            $participants[] = [
                'user_id'           => $uid,
                'role'              => $assignment['role'],
                'created_at'        => $assignment['created_at'],
                'first_name'        => $firstName,
                'last_name'         => $lastName,
                'privacy_hide_email' => $hideEmail,
            ];
        }

        return $participants;
    }

    /**
     * Directly join an internal project as a participant
     *
     * @param int $projectId Project ID
     * @param int $userId User ID
     * @return bool True on success
     * @throws Exception If already joined or project is full
     */
    public static function joinProject($projectId, $userId) {
        $db = Database::getContentDB();

        // Check if already assigned
        $stmt = $db->prepare("
            SELECT id FROM project_assignments
            WHERE project_id = ? AND user_id = ?
        ");
        $stmt->execute([$projectId, $userId]);
        if ($stmt->fetch()) {
            throw new Exception('Du nimmst bereits an diesem Projekt teil');
        }

        // Check capacity (skip for internal projects which allow open participation)
        $project = self::getById($projectId);
        if (!$project) {
            throw new Exception('Projekt nicht gefunden');
        }
        if (($project['type'] ?? 'internal') !== 'internal') {
            $teamSize = self::getTeamSize($projectId);
            if ($teamSize >= intval($project['max_consultants'] ?? 1)) {
                throw new Exception('Das Projekt ist bereits vollständig besetzt');
            }
        }

        $stmt = $db->prepare("
            INSERT INTO project_assignments (project_id, user_id, role)
            VALUES (?, ?, 'member')
        ");
        $stmt->execute([$projectId, $userId]);

        return true;
    }

    /**
     * Leave an internal project (remove own participation)
     *
     * @param int $projectId Project ID
     * @param int $userId User ID
     * @return bool True on success
     */
    public static function leaveProject($projectId, $userId) {
        $db = Database::getContentDB();

        $stmt = $db->prepare("
            DELETE FROM project_assignments
            WHERE project_id = ? AND user_id = ? AND role != 'lead'
        ");
        $stmt->execute([$projectId, $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Set or remove the feedback contact (alumni Ansprechpartner) for a project.
     *
     * @param int $projectId Project ID
     * @param int|null $userId User ID to set as contact, or null to remove
     * @return bool True on success
     */
    public static function setFeedbackContact(int $projectId, ?int $userId): bool {
        $db = Database::getContentDB();
        $stmt = $db->prepare("UPDATE projects SET feedback_contact_user_id = ? WHERE id = ?");
        return $stmt->execute([$userId, $projectId]);
    }

    /**
     * Get the feedback contact user profile for a project.
     *
     * @param int $projectId Project ID
     * @return array|null Profile data (first_name, last_name, image_path, position, company) or null
     */
    public static function getFeedbackContact(int $projectId): ?array {
        $db = Database::getContentDB();
        $stmt = $db->prepare("SELECT feedback_contact_user_id FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $row = $stmt->fetch();
        if (!$row || empty($row['feedback_contact_user_id'])) {
            return null;
        }
        $userId = (int)$row['feedback_contact_user_id'];

        // Get profile from alumni_profiles
        $stmt = $db->prepare("SELECT user_id, first_name, last_name, image_path, position, company FROM alumni_profiles WHERE user_id = ?");
        $stmt->execute([$userId]);
        $profile = $stmt->fetch();
        if (!$profile) {
            return ['user_id' => $userId, 'first_name' => '', 'last_name' => '', 'image_path' => null, 'position' => null, 'company' => null];
        }
        return $profile;
    }
}
