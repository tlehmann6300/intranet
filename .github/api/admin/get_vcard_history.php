<?php
/**
 * API: vCard-Historie einer Rolle abrufen.
 *
 * GET-Parameter:
 *   rolle = Rollen-Bezeichnung (z.B. "Vorstand Finanzen")
 *
 * Antwort:
 *   { success: bool, items: [ { id, vorname, nachname, rolle, funktion, jahr, ... }, ... ] }
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/VCard.php';
require_once __DIR__ . '/../../config/config.php';

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht authentifiziert']);
    exit;
}

$allowedRoles = [
    Auth::ROLE_BOARD_FINANCE,
    Auth::ROLE_BOARD_INTERNAL,
    Auth::ROLE_BOARD_EXTERNAL,
    Auth::ROLE_HEAD,
];
if (!Auth::hasRole($allowedRoles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Keine Berechtigung']);
    exit;
}

$rolle = trim((string)($_GET['rolle'] ?? ''));
if ($rolle === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Parameter "rolle" fehlt']);
    exit;
}

try {
    $items = VCard::getHistoryByRolle($rolle);
    echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('get_vcard_history: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Datenbankfehler']);
}
