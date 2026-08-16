<?php
/**
 * Profile Reminder Cron Script
 * 
 * Sends a single reminder email to users whose last_profile_update is older than
 * 1 year and who have not yet received a reminder for the current cycle
 * (profile_reminder_sent_at IS NULL).
 * After sending, sets profile_reminder_sent_at = NOW() to prevent duplicate emails.
 * When the user saves their profile, last_profile_update and profile_reminder_sent_at
 * are reset so the 1-year interval starts fresh.
 * 
 * Usage: php cron/send_profile_reminders.php
 */

// Load required files
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Database.php';
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
echo "=== Profile Reminder Email Cron Job ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // Get database connections
    $userDb = Database::getUserDB();
    $contentDb = Database::getContentDB();
    
    // Query to find users whose profile has not been updated for at least 1 year
    // and who have not yet received a reminder for the current cycle.
    // - last_profile_update older than 1 year (must not be NULL;
    //   users who never set last_profile_update are excluded intentionally —
    //   they should update their profile first before the reminder cycle applies)
    // - profile_reminder_sent_at IS NULL (no reminder sent yet for this cycle)
    // - deleted_at IS NULL (exclude soft-deleted users)
    // LIMIT 50 per run to prevent server timeout with large user bases
    $stmt = $userDb->prepare("
        SELECT 
            u.id,
            u.email,
            u.last_profile_update,
            ap.first_name,
            ap.last_name
        FROM users u
        LEFT JOIN " . DB_CONTENT_NAME . ".alumni_profiles ap ON u.id = ap.user_id
        WHERE u.last_profile_update < DATE_SUB(NOW(), INTERVAL 1 YEAR)
        AND u.profile_reminder_sent_at IS NULL
        AND u.deleted_at IS NULL
        ORDER BY u.last_profile_update ASC
        LIMIT 50
    ");
    
    $stmt->execute();
    $outdatedUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalUsers = count($outdatedUsers);
    echo "Found {$totalUsers} user(s) with outdated profiles.\n\n";
    
    if ($totalUsers === 0) {
        echo "No profile reminder emails to send today.\n";
        echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
        exit(0);
    }
    
    $emailsSent = 0;
    $emailsFailed = 0;
    
    // Log cron execution start
    try {
        $stmt = $contentDb->prepare("INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([0, 'cron_profile_reminders', 'cron', null, "Started: Found {$totalUsers} user(s) with outdated profiles", 'cron', 'cron']);
    } catch (Exception $e) {
        // Ignore logging errors
    }
    
    // Loop through users and send profile reminder emails
    foreach ($outdatedUsers as $user) {
        $userId = $user['id'];
        $email = $user['email'];
        $firstName = $user['first_name'] ?? 'Mitglied';
        $lastName = $user['last_name'] ?? '';
        $fullName = trim($firstName . ' ' . $lastName);
        
        echo "Sending profile reminder to: {$fullName} ({$email})... ";
        
        // Create call-to-action button with link to profile page
        $profileLink = BASE_URL . '/pages/auth/profile.php';
        
        // Get complete HTML template using new method
        $htmlBody = MailService::getProfileReminderEmailTemplate($firstName, $profileLink);
        
        // Send email using MailService
        try {
            $success = MailService::sendEmail($email, 'Bitte aktualisiere dein IBC Profil', $htmlBody);
            
            if ($success) {
                // Set profile_reminder_sent_at to NOW() to prevent re-sending
                // last_profile_update is NOT changed here; it is reset only when user saves profile
                $updateStmt = $userDb->prepare("UPDATE users SET profile_reminder_sent_at = NOW() WHERE id = ?");
                $updateStmt->execute([$userId]);
                
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
    echo "Total users with outdated profiles: {$totalUsers}\n";
    echo "Emails sent successfully: {$emailsSent}\n";
    echo "Emails failed: {$emailsFailed}\n";
    echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
    
    // Log cron execution completion
    try {
        $stmt = $contentDb->prepare("INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $logDetails = "Completed: Total={$totalUsers}, Sent={$emailsSent}, Failed={$emailsFailed}";
        $stmt->execute([0, 'cron_profile_reminders', 'cron', null, $logDetails, 'cron', 'cron']);
    } catch (Exception $e) {
        // Ignore logging errors
    }
    
} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    
    // Log error
    try {
        if (!isset($contentDb)) {
            $contentDb = Database::getContentDB();
        }
        $stmt = $contentDb->prepare("INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([0, 'cron_profile_reminders', 'cron', null, "ERROR: " . $e->getMessage(), 'cron', 'cron']);
    } catch (Exception $logError) {
        // Ignore logging errors
    }
    
    exit(1);
}
