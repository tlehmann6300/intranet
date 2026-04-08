<?php
/**
 * Event Signup API - Simple Registration
 * Handles simple event registrations
 */

// Load configuration and authentication
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/MailService.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';

// Set response header
header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Nicht authentifiziert'
    ]);
    exit;
}

try {
    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Nur POST-Anfragen erlaubt'
        ]);
        exit;
    }
    
    // Read JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Ungültiges JSON-Format');
    }

    // CSRF protection
    CSRFHandler::verifyToken($input['csrf_token'] ?? '');

    // Get event ID from input
    $eventId = $input['event_id'] ?? null;
    
    if (!$eventId) {
        throw new Exception('Event-ID fehlt');
    }
    
    // Get current user
    $user = Auth::user();
    if (!$user) {
        throw new Exception('Benutzer nicht gefunden');
    }
    
    $userId = $user['id'];
    $userEmail = $user['email'];
    $userName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    
    // Get database connection
    $db = Database::getContentDB();
    
    // Get event data
    $stmt = $db->prepare("
        SELECT id, title, description, location, start_time, end_time, contact_person
        FROM events
        WHERE id = ?
    ");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$event) {
        throw new Exception('Event nicht gefunden');
    }
    
    // Check if user is already registered
    $stmt = $db->prepare("
        SELECT id, status 
        FROM event_registrations 
        WHERE event_id = ? AND user_id = ?
    ");
    $stmt->execute([$eventId, $userId]);
    $existingRegistration = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingRegistration && $existingRegistration['status'] === 'confirmed') {
        throw new Exception('Du bist bereits für dieses Event angemeldet');
    }
    
    // Save registration in event_registrations table
    if ($existingRegistration) {
        // Update existing cancelled registration
        $stmt = $db->prepare("
            UPDATE event_registrations 
            SET status = 'confirmed', registered_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$existingRegistration['id']]);
    } else {
        // Insert new registration
        $stmt = $db->prepare("
            INSERT INTO event_registrations (event_id, user_id, status, registered_at)
            VALUES (?, ?, 'confirmed', NOW())
        ");
        $stmt->execute([$eventId, $userId]);
    }
    
    // Send confirmation email
    try {
        MailService::sendEventConfirmation($userEmail, $userName, $event);
    } catch (Exception $mailError) {
        // Log error but don't fail the registration
        error_log("Failed to send confirmation email: " . $mailError->getMessage());
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Erfolgreich angemeldet'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
