<?php
/**
 * API Middleware
 *
 * Provides a secure, standardised bootstrap for all JSON API endpoints.
 *
 * Usage:
 *   // POST endpoint with CSRF check (default):
 *   $user = ApiMiddleware::requireAuth();
 *
 *   // GET endpoint without CSRF check:
 *   $user = ApiMiddleware::requireAuth('GET', false);
 *
 *   // Terminate early with a JSON error response:
 *   ApiMiddleware::error(400, 'Ungültige Eingabe');
 *
 * Guarantees enforced before returning:
 *   1. Content-Type is set to application/json.
 *   2. A valid session is running.
 *   3. The caller is authenticated (→ 401 otherwise).
 *   4. The HTTP method matches the expected method (→ 405 otherwise).
 *   5. The CSRF token is valid (→ 403 otherwise).
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/CSRFHandler.php';

class ApiMiddleware
{
    /**
     * Initialise a JSON API request.
     *
     * @param  string $method      Expected HTTP method (default: 'POST').
     * @param  bool   $verifyCsrf  Whether to validate the CSRF token (default: true).
     * @return array               The authenticated user array from Auth::user().
     */
    public static function requireAuth(string $method = 'POST', bool $verifyCsrf = true): array
    {
        // 1. Every API response is JSON – set the header unconditionally and first.
        header('Content-Type: application/json; charset=utf-8');

        // 2. Ensure a secure session is running.
        if (session_status() === PHP_SESSION_NONE) {
            init_session();
        }

        // 3. Authentication check.
        if (!Auth::check()) {
            self::error(401, 'Nicht authentifiziert');
        }

        // 4. HTTP method check.
        if ($_SERVER['REQUEST_METHOD'] !== strtoupper($method)) {
            header('Allow: ' . strtoupper($method));
            self::error(405, 'Methode nicht erlaubt');
        }

        // 5. CSRF verification.
        if ($verifyCsrf) {
            try {
                CSRFHandler::verifyTokenOrThrow(self::extractCsrfToken());
            } catch (RuntimeException $e) {
                self::error(403, 'CSRF-Validierung fehlgeschlagen');
            }
        }

        // Auth::user() re-validates the session internally – returning directly is safe
        // because Auth::check() above already confirmed an authenticated session.
        return Auth::user();  // @phpstan-ignore-line (non-null guaranteed by Auth::check())
    }

    /**
     * Terminate the request immediately with a JSON error response.
     *
     * @param int    $status  HTTP status code (e.g. 400, 401, 403, 500).
     * @param string $message Human-readable error message.
     * @return never
     */
    public static function error(int $status, string $message): never
    {
        http_response_code($status);
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }

    /**
     * Extract the CSRF token from the current request.
     * Supports both JSON body and form-encoded (multipart/POST) data.
     */
    private static function extractCsrfToken(): string
    {
        // Extract just the media-type before any "; charset=..." parameters.
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($contentType === 'application/json') {
            $body = json_decode(file_get_contents('php://input'), true);
            return is_array($body) ? ($body['csrf_token'] ?? '') : '';
        }
        return $_POST['csrf_token'] ?? '';
    }
}
