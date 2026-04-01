<?php
/**
 * Helper Functions
 */

/**
 * Check form-specific rate limit using a per-action session timer.
 *
 * Only explicit POST requests for content creation should be limited.
 * Harmless GET requests (e.g. user search, notifications) must NOT
 * call this function – they are exempt from rate limiting by design.
 *
 * @param string $sessionKey    A unique session key per form action,
 *                              e.g. 'last_support_submit_time',
 *                              'last_idea_submit_time', 'last_job_submit_time'.
 * @param int    $cooldown      Minimum seconds required between submissions (default: 60).
 * @return int   0 if the request is allowed; positive integer = remaining seconds to wait.
 */
function checkFormRateLimit(string $sessionKey, int $cooldown = 60): int {
    if (isset($_SESSION[$sessionKey])) {
        $elapsed = time() - (int)$_SESSION[$sessionKey];
        if ($elapsed < $cooldown) {
            return $cooldown - $elapsed;
        }
    }
    return 0;
}

/**
 * Record the time of a successful form submission for rate limiting purposes.
 *
 * Call this after a form has been successfully processed to start the cooldown timer.
 *
 * @param string $sessionKey The same key used in checkFormRateLimit().
 */
function recordFormSubmit(string $sessionKey): void {
    $_SESSION[$sessionKey] = time();
}

/**
 * Initialize PHP session with secure parameters
 * Only starts the session if it has not been started yet
 */
