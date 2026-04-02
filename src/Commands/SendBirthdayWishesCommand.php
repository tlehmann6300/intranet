<?php

declare(strict_types=1);

namespace App\Commands;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * SendBirthdayWishesCommand
 *
 * Sends birthday greeting e-mails to all users whose birthday is today.
 * Replaces cron/send_birthday_wishes.php.
 *
 * Recommended schedule: daily at 08:00
 *   0 8 * * * php /path/to/bin/console app:send-birthday-wishes
 */
#[AsCommand(
    name:        'app:send-birthday-wishes',
    description: 'Sends birthday greetings to users celebrating today',
)]
class SendBirthdayWishesCommand extends Command
{
    public function __construct(private readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('=== Birthday Wishes Email Cron Job ===');
        $output->writeln('Started at: ' . date('Y-m-d H:i:s'));

        try {
            $userDb  = \Database::getUserDB();
            $today   = date('m-d');

            $stmt = $userDb->prepare('
                SELECT u.id, u.email, u.gender, u.birthday, ap.first_name
                FROM users u
                LEFT JOIN ' . \DB_CONTENT_NAME . '.alumni_profiles ap ON u.id = ap.user_id
                WHERE DATE_FORMAT(u.birthday, \'%m-%d\') = ?
                  AND u.deleted_at IS NULL
                  AND u.is_active = 1
            ');
            $stmt->execute([$today]);
            $users = $stmt->fetchAll();

            $output->writeln('Found ' . count($users) . ' user(s) with birthday today.');

            $sent   = 0;
            $failed = 0;

            foreach ($users as $user) {
                try {
                    $firstName  = $user['first_name'] ?? explode('@', $user['email'])[0];
                    $salutation = match ($user['gender'] ?? '') {
                        'male'   => 'Lieber ' . $firstName,
                        'female' => 'Liebe ' . $firstName,
                        default  => 'Liebes Mitglied ' . $firstName,
                    };

                    $body = '<h2>Herzlichen Glückwunsch zum Geburtstag!</h2>'
                        . '<p>' . htmlspecialchars($salutation) . ',</p>'
                        . '<p>das gesamte IBC-Team wünscht dir alles Gute zu deinem Geburtstag! 🎉🎂</p>';

                    \MailService::send($user['email'], 'Happy Birthday! 🎂', $body);
                    $sent++;
                    $output->writeln("  Sent to: {$user['email']}");
                } catch (\Exception $e) {
                    $failed++;
                    $this->logger->error('Birthday email failed for ' . $user['email'] . ': ' . $e->getMessage());
                    $output->writeln("<error>  Failed for: {$user['email']}</error>");
                }
            }

            $output->writeln("\nSent: {$sent}  Failed: {$failed}");
            $output->writeln('Finished at: ' . date('Y-m-d H:i:s'));

            return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
        } catch (\Exception $e) {
            $this->logger->error('Birthday wishes command failed: ' . $e->getMessage());
            $output->writeln('<error>FATAL: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
