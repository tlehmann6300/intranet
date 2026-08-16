<?php
/**
 * Hide Poll API
 * Allows users to manually hide polls from their dashboard (for external Forms polls)
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';

header('Content-Type: application/json');

// Check authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests allowed']);
    exit;
}

$user = Auth::user();

// Get poll ID from request
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültiges JSON-Format']);
    exit;
}
$pollId = isset($input['poll_id']) ? (int)$input['poll_id'] : null;

// CSRF protection
CSRFHandler::verifyToken($input['csrf_token'] ?? '');

if (!$pollId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Poll ID is required']);
    exit;
}

try {
    $db = Database::getContentDB();
    
    // Check if poll exists
    $stmt = $db->prepare("SELECT id FROM polls WHERE id = ?");
    $stmt->execute([$pollId]);
    $poll = $stmt->fetch();
    
    if (!$poll) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Poll not found']);
        exit;
    }
    
    // Insert or update hidden status
    $stmt = $db->prepare("
        INSERT INTO poll_hidden_by_user (poll_id, user_id) 
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE hidden_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$pollId, $user['id']]);
    
    echo json_encode(['success' => true, 'message' => 'Poll hidden successfully']);
    
} catch (Exception $e) {
    error_log('Error hiding poll: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
