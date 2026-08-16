<?php
/**
 * AJAX endpoint: Assign (or update) an app role for an Entra user
 * in the Intranet Enterprise Application.
 *
 * POST parameters:
 *   entra_id   - Azure Object ID (OID) of the user
 *   role       - Internal role key, must be in Auth::VALID_ROLES
 *   csrf_token - CSRF protection token
 *
 * Returns JSON:
 *   { "success": true, "message": "..." }
 *   { "success": false, "error": "..." }
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/services/MicrosoftGraphService.php';

header('Content-Type: application/json');

// Auth guard
if (!Auth::check() || !Auth::canManageUsers()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Keine Berechtigung.']);
    exit;
}

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Methode nicht erlaubt.']);
    exit;
}

// CSRF check
try {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
} catch (Exception $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Ungültiges CSRF-Token.']);
    exit;
}

$entraId = trim($_POST['entra_id'] ?? '');
$role    = trim($_POST['role']     ?? '');

if (empty($entraId) || empty($role)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Entra-ID und Rolle sind erforderlich.']);
    exit;
}

if (!in_array($role, Auth::VALID_ROLES, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ungültige Rolle: ' . htmlspecialchars($role)]);
    exit;
}

try {
    $graphService = new MicrosoftGraphService();

    // updateUserRole() handles removing the old role (if any) before assigning the new one
    $graphService->updateUserRole($entraId, $role);

    echo json_encode([
        'success' => true,
        'message' => 'Entra-Rolle wurde erfolgreich zugewiesen.',
    ]);

} catch (Exception $e) {
    error_log('assign_entra_app_role.php failed for OID ' . $entraId . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Rollenzuweisung fehlgeschlagen: ' . $e->getMessage(),
    ]);
}
