<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Handlers\CSRFHandler;

/**
 * CsrfMiddleware
 *
 * Automatically validates the CSRF token for every state-changing HTTP request
 * (POST, PUT, PATCH, DELETE).  GET and HEAD requests are passed through without
 * any CSRF check.
 *
 * The token is read from the following locations in priority order:
 *   1. JSON body field  "csrf_token"
 *   2. Form POST field  "csrf_token"
 *   3. Request header   "X-CSRF-Token"
 *
 * On failure the middleware short-circuits with a 403 response (JSON for
 * API-style requests, plain text otherwise) and does NOT call $next.
 *
 * Registration in routes/web.php:
 *   use App\Middleware\CsrfMiddleware;
 *   $r->addRoute('POST', '/some/path', ['Controller@method', [AuthMiddleware::class, CsrfMiddleware::class]]);
 *
 * Or inject globally for all POST/PUT/DELETE in index.php (see index.php).
 */
class CsrfMiddleware implements MiddlewareInterface
{
    /** HTTP methods that require a valid CSRF token */
    private const PROTECTED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(string $method, string $uri, callable $next): void
    {
        if (! in_array(strtoupper($method), self::PROTECTED_METHODS, true)) {
            $next($method, $uri);
            return;
        }

        $token = $this->resolveToken();

        if (! CSRFHandler::validate($token)) {
            $this->reject();
        }

        $next($method, $uri);
    }

    // -------------------------------------------------------------------------

    /**
     * Extract the CSRF token from the request in the preferred priority order.
     */
    private function resolveToken(): string
    {
        // 1. JSON body
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = (string) file_get_contents('php://input');
            $body = json_decode($raw, true);
            if (is_array($body) && isset($body['csrf_token']) && is_string($body['csrf_token'])) {
                return $body['csrf_token'];
            }
        }

        // 2. Form POST field
        if (isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])) {
            return $_POST['csrf_token'];
        }

        // 3. Custom request header (useful for SPA / fetch()-based requests)
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($header !== '') {
            return $header;
        }

        return '';
    }

    /**
     * Short-circuit with a 403 Forbidden response.
     */
    private function reject(): never
    {
        http_response_code(403);

        $acceptsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

        if ($acceptsJson) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'CSRF-Token ungültig oder abgelaufen.']);
        } else {
            echo 'CSRF-Validierung fehlgeschlagen. Bitte lade die Seite neu und versuche es erneut.';
        }

        exit;
    }
}
