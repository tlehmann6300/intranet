<?php
/**
 * Pseudo-Cron for Event Status Updates
 * Include this file on page loads to keep event statuses up to date
 */

// Only run status update occasionally to avoid excessive database queries
// Check if we need to run (once every 5 minutes)
$lastStatusCheck = $_SESSION['last_event_status_check'] ?? 0;
$now = time();

if (($now - $lastStatusCheck) > 300) { // 300 seconds = 5 minutes
    require_once __DIR__ . '/models/Event.php';
    require_once __DIR__ . '/database.php';
    
    try {
        Event::updateEventStatuses();
        $_SESSION['last_event_status_check'] = $now;
    } catch (Exception $e) {
        error_log("Error in pseudo-cron status update: " . $e->getMessage());
    }
}
