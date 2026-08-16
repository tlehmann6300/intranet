<?php
/**
 * Database Backup – Cron Script
 *
 * Creates a full backup of every configured database (user, content, invoice/rech).
 * Each dump is written as a gzip-compressed .sql.gz file inside the backups/ directory,
 * using the current date in the filename (e.g. backup_user_2026-03-01.sql.gz).
 * Backups older than 30 days are automatically removed.
 *
 * mysqldump is used when available; otherwise a pure-PHP fallback produces the dump.
 *
 * Recommended cron schedule (daily at 02:00):
 *   0 2 * * * php /path/to/cron/backup_database.php >> /path/to/logs/backup_database.log 2>&1
 *
 * Usage: php cron/backup_database.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/database.php';

if (PHP_SAPI !== 'cli') {
    if (empty($_ENV['CRON_TOKEN']) || !is_string($_ENV['CRON_TOKEN']) || strlen($_ENV['CRON_TOKEN']) < 16) {
        http_response_code(500);
        exit('CRON_TOKEN not configured securely');
    }
    $__cronToken = CRON_TOKEN;
    if ($__cronToken === '' || !isset($_GET['token']) || !is_string($_GET['token']) || !hash_equals($__cronToken, $_GET['token'])) {
        http_response_code(403);
        exit('Forbidden.' . PHP_EOL);
    }
    unset($__cronToken);
}

define('BACKUP_DIR',          __DIR__ . '/../backups');
define('BACKUP_RETENTION_DAYS', 30);

echo "=== Database Backup Cron ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

// Ensure the backup directory exists and is protected
if (!is_dir(BACKUP_DIR)) {
    if (!mkdir(BACKUP_DIR, 0750, true)) {
        echo "ERROR: Could not create backup directory: " . BACKUP_DIR . "\n";
        exit(1);
    }
}

$htaccess = BACKUP_DIR . '/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess,
        "# Deny all public access to this directory\n" .
        "<IfModule mod_authz_core.c>\n" .
        "    Require all denied\n" .
        "</IfModule>\n" .
        "<IfModule !mod_authz_core.c>\n" .
        "    Order deny,allow\n" .
        "    Deny from all\n" .
        "</IfModule>\n"
    );
}

/**
 * Locate the mysqldump binary.
 * Returns the path on success, or null if not found.
 */
function findMysqldump(): ?string {
    $candidates = ['/usr/bin/mysqldump', '/usr/local/bin/mysqldump', 'mysqldump'];
    foreach ($candidates as $bin) {
        $out = shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null');
        if (!empty($out)) {
            return trim($out);
        }
    }
    return null;
}

/**
 * Dump a single database using mysqldump and write to a .sql.gz file.
 *
 * @param string $host
 * @param string $port
 * @param string $dbname
 * @param string $user
 * @param string $pass
 * @param string $outFile  Destination .sql.gz path
 * @param string $mysqldump Path to mysqldump binary
 * @return bool
 */
function dumpWithMysqldump(string $host, string $port, string $dbname, string $user, string $pass, string $outFile, string $mysqldump): bool {
    // Write a temporary option file to avoid password in process list
    $optFile = tempnam(sys_get_temp_dir(), 'dbbackup_');
    if ($optFile === false) {
        error_log("backup_database: could not create temp option file");
        return false;
    }

    $optContent = "[client]\n"
        . "host=" . $host . "\n"
        . "port=" . $port . "\n"
        . "user=" . $user . "\n"
        . "password=" . $pass . "\n";

    if (file_put_contents($optFile, $optContent) === false) {
        error_log("backup_database: could not write temp option file");
        @unlink($optFile);
        return false;
    }
    chmod($optFile, 0600);

    $cmd = escapeshellarg($mysqldump)
        . ' --defaults-extra-file=' . escapeshellarg($optFile)
        . ' --single-transaction'
        . ' --routines'
        . ' --triggers'
        . ' --hex-blob'
        . ' ' . escapeshellarg($dbname);

    $gz = gzopen($outFile, 'wb9');
    if ($gz === false) {
        error_log("backup_database: could not open gz output file: $outFile");
        @unlink($optFile);
        return false;
    }

    $handle = popen($cmd, 'r');
    if ($handle === false) {
        error_log("backup_database: could not popen mysqldump");
        gzclose($gz);
        @unlink($optFile);
        return false;
    }

    while (!feof($handle)) {
        $chunk = fread($handle, 65536);
        if ($chunk !== false && $chunk !== '') {
            gzwrite($gz, $chunk);
        }
    }

    $exitCode = pclose($handle);
    gzclose($gz);
    @unlink($optFile);

    if ($exitCode !== 0) {
        error_log("backup_database: mysqldump exited with code $exitCode for database $dbname");
        @unlink($outFile);
        return false;
    }

    return true;
}

