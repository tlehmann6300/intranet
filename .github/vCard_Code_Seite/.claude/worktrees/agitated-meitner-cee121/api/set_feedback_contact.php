<?php
/**
 * Set Feedback Contact API
 * Allows alumni roles to volunteer as (or resign from) feedback Ansprechpartner
 * for a project or event.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../includes/models/Project.php';
require_once __DIR__ . '/../includes/models/Event.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';

header('Content-Type: application/json');

// Roles that are allowed to become feedback contacts
const FEEDBACK_CONTACT_ROLES = ['alumni', 'alumni_vorstand', 'alumni_finanz', 'ehrenmitglied'];

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht authentifiziert']);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ungültiges JSON-Format']);
        exit;
    }

    CSRFHandler::verifyToken($input['csrf_token'] ?? '');

    $user = Auth::user();
    $userRole = $_SESSION['user_role'] ?? '';

    // Only allowed roles can become feedback contacts
    if (!in_array($userRole, FEEDBACK_CONTACT_ROLES)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Nur Alumni-Rollen können Feedback-Ansprechpartner werden']);
        exit;
    }

    $type   = $input['type']   ?? null;   // 'project' or 'event'
    $itemId = intval($input['id'] ?? 0);
    $action = $input['action'] ?? 'set'; // 'set' or 'remove'

    if (!$itemId || !in_array($type, ['project', 'event']) || !in_array($action, ['set', 'remove'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ungültige Anfrage']);
        exit;
    }

    $userId = $action === 'set' ? $user['id'] : null;

    if ($type === 'project') {
        $project = Project::getById($itemId);
        if (!$project) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Projekt nicht gefunden']);
            exit;
        }
        // Allow removing only if current user is the contact
        if ($action === 'remove' && intval($project['feedback_contact_user_id'] ?? 0) !== $user['id']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Du bist nicht der Ansprechpartner dieses Projekts']);
            exit;
        }
        Project::setFeedbackContact($itemId, $userId);
    } else {
        $event = Event::getById($itemId, false);
        if (!$event) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Event nicht gefunden']);
            exit;
        }
        // Allow removing only if current user is the contact
        if ($action === 'remove' && intval($event['feedback_contact_user_id'] ?? 0) !== $user['id']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Du bist nicht der Ansprechpartner dieses Events']);
            exit;
        }
        Event::setFeedbackContact($itemId, $userId);
    }

    $message = $action === 'set'
        ? 'Du bist jetzt Feedback-Ansprechpartner'
        : 'Du bist nicht mehr Feedback-Ansprechpartner';

    echo json_encode(['success' => true, 'message' => $message]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
