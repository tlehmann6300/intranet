<?php

declare(strict_types=1);

namespace App\Commands;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * ProcessMailQueueCommand
 *
 * Processes up to 200 queued e-mails per batch from the mail_queue table.
 * A 60-minute cooldown is enforced when the previous batch reached the limit.
 * Replaces cron/process_mail_queue.php.
 *
 * Recommended schedule: every 5 minutes
 *   *\/5 * * * * php /path/to/bin/console app:process-mail-queue
 */
#[AsCommand(
    name:        'app:process-mail-queue',
    description: 'Sends queued emails from the mail_queue table',
)]
class ProcessMailQueueCommand extends Command
{
    private const BATCH_SIZE       = 200;
    private const COOLDOWN_MINUTES = 60;

    public function __construct(private readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('batch-size', null, InputOption::VALUE_OPTIONAL, 'Mails per batch (default: ' . self::BATCH_SIZE . ')', self::BATCH_SIZE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batchSize = (int)$input->getOption('batch-size');

        $output->writeln('=== Mail Queue Cron ===');
        $output->writeln('Started at: ' . date('Y-m-d H:i:s'));

        try {
            $db           = \Database::getContentDB();
            $pendingCount = (int)$db->query('SELECT COUNT(*) FROM mail_queue WHERE sent = 0')->fetchColumn();

            if ($pendingCount === 0) {
                $output->writeln('No pending mails in queue.');
                return Command::SUCCESS;
            }

            $output->writeln("Pending mails: {$pendingCount}");

            // Cooldown check
            $lastBatch = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'mail_queue_last_batch'")->fetchColumn();
            if ($lastBatch !== false) {
                $minutesSince = (time() - (int)$lastBatch) / 60;
                if ($minutesSince < self::COOLDOWN_MINUTES) {
                    $waitMin = (int)(self::COOLDOWN_MINUTES - $minutesSince);
                    $output->writeln("Cooldown active – next batch in ~{$waitMin} min.");
                    return Command::SUCCESS;
                }
            }

            $stmt  = $db->prepare('SELECT * FROM mail_queue WHERE sent = 0 ORDER BY created_at ASC LIMIT ?');
            $stmt->execute([$batchSize]);
            $mails = $stmt->fetchAll();

            $sent   = 0;
            $failed = 0;

            foreach ($mails as $mail) {
                try {
                    \MailService::send(
                        $mail['recipient_email'],
                        $mail['subject'],
                        $mail['body'],
                        $mail['sender_email'] ?? null
                    );
                    $db->prepare('UPDATE mail_queue SET sent = 1, sent_at = NOW() WHERE id = ?')->execute([$mail['id']]);
                    $sent++;
                } catch (\Exception $e) {
                    $failed++;
                    $this->logger->error('Mail queue send failed for ID ' . $mail['id'] . ': ' . $e->getMessage());
                    $output->writeln('<error>  Failed ID ' . $mail['id'] . ': ' . $e->getMessage() . '</error>');
                }
            }

            // Store batch timestamp for cooldown (only when batch was full)
            if (count($mails) >= $batchSize) {
                $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('mail_queue_last_batch', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                   ->execute([time()]);
            }

            $output->writeln("\nSent: {$sent}  Failed: {$failed}");
            $output->writeln('Finished at: ' . date('Y-m-d H:i:s'));

            return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
        } catch (\Exception $e) {
            $this->logger->error('Mail queue command failed: ' . $e->getMessage());
            $output->writeln('<error>FATAL: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