function init_session() {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

/**
 * Get base URL path
 */
function getBasePath() {
    return rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
}

/**
 * Generate URL relative to document root using BASE_URL
 * Uses BASE_URL constant for robust URL generation regardless of subdirectory depth
 */
function url($path) {
    // Remove trailing slashes from BASE_URL
    $baseUrl = rtrim(BASE_URL, '/');
    
    // Remove leading slashes from path
    $path = ltrim($path, '/');
    
    // Combine with exactly one slash
    return $baseUrl . '/' . $path;
}

/**
 * Redirect helper
 */
function redirect($path, $absolute = false) {
    if ($absolute) {
        header('Location: ' . $path);
    } else {
        header('Location: ' . url($path));
    }
    exit;
}

/**
 * Format currency
 */
function formatCurrency($amount) {
    return number_format($amount, 2, ',', '.') . ' €';
}

/**
 * Format date
 */
function formatDate($date, $format = 'd.m.Y') {
    if (empty($date)) return '-';
    return date($format, is_numeric($date) ? $date : strtotime($date));
}

/**
 * Format datetime
 */
function formatDateTime($date, $format = 'd.m.Y H:i') {
    if (empty($date)) return '-';
    return date($format, is_numeric($date) ? $date : strtotime($date));
}

/**
 * Format name from Entra ID (e.g., "tom.lehmann" -> "Tom Lehmann")
 * Replaces dots with spaces and capitalizes first letters of each word
 * 
 * Note: This function is idempotent and safe to apply to any name for display purposes.
 * It's designed for Entra ID names that may use lowercase with dots (e.g., "tom.lehmann"),
 * but can be safely applied to names already in proper format.
 * 
 * Limitation: Special name patterns like "McDonald" will become "Mcdonald" and 
 * "O'Brien" will become "O'brien". This is acceptable for Entra ID names which 
 * typically use simple lowercase format.
 * 
 * @param string $name The name to format
 * @return string The formatted name
 */
function formatEntraName($name) {
    if (empty($name)) {
        return '';
    }
    
    // Replace dots with spaces
    $name = str_replace('.', ' ', $name);
    
    // Convert to lowercase first, then capitalize first letter of each word
    $name = mb_strtolower($name, 'UTF-8');
    return ucwords($name);
}

/**
 * Escape HTML
 */
if (!function_exists('e')) {
    function e($text) {
        return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Check if current page is active
 */
function isActive($page) {
    return strpos($_SERVER['REQUEST_URI'], $page) !== false ? 'active' : '';
}

/**
 * Generate asset URL with BASE_URL
 * Ensures exactly one slash between BASE_URL and path
 */
function asset_url($path) {
    // Remove trailing slashes from BASE_URL
    $baseUrl = rtrim(BASE_URL, '/');
    
    // Remove leading slashes from path
    $path = ltrim($path, '/');
    
    // Combine with exactly one slash
    return $baseUrl . '/' . $path;
}

/**
 * Generate asset path with BASE_URL
 * Ensures no double slash by using rtrim on BASE_URL
 * This is an alias for asset_url() for convenience
 */
function asset($path) {
    return asset_url($path);
}

/**
 * Resolve an asset path using the Vite manifest (for cache-busting).
 *
 * When a Vite production build is present (public/assets/.vite/manifest.json)
 * this returns the hashed filename.  Falls back to the raw path so that the
 * legacy Tailwind CSS build continues to work when Vite has not been run.
 *
 * @param  string $path  Entry point path relative to the project root,
 *                       e.g. 'assets/css/tailwind.src.css' or 'assets/js/app.js'
 * @return string        Full URL to the (optionally hashed) asset
 */
function vite_asset(string $path): string {
    static $manifest = null;

    if ($manifest === null) {
        $manifestFile = __DIR__ . '/../public/assets/.vite/manifest.json';
        if (file_exists($manifestFile)) {
            $content  = file_get_contents($manifestFile);
            $manifest = $content !== false ? json_decode($content, true) : [];
        } else {
            $manifest = [];
        }
    }

    if (isset($manifest[$path]['file'])) {
        return asset_url('public/assets/' . $manifest[$path]['file']);
    }

    // Fallback: serve asset directly (dev mode or no build yet)
    return asset_url($path);
}

/**
 * Return a clean, German-formatted role name for a given database role string.
 * This is the central role display function that should be used in all frontend views.
 *
 * @param string $role Database role identifier (e.g. 'vorstand_intern')
 * @return string Formatted German role name (e.g. 'Vorstand Intern')
 */
function getFormattedRoleName(string $role): string {
    return match($role) {
        'admin'               => 'Administrator',
        'vorstand_finanzen'   => 'Vorstand Finanzen und Recht',
        'vorstand_intern'     => 'Vorstand Intern',
        'vorstand_extern'     => 'Vorstand Extern',
        'ressortleiter'       => 'Ressortleiter',
        'mitglied'            => 'Mitglied',
        'alumni'              => 'Alumni',
        'anwaerter'           => 'Anwärter',
        'alumni_vorstand'     => 'Alumni-Vorstand',
        'alumni_finanz'       => 'Alumni-Finanzprüfer',
        'ehrenmitglied'       => 'Ehrenmitglied',
        'manager'             => 'Ressortleiter',
        default               => ucfirst(str_replace('_', ' ', $role)),
    };
}

/**
 * Return the Font Awesome icon class for a given role key.
 * Used to display a consistent icon next to role badges across all views.
 *
 * @param string $role Database role identifier (e.g. 'vorstand_intern')
 * @return string Font Awesome class name (e.g. 'fa-crown')
 */
function getRoleIcon(string $role): string {
    return match($role) {
        'admin'             => 'fa-shield-alt',
        'vorstand_intern'   => 'fa-crown',
        'vorstand_extern'   => 'fa-crown',
        'vorstand_finanzen' => 'fa-crown',
        'alumni_vorstand'   => 'fa-user-tie',
        'alumni_finanz'     => 'fa-user-tie',
        'alumni'            => 'fa-user-graduate',
        'ressortleiter'     => 'fa-briefcase',
        'mitglied'          => 'fa-user',
        'anwaerter'         => 'fa-user-clock',
        'ehrenmitglied'     => 'fa-star',
        default             => 'fa-user',
    };
}

/**
 * Translate role from English to German
 * All board sub-roles (vorstand_*) are displayed as 'Vorstand'
 * 
 * @param string $role Role identifier
 * @return string German translation of the role
 */
function translateRole($role) {
    $roleTranslations = [
        'admin'               => 'Administrator',
        'vorstand_finanzen'   => 'Vorstand Finanzen und Recht',
        'vorstand_intern'     => 'Vorstand Intern',
        'vorstand_extern'     => 'Vorstand Extern',
        'ressortleiter'       => 'Ressortleiter',
        'mitglied'            => 'Mitglied',
        'alumni'              => 'Alumni',
        'anwaerter'           => 'Anwärter',
        'alumni_vorstand'     => 'Alumni-Vorstand',
        'alumni_finanz'       => 'Alumni-Finanzprüfer',
        'ehrenmitglied'       => 'Ehrenmitglied',
        'manager'             => 'Ressortleiter'
    ];
    
    return $roleTranslations[$role] ?? ucfirst($role);
}

/**
 * Translate Azure/Entra ID role to German display name
 * Maps the original Azure role names to their German equivalents
 * 
 * @param string $azureRole Azure role identifier (e.g., 'anwaerter', 'mitglied')
 * @return string German display name
 */
function translateAzureRole($azureRole) {
    $azureRoleTranslations = [
        'anwaerter'           => 'Anwärter',
        'mitglied'            => 'Mitglied',
        'ressortleiter'       => 'Ressortleiter',
        'vorstand_finanzen'   => 'Vorstand Finanzen und Recht',
        'vorstand_intern'     => 'Vorstand Intern',
        'vorstand_extern'     => 'Vorstand Extern',
        'alumni'              => 'Alumni',
        'alumni_vorstand'     => 'Alumni-Vorstand',
        'alumni_finanz'       => 'Alumni-Finanzprüfer',
        'ehrenmitglied'       => 'Ehrenmitglied'
    ];
    
    // If role not found in mapping, log it for manual addition and return formatted version
    if (!isset($azureRoleTranslations[$azureRole])) {
        error_log("Unknown Azure role encountered: '$azureRole'. Consider adding translation to translateAzureRole()");
        return ucfirst(str_replace('_', ' ', $azureRole));
    }
    
    return $azureRoleTranslations[$azureRole];
}

/**
 * Check if role is an active member role
 * Active member roles: candidate, member, head, board (and board variants)
 * 
 * Note: This matches Auth::BOARD_ROLES plus candidate, member, head.
 * Keep this in sync with Member::ACTIVE_ROLES constant.
 * 
 * @param string $role Role identifier
 * @return bool True if role is an active member role
 */
function isMemberRole($role) {
    // Active roles: board roles + anwaerter, mitglied, ressortleiter
    // Matches Member::ACTIVE_ROLES constant
    return in_array($role, ['anwaerter', 'mitglied', 'ressortleiter', 'vorstand_finanzen', 'vorstand_intern', 'vorstand_extern']);
}

/**
 * Sanitize a value for safe inclusion in a CSV cell (CWE-1236 / CSV injection prevention).
 * A single quote is prepended when the value starts with (optional leading whitespace
 * followed by) a formula-triggering character: =, +, -, @, tab (\t), or carriage
 * return (\r). Leading whitespace is considered a potential obfuscation vector;
 * tab and carriage return are both possible leading whitespace AND trigger characters
 * on their own. This ensures spreadsheet applications (Excel, LibreOffice Calc, …)
 * treat the cell as plain text instead of executing it as a formula.
 *
 * @param mixed $val The value to sanitize
 * @return string The sanitized string value
 */
function sanitizeCsvValue($val): string {
    $val = (string)$val;
    if (preg_match('/^\s*[=+\-@\t\r]/', $val)) {
        $val = "'" . $val;
    }
    return $val;
}

/**
 * Check if role is an alumni role
 * Alumni roles: alumni, alumni_board, honorary_member
 * 
 * @param string $role Role identifier
 * @return bool True if role is an alumni role
 */
function isAlumniRole($role) {
    return in_array($role, ['alumni', 'alumni_vorstand', 'alumni_finanz', 'ehrenmitglied']);
}

/**
 * Extract initials from a full name string
 * Returns the first letter of each of the first two name parts, uppercased
 * Example: "Tom Lehmann" -> "TL"
 *
 * @param string $name Full name (e.g. "Tom Lehmann")
 * @return string Up to two uppercase initials, or '?' if name is empty
 */
function getInitials($name) {
    if (empty($name)) {
        return '?';
    }
    $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= strtoupper(mb_substr($part, 0, 1, 'UTF-8'));
    }
    return $initials !== '' ? $initials : '?';
}

/**
 * Extract initials from first and last name
 *
 * @param string $firstName
 * @param string $lastName
 * @return string Two-letter uppercase initials, or '?' if both are empty
 */
function getMemberInitials($firstName, $lastName) {
    return getInitials($firstName . ' ' . $lastName);
}

/**
 * Generate a consistent background color for an avatar based on a name
 * Uses a hash of the name to select from a palette of accessible colors
 *
 * @param string $name Full name or any string
 * @return string Hex color code (e.g. '#0066b3')
 */
function getAvatarColor($name) {
    $colors = [
        '#0066b3', '#4f46e5', '#0891b2', '#059669', '#d97706',
        '#dc2626', '#7c3aed', '#065f46', '#92400e', '#1e3a5f',
    ];
    if (empty($name)) {
        return $colors[0];
    }
    $index = abs(crc32($name)) % count($colors);
    return $colors[$index];
}

/**
 * Resolve a single image path: returns the path only if it is non-empty
 * AND the file actually exists on the server (path-traversal safe).
 *
 * @param string|null $imagePath Relative path to check
 * @return string|null The path if valid, null otherwise
 */
function resolveImagePath(?string $imagePath): ?string {
    if (empty($imagePath)) {
        return null;
    }
    $basePath = realpath(__DIR__ . '/..');
    if ($basePath === false) {
        return null;
    }
    $fullPath = realpath($basePath . '/' . ltrim($imagePath, '/'));
    if ($fullPath !== false && str_starts_with($fullPath, $basePath) && is_file($fullPath)) {
        return $imagePath;
    }
    error_log('[resolveImagePath] File not found for path: ' . $imagePath);
    return null;
}

/**
 * Get the profile image URL using users.avatar_path as the single source of truth.
 *
 * Hierarchy:
 *  1. If avatar_path is set AND the file physically exists on the server → return the path
 *     (works for both custom_* user uploads and entra_* cached Entra ID photos)
 *  2. Otherwise (avatar_path is empty/NULL or the file is missing) → return the default image
 *
 * @param string|null $avatarPath  The avatar_path value from users table (may be custom_* or entra_*)
 * @return string URL-ready image path
 */
function getProfileImageUrl(?string $avatarPath): string {
    $default = defined('DEFAULT_PROFILE_IMAGE') ? DEFAULT_PROFILE_IMAGE : 'assets/img/default_profil.png';

    // Return the path only if it is set and the file physically exists on the server
    $resolved = resolveImagePath($avatarPath);
    if ($resolved !== null) {
        return $resolved;
    }

    // Guaranteed fallback: default profile image
    return $default;
}

/**
 * Resolve the display role name for a user, preferring Entra role data when available.
 *
 * @param string $internalRole    Internal role key (e.g. 'alumni')
 * @param string|null $entraRolesJson JSON-encoded Entra roles array
 * @return string Human-readable role name
 */
function resolveDisplayRole(string $internalRole, ?string $entraRolesJson): string {
    if (!empty($entraRolesJson)) {
        require_once __DIR__ . '/../src/Auth.php';
        $entraArr = json_decode($entraRolesJson, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($entraArr) && !empty($entraArr)) {
            $displayNames = [];
            foreach ($entraArr as $entraRole) {
                if (is_array($entraRole) && isset($entraRole['displayName'])) {
                    $displayNames[] = $entraRole['displayName'];
                } elseif (is_array($entraRole) && isset($entraRole['id'])) {
                    $displayNames[] = Auth::getRoleLabel($entraRole['id']);
                } elseif (is_string($entraRole)) {
                    $displayNames[] = Auth::getRoleLabel($entraRole);
                }
            }
            if (!empty($displayNames)) {
                return implode(', ', $displayNames);
            }
        }
    }
    return getFormattedRoleName($internalRole);
}


function extractGroupDisplayNames($groups) {
    if (!is_array($groups)) {
        return [];
    }
    
    return array_filter(array_map(function($group) {
        return is_array($group) && isset($group['displayName']) ? $group['displayName'] : $group;
    }, $groups));
}

