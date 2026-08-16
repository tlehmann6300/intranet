<?php
/**
 * Mail Queue – Cron Script
 *
 * Checks the mail_queue table for unsent emails and sends up to 200 per batch.
 * A 60-minute cooldown is enforced between batches only when the previous batch
 * reached the 200-mail limit (SMTP rate-limit protection).
 *
 * After each mail the row is updated: sent = 1, sent_at = NOW().
 * The batch start timestamp is stored in system_settings so the cooldown logic
 * survives across separate cron invocations.
 *
 * Recommended cron schedule (every 5 minutes):
 *   *\/5 * * * * php /path/to/offer/cron/process_mail_queue.php >> /path/to/logs/mail_queue.log 2>&1
 *
 * Usage: php cron/process_mail_queue.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../src/MailService.php';

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

define('MAIL_BATCH_SIZE', 200);
define('MAIL_BATCH_COOLDOWN_MINUTES', 60);

echo "=== Mail Queue Cron ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $db = Database::getContentDB();

    // Check whether any unsent mails exist
    $pendingCount = (int)$db->query("SELECT COUNT(*) FROM mail_queue WHERE sent = 0")->fetchColumn();

    if ($pendingCount === 0) {
        echo "No pending mails in queue.\n";
        exit(0);
    }

    echo "Pending mails: {$pendingCount}\n";

    // Check whether 200+ mails were already sent within the last 60 minutes.
    // This queries the actual sent records in mail_queue for a reliable rate-limit check.
    $recentSentCount = (int)$db->query(
        "SELECT COUNT(*) FROM mail_queue WHERE sent = 1 AND sent_at >= NOW() - INTERVAL " . (int)MAIL_BATCH_COOLDOWN_MINUTES . " MINUTE"
    )->fetchColumn();

    if ($recentSentCount >= MAIL_BATCH_SIZE) {
        echo "Pause aktiv. In den letzten " . MAIL_BATCH_COOLDOWN_MINUTES . " Minuten wurden bereits {$recentSentCount} Mails gesendet.\n";
        exit(0);
    }

    // Retrieve cooldown-related settings persisted from the previous run.
    // fetchColumn() returns false when no row exists (first-ever run), which
    // means the cooldown check below is safely skipped on the initial execution.
    $settingStmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");

    $settingStmt->execute(['mail_queue_last_block_started_at']);
    $lastBlockStartedAt = $settingStmt->fetchColumn();

    $settingStmt->execute(['mail_queue_last_batch_was_full']);
    $lastBatchWasFull = $settingStmt->fetchColumn();

    // Enforce cooldown only when the previous batch was a full batch (200 mails)
    if ($lastBatchWasFull === '1' && is_string($lastBlockStartedAt) && $lastBlockStartedAt !== '') {
        $minutesSince = (time() - strtotime($lastBlockStartedAt)) / 60;
        if ($minutesSince < MAIL_BATCH_COOLDOWN_MINUTES) {
            $waitMinutes = (int)ceil(MAIL_BATCH_COOLDOWN_MINUTES - $minutesSince);
            echo "Pause aktiv. Nächster Batch in {$waitMinutes} Minute(n).\n";
            exit(0);
        }
    }

    // Record the block start timestamp before sending
    $blockStartedAt = date('Y-m-d H:i:s');
    $upsert = $db->prepare("
        INSERT INTO system_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ");
    $upsert->execute(['mail_queue_last_block_started_at', $blockStartedAt]);

    // Fetch the next batch of unsent mails (oldest first)
    $batchStmt = $db->prepare(
        "SELECT id, to_email, subject, body
         FROM mail_queue
         WHERE sent = 0
         ORDER BY created_at ASC
         LIMIT " . (int)MAIL_BATCH_SIZE
    );
    $batchStmt->execute();
    $batch = $batchStmt->fetchAll();

    $sent   = 0;
    $failed = 0;
    $updStmt = $db->prepare("UPDATE mail_queue SET sent = 1, sent_at = NOW() WHERE id = ?");

    foreach ($batch as $mail) {
        try {
            if (MailService::sendEmail($mail['to_email'], $mail['subject'], $mail['body'])) {
                $sent++;
                $updStmt->execute([$mail['id']]);
            } else {
                // Failed mails keep sent = 0 and will be retried in subsequent batches
                $failed++;
                error_log("process_mail_queue: failed to send to " . $mail['to_email'] . " (id #{$mail['id']})");
            }
        } catch (Exception $e) {
            // Keep sent = 0 so the mail is retried in the next run
            $failed++;
            error_log("process_mail_queue: exception for id #{$mail['id']}: " . $e->getMessage());
        }
    }

    // Persist whether this batch was full so the next run can decide on cooldown
    $batchWasFull = count($batch) >= MAIL_BATCH_SIZE ? '1' : '0';
    $upsert->execute(['mail_queue_last_batch_was_full', $batchWasFull]);

    echo "Sent: {$sent}, Failed: {$failed}. Batch size: " . count($batch) . ".\n";
    if ($batchWasFull === '1') {
        echo "Batch limit reached. Next batch delayed by " . MAIL_BATCH_COOLDOWN_MINUTES . " minute(s).\n";
    }

} catch (Exception $e) {
    error_log("process_mail_queue cron error: " . $e->getMessage());
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nDone at: " . date('Y-m-d H:i:s') . "\n";
exit(0);
