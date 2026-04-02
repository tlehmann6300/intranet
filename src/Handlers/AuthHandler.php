<?php
/**
 * Authentication Handler
 * Manages user authentication, sessions, and 2FA
 */

declare(strict_types=1);

namespace App\Handlers;

use Database;
use User;
use Auth;
use App\Services\MicrosoftGraphService;
use Exception;
use PDO;
use PDOException;
use JsonException;


class AuthHandler {
    
    /**
     * Start secure session
     */
    public static function startSession() {
        // Set timezone at the very beginning
        date_default_timezone_set('Europe/Berlin');
        
        // Use the centralized init_session() function for secure session initialization
        init_session();
        
        // Regenerate session ID periodically to prevent session fixation
        // BUT skip regeneration during OAuth flow to preserve state parameter
        if (!isset($_SESSION['created'])) {
            $_SESSION['created'] = time();
        } else if (time() - $_SESSION['created'] > 1800) {
            // Only skip regeneration if OAuth state is present
            if (!isset($_SESSION['oauth2state'])) {
                session_regenerate_id(true);
                $_SESSION['created'] = time();
            }
        }
        
        // Check for session timeout (30 minutes of inactivity)
        self::checkSessionTimeout();
    }
    
    /**
     * Check if session has timed out due to inactivity
     */
    private static function checkSessionTimeout() {
        // Skip timeout check if user is not authenticated
        if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
            return;
        }
        
        // Check if last_activity is set
        if (isset($_SESSION['last_activity'])) {
            // Calculate time difference
            $inactiveTime = time() - $_SESSION['last_activity'];
            
            // If inactive for more than 30 minutes (1800 seconds)
            if ($inactiveTime > 1800) {
                // Destroy the session
                session_unset();
                session_destroy();
                
                // Redirect to login page with timeout parameter
                // Use BASE_URL for portability across environments
                $loginUrl = (defined('BASE_URL') && BASE_URL) ? BASE_URL . '/login?timeout=1' : '/login?timeout=1';
                header('Location: ' . $loginUrl);
                exit;
            }
        }
        
