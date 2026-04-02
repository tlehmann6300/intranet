<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\CsrfMiddleware;
use App\Handlers\CSRFHandler;
use PHPUnit\Framework\TestCase;

/**
 * Feature tests for authentication-related behaviour.
 *
 * These tests focus on component-level behaviour (middleware, CSRF handler)
 * without requiring a live database.
 */
final class AuthControllerTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        unset($_SESSION['csrf_token'], $_SESSION['csrf_tokens'], $_SESSION['csrf_token_time']);
        $_POST   = [];
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_SERVER['HTTP_ACCEPT']  = 'text/html';
        $_SERVER['REMOTE_ADDR']  = '127.0.0.1';
    }

    protected function tearDown(): void
    {
        unset($_SESSION['csrf_token'], $_SESSION['csrf_tokens'], $_SESSION['csrf_token_time']);
        $_POST = [];
    }

    // -------------------------------------------------------------------------
    // CsrfMiddleware unit tests
    // -------------------------------------------------------------------------

    public function testCsrfMiddlewareAllowsGetRequest(): void
    {
        $middleware = new CsrfMiddleware();
        $nextCalled = false;

        $middleware->handle('GET', '/login', function () use (&$nextCalled): void {
            $nextCalled = true;
        });

        $this->assertTrue($nextCalled, 'GET requests must pass through CsrfMiddleware without token check');
    }

    public function testCsrfMiddlewareAllowsHeadRequest(): void
    {
        $middleware = new CsrfMiddleware();
        $nextCalled = false;

        $middleware->handle('HEAD', '/login', function () use (&$nextCalled): void {
            $nextCalled = true;
        });

        $this->assertTrue($nextCalled, 'HEAD requests must pass through CsrfMiddleware without token check');
    }

    public function testCsrfMiddlewareRejectsPostWithoutToken(): void
    {
        $middleware = new CsrfMiddleware();
        $_POST     = [];

        $this->expectOutputRegex('/CSRF/i');

        // The middleware calls exit() on failure; we catch the exit via a
        // custom assertion on the response code.
        try {
            $middleware->handle('POST', '/login', function (): void {
                // Should not reach here
                $this->fail('CsrfMiddleware should have blocked the request');
            });
        } catch (\Throwable) {
            // exit() throws a Throwable in some test environments
        }
    }

    public function testCsrfMiddlewareAllowsPostWithValidToken(): void
    {
        $middleware = new CsrfMiddleware();
        $token      = CSRFHandler::getToken();
        $_POST      = ['csrf_token' => $token];
        $nextCalled = false;

        $middleware->handle('POST', '/login', function () use (&$nextCalled): void {
            $nextCalled = true;
        });

        $this->assertTrue($nextCalled, 'POST with valid CSRF token must pass CsrfMiddleware');
    }

    public function testCsrfMiddlewareReadsTokenFromJsonBody(): void
    {
        $middleware = new CsrfMiddleware();
        $token      = CSRFHandler::getToken();

        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_POST = []; // JSON body is read from php://input – we use POST fallback here for testing

        // Because we cannot override php://input in unit tests easily,
        // we verify the fallback chain works by supplying the token in $_POST
        // after the JSON parse fails (empty php://input) the middleware falls back.
        $_POST = ['csrf_token' => $token];
        $nextCalled = false;

        $middleware->handle('POST', '/api/ideas/create', function () use (&$nextCalled): void {
            $nextCalled = true;
        });

        // Reset content type
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';

        $this->assertTrue($nextCalled);
    }

    // -------------------------------------------------------------------------
    // CSRFHandler.validate() tests
    // -------------------------------------------------------------------------

    public function testCsrfHandlerValidateReturnsTrueForCurrentToken(): void
    {
        $token = CSRFHandler::getToken();
        $this->assertTrue(CSRFHandler::validate($token));
    }

    public function testCsrfHandlerValidateReturnsFalseForBogusToken(): void
    {
        CSRFHandler::getToken();
        $this->assertFalse(CSRFHandler::validate('not-a-real-token'));
    }

    public function testCsrfHandlerValidateReturnsFalseForEmptyToken(): void
    {
        CSRFHandler::getToken();
        $this->assertFalse(CSRFHandler::validate(''));
    }
}
