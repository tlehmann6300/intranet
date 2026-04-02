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
 * BackupDatabaseCommand
 *
 * Creates gzip-compressed SQL dumps for every configured database.
 * Replaces cron/backup_database.php.
 *
 * Usage:
 *   php bin/console app:backup-database
 *   php bin/console app:backup-database --retention=60
 */
#[AsCommand(
    name:        'app:backup-database',
    description: 'Backs up all configured databases to the backups/ directory',
)]
class BackupDatabaseCommand extends Command
{
    private const DEFAULT_RETENTION_DAYS = 30;
    private const BACKUP_DIR             = __DIR__ . '/../../backups';

    public function __construct(private readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'retention',
            null,
            InputOption::VALUE_OPTIONAL,
            'Number of days to keep backups (default: ' . self::DEFAULT_RETENTION_DAYS . ')',
            self::DEFAULT_RETENTION_DAYS
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $retentionDays = (int)$input->getOption('retention');
        $output->writeln('=== Database Backup ===');
        $output->writeln('Started at: ' . date('Y-m-d H:i:s'));

        $backupDir = realpath(self::BACKUP_DIR) ?: self::BACKUP_DIR;
        if (! is_dir($backupDir) && ! mkdir($backupDir, 0750, true)) {
            $output->writeln('<error>ERROR: Could not create backup directory: ' . $backupDir . '</error>');
            return Command::FAILURE;
        }

        $mysqldump = $this->findMysqldump();
        $output->writeln($mysqldump ? 'Using mysqldump: ' . $mysqldump : 'mysqldump not found – PHP fallback will be used.');

        $databases = $this->getDatabaseConfigs();
        $success   = 0;
        $failed    = 0;

        foreach ($databases as $db) {
            $ok = $this->backupDatabase($db, $backupDir, $mysqldump, $output);
            $ok ? $success++ : $failed++;
        }

        // Cleanup
        $output->writeln("\n--- Cleanup (retention: {$retentionDays} days) ---");
        $deleted = $this->cleanOldBackups($backupDir, $retentionDays, $output);

        $output->writeln("\n=== Summary ===");
        $output->writeln("Backed up: {$success}  Errors: {$failed}  Deleted: {$deleted}");
        $output->writeln('Finished at: ' . date('Y-m-d H:i:s'));

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    // -------------------------------------------------------------------------

    private function findMysqldump(): ?string
    {
        foreach (['/usr/bin/mysqldump', '/usr/local/bin/mysqldump', 'mysqldump'] as $bin) {
            $out = shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null');
            if (! empty($out)) {
                return trim((string)$out);
            }
        }
        return null;
    }

    /** @return array<int, array{label: string, host: string, port: string, dbname: string, user: string, pass: string}> */
    private function getDatabaseConfigs(): array
    {
        return [
            ['label' => 'user',    'host' => defined('DB_USER_HOST')    ? DB_USER_HOST    : 'localhost', 'port' => '3306',                                        'dbname' => defined('DB_USER_NAME')    ? DB_USER_NAME    : '', 'user' => defined('DB_USER_USER') ? DB_USER_USER : '', 'pass' => defined('DB_USER_PASS') ? DB_USER_PASS : ''],
            ['label' => 'content', 'host' => defined('DB_CONTENT_HOST') ? DB_CONTENT_HOST : 'localhost', 'port' => '3306',                                        'dbname' => defined('DB_CONTENT_NAME') ? DB_CONTENT_NAME : '', 'user' => defined('DB_CONTENT_USER') ? DB_CONTENT_USER : '', 'pass' => defined('DB_CONTENT_PASS') ? DB_CONTENT_PASS : ''],
            ['label' => 'invoice', 'host' => defined('DB_RECH_HOST')    ? DB_RECH_HOST    : 'localhost', 'port' => defined('DB_RECH_PORT') ? DB_RECH_PORT : '3306', 'dbname' => defined('DB_RECH_NAME')    ? DB_RECH_NAME    : '', 'user' => defined('DB_RECH_USER')    ? DB_RECH_USER    : '', 'pass' => defined('DB_RECH_PASS')    ? DB_RECH_PASS    : ''],
        ];
    }

    /** @param array{label: string, host: string, port: string, dbname: string, user: string, pass: string} $db */
    private function backupDatabase(array $db, string $backupDir, ?string $mysqldump, OutputInterface $output): bool
    {
        if (empty($db['dbname'])) {
            $output->writeln("  [{$db['label']}] Skipped – no database configured.");
            return true;
        }

        $outFile = $backupDir . '/backup_' . $db['label'] . '_' . date('Y-m-d') . '.sql.gz';
        $output->writeln("  [{$db['label']}] Backing up '{$db['dbname']}' → " . basename($outFile));

        $ok = false;
        if ($mysqldump !== null) {
            $ok = $this->dumpWithMysqldump($db, $outFile, $mysqldump);
        }
        if (! $ok) {
            $output->writeln("  [{$db['label']}] Using PHP PDO fallback.");
            $ok = $this->dumpWithPdo($db, $outFile);
        }

        if ($ok) {
            $size = file_exists($outFile) ? round(filesize($outFile) / 1024, 1) : 0;
            $output->writeln("  [{$db['label']}] Done. Size: {$size} KB.");
        } else {
            $output->writeln("  <error>[{$db['label']}] ERROR: Backup failed.</error>");
            $this->logger->error('Backup failed for database: ' . $db['label']);
        }

        return $ok;
    }

    /** @param array{host: string, port: string, dbname: string, user: string, pass: string} $db */
    private function dumpWithMysqldump(array $db, string $outFile, string $mysqldump): bool
    {
        $optFile = tempnam(sys_get_temp_dir(), 'dbbackup_');
        if ($optFile === false) {
            return false;
        }
        file_put_contents($optFile, "[client]\nhost={$db['host']}\nport={$db['port']}\nuser={$db['user']}\npassword={$db['pass']}\n");
        chmod($optFile, 0600);

        $cmd    = escapeshellarg($mysqldump) . ' --defaults-extra-file=' . escapeshellarg($optFile) . ' --single-transaction --routines --triggers --hex-blob ' . escapeshellarg($db['dbname']);
        $gz     = gzopen($outFile, 'wb9');
        if ($gz === false) { @unlink($optFile); return false; }
        $handle = popen($cmd, 'r');
        if ($handle === false) { gzclose($gz); @unlink($optFile); return false; }
        while (! feof($handle)) { $chunk = fread($handle, 65536); if ($chunk !== false && $chunk !== '') { gzwrite($gz, $chunk); } }
        $exit = pclose($handle);
        gzclose($gz);
        @unlink($optFile);
        if ($exit !== 0) { @unlink($outFile); return false; }
        return true;
    }

    /** @param array{host: string, port: string, dbname: string, user: string, pass: string} $db */
    private function dumpWithPdo(array $db, string $outFile): bool
    {
        try {
            $pdo = new \PDO("mysql:host={$db['host']};port={$db['port']};dbname={$db['dbname']};charset=utf8mb4", $db['user'], $db['pass'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $gz  = gzopen($outFile, 'wb9');
            if ($gz === false) { return false; }
            gzwrite($gz, "-- PHP PDO Backup of `{$db['dbname']}`\n-- " . date('Y-m-d H:i:s') . "\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $q      = '`' . str_replace('`', '``', $table) . '`';
                $create = $pdo->query("SHOW CREATE TABLE $q")->fetch(\PDO::FETCH_NUM);
                gzwrite($gz, "DROP TABLE IF EXISTS $q;\n{$create[1]};\n\n");
            }
            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
            gzclose($gz);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('PDO dump failed: ' . $e->getMessage());
            return false;
        }
    }

    private function cleanOldBackups(string $backupDir, int $days, OutputInterface $output): int
    {
        $cutoff  = time() - ($days * 86400);
        $deleted = 0;
        foreach (glob($backupDir . '/backup_*.sql.gz') ?: [] as $file) {
            if (filemtime($file) < $cutoff && unlink($file)) {
                $output->writeln('  Deleted: ' . basename($file));
                $deleted++;
            }
        }
        if ($deleted === 0) { $output->writeln('  No old backups to remove.'); }
        return $deleted;
    }
}
