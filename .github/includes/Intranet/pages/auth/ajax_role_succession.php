<?php
/**
 * AJAX endpoint for handling role succession when a board member demotes themselves
 */

// Set JSON response header
header('Content-Type: application/json');

// Disable error output in body
ini_set('display_errors', 0);

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/models/User.php';
require_once __DIR__ . '/../../includes/services/MicrosoftGraphService.php';

// Redirect path after successful role change
define('REDIRECT_AFTER_ROLE_CHANGE', '/pages/dashboard/index.php');


try {
    // Check authentication
    if (!Auth::check()) {
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

    $currentUser = Auth::user();
    $currentUserId = $currentUser['id'];
    $currentRole = $currentUser['role'];
    
    // Get POST data
    $newRole = $_POST['new_role'] ?? '';
    $successorId = isset($_POST['successor_id']) ? intval($_POST['successor_id']) : 0;
    
    // Validate that current user has a board role
    if (!in_array($currentRole, Auth::BOARD_ROLES)) {
        echo json_encode([
            'success' => false,
            'message' => 'Du bist kein Vorstandsmitglied'
        ]);
        exit;
    }
    
    // Validate new role
    if (!in_array($newRole, Auth::VALID_ROLES)) {
        echo json_encode([
            'success' => false,
            'message' => 'Ungültige neue Rolle'
        ]);
        exit;
    }
    
    // Check if this is a demotion from board to non-board
    $isNewRoleBoard = in_array($newRole, Auth::BOARD_ROLES);
    $isDemotion = !$isNewRoleBoard;
    
    if ($isDemotion) {
        // Demotion requires a successor
        if ($successorId <= 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Nachfolger muss ausgewählt werden',
                'requiresSuccessor' => true
            ]);
            exit;
        }
        
        // Validate successor
        $successor = User::getById($successorId);
        if (!$successor) {
            echo json_encode([
                'success' => false,
                'message' => 'Nachfolger nicht gefunden'
            ]);
            exit;
        }
        
        // Successor must not be the same as current user
        if ($successorId === $currentUserId) {
            echo json_encode([
                'success' => false,
                'message' => 'Du kannst nicht dein eigener Nachfolger sein'
            ]);
            exit;
        }
        
        // Successor must have mitglied role to be eligible
        if ($successor['role'] !== 'mitglied') {
            echo json_encode([
                'success' => false,
                'message' => 'Nachfolger muss ein reguläres Mitglied sein'
            ]);
            exit;
        }
        
        // Get Azure OIDs for both users
        $db = Database::getUserDB();
        
        $stmt = $db->prepare("SELECT azure_oid FROM users WHERE id = ?");
        $stmt->execute([$currentUserId]);
        $currentUserData = $stmt->fetch();
        $currentUserOid = $currentUserData['azure_oid'] ?? null;
        
        $stmt->execute([$successorId]);
        $successorData = $stmt->fetch();
        $successorOid = $successorData['azure_oid'] ?? null;
        
        // Check if both users have Azure OIDs
        if (!$currentUserOid || !$successorOid) {
            echo json_encode([
                'success' => false,
                'message' => 'Azure-Synchronisierung nicht möglich: Fehlende Azure-Daten. Bitte melden Sie sich erneut an.'
            ]);
            exit;
        }
        
        // Perform role swap in Azure first
        try {
            $graphService = new MicrosoftGraphService();
            
            // Update current user role in Azure (demotion)
            $graphService->updateUserRole($currentUserOid, $newRole);
            
            // Update successor role in Azure (promotion)
            $graphService->updateUserRole($successorOid, $currentRole);
            
        } catch (Exception $e) {
            error_log('Azure role sync failed in ajax_role_succession.php: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Fehler bei der Azure-Synchronisierung: ' . $e->getMessage()
            ]);
            exit;
        }
        
        // Perform atomic role swap in database only after Azure succeeds
        try {
            $db->beginTransaction();
            
            // Prepare statement for role updates
            $stmt = $db->prepare("UPDATE users SET role = ?, prompt_profile_review = 1 WHERE id = ?");
            
            // Update current user to new role (demotion)
            $stmt->execute([$newRole, $currentUserId]);
            
            // Update successor to inherit the exact board role
            $stmt->execute([$currentRole, $successorId]);
            
            $db->commit();
            
            // Update session with new role
            $_SESSION['user_role'] = $newRole;
            
            echo json_encode([
                'success' => true,
                'message' => 'Rollenwechsel erfolgreich durchgeführt',
                'redirect' => REDIRECT_AFTER_ROLE_CHANGE
            ]);
            
        } catch (Exception $e) {
            $db->rollBack();
            error_log('Role succession transaction failed: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Fehler beim Rollenwechsel'
            ]);
        }
        
    } else {
        // Not a demotion, just update the role normally
        // Get Azure OID for current user
        $db = Database::getUserDB();
        $stmt = $db->prepare("SELECT azure_oid FROM users WHERE id = ?");
        $stmt->execute([$currentUserId]);
        $userData = $stmt->fetch();
        $userOid = $userData['azure_oid'] ?? null;
        
        // Sync with Azure if OID is available
        if ($userOid) {
            try {
                $graphService = new MicrosoftGraphService();
                
                // Update user role in Azure
                $graphService->updateUserRole($userOid, $newRole);
                
            } catch (Exception $e) {
                error_log('Azure role sync failed in ajax_role_succession.php: ' . $e->getMessage());
                echo json_encode([
                    'success' => false,
                    'message' => 'Fehler bei der Azure-Synchronisierung: ' . $e->getMessage()
                ]);
                exit;
            }
        }
        
        // Update database only after Azure succeeds (or if no OID available)
        if (User::update($currentUserId, ['role' => $newRole, 'prompt_profile_review' => 1])) {
            $_SESSION['user_role'] = $newRole;
            
            echo json_encode([
                'success' => true,
                'message' => 'Rolle erfolgreich geändert',
                'redirect' => REDIRECT_AFTER_ROLE_CHANGE
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Fehler beim Ändern der Rolle'
            ]);
        }
    }
    
} catch (Exception $e) {
    // Log the full error details
    error_log('Error in ajax_role_succession.php: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());
    
    // Return generic JSON error response (don't expose internal details)
    echo json_encode([
        'success' => false,
        'message' => 'Server Fehler: Es ist ein interner Fehler aufgetreten. Bitte versuche es später erneut.'
    ]);
}