/**
 * Pure-PHP fallback: dump table structures and data via PDO.
 * Produces a basic SQL dump compressed to .sql.gz.
 *
 * @param PDO    $pdo
 * @param string $dbname
 * @param string $outFile  Destination .sql.gz path
 * @return bool
 */
function dumpWithPdo(PDO $pdo, string $dbname, string $outFile): bool {
    $gz = gzopen($outFile, 'wb9');
    if ($gz === false) {
        error_log("backup_database: could not open gz output file: $outFile");
        return false;
    }

    gzwrite($gz, "-- PHP PDO Backup of `$dbname`\n");
    gzwrite($gz, "-- Generated: " . date('Y-m-d H:i:s') . "\n\n");
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\n\n");

    try {
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            $quotedTable = '`' . str_replace('`', '``', $table) . '`';

            // Table structure
            $createRow = $pdo->query("SHOW CREATE TABLE $quotedTable")->fetch(PDO::FETCH_NUM);
            gzwrite($gz, "DROP TABLE IF EXISTS $quotedTable;\n");
            gzwrite($gz, $createRow[1] . ";\n\n");

            // Table data – use unbuffered query + batched INSERTs to keep memory usage low
            $cols = $pdo->query("SHOW COLUMNS FROM $quotedTable")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($cols)) {
                $quotedCols = implode(', ', array_map(fn($c) => '`' . str_replace('`', '``', $c) . '`', $cols));

                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                $stmt = $pdo->query("SELECT * FROM $quotedTable");
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

                $batch      = [];
                $batchSize  = 100;

                while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
                    $values = implode(', ', array_map(function ($v) use ($pdo) {
                        if ($v === null) return 'NULL';
                        return $pdo->quote($v);
                    }, $row));
                    $batch[] = "($values)";

                    if (count($batch) >= $batchSize) {
                        gzwrite($gz, "INSERT INTO $quotedTable ($quotedCols) VALUES\n  " . implode(",\n  ", $batch) . ";\n");
                        $batch = [];
                    }
                }

                if (!empty($batch)) {
                    gzwrite($gz, "INSERT INTO $quotedTable ($quotedCols) VALUES\n  " . implode(",\n  ", $batch) . ";\n");
                }
                gzwrite($gz, "\n");
            }
        }

        // Views
        $views = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($views as $view) {
            $quotedView = '`' . str_replace('`', '``', $view) . '`';
            $createRow  = $pdo->query("SHOW CREATE VIEW $quotedView")->fetch(PDO::FETCH_NUM);
            gzwrite($gz, "-- View: $view\n");
            gzwrite($gz, "DROP VIEW IF EXISTS $quotedView;\n");
            gzwrite($gz, $createRow[1] . ";\n\n");
        }

    } catch (Exception $e) {
        gzclose($gz);
        error_log("backup_database: PDO dump error for $dbname: " . $e->getMessage());
        @unlink($outFile);
        return false;
    }

    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($gz);
    return true;
}

/**
 * Back up a single database configuration.
 *
 * @param string      $label    Human-readable name (e.g. 'user')
 * @param string      $host
 * @param string      $port
 * @param string      $dbname
 * @param string      $user
 * @param string      $pass
 * @param string|null $mysqldump Path to mysqldump binary, or null to use PHP fallback
 * @return bool
 */
