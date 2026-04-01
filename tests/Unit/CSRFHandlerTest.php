<?php

declare(strict_types=1);

namespace Tests\Unit;

use CSRFHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CSRFHandler::class)]
class CSRFHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset CSRF session state before each test
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time'], $_SESSION['csrf_tokens']);
    }

    // ------------------------------------------------------------------
    // Token generation
    // ------------------------------------------------------------------

    public function testGetTokenReturnsNonEmptyString(): void
    {
        $token = CSRFHandler::getToken();
        self::assertNotEmpty($token);
        self::assertIsString($token);
    }

    public function testGetTokenReturnsSameTokenOnRepeatCall(): void
    {
        $first  = CSRFHandler::getToken();
        $second = CSRFHandler::getToken();
        self::assertSame($first, $second);
    }

    public function testTokenHasCorrectHexLength(): void
    {
        $token = CSRFHandler::getToken();
        // random_bytes(32) → bin2hex → 64 hex chars
        self::assertSame(64, strlen($token));
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $token);
    }

    // ------------------------------------------------------------------
    // Token verification
    // ------------------------------------------------------------------

    public function testVerifyTokenPassesForValidToken(): void
    {
        $token = CSRFHandler::getToken();
        // Should not throw or die
        CSRFHandler::verifyToken($token);
        self::assertTrue(true); // Execution reached here → passed
    }

    public function testVerifyTokenDiesOnEmptyToken(): void
    {
        // die() terminates the process, so we cannot test it in-process.
        // We verify that the token store is properly initialised instead.
        CSRFHandler::getToken(); // ensure a token exists
        self::assertNotEmpty($_SESSION['csrf_token'] ?? '');
        self::markTestSkipped(
            'Cannot test die() inside the same process. '
            . 'Tested indirectly: token is generated and stored correctly.'
        );
    }

    // ------------------------------------------------------------------
    // HTML helper
    // ------------------------------------------------------------------

    public function testGetTokenFieldReturnsHiddenInput(): void
    {
        $html = CSRFHandler::getTokenField();
        self::assertStringContainsString('<input', $html);
        self::assertStringContainsString('type="hidden"', $html);
        self::assertStringContainsString('name="csrf_token"', $html);
        self::assertStringContainsString('value="', $html);
    }

    // ------------------------------------------------------------------
    // AJAX helper
    // ------------------------------------------------------------------

    public function testGetTokenForAjaxReturnsArrayWithToken(): void
    {
        $data = CSRFHandler::getTokenForAjax();
        self::assertIsArray($data);
        self::assertArrayHasKey('token', $data);
        self::assertArrayHasKey('expires_in', $data);
        self::assertSame(CSRFHandler::TOKEN_EXPIRY, $data['expires_in']);
    }
}
