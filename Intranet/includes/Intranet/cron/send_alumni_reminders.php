<?php
/**
 * Alumni Reminders Cron Script
 * 
 * Sends reminder emails to alumni whose profiles haven't been verified in over 1 year.
 * Limits to max 20 emails per execution to avoid SMTP timeouts/blocks.
 * 
 * Usage: php cron/send_alumni_reminders.php
 */

// Load required files
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/models/Alumni.php';
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

// Output start message
echo "=== Alumni Reminder Email Cron Job ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

// Get database connection for logging
$contentDb = Database::getContentDB();

// Fetch profiles where last_verified_at is older than 1 year (12 months)
$outdatedProfiles = Alumni::getOutdatedProfiles(12);

$totalOutdated = count($outdatedProfiles);
echo "Found {$totalOutdated} alumni profiles that need verification.\n";

// Log cron execution start
try {
    $stmt = $contentDb->prepare("INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([0, 'cron_alumni_reminders', 'cron', null, "Started: Found {$totalOutdated} outdated profiles", 'cron', 'cron']);
} catch (Exception $e) {
    // Ignore logging errors
}

// Limit to max 20 emails per execution
$maxEmails = 20;
$profilesToProcess = array_slice($outdatedProfiles, 0, $maxEmails);

echo "Processing {" . count($profilesToProcess) . "} profiles (max {$maxEmails} per execution).\n\n";

$emailsSent = 0;
$emailsFailed = 0;

// Loop through profiles and send reminder emails
foreach ($profilesToProcess as $profile) {
    $firstName = $profile['first_name'];
    $email = $profile['email'];
    $userId = $profile['user_id'];
    
    echo "Sending reminder to: {$firstName} ({$email})... ";
    
    // Send email using MailService
    try {
        $success = MailService::sendAlumniReminder($email, $firstName);
        
        if ($success) {
            // Mark reminder as sent to prevent re-sending
            Alumni::markReminderSent($userId);
            $emailsSent++;
            echo "SUCCESS\n";
        } else {
            $emailsFailed++;
            echo "FAILED\n";
        }
    } catch (Exception $e) {
        $emailsFailed++;
        echo "ERROR: " . $e->getMessage() . "\n";
    }
    
    // Small delay between emails to avoid overwhelming SMTP server
    usleep(100000); // 0.1 second delay
}

// Output summary
echo "\n=== Summary ===\n";
echo "Total outdated profiles: {$totalOutdated}\n";
echo "Emails sent successfully: {$emailsSent}\n";
echo "Emails failed: {$emailsFailed}\n";
echo "Remaining profiles: " . max(0, $totalOutdated - $maxEmails) . "\n";
echo "Completed at: " . date('Y-m-d H:i:s') . "\n";

// Log cron execution completion
try {
    $stmt = $contentDb->prepare("INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $logDetails = "Completed: Total={$totalOutdated}, Sent={$emailsSent}, Failed={$emailsFailed}, Remaining=" . max(0, $totalOutdated - $maxEmails);
    $stmt->execute([0, 'cron_alumni_reminders', 'cron', null, $logDetails, 'cron', 'cron']);
} catch (Exception $e) {
    // Ignore logging errors
}
