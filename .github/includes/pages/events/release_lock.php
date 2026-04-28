<?php
/**
 * Release lock endpoint for sendBeacon API
 * Called when user leaves the edit page
 */

// Set JSON content type before any other output
header('Content-Type: application/json');

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Event.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Must be authenticated
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$eventId = intval($_POST['event_id'] ?? 0);
$userId = intval($_POST['user_id'] ?? 0);

// Validate that the user ID matches the session
if ($userId !== $_SESSION['user_id']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'User ID mismatch']);
    exit;
}

if ($eventId > 0) {
    try {
        Event::releaseLock($eventId, $userId);
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Lock released successfully']);
    } catch (Exception $e) {
        http_response_code(500);
        error_log("Failed to release lock: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Failed to release lock']);
    }
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Event ID is required']);
}

exit;
