<?php
/**
 * EasyVerein Synchronization Cron Script
 * 
 * Synchronizes inventory data from EasyVerein API to local database.
 * This script should be scheduled to run every 30 minutes.
 * 
 * Crontab example (every 30 minutes):
 * 0,30 * * * * /usr/bin/php /path/to/cron/sync_easyverein.php >> /path/to/logs/easyverein_sync.log 2>&1
 * 
 * Or using the step syntax: `[star][slash]30 * * * *` (replace [star][slash] with actual characters)
 * 
 * Usage: php cron/sync_easyverein.php
 */

// Load required files
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/services/EasyVereinSync.php';

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
echo "=== EasyVerein Inventory Synchronization ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

// Get database connection for logging
$contentDb = Database::getContentDB();

// Log cron execution start
try {
    $stmt = $contentDb->prepare("INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([0, 'cron_easyverein_sync', 'cron', null, "Started synchronization", 'cron', 'cron']);
} catch (Exception $e) {
    // Ignore logging errors
}

try {
    // Create an instance of the sync service
    $syncService = new EasyVereinSync();
    
    // Perform the synchronization (userId = 0 for system/cron)
    echo "Fetching data from EasyVerein API...\n";
    $result = $syncService->sync(0);
    
    // Display results
    echo "\n=== Synchronization Results ===\n";
    echo "Created: {$result['created']} items\n";
    echo "Updated: {$result['updated']} items\n";
    echo "Archived: {$result['archived']} items\n";
    
    if (!empty($result['errors'])) {
        echo "\nErrors encountered:\n";
        foreach ($result['errors'] as $error) {
            echo "  - $error\n";
        }
    } else {
        echo "\nSynchronization completed successfully!\n";
    }
    
    // Log cron execution completion
    try {
        $stmt = $contentDb->prepare("INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $logDetails = "Completed: Created={$result['created']}, Updated={$result['updated']}, Archived={$result['archived']}";
        if (!empty($result['errors'])) {
            $logDetails .= ", Errors=" . count($result['errors']);
        }
        $stmt->execute([0, 'cron_easyverein_sync', 'cron', null, $logDetails, 'cron', 'cron']);
    } catch (Exception $e) {
        // Ignore logging errors
    }
    
} catch (Exception $e) {
    echo "\nFATAL ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    
    // Log error (reuse existing contentDb or create new connection if not available)
    try {
        if (!isset($contentDb)) {
            $contentDb = Database::getContentDB();
        }
        $stmt = $contentDb->prepare("INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([0, 'cron_easyverein_sync', 'cron', null, "ERROR: " . $e->getMessage(), 'cron', 'cron']);
    } catch (Exception $logError) {
        // Ignore logging errors
    }
    
    exit(1);
}

echo "\nSync executed at " . date('Y-m-d H:i:s') . "\n";
echo "=== End of Synchronization ===\n";
