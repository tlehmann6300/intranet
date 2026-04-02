<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\Alumni;use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * SendAlumniRemindersCommand
 *
 * Sends reminder e-mails to alumni whose profiles have not been verified in
 * over 12 months (configurable).  Processes at most 20 profiles per run to
 * avoid SMTP timeouts.
 * Replaces cron/send_alumni_reminders.php.
 *
 * Recommended schedule: weekly (e.g. every Monday at 07:00)
 *   0 7 * * 1 php /path/to/bin/console app:send-alumni-reminders
 */
#[AsCommand(
    name:        'app:send-alumni-reminders',
    description: 'Sends reminder emails to alumni with outdated profile verifications',
)]
class SendAlumniRemindersCommand extends Command
{
    public function __construct(private readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('months', null, InputOption::VALUE_OPTIONAL, 'Months threshold for "outdated" (default: 12)', 12);
        $this->addOption('limit',  null, InputOption::VALUE_OPTIONAL, 'Max emails per run (default: 20)',               20);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $months = (int)$input->getOption('months');
        $limit  = (int)$input->getOption('limit');

        $output->writeln('=== Alumni Reminder Email Cron Job ===');
        $output->writeln('Started at: ' . date('Y-m-d H:i:s'));

        try {
            $profiles = Alumni::getOutdatedProfiles($months);
            $total    = count($profiles);
            $output->writeln("Found {$total} alumni profiles that need verification.");

            $toProcess = array_slice($profiles, 0, $limit);
            $output->writeln('Processing ' . count($toProcess) . " profiles (limit: {$limit}).\n");

            $sent   = 0;
            $failed = 0;

            foreach ($toProcess as $profile) {
                try {
                    $email     = $profile['email'] ?? '';
                    $firstName = $profile['first_name'] ?? '';

                    $body = '<h2>Profil-Überprüfung erforderlich</h2>'
                        . '<p>Liebe(r) ' . htmlspecialchars($firstName ?: 'Alumni') . ',</p>'
                        . '<p>Bitte überprüfe und aktualisiere dein Alumni-Profil im IBC-Intranet.</p>'
                        . '<p><a href="' . \BASE_URL . '/alumni">Zum Alumni-Bereich</a></p>';

                    \MailService::send($email, 'Profil-Überprüfung – IBC Alumni', $body);
                    Alumni::markReminderSent((int)$profile['id']);
                    $sent++;
                    $output->writeln("  Sent to: {$email}");
                } catch (\Exception $e) {
                    $failed++;
                    $this->logger->error('Alumni reminder failed for profile ' . ($profile['id'] ?? '?') . ': ' . $e->getMessage());
                    $output->writeln('<error>  Failed: ' . $e->getMessage() . '</error>');
                }
            }

            $output->writeln("\nSent: {$sent}  Failed: {$failed}");
            $output->writeln('Finished at: ' . date('Y-m-d H:i:s'));

            return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
        } catch (\Exception $e) {
            $this->logger->error('Alumni reminders command failed: ' . $e->getMessage());
            $output->writeln('<error>FATAL: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
