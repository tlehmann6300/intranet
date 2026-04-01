<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RateLimiter;

#[CoversClass(RateLimiter::class)]
class RateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear any leftover rate-limit session data before each test
        foreach (array_keys($_SESSION) as $key) {
            if (str_starts_with((string) $key, '_rl_')) {
                unset($_SESSION[$key]);
            }
        }
    }

    // ------------------------------------------------------------------
    // Basic hit / attempts
    // ------------------------------------------------------------------

    public function testFreshLimiterHasZeroAttempts(): void
    {
        $limiter = new RateLimiter('test_zero', 5, 60);
        self::assertSame(0, $limiter->attempts());
    }

    public function testHitIncrementsAttemptCount(): void
    {
        $limiter = new RateLimiter('test_hit', 5, 60);
        $limiter->hit();
        $limiter->hit();
        self::assertSame(2, $limiter->attempts());
    }

    public function testRemainingDecreasesWithEachHit(): void
    {
        $limiter = new RateLimiter('test_remaining', 5, 60);
        self::assertSame(5, $limiter->remaining());

        $limiter->hit();
        self::assertSame(4, $limiter->remaining());
    }

    // ------------------------------------------------------------------
    // Throttle detection
    // ------------------------------------------------------------------

    public function testNotThrottledBelowMaxAttempts(): void
    {
        $limiter = new RateLimiter('test_throttle_below', 3, 60);
        $limiter->hit();
        $limiter->hit();
        self::assertFalse($limiter->tooManyAttempts());
    }

    public function testThrottledAtMaxAttempts(): void
    {
        $limiter = new RateLimiter('test_throttle_at', 3, 60);
        $limiter->hit();
        $limiter->hit();
        $limiter->hit(); // 3rd hit reaches limit
        self::assertTrue($limiter->tooManyAttempts());
    }

    public function testThrottledAboveMaxAttempts(): void
    {
        $limiter = new RateLimiter('test_throttle_above', 2, 60);
        $limiter->hit();
        $limiter->hit();
        $limiter->hit(); // over limit
        self::assertTrue($limiter->tooManyAttempts());
    }

    // ------------------------------------------------------------------
    // Clear
    // ------------------------------------------------------------------

    public function testClearResetsAttempts(): void
    {
        $limiter = new RateLimiter('test_clear', 3, 60);
        $limiter->hit();
        $limiter->hit();
        $limiter->clear();
        self::assertSame(0, $limiter->attempts());
        self::assertFalse($limiter->tooManyAttempts());
    }

    // ------------------------------------------------------------------
    // Decay / expiry
    // ------------------------------------------------------------------

    public function testExpiredWindowResetsAutomatically(): void
    {
        $limiter = new RateLimiter('test_expiry', 2, 1); // 1-second window
        $limiter->hit();
        $limiter->hit();
        self::assertTrue($limiter->tooManyAttempts());

        // Fake expiry by manipulating session timestamp
        $sessionKey = '_rl_test_expiry';
        $_SESSION[$sessionKey]['first_attempt'] = time() - 2; // 2 seconds ago

        self::assertFalse($limiter->tooManyAttempts());
        self::assertSame(0, $limiter->attempts());
    }

    public function testAvailableInReturnsZeroWhenNotThrottled(): void
    {
        $limiter = new RateLimiter('test_available', 5, 60);
        self::assertSame(0, $limiter->availableIn());
    }

    public function testAvailableInReturnsPositiveWhenThrottled(): void
    {
        $limiter = new RateLimiter('test_available_throttled', 1, 60);
        $limiter->hit();
        self::assertGreaterThan(0, $limiter->availableIn());
    }
}
