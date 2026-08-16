<?php
/**
 * API: Delete VCard (Admin)
 *
 * Deletes a vCard record from the external vCard database.
 *
 * Required permissions: Vorstand (vorstand_finanzen, vorstand_extern, vorstand_intern)
 *                       or Resortleiter (ressortleiter)
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/VCard.php';
require_once __DIR__ . '/../../config/config.php';

header('Content-Type: application/json');

// ── Authentication ─────────────────────────────────────────────────────────────
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht authentifiziert']);
    exit;
}

// ── Role check ─────────────────────────────────────────────────────────────────
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

// ── HTTP method ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Methode nicht erlaubt']);
    exit;
}

// ── CSRF verification ──────────────────────────────────────────────────────────
CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

// ── Input validation ───────────────────────────────────────────────────────────
$id = intval($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültige oder fehlende ID']);
    exit;
}

// ── Delete vCard ───────────────────────────────────────────────────────────────
try {
    VCard::delete($id);
    echo json_encode(['success' => true, 'message' => 'VCard erfolgreich gelöscht']);
} catch (Exception $e) {
    error_log('delete_vcard(admin): ' . $e->getMessage());
    $code = ($e->getCode() === 404) ? 404 : 500;
    $message = ($e->getCode() === 404)
        ? 'VCard-Eintrag nicht gefunden'
        : 'Datenbankfehler beim Löschen der VCard';
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
}
