<?php
// Set JSON response header
header('Content-Type: application/json');

// Disable error output in body
ini_set('display_errors', 0);

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/User.php';

try {
    // Check authentication and permission
    if (!Auth::check() || !Auth::canManageUsers()) {
        echo json_encode([
            'success' => false,
            'message' => 'Nicht autorisiert'
        ]);
        exit;
    }

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Ungültige Anfrage'
    ]);
    exit;
}

// Get POST data
$userId = intval($_POST['user_id'] ?? 0);
$newRole = $_POST['new_role'] ?? '';
$successorId = isset($_POST['successor_id']) ? intval($_POST['successor_id']) : null;

// Validate input
if ($userId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Ungültige Benutzer-ID'
    ]);
    exit;
}

if (!in_array($newRole, Auth::VALID_ROLES)) {
    echo json_encode([
        'success' => false,
        'message' => 'Ungültige Rolle'
    ]);
    exit;
}

// Get current user's actual role from the database for consistency
$currentUserData = Auth::user();
if (!$currentUserData) {
    echo json_encode([
        'success' => false,
        'message' => 'Fehler beim Abrufen der Benutzerdaten'
    ]);
    exit;
}
$currentUserRole = $currentUserData['role'];
$isBoardMember = in_array($currentUserRole, Auth::BOARD_ROLES);

// Check if user is trying to change their own role
$isOwnRole = ($userId === (int)$currentUserData['id']);

// If board member is demoting themselves to mitglied or alumni, require a successor
$successor = null;
if ($isOwnRole && $isBoardMember && in_array($newRole, ['mitglied', 'alumni'])) {
    if (!$successorId || $successorId <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Ein Nachfolger muss bestimmt werden'
        ]);
        exit;
    }
    
    // Validate successor exists and has appropriate role
    $successor = User::getById($successorId);
    if (!$successor) {
        echo json_encode([
            'success' => false,
            'message' => 'Ungültiger Nachfolger'
        ]);
        exit;
    }
    
    // Ensure successor has a 'mitglied' or 'ressortleiter' role (not already a board member or other role)
    if (!in_array($successor['role'], ['mitglied', 'ressortleiter'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Der gewählte Nachfolger muss die Rolle "Mitglied" oder "Ressortleiter" haben'
        ]);
        exit;
    }
    
    // Use a database transaction to ensure atomicity
    $db = Database::getUserDB();
    
    try {
        $db->beginTransaction();
        
        // Update successor's role to the current user's board role
        if (!User::update($successorId, [
            'role' => $currentUserRole,
            'prompt_profile_review' => 1
        ])) {
            $db->rollBack();
            echo json_encode([
                'success' => false,
                'message' => 'Fehler beim Zuweisen der Rolle an den Nachfolger'
            ]);
            exit;
        }
        
        // Update current user's role to the new role
        if (!User::update($userId, [
            'role' => $newRole,
            'prompt_profile_review' => 1
        ])) {
            $db->rollBack();
            echo json_encode([
                'success' => false,
                'message' => 'Fehler beim Ändern deiner Rolle'
            ]);
            exit;
        }
        
        // Commit the transaction
        $db->commit();
        
        // Build success message
        $message = 'Rollenwechsel erfolgreich durchgeführt. deine Rolle wurde an ' . 
                   htmlspecialchars($successor['email'], ENT_QUOTES, 'UTF-8') . ' übertragen.';
        
        echo json_encode([
            'success' => true,
            'message' => $message
        ]);
        exit;
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log('Transaction failed in ajax_update_role.php: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Fehler beim Rollenwechsel'
        ]);
        exit;
    }
}

// For non-succession role changes, update the role normally
// Update the role and set prompt_profile_review flag
// When role is updated by Board/Admin, prompt user to review their profile
$updateData = [
    'role' => $newRole,
    'prompt_profile_review' => 1
];

if (User::update($userId, $updateData)) {
    echo json_encode([
        'success' => true,
        'message' => 'Rolle erfolgreich geändert'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Fehler beim Ändern der Rolle'
    ]);
}
} catch (Exception $e) {
    // Log the full error details
    error_log('Error in ajax_update_role.php: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());
    
    // Return generic JSON error response (don't expose internal details)
    echo json_encode([
        'success' => false,
        'message' => 'Server Fehler: Es ist ein interner Fehler aufgetreten. Bitte versuche es später erneut.'
    ]);
}
