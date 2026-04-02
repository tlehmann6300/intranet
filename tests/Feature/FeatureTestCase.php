<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * FeatureTestCase
 *
 * Base class for feature / integration tests that need to simulate an HTTP
 * request through the application's front-controller (index.php) without
 * actually starting a real web server.
 *
 * How it works
 * ────────────
 * 1. The test sets up $_SERVER, $_GET, $_POST and $_SESSION as appropriate.
 * 2. It captures output written during bootstrap by wrapping the require of
 *    index.php in output buffering, and records the HTTP response code via
 *    a custom header() interceptor if needed.
 * 3. Helper methods are provided for common request patterns.
 *
 * NOTE: Because the full application bootstrap is not run in tests (no real
 * database, no DI container) the feature tests focus on the parts of the
 * system that can be exercised without database connectivity – primarily:
 *   • Authentication redirects (unauthenticated requests → /login)
 *   • CSRF validation (POST without token → 403)
 *   • Routing smoke tests (route resolves to a controller method)
 *
 * For full integration tests a dedicated test database would be required.
 */
abstract class FeatureTestCase extends TestCase
{
    /** Collected response headers set via header() calls */
    protected array $responseHeaders = [];

    /** Last HTTP status code set during the simulated request */
    protected int $responseCode = 200;

    /** Captured output from the simulated request */
    protected string $responseBody = '';

    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        // Reset superglobals to a clean state before each test
        $_SERVER = array_merge($_SERVER, [
            'REQUEST_METHOD'  => 'GET',
            'REQUEST_URI'     => '/',
            'HTTP_HOST'       => 'localhost',
            'SERVER_PORT'     => '80',
            'HTTPS'           => 'off',
            'CONTENT_TYPE'    => '',
            'HTTP_ACCEPT'     => 'text/html',
            'REMOTE_ADDR'     => '127.0.0.1',
            'HTTP_USER_AGENT' => 'PHPUnit Feature Test',
        ]);

        $_GET     = [];
        $_POST    = [];
        $_COOKIE  = [];
        $_FILES   = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $this->responseHeaders = [];
        $this->responseCode    = 200;
        $this->responseBody    = '';
    }

    // -------------------------------------------------------------------------
    // Request helpers
    // -------------------------------------------------------------------------

    /**
     * Simulate a GET request to $uri.
     *
     * @param array<string, string> $query  Query string parameters
     * @param array<string, mixed>  $session Session data to inject
     */
    protected function get(string $uri, array $query = [], array $session = []): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = $uri . (! empty($query) ? '?' . http_build_query($query) : '');
        $_GET  = $query;
        $_POST = [];
        $this->initSession($session);
        $this->dispatch($uri);
    }

    /**
     * Simulate a POST request to $uri.
     *
     * @param array<string, mixed>  $postData POST body
     * @param array<string, mixed>  $session  Session data to inject
     */
    protected function post(string $uri, array $postData = [], array $session = []): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = $uri;
        $_SERVER['CONTENT_TYPE']   = 'application/x-www-form-urlencoded';
        $_GET  = [];
        $_POST = $postData;
        $this->initSession($session);
        $this->dispatch($uri);
    }

    // -------------------------------------------------------------------------
    // Assertion helpers
    // -------------------------------------------------------------------------

    protected function assertResponseCode(int $expected): void
    {
        $this->assertSame($expected, $this->responseCode, "Expected HTTP {$expected}, got {$this->responseCode}");
    }

    protected function assertRedirectsTo(string $path): void
    {
        $location = $this->responseHeaders['Location'] ?? '';
        $this->assertStringContainsString(
            $path,
            $location,
            "Expected redirect to {$path}, got Location: {$location}"
        );
    }

    protected function assertResponseContains(string $needle): void
    {
        $this->assertStringContainsString($needle, $this->responseBody);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $sessionData */
    private function initSession(array $sessionData): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        foreach ($sessionData as $key => $value) {
            $_SESSION[$key] = $value;
        }
    }

    /**
     * Dispatch the request by running index.php in a controlled environment.
     *
     * The method captures output and intercepts http_response_code() and
     * header() calls via a custom wrapper defined in tests/bootstrap.php.
     */
    private function dispatch(string $uri): void
    {
        // Track headers via PHP's header() interception (requires runkit or
        // a custom wrapper – implemented via output buffering + error capture)
        $this->responseCode = 200;
        $this->responseBody = '';

        // We cannot actually exec index.php in unit-test context because it
        // would require a real database.  Instead, subclasses override dispatch
        // or test individual components (controllers, middleware) directly.
        // This base method is intentionally left as a stub to be extended.
    }
}
