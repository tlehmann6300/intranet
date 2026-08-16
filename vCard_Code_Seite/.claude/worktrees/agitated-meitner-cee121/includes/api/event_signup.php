<?php
/**
 * Event Signup API
 * Handles event and helper slot signups/cancellations
 */

require_once __DIR__ . '/../includes/handlers/AuthHandler.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../includes/models/Event.php';
require_once __DIR__ . '/../includes/models/User.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../src/CalendarService.php';
require_once __DIR__ . '/../src/MailService.php';

// Start session and check authentication
AuthHandler::startSession();

header('Content-Type: application/json');

// Check authentication
if (!AuthHandler::isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht authentifiziert']);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
    exit;
}

// Get request data
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültiges JSON-Format']);
    exit;
}
$action = $input['action'] ?? null;

// CSRF protection
CSRFHandler::verifyToken($input['csrf_token'] ?? '');

$user = AuthHandler::getCurrentUser();
$userId = $user['id'];
$userRole = $_SESSION['user_role'] ?? 'mitglied';

try {
    switch ($action) {
        case 'signup':
            // Get parameters
            $eventId = isset($input['event_id']) ? (int)$input['event_id'] : null;
            $slotId = isset($input['slot_id']) ? (int)$input['slot_id'] : null;
            $slotStart = $input['slot_start'] ?? null;
            $slotEnd = $input['slot_end'] ?? null;
            
            if (!$eventId) {
                throw new Exception('Event-ID fehlt');
            }
            
            // Get event details
            $event = Event::getById($eventId, false);
            if (!$event) {
                throw new Exception('Event nicht gefunden');
            }
            
            // Check if user already has a signup for this event
            $userSignups = Event::getUserSignups($userId);
            $existingSignup = null;
            foreach ($userSignups as $signup) {
                if ($signup['event_id'] == $eventId && $signup['status'] !== 'cancelled') {
                    $existingSignup = $signup;
                    break;
                }
            }
            
            // If signing up for a slot, check for time conflicts
            if ($slotId && $slotStart && $slotEnd) {
                // Prevent double booking - check if user has another slot at same time
                $db = Database::getContentDB();
                
                $stmt = $db->prepare("
                    SELECT s.id, s.event_id, e.title, es.start_time, es.end_time
                    FROM event_signups s
                    JOIN event_slots es ON s.slot_id = es.id
                    JOIN events e ON s.event_id = e.id
                    WHERE s.user_id = ? 
                    AND s.status = 'confirmed'
                    AND s.slot_id IS NOT NULL
                    AND s.event_id != ?
                    AND es.start_time < ?
                    AND es.end_time > ?
                ");
                
                $stmt->execute([
                    $userId,
                    $eventId,
                    $slotEnd,      // New slot ends after existing slot starts (es.start_time < slot_end)
                    $slotStart     // New slot starts before existing slot ends (es.end_time > slot_start)
                ]);
                
                $conflicts = $stmt->fetchAll();
                
                if (!empty($conflicts)) {
                    $conflictEvent = $conflicts[0];
                    throw new Exception('Du hast bereits einen Helfer-Slot zur gleichen Zeit: ' . 
                                      htmlspecialchars($conflictEvent['title']));
                }
                
                // If user already has a general signup, we'll upgrade it to slot signup
                // Otherwise create new signup
            }
            
            // Perform signup
            if ($existingSignup && !$slotId) {
                // User is already signed up for general event
                throw new Exception('Sie sind bereits für dieses Event angemeldet');
            } elseif ($existingSignup && $slotId) {
                // User has general signup but now wants to sign up for a slot
                // We need to update the existing signup or create a new one for the slot
                // For simplicity, we'll cancel the old one and create a new one
                Event::cancelSignup($existingSignup['id'], $userId);
            }
            
            $result = Event::signup($eventId, $userId, $slotId, $userRole);
            
            // Send confirmation email ONLY if signing up for a helper slot (not for general event participation)
            if ($slotId && $result['status'] === 'confirmed') {
                try {
                    // Get full event details
                    $fullEvent = Event::getById($eventId, true);
                    
                    // Get user details
                    $db = Database::getContentDB();
                    $userDb = Database::getUserDB();
                    $userStmt = $userDb->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
                    $userStmt->execute([$userId]);
                    $userDetails = $userStmt->fetch();
                    
                    if ($userDetails && $fullEvent) {
                        // Get slot details
                        $slotDetails = null;
                        foreach ($fullEvent['helper_types'] as $helperType) {
                            foreach ($helperType['slots'] as $slot) {
                                if ($slot['id'] == $slotId) {
                                    $slotDetails = $slot;
                                    $slotDetails['slot_title'] = $helperType['title'];
                                    break 2;
                                }
                            }
                        }
                        
                        if ($slotDetails) {
                            // Generate ICS content
                            $icsContent = CalendarService::generateICS($fullEvent, $slotDetails);
                            
                            // Generate Google Calendar link
                            $googleCalendarLink = CalendarService::generateGoogleCalendarLink($fullEvent, $slotDetails);
                            
                            // Send email
                            $userName = $userDetails['first_name'] . ' ' . $userDetails['last_name'];
                            MailService::sendHelperConfirmation(
                                $userDetails['email'],
                                $userName,
                                $fullEvent,
                                $slotDetails,
                                $icsContent,
                                $googleCalendarLink
                            );
                        }
                    }
                } catch (Exception $mailError) {
                    // Log error but don't fail the signup
                    error_log("Failed to send confirmation email: " . $mailError->getMessage());
                }
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Anmeldung erfolgreich',
                'signup_id' => $result['id'],
                'status' => $result['status']
            ]);
            break;
            
        case 'cancel':
            // Get parameters
            $signupId = isset($input['signup_id']) ? (int)$input['signup_id'] : null;
            
            if (!$signupId) {
                throw new Exception('Signup-ID fehlt');
            }
            
            // Get signup to check it belongs to user
            $db = Database::getContentDB();
            $stmt = $db->prepare("
                SELECT s.*, e.start_time 
                FROM event_signups s
                JOIN events e ON s.event_id = e.id
                WHERE s.id = ? AND s.user_id = ?
            ");
            $stmt->execute([$signupId, $userId]);
            $signup = $stmt->fetch();
            
            if (!$signup) {
                throw new Exception('Anmeldung nicht gefunden');
            }
            
            // Check if cancellation is still allowed (before event start)
            if (strtotime($signup['start_time']) <= time()) {
                throw new Exception('Abmeldung nicht mehr möglich (Event hat bereits begonnen)');
            }
            
            // Cancel signup
            $cancelResult = Event::cancelSignup($signupId, $userId);
            
            // If someone was promoted from waitlist, send them a confirmation email
            if (!empty($cancelResult['promoted_user_id'])) {
                try {
                    $promotedUserId = $cancelResult['promoted_user_id'];
                    $eventId = $cancelResult['event_id'];
                    $slotId = $cancelResult['slot_id'];
                    
                    // Get full event details
                    $fullEvent = Event::getById($eventId, true);
                    
                    // Get promoted user details
                    $userDb = Database::getUserDB();
                    $userStmt = $userDb->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
                    $userStmt->execute([$promotedUserId]);
                    $promotedUserDetails = $userStmt->fetch();
                    
                    if ($promotedUserDetails && $fullEvent) {
                        // Get slot details
                        $slotDetails = null;
                        foreach ($fullEvent['helper_types'] as $helperType) {
                            foreach ($helperType['slots'] as $slot) {
                                if ($slot['id'] == $slotId) {
                                    $slotDetails = $slot;
                                    $slotDetails['slot_title'] = $helperType['title'];
                                    break 2;
                                }
                            }
                        }
                        
                        if ($slotDetails) {
                            // Generate ICS content
                            $icsContent = CalendarService::generateICS($fullEvent, $slotDetails);
                            
                            // Generate Google Calendar link
                            $googleCalendarLink = CalendarService::generateGoogleCalendarLink($fullEvent, $slotDetails);
                            
                            // Send email
                            $userName = $promotedUserDetails['first_name'] . ' ' . $promotedUserDetails['last_name'];
                            MailService::sendHelperConfirmation(
                                $promotedUserDetails['email'],
                                $userName,
                                $fullEvent,
                                $slotDetails,
                                $icsContent,
                                $googleCalendarLink
                            );
                        }
                    }
                } catch (Exception $mailError) {
                    // Log error but don't fail the cancellation
                    error_log("Failed to send promotion email: " . $mailError->getMessage());
                }
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Abmeldung erfolgreich'
            ]);
            break;
            
        case 'simple_register':
            // Simple event registration using event_registrations table
            $eventId = isset($input['event_id']) ? (int)$input['event_id'] : null;
            
            if (!$eventId) {
                throw new Exception('Event-ID fehlt');
            }
            
            // Get user details
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
            
            // Send confirmation email using MailService::sendEventConfirmation
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
            break;
            
        default:
            throw new Exception('Ungültige Aktion');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
