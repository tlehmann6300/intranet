<?php
/**
 * AJAX endpoint: Search users in Microsoft Entra (Azure AD)
 * Returns JSON array of matching users.
 * Requires admin session.
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../includes/services/MicrosoftGraphService.php';

header('Content-Type: application/json');

if (!Auth::check() || !Auth::canManageUsers()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$query = trim($_GET['q'] ?? '');

if (strlen($query) < 2) {
    echo json_encode(['users' => []]);
    exit;
}

try {
    $graphService = new MicrosoftGraphService();
    $users = $graphService->searchUsers($query);
    echo json_encode(['users' => $users]);
} catch (Exception $e) {
    error_log('Entra user search failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Suche fehlgeschlagen. Bitte prüfe die Azure-Konfiguration.']);
}
