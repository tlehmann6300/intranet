<?php

declare(strict_types=1);

namespace App\Services;

use Psr\Log\LoggerInterface;

/**
 * JobQueue
 *
 * Database-backed job queue for asynchronous background processing.
 *
 * Usage – dispatching a job from a controller:
 *
 *   $queue->dispatch(App\Jobs\SendNewsletterJob::class, [
 *       'newsletter_id' => 42,
 *       'recipient_ids' => [1, 2, 3],
 *   ]);
 *
 * Usage – processing jobs (from ProcessJobQueueCommand):
 *
 *   $queue->process('default', maxJobs: 50);
 *
 * Job handler contract:
 *   Every job class must implement App\Jobs\JobInterface (handle(array $payload): void).
 */
class JobQueue
{
    /** How long (seconds) a reserved job may be held before being considered stale */
    private const STALE_LOCK_SECONDS = 300;

    public function __construct(
        private readonly \PDO            $db,
        private readonly LoggerInterface $logger,
        private readonly string          $defaultQueue = 'default',
    ) {}

    // -------------------------------------------------------------------------
    // Dispatch
    // -------------------------------------------------------------------------

    /**
     * Push a new job onto the queue.
     *
     * @param class-string      $jobClass   Fully-qualified class name of the job handler
     * @param array<mixed>      $payload    Arbitrary data serialised as JSON
     * @param string|null       $queue      Queue name (defaults to $defaultQueue)
     * @param \DateTimeInterface|null $availableAt Earliest processing time (null = now)
     * @param int               $maxAttempts Maximum retries before marking failed
     */
    public function dispatch(
        string             $jobClass,
        array              $payload     = [],
        ?string            $queue       = null,
        ?\DateTimeInterface $availableAt = null,
        int                $maxAttempts = 3,
    ): int {
        $queueName = $queue ?? $this->defaultQueue;
        $availableAt ??= new \DateTimeImmutable();

        $stmt = $this->db->prepare(
            'INSERT INTO jobs (queue, job_class, payload, attempts, max_attempts, available_at, created_at)
             VALUES (:queue, :job_class, :payload, 0, :max_attempts, :available_at, NOW())'
        );
        $stmt->execute([
            'queue'        => $queueName,
            'job_class'    => $jobClass,
            'payload'      => json_encode($payload, JSON_THROW_ON_ERROR),
            'max_attempts' => $maxAttempts,
            'available_at' => $availableAt->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    // -------------------------------------------------------------------------
    // Process
    // -------------------------------------------------------------------------

    /**
     * Pop and execute jobs from the queue until the batch limit is reached.
     *
     * @return int Number of jobs successfully processed
     */
    public function process(string $queue = 'default', int $maxJobs = 100): int
    {
        $processed = 0;

        // Release stale locks first (worker crashed mid-job)
        $this->releaseStale();

        for ($i = 0; $i < $maxJobs; $i++) {
            $job = $this->reserve($queue);
            if ($job === null) {
                break; // No more jobs
            }

            $jobId = (int) $job['id'];

            try {
                $this->runJob($job);
                $this->delete($jobId);
                $processed++;
            } catch (\Throwable $e) {
                $this->handleFailure($job, $e);
            }
        }

        return $processed;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Atomically reserve the next available job for this queue.
     *
     * @return array<string, mixed>|null
     */
    private function reserve(string $queue): ?array
    {
        // BEGIN – find the oldest available, unreserved, non-failed job
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                'SELECT * FROM jobs
                 WHERE queue        = :queue
                   AND reserved_at  IS NULL
                   AND failed_at    IS NULL
                   AND available_at <= NOW()
                 ORDER BY available_at ASC, id ASC
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute(['queue' => $queue]);
            /** @var array<string, mixed>|false $row */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row === false) {
                $this->db->rollBack();
                return null;
            }

            // Mark as reserved
            $update = $this->db->prepare(
                'UPDATE jobs SET reserved_at = NOW(), attempts = attempts + 1 WHERE id = :id'
            );
            $update->execute(['id' => $row['id']]);
            $this->db->commit();

            return $row;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @param array<string, mixed> $job */
    private function runJob(array $job): void
    {
        $class = $job['job_class'] ?? '';
        if (!class_exists($class)) {
            throw new \RuntimeException("Job class not found: {$class}");
        }

        /** @var \App\Jobs\JobInterface $handler */
        $handler = new $class();
        /** @var array<mixed> $payload */
        $payload = json_decode((string) ($job['payload'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
        $handler->handle($payload);
    }

    private function delete(int $jobId): void
    {
        $this->db->prepare('DELETE FROM jobs WHERE id = ?')->execute([$jobId]);
    }

    /** @param array<string, mixed> $job */
    private function handleFailure(array $job, \Throwable $e): void
    {
        $jobId       = (int) $job['id'];
        $attempts    = (int) $job['attempts'] + 1; // +1 for the current attempt that was recorded on reserve
        $maxAttempts = (int) ($job['max_attempts'] ?? 3);
        $error       = $e->getMessage() . "\n" . $e->getTraceAsString();

        $this->logger->error('Job failed', [
            'job_id'    => $jobId,
            'job_class' => $job['job_class'] ?? '?',
            'attempt'   => $attempts,
            'error'     => $e->getMessage(),
        ]);

        if ($attempts >= $maxAttempts) {
            // Mark as permanently failed
            $this->db->prepare(
                'UPDATE jobs SET reserved_at = NULL, failed_at = NOW(), error = ? WHERE id = ?'
            )->execute([$error, $jobId]);
        } else {
            // Back off exponentially: 1 min, 5 min, 15 min …
            $delaySeconds = 60 * (int) pow(5, $attempts - 1);
            $this->db->prepare(
                'UPDATE jobs
                 SET reserved_at = NULL,
                     available_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                     error = ?
                 WHERE id = ?'
            )->execute([$delaySeconds, $error, $jobId]);
        }
    }

    /**
     * Release any jobs reserved by workers that appear to have crashed.
     */
    private function releaseStale(): void
    {
        $this->db->prepare(
            'UPDATE jobs
             SET reserved_at = NULL
             WHERE reserved_at IS NOT NULL
               AND failed_at   IS NULL
               AND reserved_at < DATE_SUB(NOW(), INTERVAL :seconds SECOND)'
        )->execute(['seconds' => self::STALE_LOCK_SECONDS]);
    }

    // -------------------------------------------------------------------------
    // Stats / helpers
    // -------------------------------------------------------------------------

    /**
     * Return pending job counts grouped by queue name.
     *
     * @return array<string, int>
     */
    public function pendingCounts(): array
    {
        $stmt = $this->db->query(
            'SELECT queue, COUNT(*) as cnt FROM jobs
             WHERE reserved_at IS NULL AND failed_at IS NULL
             GROUP BY queue'
        );
        $result = [];
        if ($stmt !== false) {
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $result[(string) $row['queue']] = (int) $row['cnt'];
            }
        }
        return $result;
    }

    /**
     * Return failed job count.
     */
    public function failedCount(): int
    {
        $stmt = $this->db->query('SELECT COUNT(*) FROM jobs WHERE failed_at IS NOT NULL');
        if ($stmt === false) {
            return 0;
        }
        return (int) $stmt->fetchColumn();
    }

    /**
     * Retry all failed jobs (reset failed_at, attempts = 0, available_at = NOW).
     */
    public function retryFailed(string $queue = 'default'): int
    {
        $stmt = $this->db->prepare(
            'UPDATE jobs
             SET failed_at = NULL, reserved_at = NULL, attempts = 0, available_at = NOW()
             WHERE failed_at IS NOT NULL AND queue = ?'
        );
        $stmt->execute([$queue]);
        return (int) $stmt->rowCount();
    }
}
