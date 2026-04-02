<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Handlers\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Handlers\RateLimiter
 */
final class RateLimiterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        // Use a unique temporary directory per test so tests are isolated
        $this->tmpDir = sys_get_temp_dir() . '/rl_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0750, true);
    }

    protected function tearDown(): void
    {
        // Remove all test cache files
        foreach (glob($this->tmpDir . '/*.json') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    private function limiter(int $max = 3, int $window = 60): RateLimiter
    {
        return new RateLimiter('test', $max, $window, $this->tmpDir);
    }

    // ------------------------------------------------------------------

    public function testFreshLimiterAllowsRequests(): void
    {
        $limiter = $this->limiter();
        $this->assertFalse($limiter->tooManyAttempts());
    }

    public function testHitIncrementsAttemptCount(): void
    {
        $limiter = $this->limiter();
        $limiter->hit();
        $limiter->hit();
        $this->assertSame(2, $limiter->getAttempts());
    }

    public function testTooManyAttemptsReturnsTrueAfterMaxReached(): void
    {
        $limiter = $this->limiter(max: 3);
        $limiter->hit();
        $limiter->hit();
        $limiter->hit();
        $this->assertTrue($limiter->tooManyAttempts());
    }

    public function testRemainingAttemptsDecreasesWithHits(): void
    {
        $limiter = $this->limiter(max: 5);
        $this->assertSame(5, $limiter->remainingAttempts());
        $limiter->hit();
        $this->assertSame(4, $limiter->remainingAttempts());
    }

    public function testRemainingAttemptsDoesNotGoBelowZero(): void
    {
        $limiter = $this->limiter(max: 2);
        $limiter->hit();
        $limiter->hit();
        $limiter->hit(); // exceeds limit
        $this->assertSame(0, $limiter->remainingAttempts());
    }

    public function testClearResetsAttemptCount(): void
    {
        $limiter = $this->limiter(max: 3);
        $limiter->hit();
        $limiter->hit();
        $limiter->clear();
        $this->assertSame(0, $limiter->getAttempts());
        $this->assertFalse($limiter->tooManyAttempts());
    }

    public function testAvailableInReturnsZeroForFreshLimiter(): void
    {
        $this->assertSame(0, $this->limiter()->availableIn());
    }

    public function testAvailableInIsPositiveAfterHit(): void
    {
        $limiter = $this->limiter(window: 60);
        $limiter->hit();
        $this->assertGreaterThan(0, $limiter->availableIn());
    }

    public function testExpiredAttemptsArePruned(): void
    {
        // Very short window so attempts expire quickly
        $limiter = new RateLimiter('test', 10, 1, $this->tmpDir);
        $limiter->hit();
        $this->assertSame(1, $limiter->getAttempts());

        // Wait for the window to expire
        sleep(2);

        // Record a new hit; the old entry must be pruned
        $limiter->hit();
        $this->assertSame(1, $limiter->getAttempts());
    }

    public function testMultipleNamespacesAreIsolated(): void
    {
        $limiterA = new RateLimiter('action_a', 3, 60, $this->tmpDir);
        $limiterB = new RateLimiter('action_b', 3, 60, $this->tmpDir);

        $limiterA->hit();
        $limiterA->hit();
        $limiterA->hit();

        $this->assertTrue($limiterA->tooManyAttempts());
        $this->assertFalse($limiterB->tooManyAttempts());
    }
}
