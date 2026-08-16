<?php
/**
 * Birthday Wishes Cron Script
 * 
 * Sends birthday wishes emails to all users who have their birthday today.
 * Uses gender-specific salutations based on the user's gender field.
 * 
 * Usage: php cron/send_birthday_wishes.php
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
echo "=== Birthday Wishes Email Cron Job ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // Get database connections
    $userDb = Database::getUserDB();
    $contentDb = Database::getContentDB();
    
    // Get today's date in format MM-DD (month and day only)
    $today = date('m-d');
    
    // Query to find users with birthday today
    // Uses DATE_FORMAT to compare only month and day, ignoring the year
    // Note: DB_CONTENT_NAME is a constant from config.php and is safe to use in the query
    $stmt = $userDb->prepare("
        SELECT 
            u.id,
            u.email,
            u.gender,
            u.birthday,
            ap.first_name
        FROM users u
        LEFT JOIN " . DB_CONTENT_NAME . ".alumni_profiles ap ON u.id = ap.user_id
        WHERE u.birthday IS NOT NULL
        AND DATE_FORMAT(u.birthday, '%m-%d') = :today
        ORDER BY u.id
    ");
    
    $stmt->execute(['today' => $today]);
    $birthdayUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalUsers = count($birthdayUsers);
    echo "Found {$totalUsers} user(s) with birthday today.\n\n";
    
    if ($totalUsers === 0) {
        echo "No birthday emails to send today.\n";
        echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
        exit(0);
    }
    
    $emailsSent = 0;
    $emailsFailed = 0;
    
    // Log cron execution start
    try {
        $stmt = $contentDb->prepare("INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([0, 'cron_birthday_wishes', 'cron', null, "Started: Found {$totalUsers} user(s) with birthday today", 'cron', 'cron']);
    } catch (Exception $e) {
        // Ignore logging errors
    }
    
    // Loop through users and send birthday emails
    foreach ($birthdayUsers as $user) {
        $userId = $user['id'];
        $email = $user['email'];
        $gender = $user['gender'];
        $firstName = $user['first_name'] ?? 'Mitglied';
        
        echo "Sending birthday wishes to: {$firstName} ({$email})... ";
        
        // Get the festive birthday email template
        $htmlBody = MailService::getBirthdayEmailTemplate($firstName, $gender);
        
        // Send email using MailService
        try {
            $success = MailService::sendEmail($email, 'Herzlichen Glückwunsch zum Geburtstag!', $htmlBody);
            
            if ($success) {
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
    echo "Total users with birthday today: {$totalUsers}\n";
    echo "Emails sent successfully: {$emailsSent}\n";
    echo "Emails failed: {$emailsFailed}\n";
    echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
    
    // Log cron execution completion
    try {
        $stmt = $contentDb->prepare("INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $logDetails = "Completed: Total={$totalUsers}, Sent={$emailsSent}, Failed={$emailsFailed}";
        $stmt->execute([0, 'cron_birthday_wishes', 'cron', null, $logDetails, 'cron', 'cron']);
    } catch (Exception $e) {
        // Ignore logging errors
    }
    
} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    
    // Log error (reuse existing contentDb or create new connection if not available)
    try {
        if (!isset($contentDb)) {
            $contentDb = Database::getContentDB();
        }
        $stmt = $contentDb->prepare("INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([0, 'cron_birthday_wishes', 'cron', null, "ERROR: " . $e->getMessage(), 'cron', 'cron']);
    } catch (Exception $logError) {
        // Ignore logging errors
    }
    
    exit(1);
}
