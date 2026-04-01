<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Validator;

#[CoversClass(Validator::class)]
class ValidatorTest extends TestCase
{
    // ------------------------------------------------------------------
    // Required field
    // ------------------------------------------------------------------

    public function testRequiredPassesWhenValuePresent(): void
    {
        $v = Validator::make(['name' => 'Alice'], ['name' => 'required']);
        self::assertTrue($v->passes());
    }

    public function testRequiredFailsWhenValueEmpty(): void
    {
        $v = Validator::make(['name' => ''], ['name' => 'required']);
        self::assertTrue($v->fails());
        self::assertArrayHasKey('name', $v->errors());
    }

    public function testRequiredFailsWhenKeyMissing(): void
    {
        $v = Validator::make([], ['name' => 'required']);
        self::assertTrue($v->fails());
    }

    // ------------------------------------------------------------------
    // Email
    // ------------------------------------------------------------------

    public function testEmailPassesForValidAddress(): void
    {
        $v = Validator::make(['email' => 'user@example.com'], ['email' => 'required|email']);
        self::assertTrue($v->passes());
    }

    /** @return array<string,array{string}> */
    public static function invalidEmailProvider(): array
    {
        return [
            'no at sign'        => ['notanemail'],
            'double at'         => ['a@@b.de'],
            'missing domain'    => ['user@'],
            'spaces'            => ['user @example.com'],
        ];
    }

    #[DataProvider('invalidEmailProvider')]
    public function testEmailFailsForInvalidAddresses(string $email): void
    {
        $v = Validator::make(['email' => $email], ['email' => 'email']);
        self::assertTrue($v->fails(), "Expected validation to fail for: $email");
    }

    // ------------------------------------------------------------------
    // Numeric
    // ------------------------------------------------------------------

    public function testNumericPassesForIntegerString(): void
    {
        $v = Validator::make(['amount' => '42'], ['amount' => 'numeric']);
        self::assertTrue($v->passes());
    }

    public function testNumericPassesForDecimalString(): void
    {
        $v = Validator::make(['amount' => '19.99'], ['amount' => 'numeric']);
        self::assertTrue($v->passes());
    }

    public function testNumericFailsForNonNumericString(): void
    {
        $v = Validator::make(['amount' => 'abc'], ['amount' => 'numeric']);
        self::assertTrue($v->fails());
    }

    // ------------------------------------------------------------------
    // Min / Max (string length)
    // ------------------------------------------------------------------

    public function testMinStringPassesWhenSufficient(): void
    {
        $v = Validator::make(['pw' => 'password'], ['pw' => 'min:6']);
        self::assertTrue($v->passes());
    }

    public function testMinStringFailsWhenTooShort(): void
    {
        $v = Validator::make(['pw' => 'hi'], ['pw' => 'min:6']);
        self::assertTrue($v->fails());
    }

    public function testMaxStringFailsWhenTooLong(): void
    {
        $v = Validator::make(['note' => str_repeat('a', 256)], ['note' => 'max:255']);
        self::assertTrue($v->fails());
    }

    // ------------------------------------------------------------------
    // Min / Max (numeric value)
    // ------------------------------------------------------------------

    public function testMinNumericPassesWhenAboveThreshold(): void
    {
        $v = Validator::make(['amount' => '0.01'], ['amount' => 'numeric|min:0.01']);
        self::assertTrue($v->passes());
    }

    public function testMinNumericFailsWhenBelowThreshold(): void
    {
        $v = Validator::make(['amount' => '0'], ['amount' => 'numeric|min:0.01']);
        self::assertTrue($v->fails());
    }

    // ------------------------------------------------------------------
    // In / Not in
    // ------------------------------------------------------------------

    public function testInPassesWhenValueAllowed(): void
    {
        $v = Validator::make(['role' => 'admin'], ['role' => 'in:admin,user,guest']);
        self::assertTrue($v->passes());
    }

    public function testInFailsWhenValueNotAllowed(): void
    {
        $v = Validator::make(['role' => 'superadmin'], ['role' => 'in:admin,user,guest']);
        self::assertTrue($v->fails());
    }

    // ------------------------------------------------------------------
    // Nullable
    // ------------------------------------------------------------------

    public function testNullableAllowsNull(): void
    {
        $v = Validator::make(['note' => null], ['note' => 'nullable|string']);
        self::assertTrue($v->passes());
    }

    public function testNullableAllowsEmptyString(): void
    {
        $v = Validator::make(['note' => ''], ['note' => 'nullable|string']);
        self::assertTrue($v->passes());
    }

    // ------------------------------------------------------------------
    // Date
    // ------------------------------------------------------------------

    public function testDatePassesForValidDateString(): void
    {
        $v = Validator::make(['date' => '2025-12-31'], ['date' => 'date']);
        self::assertTrue($v->passes());
    }

    public function testDateFailsForInvalidString(): void
    {
        $v = Validator::make(['date' => 'not-a-date'], ['date' => 'date']);
        self::assertTrue($v->fails());
    }

    // ------------------------------------------------------------------
    // Multiple rules – stops at first failure per field
    // ------------------------------------------------------------------

    public function testOnlyFirstErrorIsReturnedPerField(): void
    {
        $v = Validator::make([], ['email' => 'required|email']);
        self::assertCount(1, $v->errors());
        self::assertStringContainsString('erforderlich', (string) ($v->errors()['email'] ?? ''));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public function testGetReturnsValue(): void
    {
        $v = Validator::make(['key' => 'val'], ['key' => 'required']);
        self::assertSame('val', $v->get('key'));
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $v = Validator::make([], ['key' => 'nullable']);
        self::assertSame('default', $v->get('key', 'default'));
    }

    public function testOnlyReturnsSubset(): void
    {
        $v = Validator::make(['a' => '1', 'b' => '2', 'c' => '3'], ['a' => 'required']);
        self::assertSame(['a' => '1', 'b' => '2'], $v->only(['a', 'b']));
    }
}
