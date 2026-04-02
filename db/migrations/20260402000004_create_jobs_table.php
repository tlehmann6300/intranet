<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * CreateJobsTable
 *
 * Database-backed job queue for asynchronous background processing.
 * Each row represents one pending or failed job.
 *
 * Run with:
 *   php vendor/bin/phinx migrate -e content
 *
 * Worker command:
 *   php bin/console app:process-job-queue
 */
final class CreateJobsTable extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('jobs')) {
            return;
        }

        $this->table('jobs', ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('queue', 'string', [
                'limit'   => 100,
                'default' => 'default',
                'null'    => false,
                'comment' => 'Queue name (e.g. default, mail, pdf)',
            ])
            ->addColumn('job_class', 'string', [
                'limit'   => 255,
                'null'    => false,
                'comment' => 'Fully-qualified class name of the job handler',
            ])
            ->addColumn('payload', 'text', [
                'null'    => false,
                'comment' => 'JSON-encoded job parameters',
            ])
            ->addColumn('attempts', 'integer', [
                'default' => 0,
                'null'    => false,
                'comment' => 'Number of processing attempts so far',
            ])
            ->addColumn('max_attempts', 'integer', [
                'default' => 3,
                'null'    => false,
                'comment' => 'Maximum number of attempts before the job is marked failed',
            ])
            ->addColumn('reserved_at', 'datetime', [
                'null'    => true,
                'default' => null,
                'comment' => 'Set while a worker is processing this job (stale-lock detection)',
            ])
            ->addColumn('available_at', 'datetime', [
                'null'    => false,
                'default' => 'CURRENT_TIMESTAMP',
                'comment' => 'Earliest time the job may be picked up (supports delayed dispatch)',
            ])
            ->addColumn('failed_at', 'datetime', [
                'null'    => true,
                'default' => null,
                'comment' => 'Set when all retry attempts are exhausted',
            ])
            ->addColumn('error', 'text', [
                'null'    => true,
                'default' => null,
                'comment' => 'Last error message / stack trace',
            ])
            ->addColumn('created_at', 'datetime', [
                'null'    => false,
                'default' => 'CURRENT_TIMESTAMP',
            ])
            ->addIndex(['queue', 'available_at', 'reserved_at', 'failed_at'], [
                'name' => 'idx_jobs_queue_dispatch',
            ])
            ->addIndex('failed_at')
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('jobs')) {
            $this->dropTable('jobs');
        }
    }
}
