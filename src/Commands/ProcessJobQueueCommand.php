<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\JobQueue;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * ProcessJobQueueCommand
 *
 * Worker that pulls jobs from the `jobs` table and executes them.
 *
 * Recommended schedule (crontab): every minute
 *   * * * * * php /path/to/bin/console app:process-job-queue
 *
 * Or run continuously with a small sleep between batches:
 *   php bin/console app:process-job-queue --daemon
 */
#[AsCommand(
    name:        'app:process-job-queue',
    description: 'Process pending jobs from the database job queue',
)]
class ProcessJobQueueCommand extends Command
{
    private const DEFAULT_BATCH  = 100;
    private const DAEMON_SLEEP   = 5;   // seconds between daemon loop iterations
    private const DAEMON_TIMEOUT = 3600; // max seconds a daemon run lasts

    public function __construct(
        private readonly JobQueue         $jobQueue,
        private readonly LoggerInterface  $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'queue',
                null,
                InputOption::VALUE_OPTIONAL,
                'Queue name to process (default: all queues)',
                'default',
            )
            ->addOption(
                'batch',
                null,
                InputOption::VALUE_OPTIONAL,
                'Maximum jobs to process per run (default: ' . self::DEFAULT_BATCH . ')',
                self::DEFAULT_BATCH,
            )
            ->addOption(
                'daemon',
                null,
                InputOption::VALUE_NONE,
                'Run continuously until timeout is reached (useful for local dev)',
            )
            ->addOption(
                'retry-failed',
                null,
                InputOption::VALUE_NONE,
                'Re-queue all failed jobs and exit',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queue = (string) ($input->getOption('queue') ?? 'default');
        $batch = (int)    ($input->getOption('batch')  ?? self::DEFAULT_BATCH);

        if ($input->getOption('retry-failed')) {
            $retried = $this->jobQueue->retryFailed($queue);
            $output->writeln("<info>Re-queued {$retried} failed jobs.</info>");
            return Command::SUCCESS;
        }

        if ($input->getOption('daemon')) {
            return $this->runDaemon($queue, $batch, $output);
        }

        return $this->runBatch($queue, $batch, $output);
    }

    private function runBatch(string $queue, int $batch, OutputInterface $output): int
    {
        $output->writeln('=== Job Queue Worker ===');
        $output->writeln('Queue    : ' . $queue);
        $output->writeln('Started  : ' . date('Y-m-d H:i:s'));

        try {
            $pending = $this->jobQueue->pendingCounts();
            $total   = array_sum($pending);
            $output->writeln("Pending  : {$total}");

            $processed = $this->jobQueue->process($queue, $batch);

            $output->writeln("Processed: {$processed}");
            $output->writeln('Finished : ' . date('Y-m-d H:i:s'));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->logger->critical('Job queue batch failed', ['error' => $e->getMessage()]);
            $output->writeln('<error>FATAL: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function runDaemon(string $queue, int $batch, OutputInterface $output): int
    {
        $output->writeln('<info>Starting job queue daemon (press Ctrl+C to stop)…</info>');
        $startedAt = time();

        while (true) {
            if ((time() - $startedAt) >= self::DAEMON_TIMEOUT) {
                $output->writeln('<comment>Daemon timeout reached – exiting cleanly.</comment>');
                break;
            }

            try {
                $processed = $this->jobQueue->process($queue, $batch);
                if ($processed > 0 && $output->isVerbose()) {
                    $output->writeln('[' . date('H:i:s') . "] Processed {$processed} job(s).");
                }
            } catch (\Throwable $e) {
                $this->logger->error('Daemon batch error: ' . $e->getMessage());
                $output->writeln('<error>' . $e->getMessage() . '</error>');
            }

            sleep(self::DAEMON_SLEEP);
        }

        return Command::SUCCESS;
    }
}