function backupDatabase(string $label, string $host, string $port, string $dbname, string $user, string $pass, ?string $mysqldump): bool {
    if (empty($dbname)) {
        echo "  [$label] Skipped – no database name configured.\n";
        return true;
    }

    $date    = date('Y-m-d');
    $outFile = BACKUP_DIR . '/backup_' . $label . '_' . $date . '.sql.gz';

    echo "  [$label] Backing up database '{$dbname}' to " . basename($outFile) . " ...\n";

    if ($mysqldump !== null) {
        $ok = dumpWithMysqldump($host, $port, $dbname, $user, $pass, $outFile, $mysqldump);
        if (!$ok) {
            echo "  [$label] mysqldump failed – falling back to PHP dump.\n";
            $ok = fallbackPdoDump($label, $host, $port, $dbname, $user, $pass, $outFile);
        }
    } else {
        echo "  [$label] mysqldump not found – using PHP fallback.\n";
        $ok = fallbackPdoDump($label, $host, $port, $dbname, $user, $pass, $outFile);
    }

    if ($ok) {
        $size = file_exists($outFile) ? round(filesize($outFile) / 1024, 1) : 0;
        echo "  [$label] Done. File size: {$size} KB.\n";
    } else {
        echo "  [$label] ERROR: Backup failed.\n";
    }

    return $ok;
}

/**
 * Create a PDO connection and run the PHP-based dump.
 */
function fallbackPdoDump(string $label, string $host, string $port, string $dbname, string $user, string $pass, string $outFile): bool {
    try {
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        return dumpWithPdo($pdo, $dbname, $outFile);
    } catch (PDOException $e) {
        error_log("backup_database: PDO connection failed for $label: " . $e->getMessage());
        return false;
    }
}

// -------------------------------------------------------------------------
// Main execution
// -------------------------------------------------------------------------

$mysqldump = findMysqldump();
if ($mysqldump) {
    echo "Using mysqldump: $mysqldump\n\n";
} else {
    echo "mysqldump not found – PHP fallback will be used.\n\n";
}

$databases = [
    [
        'label'  => 'user',
        'host'   => DB_USER_HOST,
        'port'   => '3306',  // no separate port constant defined for user DB (see config/config.php)
        'dbname' => DB_USER_NAME,
        'user'   => DB_USER_USER,
        'pass'   => DB_USER_PASS,
    ],
    [
        'label'  => 'content',
        'host'   => DB_CONTENT_HOST,
        'port'   => '3306',  // no separate port constant defined for content DB (see config/config.php)
        'dbname' => DB_CONTENT_NAME,
        'user'   => DB_CONTENT_USER,
        'pass'   => DB_CONTENT_PASS,
    ],
    [
        'label'  => 'invoice',
        'host'   => DB_RECH_HOST,
        'port'   => DB_RECH_PORT,
        'dbname' => DB_RECH_NAME,
        'user'   => DB_RECH_USER,
        'pass'   => DB_RECH_PASS,
    ],
];

$successCount = 0;
$failCount    = 0;

echo "--- Backup phase ---\n";
foreach ($databases as $db) {
    $ok = backupDatabase(
        $db['label'],
        $db['host'],
        $db['port'],
        $db['dbname'],
        $db['user'],
        $db['pass'],
        $mysqldump
    );
    $ok ? $successCount++ : $failCount++;
}

// -------------------------------------------------------------------------
// Cleanup: remove backups older than BACKUP_RETENTION_DAYS days
// -------------------------------------------------------------------------
echo "\n--- Cleanup phase (retention: " . BACKUP_RETENTION_DAYS . " days) ---\n";

$cutoff   = time() - (BACKUP_RETENTION_DAYS * 86400);
$deleted  = 0;

foreach (glob(BACKUP_DIR . '/backup_*.sql.gz') as $file) {
    if (filemtime($file) < $cutoff) {
        if (unlink($file)) {
            echo "  Deleted old backup: " . basename($file) . "\n";
            $deleted++;
        } else {
            echo "  WARNING: Could not delete: " . basename($file) . "\n";
        }
    }
}

if ($deleted === 0) {
    echo "  No old backups to remove.\n";
}

echo "\n=== Summary ===\n";
echo "Databases backed up successfully: {$successCount}\n";
echo "Databases with errors:            {$failCount}\n";
echo "Old backups deleted:              {$deleted}\n";
echo "\nFinished at: " . date('Y-m-d H:i:s') . "\n";

exit($failCount > 0 ? 1 : 0);
