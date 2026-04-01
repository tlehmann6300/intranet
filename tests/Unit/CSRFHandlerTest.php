<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

// Load the class under test
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';

/**
 * @covers \CSRFHandler
 */
final class CSRFHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        // Start an isolated session for each test
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        // Reset CSRF state between tests
        unset($_SESSION['csrf_tokens']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['csrf_tokens']);
    }

    // ------------------------------------------------------------------

    public function testGetTokenReturnsNonEmptyString(): void
    {
        $token = CSRFHandler::getToken();
        $this->assertNotEmpty($token);
        $this->assertIsString($token);
    }

    public function testTokenIsConsistentWithinSameRequest(): void
    {
        $token1 = CSRFHandler::getToken();
        $token2 = CSRFHandler::getToken();
        $this->assertSame($token1, $token2);
    }

    public function testValidTokenPasses(): void
    {
        $token = CSRFHandler::getToken();
        $this->assertTrue(CSRFHandler::verifyToken($token));
    }

    public function testInvalidTokenFails(): void
    {
        CSRFHandler::getToken(); // generate a legitimate token first
        $this->assertFalse(CSRFHandler::verifyToken('invalid-token'));
    }

    public function testEmptyTokenFails(): void
    {
        CSRFHandler::getToken();
        $this->assertFalse(CSRFHandler::verifyToken(''));
    }

    public function testGetTokenFieldReturnsHiddenInput(): void
    {
        $html = CSRFHandler::getTokenField();
        $this->assertStringContainsString('<input', $html);
        $this->assertStringContainsString('type="hidden"', $html);
        $this->assertStringContainsString('csrf_token', $html);
    }

    public function testGetTokenFieldContainsCurrentToken(): void
    {
        $token = CSRFHandler::getToken();
        $html  = CSRFHandler::getTokenField();
        $this->assertStringContainsString(htmlspecialchars($token), $html);
    }
}
