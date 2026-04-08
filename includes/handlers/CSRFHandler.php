<?php
/**
 * CSRF Handler
 * Manages CSRF token generation and verification for form security
 * Enhanced with token rotation, expiry, and rate limiting
 */

class CSRFHandler {
    
    // Token expiry time in seconds (30 minutes)
    const TOKEN_EXPIRY = 1800;
    
    // Maximum number of tokens per session
    const MAX_TOKENS = 5;
    
    /**
     * Generate and return CSRF token
     * If token doesn't exist in session, creates a new one
     * Implements token rotation for enhanced security
     * 
     * @return string The CSRF token
     */
    public static function getToken() {
        // Ensure session is started with secure parameters
        init_session();
        
        // Initialize token storage if it doesn't exist
        if (!isset($_SESSION['csrf_tokens'])) {
            $_SESSION['csrf_tokens'] = [];
        }
        
        // Clean up expired tokens
        self::cleanExpiredTokens();
        
        // Check if we need to rotate token (old token expired or doesn't exist)
        $needNewToken = true;
        if (isset($_SESSION['csrf_token']) && isset($_SESSION['csrf_token_time'])) {
            $tokenAge = time() - $_SESSION['csrf_token_time'];
            if ($tokenAge < self::TOKEN_EXPIRY) {
                $needNewToken = false;
            }
        }
        
        // Generate new token if needed
        if ($needNewToken) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['csrf_token'] = $token;
            $_SESSION['csrf_token_time'] = time();
            
            // Store in token history for validation
            $_SESSION['csrf_tokens'][$token] = time();
            
            // Limit number of stored tokens
            if (count($_SESSION['csrf_tokens']) > self::MAX_TOKENS) {
                // Remove oldest token
                $oldestToken = array_key_first($_SESSION['csrf_tokens']);
                unset($_SESSION['csrf_tokens'][$oldestToken]);
            }
        }
        
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Clean up expired tokens from session
     */
    private static function cleanExpiredTokens() {
        if (isset($_SESSION['csrf_tokens']) && is_array($_SESSION['csrf_tokens'])) {
            $currentTime = time();
            foreach ($_SESSION['csrf_tokens'] as $token => $timestamp) {
                if ($currentTime - $timestamp > self::TOKEN_EXPIRY) {
                    unset($_SESSION['csrf_tokens'][$token]);
                }
            }
        }
    }
    
    /**
     * Verify CSRF token
     * Compares provided token with session token or recent valid tokens
     * Dies with error message if verification fails
     * 
     * @param string $token The token to verify
     * @return void
     */
    public static function verifyToken($token) {
        // Ensure session is started with secure parameters
        init_session();
        
        // Clean expired tokens first
        self::cleanExpiredTokens();
        
        // Check if token is empty
        if (empty($token)) {
            self::logCSRFFailure('Empty token');
            die('CSRF validation failed: Invalid token');
        }
        
        // Check if current token matches
        if (isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token)) {
            // Valid current token
            return;
        }
        
        // Check if token exists in valid token history
        if (isset($_SESSION['csrf_tokens']) && is_array($_SESSION['csrf_tokens'])) {
            foreach ($_SESSION['csrf_tokens'] as $validToken => $timestamp) {
                if (hash_equals($validToken, $token)) {
                    // Token found in history and still valid
                    return;
                }
            }
        }
        
        // Token not found or invalid
        self::logCSRFFailure('Token mismatch');
        die('CSRF validation failed: Invalid or expired token');
    }
    
    /**
     * Log CSRF validation failures for security monitoring
     * Sanitizes all inputs to prevent log injection attacks
     * 
     * @param string $reason Reason for CSRF failure
     */
    private static function logCSRFFailure($reason) {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $requestUri = $_SERVER['REQUEST_URI'] ?? 'unknown';
        
        // Sanitize inputs to prevent log injection
        $reason = str_replace(["\r", "\n", "\t"], '', $reason);
        $ipAddress = str_replace(["\r", "\n", "\t"], '', $ipAddress);
        $userAgent = str_replace(["\r", "\n", "\t"], '', substr($userAgent, 0, 200)); // Limit length
        $requestUri = str_replace(["\r", "\n", "\t"], '', substr($requestUri, 0, 200)); // Limit length
        
        error_log(sprintf(
            'CSRF validation failed: %s - IP: %s - User Agent: %s - URI: %s',
            $reason,
            $ipAddress,
            $userAgent,
            $requestUri
        ));
    }
    
    /**
     * Generate hidden input field with CSRF token
     * Convenience method for forms
     * 
     * @return string HTML input field
     */
    public static function getTokenField() {
        $token = self::getToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
    
    /**
     * Get token for AJAX requests
     * Returns JSON-safe token data
     * 
     * @return array Token data
     */
    public static function getTokenForAjax() {
        return [
            'token' => self::getToken(),
            'expires_in' => self::TOKEN_EXPIRY
        ];
    }
}
