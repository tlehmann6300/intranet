<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\JobQueue;
use App\Jobs\JobInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the JobQueue service using an in-memory SQLite database.
 *
 * @covers \App\Services\JobQueue
 */
final class JobQueueTest extends TestCase
{
    private \PDO $db;
    private JobQueue $queue;
    private \Psr\Log\NullLogger $logger;

    protected function setUp(): void
    {
        // In-memory SQLite – no external DB required
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        // Create the jobs table (mirrors the Phinx migration schema)
        $this->db->exec("
            CREATE TABLE jobs (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                queue        TEXT    NOT NULL DEFAULT 'default',
                job_class    TEXT    NOT NULL,
                payload      TEXT    NOT NULL,
                attempts     INTEGER NOT NULL DEFAULT 0,
                max_attempts INTEGER NOT NULL DEFAULT 3,
                reserved_at  TEXT,
                available_at TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                failed_at    TEXT,
                error        TEXT,
                created_at   TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->logger = new \Psr\Log\NullLogger();
        $this->queue  = new JobQueue($this->db, $this->logger);
    }

    // ------------------------------------------------------------------
    // Dispatch
    // ------------------------------------------------------------------

    public function testDispatchInsertsJobRow(): void
    {
        $id = $this->queue->dispatch(TestSuccessJob::class, ['foo' => 'bar']);

        $this->assertGreaterThan(0, $id);

        $row = $this->db->query("SELECT * FROM jobs WHERE id = {$id}")->fetch();
        $this->assertNotFalse($row);
        $this->assertSame('default', $row['queue']);
        $this->assertSame(TestSuccessJob::class, $row['job_class']);
        $this->assertSame('{"foo":"bar"}', $row['payload']);
        $this->assertSame('0', (string) $row['attempts']);
        $this->assertNull($row['reserved_at']);
        $this->assertNull($row['failed_at']);
    }

    public function testDispatchRespectsCustomQueue(): void
    {
        $this->queue->dispatch(TestSuccessJob::class, [], queue: 'mail');
        $row = $this->db->query("SELECT queue FROM jobs LIMIT 1")->fetch();
        $this->assertSame('mail', $row['queue']);
    }

    public function testDispatchWithFutureAvailableAt(): void
    {
        $future = new \DateTimeImmutable('+1 hour');
        $this->queue->dispatch(TestSuccessJob::class, [], availableAt: $future);

        // The job should not be picked up yet
        $processed = $this->queue->process('default', 10);
        $this->assertSame(0, $processed);
    }

    // ------------------------------------------------------------------
    // Process – success path
    // ------------------------------------------------------------------

    public function testProcessRunsJobAndDeletesRow(): void
    {
        $this->queue->dispatch(TestSuccessJob::class, ['value' => 'hello']);

        $processed = $this->queue->process('default', 1);

        $this->assertSame(1, $processed);

        // Row must be removed after successful execution
        $count = (int) $this->db->query("SELECT COUNT(*) FROM jobs")->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testProcessExecutesMultipleJobs(): void
    {
        $this->queue->dispatch(TestSuccessJob::class, ['n' => 1]);
        $this->queue->dispatch(TestSuccessJob::class, ['n' => 2]);
        $this->queue->dispatch(TestSuccessJob::class, ['n' => 3]);

        $processed = $this->queue->process('default', 10);

        $this->assertSame(3, $processed);
        $count = (int) $this->db->query("SELECT COUNT(*) FROM jobs")->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testProcessRespectsQueueName(): void
    {
        $this->queue->dispatch(TestSuccessJob::class, [], queue: 'mail');
        $this->queue->dispatch(TestSuccessJob::class, [], queue: 'pdf');

        $processed = $this->queue->process('mail', 10);

        $this->assertSame(1, $processed);
        // The pdf job should remain
        $count = (int) $this->db->query("SELECT COUNT(*) FROM jobs WHERE queue='pdf'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testProcessReturnsZeroWhenQueueIsEmpty(): void
    {
        $processed = $this->queue->process('default', 10);
        $this->assertSame(0, $processed);
    }

    // ------------------------------------------------------------------
    // Process – failure path
    // ------------------------------------------------------------------

    public function testFailingJobIsMarkedForRetry(): void
    {
        $this->queue->dispatch(TestFailingJob::class, [], maxAttempts: 3);

        $this->queue->process('default', 1);

        // After 1 failure with 3 max_attempts, the job should NOT be failed yet
        $row = $this->db->query("SELECT * FROM jobs LIMIT 1")->fetch();
        $this->assertNotFalse($row);
        $this->assertNull($row['failed_at'], 'Job should not be permanently failed after first attempt');
        $this->assertNotEmpty($row['error']);
    }

    public function testJobIsMarkedFailedAfterMaxAttempts(): void
    {
        $this->queue->dispatch(TestFailingJob::class, [], maxAttempts: 1);

        $this->queue->process('default', 1);

        $row = $this->db->query("SELECT * FROM jobs LIMIT 1")->fetch();
        $this->assertNotFalse($row);
        $this->assertNotNull($row['failed_at'], 'Job should be permanently failed after max_attempts');
    }

    public function testMissingJobClassResultsInFailure(): void
    {
        $this->queue->dispatch('App\Jobs\NonExistentJobClass12345', [], maxAttempts: 1);
        $this->queue->process('default', 1);

        $row = $this->db->query("SELECT * FROM jobs LIMIT 1")->fetch();
        $this->assertNotNull($row['failed_at']);
        $this->assertStringContainsString('not found', (string) $row['error']);
    }

    // ------------------------------------------------------------------
    // Stats
    // ------------------------------------------------------------------

    public function testPendingCountsGroupsByQueue(): void
    {
        $this->queue->dispatch(TestSuccessJob::class, [], queue: 'default');
        $this->queue->dispatch(TestSuccessJob::class, [], queue: 'default');
        $this->queue->dispatch(TestSuccessJob::class, [], queue: 'mail');

        $counts = $this->queue->pendingCounts();

        $this->assertSame(2, $counts['default']);
        $this->assertSame(1, $counts['mail']);
    }

    public function testFailedCountReturnsCorrectNumber(): void
    {
        $this->queue->dispatch(TestFailingJob::class, [], maxAttempts: 1);
        $this->queue->dispatch(TestFailingJob::class, [], maxAttempts: 1);

        $this->queue->process('default', 10);

        $this->assertSame(2, $this->queue->failedCount());
    }

    public function testRetryFailedResetsJobsForReprocessing(): void
    {
        $this->queue->dispatch(TestFailingJob::class, [], maxAttempts: 1);
        $this->queue->process('default', 1);

        $this->assertSame(1, $this->queue->failedCount());

        // Fix the "error" by swapping the job class before retry
        $this->db->exec("UPDATE jobs SET job_class = '" . TestSuccessJob::class . "', max_attempts = 3");

        $retried = $this->queue->retryFailed('default');

        $this->assertSame(1, $retried);
        $this->assertSame(0, $this->queue->failedCount());

        // The re-queued job should now process successfully
        $processed = $this->queue->process('default', 1);
        $this->assertSame(1, $processed);
    }
}

// ---------------------------------------------------------------------------
// Stub job handlers used only in tests
// ---------------------------------------------------------------------------

/** A job that always succeeds */
final class TestSuccessJob implements JobInterface
{
    public function handle(array $payload): void
    {
        // No-op – success is confirmed by the row being deleted
    }
}

/** A job that always throws */
final class TestFailingJob implements JobInterface
{
    public function handle(array $payload): void
    {
        throw new \RuntimeException('Intentional test failure');
    }
}
