<?php
/**
 * AJAX endpoint: Get current app roles for an Entra user
 * in the Intranet Enterprise Application.
 *
 * GET parameters:
 *   entra_id - Azure Object ID (OID) of the user
 *
 * Returns JSON:
 *   { "roles": ["mitglied"], "rawIds": ["uuid..."] }
 *   { "error": "..." }
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/services/MicrosoftGraphService.php';

header('Content-Type: application/json');

// Auth guard
if (!Auth::check() || !Auth::canManageUsers()) {
    http_response_code(403);
    echo json_encode(['error' => 'Keine Berechtigung.']);
    exit;
}

$entraId = trim($_GET['entra_id'] ?? '');

if (empty($entraId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Entra-ID ist erforderlich.']);
    exit;
}

try {
    $graphService = new MicrosoftGraphService();
    $roles        = $graphService->getUserAppRoles($entraId);

    echo json_encode([
        'roles' => $roles,   // Array of role name strings, e.g. ['mitglied']
        'count' => count($roles),
    ]);

} catch (Exception $e) {
    error_log('get_entra_user_roles.php failed for OID ' . $entraId . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Fehler beim Abrufen der Entra-Rollen: ' . $e->getMessage()]);
}
