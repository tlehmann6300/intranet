<?php
/**
 * Microsoft Entra ID OAuth Callback Handler
 * This file handles the redirect callback from Azure AD after user authentication.
 */

// Load configuration and helpers first (no Composer required)
require_once __DIR__ . '/../config/config.php';

// Load AuthHandler and Database
require_once __DIR__ . '/../includes/handlers/AuthHandler.php';
require_once __DIR__ . '/../includes/database.php';

// Start session
AuthHandler::startSession();

// Capture diagnostic information before the try-block so it is available in the catch
$stateGet     = $_GET['state'] ?? null;
$stateSession = $_SESSION['oauth2state'] ?? null;
$stateMatch   = ($stateGet !== null && $stateSession !== null && $stateGet === $stateSession);
$tokenError   = null;
$azureOid     = null;
$userEmail    = null;

// Handle the Microsoft callback
try {
    // Check for OAuth errors returned by Azure – show the real reason from Microsoft
    if (isset($_GET['error'])) {
        die('OAuth-Fehler von Microsoft: ' . htmlspecialchars($_GET['error_description'] ?? $_GET['error']));
    }

    // Validate state for CSRF protection
    if (!isset($_GET['state']) || !isset($_SESSION['oauth2state']) || $_GET['state'] !== $_SESSION['oauth2state']) {
        unset($_SESSION['oauth2state']);
        die('State mismatch: Session-State ' . ($stateSession ?? 'not set') . ' vs GET-State ' . ($stateGet ?? 'not set'));
    }
    unset($_SESSION['oauth2state']);

    // Check for authorization code
    if (!isset($_GET['code'])) {
        throw new Exception('No authorization code received');
    }

    // Load credentials from configuration constants
    $clientId     = defined('CLIENT_ID') ? CLIENT_ID : '';
    $clientSecret = defined('CLIENT_SECRET') ? CLIENT_SECRET : '';
    $redirectUri  = defined('REDIRECT_URI') ? REDIRECT_URI : '';
    $tenantId     = defined('TENANT_ID') ? TENANT_ID : '';

    if (empty($clientId) || empty($clientSecret) || empty($redirectUri) || empty($tenantId)) {
        throw new Exception('Missing Azure OAuth configuration');
    }

    // Exchange authorization code for access token using native PHP cURL
    $tokenUrl  = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token';
    $postFields = http_build_query([
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'code'          => $_GET['code'],
        'redirect_uri'  => $redirectUri,
        'grant_type'    => 'authorization_code',
    ]);

    try {
        $ch = curl_init($tokenUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $tokenResponse = curl_exec($ch);
        $tokenHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError     = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new Exception('Token exchange cURL error: ' . $curlError);
        }

        $tokenData = json_decode($tokenResponse, true);
        if ($tokenHttpCode !== 200 || empty($tokenData['access_token'])) {
            $errorMsg = $tokenData['error_description'] ?? $tokenData['error'] ?? 'Unknown token error (HTTP ' . $tokenHttpCode . ')';
            error_log("[OAuth] getAccessToken() failed: " . $errorMsg);
            die('Token-Fehler beim Austausch des Autorisierungscodes: ' . htmlspecialchars($errorMsg));
        }
    } catch (Exception $tokenEx) {
        $tokenError = $tokenEx->getMessage();
        error_log("[OAuth] getAccessToken() failed: " . $tokenError);
        die('Token-Fehler beim Austausch des Autorisierungscodes: ' . htmlspecialchars($tokenEx->getMessage()));
    }

    $accessTokenValue = $tokenData['access_token'];
    $idToken          = $tokenData['id_token'] ?? null;

    // Get resource owner (user) details and claims from Graph API
    try {
        $ch = curl_init('https://graph.microsoft.com/v1.0/me');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessTokenValue]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $profileResponse = curl_exec($ch);
        $profileHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $profileCurlErr  = curl_error($ch);
        curl_close($ch);

        if ($profileCurlErr) {
            throw new Exception('Profile fetch cURL error: ' . $profileCurlErr);
        }
        if ($profileHttpCode !== 200) {
            throw new Exception('Graph API returned HTTP ' . $profileHttpCode . ' when fetching user profile');
        }

        $claims = json_decode($profileResponse, true) ?: [];
    } catch (Exception $roEx) {
        error_log("[OAuth] getResourceOwner() failed: " . $roEx->getMessage());
        throw new Exception('Benutzerdetails konnten nicht von Microsoft abgerufen werden: ' . $roEx->getMessage());
    }

    // Extract JWT claims (Roles, OID, UPN) from the ID token
    if ($idToken) {
        $tokenParts = explode('.', $idToken);
        if (count($tokenParts) === 3) {
            // Decode the payload part of the JWT safely
            // Note: The id_token is received directly from Microsoft's token endpoint
            // over a server-side HTTPS connection, so it is implicitly trusted.
            $jwtPayload = base64_decode(str_replace(['-', '_'], ['+', '/'], $tokenParts[1]));
            if ($jwtPayload === false || $jwtPayload === '') {
                error_log('[OAuth] Failed to base64-decode the id_token payload.');
            } else {
                $jwtClaims = json_decode($jwtPayload, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log('[OAuth] Failed to JSON-decode the id_token payload: ' . json_last_error_msg());
                }
            }

            if (is_array($jwtClaims)) {
                // Merge the JWT claims (which contain 'roles', 'oid', 'preferred_username') into the existing claims
                $claims = array_merge($claims, $jwtClaims);
            }
        }
    }

    // Extract azure_oid from the oid or sub claim
    $azureOid  = $claims['oid'] ?? $claims['sub'] ?? null;
    // Prefer 'email' or 'mail' over 'userPrincipalName' – guests often have a
    // cryptic #EXT# address as their UPN while 'email'/'mail' holds the real one.
    $userEmail = $claims['email'] ?? $claims['mail'] ?? $claims['userPrincipalName'] ?? null;
    error_log(sprintf("[OAuth] Claims received. azure_oid: %s | email: %s", $azureOid ?? 'null', $userEmail ?? 'null'));

    // Look up user in local database by azure_oid
    $db = Database::getUserDB();
    $existingUser = null;
    if ($azureOid) {
        $stmt = $db->prepare("SELECT * FROM users WHERE azure_oid = ?");
        $stmt->execute([$azureOid]);
        $existingUser = $stmt->fetch() ?: null;
    }

    // Fallback for guest users: if not found by azure_oid, try matching via the best
    // available e-mail address (covers 'email', 'mail', and 'userPrincipalName' in that order)
    if (!$existingUser && !empty($userEmail)) {
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$userEmail]);
        $existingUser = $stmt->fetch() ?: null;

        // Immediately store the azure_oid so future logins are faster and unambiguous.
        // Update when azure_oid is still NULL or when it differs from the current Microsoft OID.
        if ($existingUser && $azureOid) {
            $updateStmt = $db->prepare("UPDATE users SET azure_oid = ? WHERE id = ? AND (azure_oid IS NULL OR azure_oid != ?)");
            $updateStmt->execute([$azureOid, $existingUser['id'], $azureOid]);
            error_log(sprintf("[OAuth] Stored azure_oid %s for user id %d (matched via email claim)", $azureOid, $existingUser['id']));
        }
    }

    // Sync Entra data (displayName, mail, group memberships, role) on every login.
    // Called here for existing users; new users are synced inside completeMicrosoftLogin
    // after their record is created.
    if ($existingUser && $azureOid) {
        AuthHandler::syncEntraData($existingUser['id'], $claims, $azureOid, $accessTokenValue);
    }

    // Complete the login process (role mapping, user create/update, session setup)
    // Note: Entra profile photo sync for existing users is handled inside syncEntraData() above.
    // New users receive their photo via completeMicrosoftLogin() after their record is created.
    AuthHandler::completeMicrosoftLogin($claims, $existingUser, $accessTokenValue);

} catch (Exception $e) {
    // Log full diagnostic details server-side (visible in IONOS server logs)
    error_log(sprintf(
        "[OAuth Callback] Authentifizierung fehlgeschlagen: Fehler: %s | State (GET) gesetzt: %s | State (SESSION) gesetzt: %s | States identisch: %s | Token-Fehler: %s | azure_oid: %s | E-Mail: %s",
        $e->getMessage(),
        $stateGet     !== null ? 'ja' : 'nein',
        $stateSession !== null ? 'ja' : 'nein',
        $stateMatch ? 'ja' : 'nein',
        $tokenError   ?? 'keiner',
        $azureOid     ?? 'nicht verfügbar',
        $userEmail    ?? 'nicht verfügbar'
    ));
    error_log("[OAuth Callback] Stack Trace: " . $e->getTraceAsString());

    // Redirect to login page with a generic user-facing message (details are in server logs)
    $loginUrl = (defined('BASE_URL') && BASE_URL) ? BASE_URL . '/login' : '/login';
    $errorMessage = urlencode('Authentifizierung fehlgeschlagen. Bitte versuchen Sie es erneut.');
    header('Location: ' . $loginUrl . '?error=' . $errorMessage);
    exit;
}
