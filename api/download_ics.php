<?php
/**
 * Download ICS file for event
 * Generates and downloads an iCal (.ics) file for a specific event
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../includes/models/Event.php';
require_once __DIR__ . '/../src/CalendarService.php';

// Check authentication
if (!Auth::check()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Nicht authentifiziert']);
    exit;
}

// Get event ID
$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
if (!$eventId || $eventId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Ungültige Event ID']);
    exit;
}

try {
    // Get event details
    $event = Event::getById($eventId, true);
    if (!$event) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Event nicht gefunden']);
        exit;
    }

    // Check if user has permission to view this event
    $user = Auth::user();
    $userRole = $_SESSION['user_role'] ?? 'mitglied';
    $allowedRoles = $event['allowed_roles'] ?? [];
    if (!empty($allowedRoles) && !in_array($userRole, $allowedRoles)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Keine Berechtigung']);
        exit;
    }

    // Generate ICS content
    $icsContent = CalendarService::generateIcsFile($event);

    // Generate filename - sanitize to prevent header injection
    $safeEventId = (string)$eventId;
    $safeDate = date('Ymd');
    $filename = 'event_' . $safeEventId . '_' . $safeDate . '.ics';
    // RFC 6266 compliant filename encoding
    $filename = str_replace('"', '', $filename); // Remove any quotes

    // Set headers for file download
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($icsContent));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Output ICS content
    echo $icsContent;

} catch (Exception $e) {
    error_log('download_ics.php: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Server-Fehler']);
}
exit;

