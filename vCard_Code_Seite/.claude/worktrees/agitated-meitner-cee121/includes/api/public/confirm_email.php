<?php
/**
 * Email Change Confirmation API (Public Gateway)
 * Validates a one-time token sent to the user's new e-mail address and updates
 * the user's e-mail in the database.
 *
 * SECURITY NOTICE: This script is intentionally placed in the public-API folder.
 * The confirmation link is sent to the new e-mail address; the user may not be
 * logged in at the time they click the link.  Security is provided by the
 * single-use, cryptographically random token stored in the database.
 * No sensitive data is returned – the endpoint only redirects to the settings page.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/User.php';

// Start session with secure parameters
init_session();

$error = '';
$success = '';

// Check if token is provided
if (!isset($_GET['token']) || empty($_GET['token'])) {
    $error = 'Ungültiger Bestätigungslink';
} else {
    $token = $_GET['token'];
    
    try {
        // Confirm email change
        if (User::confirmEmailChange($token)) {
            // Update session if this is the current user
            if (Auth::check()) {
                $user = Auth::user();
                // Reload user to get updated email
                $updatedUser = User::getById($user['id']);
                if ($updatedUser) {
                    $_SESSION['user_email'] = $updatedUser['email'];
                }
            }
            
            $success = 'E-Mail-Adresse erfolgreich aktualisiert';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Redirect to settings page with message using BASE_URL for security
$baseUrl = defined('BASE_URL') ? BASE_URL : '';
$redirectUrl = $baseUrl . '/pages/auth/settings.php';
if (!empty($success)) {
    $_SESSION['success_message'] = $success;
} elseif (!empty($error)) {
    $_SESSION['error_message'] = $error;
}

header('Location: ' . $redirectUrl);
exit;
