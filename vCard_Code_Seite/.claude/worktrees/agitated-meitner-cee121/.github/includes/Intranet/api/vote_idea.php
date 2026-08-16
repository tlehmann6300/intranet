<?php
/**
 * API: Vote on Idea
 * Records an up- or downvote for an idea
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

if (!Auth::canAccessPage('ideas')) {
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

$user   = Auth::user();
$ideaId = isset($_POST['idea_id']) ? (int) $_POST['idea_id'] : 0;
$vote   = $_POST['vote'] ?? '';

if ($ideaId <= 0 || !in_array($vote, ['up', 'down'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ungültige Parameter.']);
    exit;
}

try {
    $result = Idea::vote($ideaId, (int) $user['id'], $vote);

    if ($result['success']) {
        echo json_encode($result);
    } else {
        http_response_code(500);
        echo json_encode($result);
    }
} catch (Exception $e) {
    error_log('vote_idea.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server-Fehler']);
}
