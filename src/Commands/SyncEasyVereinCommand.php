<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\EasyVereinSync;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * SyncEasyVereinCommand
 *
 * Synchronizes inventory data from the EasyVerein API to the local database.
 * Replaces cron/sync_easyverein.php.
 *
 * Recommended schedule: every 30 minutes
 *   *\/30 * * * * php /path/to/bin/console app:sync-easyverein
 */
#[AsCommand(
    name:        'app:sync-easyverein',
    description: 'Synchronizes inventory data from the EasyVerein API',
)]
class SyncEasyVereinCommand extends Command
{
    public function __construct(
        private readonly EasyVereinSync  $syncService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('=== EasyVerein Inventory Synchronization ===');
        $output->writeln('Started at: ' . date('Y-m-d H:i:s'));

        try {
            $output->writeln('Fetching data from EasyVerein API...');
            $result = $this->syncService->sync(0);

            $output->writeln("\n=== Synchronization Results ===");
            $output->writeln('Created: ' . ($result['created'] ?? 0) . ' items');
            $output->writeln('Updated: ' . ($result['updated'] ?? 0) . ' items');
            $output->writeln('Deleted: ' . ($result['deleted'] ?? 0) . ' items');

            if (! empty($result['errors'])) {
                foreach ((array)$result['errors'] as $err) {
                    $output->writeln('<error>ERROR: ' . $err . '</error>');
                }
            }

            $output->writeln('Finished at: ' . date('Y-m-d H:i:s'));
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->logger->error('EasyVerein sync failed: ' . $e->getMessage(), ['exception' => $e]);
            $output->writeln('<error>FATAL: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
