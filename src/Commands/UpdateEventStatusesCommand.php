<?php

declare(strict_types=1);

namespace App\Commands;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * UpdateEventStatusesCommand
 *
 * Updates event statuses based on their start/end times.
 * Replaces the pseudo-cron logic in includes/pseudo_cron.php that was triggered
 * on every page load. Running this as a real cron avoids the per-request overhead
 * and ensures timely status transitions regardless of traffic.
 *
 * Recommended schedule: every 5 minutes
 *   *\/5 * * * * php /path/to/bin/console app:update-event-statuses
 */
#[AsCommand(
    name:        'app:update-event-statuses',
    description: 'Updates event statuses (planned → open → closed → completed) based on start/end times',
)]
class UpdateEventStatusesCommand extends Command
{
    public function __construct(private readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('=== Update Event Statuses ===');
        $output->writeln('Started at: ' . date('Y-m-d H:i:s'));

        try {
            $db  = \Database::getContentDB();
            $now = date('Y-m-d H:i:s');

            // 1. planned/open → closed: registration deadline has passed (start time reached)
            $stmt = $db->prepare(
                "UPDATE events
                 SET status = 'closed'
                 WHERE status IN ('planned', 'open')
                   AND start_time <= ?
                   AND end_time > ?"
            );
            $stmt->execute([$now, $now]);
            $closedCount = $stmt->rowCount();

            // 2. closed/planned/open → completed: event has ended
            $stmt = $db->prepare(
                "UPDATE events
                 SET status = 'completed'
                 WHERE status IN ('closed', 'planned', 'open')
                   AND end_time <= ?"
            );
            $stmt->execute([$now]);
            $completedCount = $stmt->rowCount();

            $output->writeln("Events closed:    {$closedCount}");
            $output->writeln("Events completed: {$completedCount}");
            $output->writeln('Finished at: ' . date('Y-m-d H:i:s'));

            $this->logger->info('UpdateEventStatusesCommand completed', [
                'closed'    => $closedCount,
                'completed' => $completedCount,
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            $this->logger->error('UpdateEventStatusesCommand failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
