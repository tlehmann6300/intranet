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
 * SendProfileRemindersCommand
 *
 * Sends a one-time reminder e-mail to users whose profile has not been updated
 * in over 12 months and who have not yet received a reminder this cycle.
 * Replaces cron/send_profile_reminders.php.
 *
 * Recommended schedule: monthly
 *   0 8 1 * * php /path/to/bin/console app:send-profile-reminders
 */
#[AsCommand(
    name:        'app:send-profile-reminders',
    description: 'Sends profile-update reminder emails to users with outdated profiles',
)]
class SendProfileRemindersCommand extends Command
{
    public function __construct(private readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Max emails per run (default: 50)', 50);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int)$input->getOption('limit');

        $output->writeln('=== Profile Reminder Email Cron Job ===');
        $output->writeln('Started at: ' . date('Y-m-d H:i:s'));

        try {
            $userDb    = \Database::getUserDB();
            $stmt      = $userDb->prepare('
                SELECT u.id, u.email, u.last_profile_update, ap.first_name, ap.last_name
                FROM users u
                LEFT JOIN ' . \DB_CONTENT_NAME . '.alumni_profiles ap ON u.id = ap.user_id
                WHERE u.last_profile_update < DATE_SUB(NOW(), INTERVAL 1 YEAR)
                  AND u.profile_reminder_sent_at IS NULL
                  AND u.deleted_at IS NULL
                  AND u.is_active = 1
                LIMIT ?
            ');
            $stmt->execute([$limit]);
            $users = $stmt->fetchAll();

            $output->writeln('Found ' . count($users) . " user(s) whose profile needs updating.\n");

            $sent   = 0;
            $failed = 0;

            foreach ($users as $user) {
                try {
                    $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'Mitglied';
                    $body = '<h2>Profil aktualisieren</h2>'
                        . '<p>Liebe(r) ' . htmlspecialchars($name) . ',</p>'
                        . '<p>Dein Profil im IBC-Intranet wurde seit über einem Jahr nicht aktualisiert.</p>'
                        . '<p>Bitte prüfe und aktualisiere deine Angaben: <a href="' . \BASE_URL . '/profile">Zum Profil</a></p>';

                    \MailService::send($user['email'], 'Profil-Update erforderlich – IBC Intranet', $body);

                    $userDb->prepare('UPDATE users SET profile_reminder_sent_at = NOW() WHERE id = ?')->execute([$user['id']]);
                    $sent++;
                    $output->writeln("  Sent to: {$user['email']}");
                } catch (\Exception $e) {
                    $failed++;
                    $this->logger->error('Profile reminder failed for user ' . ($user['id'] ?? '?') . ': ' . $e->getMessage());
                    $output->writeln('<error>  Failed: ' . $e->getMessage() . '</error>');
                }
            }

            $output->writeln("\nSent: {$sent}  Failed: {$failed}");
            $output->writeln('Finished at: ' . date('Y-m-d H:i:s'));

            return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
        } catch (\Exception $e) {
            $this->logger->error('Profile reminders command failed: ' . $e->getMessage());
            $output->writeln('<error>FATAL: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
