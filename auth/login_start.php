<?php
/**
 * Microsoft Login Start
 * Initiates the Microsoft Entra ID OAuth login flow
 */

// Load configuration and helpers first (no Composer required)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/handlers/AuthHandler.php';
require_once __DIR__ . '/../includes/handlers/RateLimiter.php';

// Start session
AuthHandler::startSession();

// Rate-limit: max 20 OAuth initiations per IP per 10 minutes to prevent abuse
$loginLimiter = new RateLimiter('oauth_login', maxAttempts: 20, windowSeconds: 600);
if ($loginLimiter->tooManyAttempts()) {
    $retryAfter = $loginLimiter->availableIn();
    http_response_code(429);
    header('Retry-After: ' . $retryAfter);
    $loginUrl = (defined('BASE_URL') && BASE_URL) ? BASE_URL . '/login' : '/login';
    $error    = urlencode('Zu viele Anmeldeversuche. Bitte warte ' . ceil($retryAfter / 60) . ' Minute(n) und versuche es erneut.');
    header('Location: ' . $loginUrl . '?error=' . $error);
    exit;
}
$loginLimiter->hit();

// Initiate Microsoft login
try {
    AuthHandler::initiateMicrosoftLogin();
} catch (Exception $e) {
    // Log the full error details server-side
    error_log("Microsoft login initiation error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());

    // Redirect to login page with a generic error message
    $loginUrl     = (defined('BASE_URL') && BASE_URL) ? BASE_URL . '/login' : '/login';
    $errorMessage = urlencode('Microsoft Login konnte nicht gestartet werden. Bitte kontaktieren Sie den Administrator.');
    header('Location: ' . $loginUrl . '?error=' . $errorMessage);
    exit;
}