        // Update last activity timestamp
        $_SESSION['last_activity'] = time();
    }

    /**
     * Login user
     */
    public static function login($email, $password, $tfaCode = null) {
        $db = Database::getUserDB();
        
        // Check if user is locked out
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            // Log failed login attempt with IP address and User Agent
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            self::logSystemAction(null, 'login_failed', 'user', null, "User not found: {$email} - IP: {$ipAddress} - User Agent: {$userAgent}");
            return ['success' => false, 'message' => 'Ungültige Anmeldedaten'];
        }
        
        // Check if account is locked
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $remainingTime = ceil((strtotime($user['locked_until']) - time()) / 60);
            return ['success' => false, 'message' => "Konto gesperrt. Versuchen Sie es in $remainingTime Minuten erneut."];
        }
        
        // Verify password
        if (!password_verify($password, $user['password'])) {
            // Increment failed attempts
            $failedAttempts = $user['failed_login_attempts'] + 1;
            $lockedUntil = null;
            
            // Lock account for 15 minutes after 5 consecutive failed attempts
            if ($failedAttempts >= 5) {
                $lockedUntil = date('Y-m-d H:i:s', time() + 900);
            }
            
            $stmt = $db->prepare("UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?");
            $stmt->execute([$failedAttempts, $lockedUntil, $user['id']]);
            
            // Log failed login attempt with IP address and User Agent
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            self::logSystemAction($user['id'], 'login_failed', 'user', $user['id'], "Invalid password - Attempt {$failedAttempts} - IP: {$ipAddress} - User Agent: {$userAgent}");
            
            return ['success' => false, 'message' => 'Ungültige Anmeldedaten'];
        }
        
        // Check 2FA if enabled AND secret is configured
        if ($user['tfa_enabled'] && !empty($user['tfa_secret'])) {
            if ($tfaCode === null) {
                return ['success' => false, 'require_2fa' => true, 'user_id' => $user['id']];
            }
            
            // Check if 2FA is temporarily locked due to too many failed attempts
            try {
                if (!empty($user['tfa_locked_until']) && strtotime($user['tfa_locked_until']) > time()) {
                    $remainingMinutes = ceil((strtotime($user['tfa_locked_until']) - time()) / 60);
                    return ['success' => false, 'message' => "Konto gesperrt. Zu viele fehlgeschlagene 2FA-Versuche. Bitte versuchen Sie es in {$remainingMinutes} Minute(n) erneut."];
                }
            } catch (Exception $e) {
                // Column may not exist yet; proceed without lockout check
            }
            
            require_once __DIR__ . '/GoogleAuthenticator.php';
            $ga = new PHPGangsta_GoogleAuthenticator();
            
            if (!$ga->verifyCode($user['tfa_secret'], $tfaCode, 2)) {
                // Track failed 2FA attempt with brute-force protection
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
                try {
                    $newAttempts = ($user['tfa_failed_attempts'] ?? 0) + 1;
                    if ($newAttempts >= 5) {
                        $lockedUntil = date('Y-m-d H:i:s', time() + 900);
                        $stmt = $db->prepare("UPDATE users SET tfa_failed_attempts = ?, tfa_locked_until = ? WHERE id = ?");
                        $stmt->execute([$newAttempts, $lockedUntil, $user['id']]);
                        self::logSystemAction($user['id'], 'login_2fa_locked', 'user', $user['id'], "2FA locked after {$newAttempts} failed attempts - IP: {$ipAddress}");
                        return ['success' => false, 'message' => 'Konto gesperrt. Zu viele fehlgeschlagene 2FA-Versuche. Bitte versuchen Sie es in 15 Minuten erneut.'];
                    } else {
                        $stmt = $db->prepare("UPDATE users SET tfa_failed_attempts = ? WHERE id = ?");
                        $stmt->execute([$newAttempts, $user['id']]);
                    }
                } catch (Exception $e) {
                    // Column may not exist yet; proceed without DB tracking
                }
                self::logSystemAction($user['id'], 'login_2fa_failed', 'user', $user['id'], "Invalid 2FA code - IP: {$ipAddress} - User Agent: {$userAgent}");
                return ['success' => false, 'message' => 'Ungültiger 2FA-Code'];
            }
            
            // 2FA successful - reset failed attempts counter
            try {
                $stmt = $db->prepare("UPDATE users SET tfa_failed_attempts = 0, tfa_locked_until = NULL WHERE id = ?");
                $stmt->execute([$user['id']]);
            } catch (Exception $e) {
                // Column may not exist yet; ignore
            }
        }
        
        // Reset failed attempts and update last login
        $stmt = $db->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);
        
        // Initialize session
        self::startSession();
        
        // Regenerate session ID to prevent session fixation attacks
        // This must be called after session is started but before setting user-specific session data
        session_regenerate_id(true);
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['authenticated'] = true;
        $_SESSION['last_activity'] = time(); // Initialize activity timestamp
        $_SESSION['profile_incomplete'] = !$user['profile_complete'];
        $_SESSION['is_onboarded'] = (bool)($user['is_onboarded'] ?? false);
        
        self::logSystemAction($user['id'], 'login_success', 'user', $user['id'], 'Successful login');
        
        return ['success' => true, 'user' => $user];
    }

    /**
     * Logout user
     */
    public static function logout() {
        self::startSession();
        
        if (isset($_SESSION['user_id'])) {
            self::logSystemAction($_SESSION['user_id'], 'logout', 'user', $_SESSION['user_id'], 'User logged out');
        }
        
        session_destroy();
        session_unset();
    }

    /**
     * Check if user is authenticated
     */
    public static function isAuthenticated() {
        self::startSession();
        return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
    }

    /**
     * Get current user
     */
    public static function getCurrentUser() {
        self::startSession();
        if (self::isAuthenticated()) {
            return User::getById($_SESSION['user_id']);
        }
        return null;
    }

    /**
     * Check if user has permission
     */
    public static function hasPermission($requiredRole) {
        self::startSession();
        if (!self::isAuthenticated()) {
            return false;
        }
        
        // Role hierarchy: alumni and mitglied have read-only access (level 1)
        // ressortleiter can edit inventory (level 2)
        // board roles and alumni_vorstand have full board access (level 3)
        // Note: 'admin', 'board', and 'manager' kept for backward compatibility with legacy code paths.
        // 'manager' is DEPRECATED in favor of 'ressortleiter' but kept for existing users
        // 'board' is a placeholder level 3 role used for permission checks
        // 'admin' is DEPRECATED and not assignable to new users
        $roleHierarchy = [
            'anwaerter'           => 1,
            'alumni'              => 1,
            'mitglied'            => 1,
            'ehrenmitglied'       => 1,
            'manager'             => 2,  // DEPRECATED: Use 'ressortleiter' instead. Kept for existing users.
            'resortleiter'        => 2,  // DEPRECATED: Use 'ressortleiter' instead. Kept for existing users.
            'ressortleiter'       => 2,
            'alumni_vorstand'     => 3,
            'alumni_finanz'       => 3,  // Same level as alumni_vorstand
            'vorstand_finanzen'   => 3,
            'vorstand_intern'     => 3,
            'vorstand_extern'     => 3,
            'board'               => 3,  // DEPRECATED: Placeholder for backward compatibility checks
            'admin'               => 3  // DEPRECATED: Keep for backward compatibility only. Not assignable to new users.
        ];
        $userRole = $_SESSION['user_role'];
        
        // Check if user role exists in hierarchy
        if (!isset($roleHierarchy[$userRole]) || !isset($roleHierarchy[$requiredRole])) {
            return false;
        }
        
        return $roleHierarchy[$userRole] >= $roleHierarchy[$requiredRole];
    }

    /**
     * Check if user has specific role (exact match, not hierarchical)
     * Special case: 'admin' and 'board' role checks return true for board users
     * 
     * @param string $role Required role
     * @return bool True if user has exact role
     */
    public static function hasRole($role) {
        self::startSession();
        if (!self::isAuthenticated()) {
            return false;
        }
        
        $userRole = $_SESSION['user_role'] ?? '';
        
        // Special case: 'admin' maps to board_finance for backward compatibility
        if ($role === 'admin') {
            return Auth::isAdmin();
        }
        
        // Special case: 'board' maps to any board role for backward compatibility
        if ($role === 'board') {
            return Auth::isBoard();
        }
        
        return $userRole === $role;
    }

    /**
     * Check if user is admin (general system access for Logs, Stats, User Management)
     * 
     * @return bool True if user has any board role or alumni_board or alumni_auditor
     */
    public static function isAdmin() {
        return Auth::canManageUsers();
    }

    /**
     * Require a logged-in user
     * Redirects to login if not authenticated
     */
    public static function requireLogin() {
        if (!self::isAuthenticated()) {
            $loginUrl = (defined('BASE_URL') && BASE_URL) ? BASE_URL . '/login' : '/login';
            header('Location: ' . $loginUrl);
            exit;
        }
    }

    /**
     * Require admin privileges (any board role)
     * Redirects to login if not authorized
     */
    public static function requireAdmin() {
        if (!self::isAdmin()) {
            $loginUrl = (defined('BASE_URL') && BASE_URL) ? BASE_URL . '/login' : '/login';
            header('Location: ' . $loginUrl);
            exit;
        }
    }
    
    /**
     * Check if user is a board member (any board role)
     * 
     * @return bool True if user has any board role
     */
    public static function isBoard() {
        self::startSession();
        if (!self::isAuthenticated()) {
            return false;
        }
        
        $userRole = $_SESSION['user_role'] ?? '';
        return in_array($userRole, Auth::BOARD_ROLES);
    }
    
    /**
     * Check if user can manage invoices
     * 
     * @return bool True if user is vorstand_finanzen
     */
    public static function canManageInvoices() {
        self::startSession();
        if (!self::isAuthenticated()) {
            return false;
        }
        
        $userRole = $_SESSION['user_role'] ?? '';
        return $userRole === 'vorstand_finanzen';
    }
    
    /**
     * Check if user can manage users
     * 
     * @return bool True if user has any board role, alumni_vorstand, or alumni_finanz
     */
    public static function canManageUsers() {
        return Auth::canManageUsers();
    }
    
    /**
     * Check if user can see system stats (Logs, Stats, Dashboard)
     * 
     * @return bool True if user has any board role (board_finance, board_internal, board_external)
     */
    public static function canSeeSystemStats() {
        return self::isBoard();
    }

    /**
     * Check if alumni user is validated
     * Alumni users need board approval before accessing internal alumni network data
     */
    public static function isAlumniValidated() {
        self::startSession();
        if (!self::isAuthenticated()) {
            return false;
        }
        
        $user = self::getCurrentUser();
        if (!$user || $user['role'] !== 'alumni') {
            return true; // Non-alumni users are always "validated"
        }
        
        return $user['is_alumni_validated'] == 1;
    }

    /**
     * Log system action
     */
    public static function logSystemAction($userId, $action, $entityType = null, $entityId = null, $details = null) {
        try {
            $db = Database::getContentDB();
            $stmt = $db->prepare("INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $userId,
                $action,
                $entityType,
                $entityId,
                $details,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            error_log("Failed to log system action: " . $e->getMessage());
        }
    }

    /**
     * Initiate Microsoft Entra ID OAuth login
     */
    public static function initiateMicrosoftLogin() {
        self::startSession();

        // Load credentials from configuration constants
        $clientId    = defined('CLIENT_ID')    ? CLIENT_ID    : '';
        $redirectUri = defined('REDIRECT_URI') ? REDIRECT_URI : '';
        $tenantId    = defined('TENANT_ID')    ? TENANT_ID    : '';

        // Validate required environment variables (client_secret not needed for auth URL)
        if (empty($clientId) || empty($redirectUri) || empty($tenantId)) {
            throw new Exception('Missing Azure OAuth configuration');
        }

        // Generate cryptographically secure random state for CSRF protection
        $state = bin2hex(random_bytes(16));

        // Store state in session for CSRF protection
        $_SESSION['oauth2state'] = $state;

        // Log state storage for debugging (log only presence, not actual value for security)
        error_log("[OAuth] State stored in session (length: " . strlen($state) . ")");
        error_log("[OAuth] Session ID: " . session_id());

        // Ensure session is written to disk before redirect
        // This is critical for OAuth flow to preserve the state parameter
        session_write_close();

        // Build authorization URL with required scopes (space-separated as required by Microsoft OAuth v2.0)
        $authorizationUrl = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/authorize?'
            . http_build_query([
                'client_id'     => $clientId,
                'response_type' => 'code',
                'redirect_uri'  => $redirectUri,
                'response_mode' => 'query',
                'scope'         => 'openid profile email offline_access User.Read',
                'state'         => $state,
            ]);

        // Redirect to authorization URL
        header('Location: ' . $authorizationUrl);
        exit;
    }

    /**
     * Handle Microsoft Entra ID OAuth callback
     */
    public static function handleMicrosoftCallback() {
        $_vendorPath = __DIR__ . '/../../vendor/autoload.php';
        if (!file_exists($_vendorPath)) {
            throw new Exception('Microsoft OAuth requires Composer dependencies. Run "composer install" on the server.');
        }
        require_once $_vendorPath;
        unset($_vendorPath);
        
        self::startSession();
        
        // Validate state for CSRF protection
        if (!isset($_GET['state']) || !isset($_SESSION['oauth2state']) || !hash_equals((string)$_SESSION['oauth2state'], (string)$_GET['state'])) {
            // Log detailed error information for debugging (without exposing actual values)
            error_log("[OAuth] State validation failed:");
            error_log("[OAuth]   - GET state present: " . (isset($_GET['state']) ? 'YES' : 'NO'));
            error_log("[OAuth]   - GET state length: " . (isset($_GET['state']) ? strlen($_GET['state']) : '0'));
            error_log("[OAuth]   - SESSION oauth2state present: " . (isset($_SESSION['oauth2state']) ? 'YES' : 'NO'));
            error_log("[OAuth]   - SESSION oauth2state length: " . (isset($_SESSION['oauth2state']) ? strlen($_SESSION['oauth2state']) : '0'));
            error_log("[OAuth]   - Session ID: " . session_id());
            unset($_SESSION['oauth2state']);
            throw new Exception('Invalid state parameter');
        }
        
        // Clear state
        unset($_SESSION['oauth2state']);
        
        // Check for error
        if (isset($_GET['error'])) {
            throw new Exception('OAuth error: ' . ($_GET['error_description'] ?? $_GET['error']));
        }
        
        // Check for authorization code
        if (!isset($_GET['code'])) {
            throw new Exception('No authorization code received');
        }
        
        // Load credentials from configuration constants
        $clientId = defined('CLIENT_ID') ? CLIENT_ID : '';
        $clientSecret = defined('CLIENT_SECRET') ? CLIENT_SECRET : '';
        $redirectUri = defined('REDIRECT_URI') ? REDIRECT_URI : '';
        $tenantId = defined('TENANT_ID') ? TENANT_ID : '';
        
        // Validate required environment variables
        if (empty($clientId) || empty($clientSecret) || empty($redirectUri) || empty($tenantId)) {
            throw new Exception('Missing Azure OAuth configuration');
        }
        
        // Initialize GenericProvider with Azure endpoints using config constants
        $provider = new \League\OAuth2\Client\Provider\GenericProvider([
            'clientId'                => $clientId,
            'clientSecret'            => $clientSecret,
            'redirectUri'             => $redirectUri,
            'urlAuthorize'            => 'https://login.microsoftonline.com/' . $tenantId . '/oauth2/v2.0/authorize',
            'urlAccessToken'          => 'https://login.microsoftonline.com/' . $tenantId . '/oauth2/v2.0/token',
            'urlResourceOwnerDetails' => 'https://graph.microsoft.com/v1.0/me',
        ]);
        
        try {
            // Get access token using authorization code
            $token = $provider->getAccessToken('authorization_code', [
                'code' => $_GET['code']
            ]);
            
            // Get resource owner (user) details
            $resourceOwner = $provider->getResourceOwner($token);
            
            // Get user claims (including roles)
            $claims = $resourceOwner->toArray();
            
            // Extract azure_oid from oid or sub claim
            $azureOid = $claims['oid'] ?? $claims['sub'] ?? null;
            
            // Look up user in local database by azure_oid
            $db = Database::getUserDB();
            $existingUser = null;
            if ($azureOid) {
                $stmt = $db->prepare("SELECT * FROM users WHERE azure_oid = ?");
                $stmt->execute([$azureOid]);
                $existingUser = $stmt->fetch() ?: null;
            }
            
            // Complete the login (role mapping, user create/update, session setup)
            self::completeMicrosoftLogin($claims, $existingUser, $token->getToken());
            
        } catch (Exception $e) {
            self::logSystemAction(null, 'login_failed_microsoft', 'user', null, 'Microsoft login error: ' . $e->getMessage());
            throw new Exception('Failed to authenticate with Microsoft: ' . $e->getMessage());
        }
    }

    /**
     * Complete Microsoft login after access token and initial user lookup.
     * Handles role mapping, user create/update, profile sync, and session setup.
     *
     * @param array      $claims           Token claims from the resource owner
     * @param array|null $existingUser     Pre-fetched user row (by azure_oid) or null
     * @param string|null $userAccessToken Optional user OAuth access token for Graph API photo retrieval
     */
    public static function completeMicrosoftLogin(array $claims, $existingUser = null, ?string $userAccessToken = null) {
        require_once dirname(__DIR__, 2) . '/includes/models/Alumni.php';

        $azureOid = $claims['oid'] ?? $claims['sub'] ?? null;

        // Fetch App Role assignments directly from the Enterprise Application via Graph API.
        // This is more reliable than the JWT 'roles' claim and reflects role changes
        // made in the portal without requiring a new token. Falls back to JWT claims on failure.
        $azureRoles = [];
        if ($azureOid) {
            try {
                $graphSvc = new MicrosoftGraphService(); // client-credentials flow
                $azureRoles = $graphSvc->getUserAppRoles($azureOid);
            } catch (Exception $e) {
                error_log('[completeMicrosoftLogin] getUserAppRoles failed for OID ' . $azureOid . ': ' . $e->getMessage());
                $azureRoles = $claims['roles'] ?? []; // fallback to JWT claims
            }
        } else {
            $azureRoles = $claims['roles'] ?? [];
        }
        error_log("DEBUG AZURE ROLES FROM ENTERPRISE APP: " . print_r($azureRoles, true));

        // Select the highest-priority role from the Enterprise Application assignments.
        // Roles returned by getUserAppRoles() are already translated to role name strings.
        $validEntraRoles = [
            'anwaerter'         => 1,
            'mitglied'          => 2,
            'ressortleiter'     => 3,
            'alumni'            => 4,
            'ehrenmitglied'     => 5,
            'vorstand_finanzen' => 6,
            'vorstand_intern'   => 7,
            'vorstand_extern'   => 8,
            'alumni_vorstand'   => 9,
            'alumni_finanz'     => 10,
        ];

        $highestPriority = 0;
        $selectedRole = 'mitglied'; // Default if no role is assigned in the Enterprise Application

        foreach ($azureRoles as $roleValue) {
            if (isset($validEntraRoles[$roleValue]) && $validEntraRoles[$roleValue] > $highestPriority) {
                $highestPriority = $validEntraRoles[$roleValue];
                $selectedRole = $roleValue;
            }
        }

        $roleName = $selectedRole;

        // Log the selected role for debugging
        error_log("Selected role for user {$azureOid}: {$roleName} (from Enterprise App Roles: " . implode(', ', $azureRoles) . ")");
        
        // Get user email from claims
        // Priority: email -> preferred_username -> upn
        $email = $claims['email'] ?? $claims['preferred_username'] ?? $claims['upn'] ?? null;
        
        if (!$email) {
            // Log available claims for debugging
            error_log('Azure AD claims received: ' . json_encode(array_keys($claims)));
            throw new Exception('Unable to retrieve user email from Azure AD claims. Expected one of: email, preferred_username, or upn');
        }
        
        // Extract first name and last name from claims
        // Standard OpenID Connect claims: given_name, family_name, name
        $firstName = $claims['given_name'] ?? $claims['givenName'] ?? null;
        $lastName = $claims['family_name'] ?? $claims['surname'] ?? null;
        
        // Format names from Entra ID (e.g., "tom.lehmann" -> "Tom Lehmann")
        if ($firstName) {
            $firstName = formatEntraName($firstName);
        }
        if ($lastName) {
            $lastName = formatEntraName($lastName);
        }
        
        // Track whether the user was already known when this function was called.
        // If $existingUser was null here, syncEntraData was NOT called by callback.php,
        // so this function must handle the Entra photo sync itself.
        $wasExistingOnEntry = ($existingUser !== null);

        // Look up user in database: if not already found by azure_oid, fall back to email
        $db = Database::getUserDB();
        if (!$existingUser) {
            $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $existingUser = $stmt->fetch() ?: null;
        }

        if ($existingUser) {
            // Update existing user - update role and Azure info but keep profile_complete as is
            $userId = $existingUser['id'];
            
            // Update user table with role from Microsoft (but don't override profile_complete)
            // Also store the original Azure roles as JSON for profile display and Azure OID
            $azureRolesJson = json_encode($azureRoles);
            $stmt = $db->prepare("UPDATE users SET role = ?, azure_roles = ?, azure_oid = ?, last_login = NOW() WHERE id = ?");
            $stmt->execute([$roleName, $azureRolesJson, $azureOid, $userId]);
        } else {
            // Create new user without password (OAuth login only)
            $azureRolesJson = json_encode($azureRoles);
            
            // Use a random password hash since user will login via OAuth
            $randomPassword = password_hash(bin2hex(random_bytes(32)), HASH_ALGO);
            $isAlumniValidated = ($roleName === 'alumni') ? 0 : 1;
            // Set profile_complete=0 to force first-time profile completion
            $profileComplete = 0;
            
            $stmt = $db->prepare("
                INSERT INTO users (
                    email, password, role, azure_roles, azure_oid, 
                    is_alumni_validated, profile_complete, 
                    first_name, last_name
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $email, $randomPassword, $roleName, $azureRolesJson, $azureOid,
                $isAlumniValidated, $profileComplete,
                $firstName, $lastName
            ]);
            $userId = $db->lastInsertId();
        }
        
        // Update or create alumni profile if first_name and last_name are available
        if ($firstName && $lastName) {
            try {
                $contentDb = Database::getContentDB();
                
                // Use INSERT ... ON DUPLICATE KEY UPDATE for upsert logic (prevents race conditions)
                $stmt = $contentDb->prepare("
                    INSERT INTO alumni_profiles (user_id, first_name, last_name, email)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        first_name = VALUES(first_name),
                        last_name = VALUES(last_name),
                        email = VALUES(email)
                ");
                $stmt->execute([$userId, $firstName, $lastName, $email]);
            } catch (Exception $e) {
                error_log("Failed to update alumni profile: " . $e->getMessage());
                // Don't throw - allow login to proceed even if profile update fails
            }
        }
        
        // Sync profile data and photo from Microsoft Graph
        try {
            // Reuse or create MicrosoftGraphService instance
            // Prefer the delegated user token (User.Read scope) over client-credentials flow
            if (!isset($graphService) && $azureOid) {
                $graphService = new MicrosoftGraphService($userAccessToken);
            }
            
            if ($azureOid && isset($graphService)) {
                // Get or reuse user profile data (job title, company, groups)
                // If we already fetched this earlier for role determination, reuse it
                if (!isset($profileData)) {
                    try {
                        $profileData = $graphService->getUserProfile($azureOid);
                    } catch (Exception $e) {
                        error_log("Failed to fetch user profile from Microsoft Graph: " . $e->getMessage());
                        $profileData = ['jobTitle' => null, 'companyName' => null, 'groups' => []];
                    }
                }
                
                // Store job title and company in users table
                $jobTitle = $profileData['jobTitle'] ?? null;
                $companyName = $profileData['companyName'] ?? null;

                // Store Entra App Roles (from JWT) in entra_roles field
                $entraRolesJson = !empty($azureRoles) ? json_encode($azureRoles) : null;

                // Update user record with profile data
                $stmt = $db->prepare("UPDATE users SET job_title = ?, company = ?, entra_roles = ? WHERE id = ?");
                $stmt->execute([$jobTitle, $companyName, $entraRolesJson, $userId]);

                // Store Entra roles in session for display
                $_SESSION['entra_roles'] = $azureRoles;

                // Sync profile photo for users that were not already known to callback.php.
                // - Truly new users: $wasExistingOnEntry is false and $existingUser is still null
                // - Users found via email fallback inside this function: $wasExistingOnEntry is false
                //   but $existingUser is now set. syncEntraData was NOT called for these users in
                //   callback.php (it only runs when $existingUser was truthy there), so we must
                //   perform the photo sync here.
                // - Users already found by azure_oid or email in callback.php: $wasExistingOnEntry is
                //   true, so syncEntraData already handled their photo sync – skip here.
                if (!$wasExistingOnEntry) {
                    try {
                        require_once dirname(__DIR__, 2) . '/includes/models/User.php';
                        $userDb      = Database::getUserDB();
                        $avatarStmt  = $userDb->prepare("SELECT use_custom_avatar FROM users WHERE id = ?");
                        $avatarStmt->execute([$userId]);
                        $avatarRow   = $avatarStmt->fetch();
                        // Skip photo sync if the user has disabled Entra sync by uploading their own photo
                        $hasUpload   = (int) ($avatarRow ? ($avatarRow['use_custom_avatar'] ?? 0) : 0) === 1;

                        if (!$hasUpload) {
                            // Use client-credentials flow (no user token) so the service uses the
                            // app-level permissions from .env – identical to syncEntraData and the standalone test.
                            $photoService = new MicrosoftGraphService();
                            $photoData = $photoService->getUserPhoto($email);
                            if ($photoData !== null) {
                                User::cacheEntraPhoto($userId, $photoData);
                            }
                        }
                    } catch (Exception $e) {
                        error_log('[completeMicrosoftLogin] Photo sync failed: ' . $e->getMessage());
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Failed to sync profile data from Microsoft Graph: " . $e->getMessage());
            // Don't throw - allow login to proceed even if profile sync fails
        }
        
        // Check if profile is complete and 2FA status
        $userCheck = null;
        try {
            $stmt = $db->prepare("SELECT profile_complete, tfa_enabled, tfa_secret, is_onboarded FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $userCheck = $stmt->fetch();
        } catch (PDOException $e) {
            error_log("[completeMicrosoftLogin] Failed to fetch user status for user {$userId}: " . $e->getMessage());
            // Fall back to a minimal query without tfa columns in case they don't exist yet
            try {
                $stmt = $db->prepare("SELECT profile_complete, is_onboarded FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $userCheck = $stmt->fetch();
            } catch (PDOException $e2) {
                error_log("[completeMicrosoftLogin] Minimal fallback query also failed: " . $e2->getMessage());
            }
        }

        // Check if 2FA is enabled AND the user has actually configured a secret - do this BEFORE setting authenticated session
        if ($userCheck && intval($userCheck['tfa_enabled']) === 1 && !empty($userCheck['tfa_secret'])) {
            // Store pending authentication state (without granting full access)
            $_SESSION['pending_2fa_user_id'] = $userId;
            $_SESSION['pending_2fa_email'] = $email;
            $_SESSION['pending_2fa_role'] = $roleName;
            $_SESSION['pending_2fa_profile_complete'] = $userCheck['profile_complete'] ?? 1;
            $_SESSION['pending_2fa_is_onboarded'] = $userCheck['is_onboarded'] ?? 0;
            $_SESSION['pending_2fa_show_role_notice'] = empty($azureRoles);
            
            // Log 2FA required
            self::logSystemAction($userId, 'login_2fa_required', 'user', $userId, 'Microsoft login successful, 2FA verification required');
            
            // Redirect to 2FA verification page
            $verify2faUrl = (defined('BASE_URL') && BASE_URL) ? BASE_URL . '/verify-2fa' : '/verify-2fa';
            header('Location: ' . $verify2faUrl);
            exit;
        }

        // Regenerate session ID to prevent session fixation attacks.
        // Must be called BEFORE setting any authenticated session variables so that
        // the old guest/unauthenticated session ID is immediately invalidated and
        // a brand-new session ID is issued for the authenticated session.
        session_regenerate_id(true);

        // Set session variables (only after confirming 2FA is not required)
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_email'] = $email;
        $_SESSION['user_role'] = $roleName;
        $_SESSION['authenticated'] = true;
        $_SESSION['last_activity'] = time();
        
        if ($userCheck && intval($userCheck['profile_complete']) === 0) {
            $_SESSION['profile_incomplete'] = true;
        } else {
            $_SESSION['profile_incomplete'] = false;
        }
        $_SESSION['is_onboarded'] = (bool)($userCheck['is_onboarded'] ?? false);

        // Show 2FA nudge popup if 2FA is not enabled
        if ($userCheck && intval($userCheck['tfa_enabled'] ?? 0) !== 1) {
            $_SESSION['show_2fa_nudge'] = true;
        }

        // Show role notice popup when user had no Azure roles and was assigned the default 'mitglied' role
        if (empty($azureRoles)) {
            $_SESSION['show_role_notice'] = true;
        }
        // Generate a cryptographically random session token for single-session enforcement
        $sessionToken = bin2hex(random_bytes(32));
        // Store session token in database (invalidates all other active sessions for this user).
        // current_session_id is kept for backward compatibility with existing deployments.
        $stmt = $db->prepare("UPDATE users SET current_session_id = ?, session_token = ? WHERE id = ?");
        $stmt->execute([session_id(), $sessionToken, $userId]);
        // Store session token in session for subsequent verification
        $_SESSION['session_token'] = $sessionToken;

        // Log successful login
        self::logSystemAction($userId, 'login_success_microsoft', 'user', $userId, 'Successful Microsoft Entra ID login');
        
        // Redirect to dashboard
        $dashboardUrl = (defined('BASE_URL') && BASE_URL) ? BASE_URL . '/pages/dashboard/index.php' : '/pages/dashboard/index.php';
        header('Location: ' . $dashboardUrl);
        exit;
    }

    /**
     * Sync Microsoft Entra ID data into the local users table on every login.
     *
     * Performs the following steps:
     *  1. Updates displayName (first_name, last_name) and mail from the supplied claims.
     *  2. Fetches the user's current group memberships from Microsoft Graph API.
     *  3. Overwrites the entra_roles JSON field with the current App Roles.
     *  4. Derives the primary local role from Entra App Roles and updates it.
     *
     * @param int    $userId   Local database user ID.
     * @param array  $userData Claims array from Microsoft Entra ID / OAuth token (must include 'roles').
     * @param string $azureOid Azure Object Identifier used for Graph API calls.
     * @param string|null $userAccessToken Optional user OAuth access token for Graph API photo retrieval.
     */
    public static function syncEntraData(int $userId, array $userData, string $azureOid, ?string $userAccessToken = null): void {

        $db = Database::getUserDB();

        // --- 1. Extract displayName (first_name, last_name) and mail from claims ---
        $firstName = $userData['given_name'] ?? $userData['givenName'] ?? null;
        $lastName  = $userData['family_name'] ?? $userData['surname'] ?? null;

        // Format names from Entra ID (e.g. "tom.lehmann" → "Tom Lehmann")
        if ($firstName) {
            $firstName = formatEntraName($firstName);
        }
        if ($lastName) {
            $lastName = formatEntraName($lastName);
        }

        // Prefer explicit email/mail over UPN which may carry an #EXT# suffix
        $mail = $userData['email'] ?? $userData['mail'] ?? $userData['preferred_username'] ?? null;

        // --- 2. Determine role from the Enterprise Application (Unternehmensanwendung) ---
        // Fetch App Role assignments directly from the Graph API so that role changes
        // made in the portal take effect immediately, regardless of what the JWT claims
        // contain. Falls back to JWT claims only when the Graph call fails.
        $graphSvc = null;
        try {
            $graphSvc = new MicrosoftGraphService(); // client-credentials flow
        } catch (Exception $e) {
            error_log('[syncEntraData] Could not create MicrosoftGraphService: ' . $e->getMessage());
        }

        $appRoles = [];
        if ($graphSvc !== null) {
            try {
                $appRoles = $graphSvc->getUserAppRoles($azureOid);
            } catch (Exception $e) {
                error_log('[syncEntraData] getUserAppRoles failed for OID ' . $azureOid . ': ' . $e->getMessage());
                $appRoles = $userData['roles'] ?? []; // fallback to JWT claims
            }
        } else {
            $appRoles = $userData['roles'] ?? [];
        }

        $validEntraRoles = [
            'anwaerter'         => 1,
            'mitglied'          => 2,
            'ressortleiter'     => 3,
            'alumni'            => 4,
            'ehrenmitglied'     => 5,
            'vorstand_finanzen' => 6,
            'vorstand_intern'   => 7,
            'vorstand_extern'   => 8,
            'alumni_vorstand'   => 9,
            'alumni_finanz'     => 10,
        ];

        $highestPriority = 0;
        $selectedRole    = 'mitglied'; // Default when no role is assigned in the Enterprise Application

        foreach ($appRoles as $roleValue) {
            if (isset($validEntraRoles[$roleValue]) && $validEntraRoles[$roleValue] > $highestPriority) {
                $highestPriority = $validEntraRoles[$roleValue];
                $selectedRole    = $roleValue;
            }
        }

        error_log(sprintf('[syncEntraData] Role for user %d: %s (from Enterprise App Roles via Graph API: %s)', $userId, $selectedRole, implode(', ', $appRoles)));

        // --- 3. Overwrite entra_roles JSON field with App Roles from token ---
        try {
            $entraRolesJson = json_encode($appRoles, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            error_log('[syncEntraData] Failed to JSON-encode entra_roles for user ' . $userId . ': ' . $e->getMessage());
            $entraRolesJson = '[]';
        }

        // --- Build and execute UPDATE statement ---
        $setClauses = ['entra_roles = ?', 'role = ?'];
        $params     = [$entraRolesJson, $selectedRole];
        if ($firstName !== null) {
            $setClauses[] = 'first_name = ?';
            $params[]     = $firstName;
        }
        if ($lastName !== null) {
            $setClauses[] = 'last_name = ?';
            $params[]     = $lastName;
        }
        if ($mail !== null) {
            $setClauses[] = 'email = ?';
            $params[]     = $mail;
        }

        $params[] = $userId;
        $sql      = 'UPDATE users SET ' . implode(', ', $setClauses) . ' WHERE id = ?';

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
        } catch (Exception $e) {
            // Log but do not abort the login if the sync update fails
            error_log('[syncEntraData] DB update failed for user ' . $userId . ': ' . $e->getMessage());
            return;
        }

        // --- Sync Entra profile photo (only when user has no manually uploaded photo) ---
        try {
            require_once __DIR__ . '/../../includes/models/User.php';
            require_once __DIR__ . '/../../includes/models/Alumni.php';

            // Re-load $mail from the database to ensure we use the stored email value,
            // not just what was present in the JWT claims.
            $mailStmt = $db->prepare("SELECT email FROM users WHERE id = ?");
            $mailStmt->execute([$userId]);
            $mailRow = $mailStmt->fetch();
            if ($mailRow && !empty($mailRow['email'])) {
                $mail = $mailRow['email'];
            }

            // Check users.use_custom_avatar to determine if the user has uploaded their own photo
            // and disabled Entra photo sync for their account.
            $avatarStmt  = $db->prepare("SELECT use_custom_avatar, avatar_path FROM users WHERE id = ?");
            $avatarStmt->execute([$userId]);
            $avatarRow     = $avatarStmt->fetch();
            $hasUpload     = (int) ($avatarRow ? ($avatarRow['use_custom_avatar'] ?? 0) : 0) === 1;
            $currentAvatar = $avatarRow ? ($avatarRow['avatar_path'] ?? null) : null;

            // Fetch alumni_profiles row for name sync below (existence check only)
            $contentDb    = Database::getContentDB();
            $profileStmt  = $contentDb->prepare("SELECT user_id FROM alumni_profiles WHERE user_id = ?");
            $profileStmt->execute([$userId]);
            $profileRow   = $profileStmt->fetch();

            // --- Also sync first_name / last_name into alumni_profiles when available ---
            if ($firstName !== null || $lastName !== null || $mail !== null) {
                try {
                    if ($profileRow !== false) {
                        // Profile exists – update only the name/email fields provided by Entra
                        $nameFields  = [];
                        $nameParams  = [];
                        if ($firstName !== null) {
                            $nameFields[] = 'first_name = ?';
                            $nameParams[] = $firstName;
                        }
                        if ($lastName !== null) {
                            $nameFields[] = 'last_name = ?';
                            $nameParams[] = $lastName;
                        }
                        if ($mail !== null) {
                            $nameFields[] = 'email = ?';
                            $nameParams[] = $mail;
                        }
                        if (!empty($nameFields)) {
                            $nameParams[] = $userId;
                            $contentDb->prepare('UPDATE alumni_profiles SET ' . implode(', ', $nameFields) . ' WHERE user_id = ?')
                                      ->execute($nameParams);
                        }
                    } elseif ($firstName !== null && $lastName !== null && $mail !== null) {
                        // No profile yet – create a minimal one so the sidebar can display the name
                        $contentDb->prepare('INSERT INTO alumni_profiles (user_id, first_name, last_name, email) VALUES (?, ?, ?, ?)')
                                  ->execute([$userId, $firstName, $lastName, $mail]);
                    }
                } catch (Exception $e) {
                    // Non-fatal: name sync failure must not break login
                    error_log('[syncEntraData] Name sync to alumni_profiles failed for user ' . $userId . ': ' . $e->getMessage());
                }
            }

            error_log(sprintf('[syncEntraData] Photo sync condition hasUpload for user %d: %s', $userId, $hasUpload ? 'true (skipping, user has own upload)' : 'false (will fetch from Entra)'));
            if (!$hasUpload) {
                if ($graphSvc === null) {
                    error_log('[syncEntraData] Skipping photo sync: MicrosoftGraphService unavailable (check Azure credentials)');
                } else {
                    error_log('[syncEntraData] Passing email identifier to getUserPhoto: ' . $mail);
                    $photoData = $graphSvc->getUserPhoto($mail);
                    error_log(sprintf('[syncEntraData] getUserPhoto for user %d (mail: %s): %s', $userId, $mail, $photoData !== null ? 'returned photo data (' . strlen($photoData) . ' bytes)' : 'returned null (no photo available)'));
                    if ($photoData === null) {
                        error_log(sprintf('[syncEntraData] getUserPhoto returned null for user %d (mail: %s) – no photo fetched from Entra', $userId, $mail));
                        error_log('Sync fehlgeschlagen für E-Mail: ' . $mail);
                    }
                    if ($photoData !== null) {
                        $cachedPath = User::cacheEntraPhoto($userId, $photoData);
                        error_log(sprintf('[syncEntraData] cacheEntraPhoto for user %d: %s', $userId, $cachedPath !== null ? 'cached at ' . $cachedPath : 'returned null (caching failed or skipped)'));
                    } elseif (!empty($currentAvatar) && strpos($currentAvatar, 'entra_') !== false) {
                        // User no longer has a photo in Entra – clear the stale cached path so
                        // the default profile image is shown instead of an outdated Entra photo.
                        $db->prepare("UPDATE users SET avatar_path = NULL WHERE id = ?")
                           ->execute([$userId]);
                    }
                }
            }
        } catch (Exception $e) {
            // Non-fatal: photo sync failure must not break login
            error_log('[syncEntraData] Photo sync failed for user ' . $userId . ': ' . $e->getMessage());
        }

        error_log(sprintf(
            '[syncEntraData] Synced user %d: role=%s, entra_roles=%d roles, first_name=%s, mail=%s',
            $userId,
            $selectedRole ?? '(unchanged)',
            count($appRoles),
            $firstName ?? '(not updated)',
            $mail       ?? '(not updated)'
        ));
    }
}
