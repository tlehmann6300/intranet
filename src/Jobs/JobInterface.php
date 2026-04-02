<?php

declare(strict_types=1);

namespace App\Jobs;

/**
 * JobInterface
 *
 * Contract that every job handler must implement.
 * The job class is instantiated by the JobQueue worker and its handle()
 * method is called with the decoded JSON payload.
 */
interface JobInterface
{
    /**
     * Execute the job.
     *
     * @param array<mixed> $payload Job-specific parameters
     */
    public function handle(array $payload): void;
}
