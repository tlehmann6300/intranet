<?php
/**
 * API: Update Idea Status
 * Allows board members to change the status of an idea
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../includes/models/Idea.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nicht authentifiziert']);
    exit;
}

if (!Auth::isBoard()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Keine Berechtigung']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Methode nicht erlaubt']);
    exit;
}

CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

$ideaId = isset($_POST['idea_id']) ? (int) $_POST['idea_id'] : 0;
$status = $_POST['status'] ?? '';

if ($ideaId <= 0 || empty($status)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ungültige Parameter.']);
    exit;
}

try {
    $result = Idea::updateStatus($ideaId, $status);

    if ($result) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Fehler beim Aktualisieren des Status.']);
    }
} catch (Exception $e) {
    error_log('update_idea_status.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server-Fehler']);
}
