<?php
/**
 * API: Update User Role
 * Updates the local role of a user and syncs the change to Microsoft Entra ID.
 * Required permissions: canManageUsers (board or higher)
 */

require_once __DIR__ . '/../includes/handlers/AuthHandler.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../includes/models/User.php';
require_once __DIR__ . '/../includes/services/MicrosoftGraphService.php';
require_once __DIR__ . '/../src/Auth.php';

AuthHandler::startSession();

header('Content-Type: application/json');

if (!AuthHandler::isAuthenticated() || !AuthHandler::canManageUsers()) {
    echo json_encode(['success' => false, 'message' => 'Nicht autorisiert']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Ungültige Anfrage']);
    exit;
}

CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

$userId  = intval($_POST['user_id'] ?? 0);
$newRole = $_POST['new_role'] ?? '';

if ($userId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Ungültige Benutzer-ID']);
    exit;
}

if (!in_array($newRole, Auth::VALID_ROLES, true)) {
    echo json_encode(['success' => false, 'message' => 'Ungültige Rolle']);
    exit;
}

if ($userId === intval($_SESSION['user_id'] ?? 0)) {
    echo json_encode(['success' => false, 'message' => 'Du kannst Deine eigene Rolle nicht ändern']);
    exit;
}

// Sync role change to Microsoft Entra ID if the user has an azure_oid.
$entraWarning = null;
try {
    $db = Database::getUserDB();
    $stmt = $db->prepare("SELECT azure_oid FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    $azureOid = $row['azure_oid'] ?? null;

    if ($azureOid) {
        $graphService = new MicrosoftGraphService();
        $graphService->updateUserRole($azureOid, $newRole);
    } else {
        // User has no azure_oid yet - local DB will be updated but Entra is not synced.
        // The change will be overwritten by Entra on the user's next Microsoft login.
        error_log('[update_user_role] User ' . $userId . ' has no azure_oid - role change is local only.');
    }
} catch (Exception $e) {
    // Log the error but do not block the local role update -
    // an admin should still be able to manage roles even if Entra is temporarily unavailable.
    error_log('[update_user_role] Entra sync failed for user ' . $userId . ': ' . $e->getMessage());
    $entraWarning = 'Entra-Synchronisierung fehlgeschlagen: ' . $e->getMessage();
}

try {
    if (User::update($userId, ['role' => $newRole])) {
        $response = ['success' => true, 'message' => 'Rolle erfolgreich geändert'];
        if ($entraWarning !== null) {
            $response['warning'] = $entraWarning;
        }
        echo json_encode($response);
    } else {
        echo json_encode(['success' => false, 'message' => 'Fehler beim Ändern der Rolle']);
    }
} catch (Exception $e) {
    error_log('[update_user_role] User update failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server-Fehler']);
}
