<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Handlers\CSRFHandler
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
        unset($_SESSION['csrf_tokens'], $_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['csrf_tokens'], $_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
    }

    // ------------------------------------------------------------------

    public function testGetTokenReturnsNonEmptyString(): void
    {
        $token = \App\Handlers\CSRFHandler::getToken();
        $this->assertNotEmpty($token);
        $this->assertIsString($token);
    }

    public function testTokenIsConsistentWithinSameRequest(): void
    {
        $token1 = \App\Handlers\CSRFHandler::getToken();
        $token2 = \App\Handlers\CSRFHandler::getToken();
        $this->assertSame($token1, $token2);
    }

    public function testValidateReturnsTrueForValidToken(): void
    {
        $token = \App\Handlers\CSRFHandler::getToken();
        $this->assertTrue(\App\Handlers\CSRFHandler::validate($token));
    }

    public function testValidateReturnsFalseForInvalidToken(): void
    {
        \App\Handlers\CSRFHandler::getToken(); // generate a legitimate token first
        $this->assertFalse(\App\Handlers\CSRFHandler::validate('invalid-token'));
    }

    public function testValidateReturnsFalseForEmptyToken(): void
    {
        \App\Handlers\CSRFHandler::getToken();
        $this->assertFalse(\App\Handlers\CSRFHandler::validate(''));
    }

    public function testGetTokenFieldReturnsHiddenInput(): void
    {
        $html = \App\Handlers\CSRFHandler::getTokenField();
        $this->assertStringContainsString('<input', $html);
        $this->assertStringContainsString('type="hidden"', $html);
        $this->assertStringContainsString('csrf_token', $html);
    }

    public function testGetTokenFieldContainsCurrentToken(): void
    {
        $token = \App\Handlers\CSRFHandler::getToken();
        $html  = \App\Handlers\CSRFHandler::getTokenField();
        $this->assertStringContainsString(htmlspecialchars($token), $html);
    }

    public function testValidateReturnsTrueForHistoricToken(): void
    {
        // Store a token in the history manually
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_tokens'][$token] = time();
        $this->assertTrue(\App\Handlers\CSRFHandler::validate($token));
    }
}
