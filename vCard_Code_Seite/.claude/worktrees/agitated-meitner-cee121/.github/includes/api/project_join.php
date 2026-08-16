<?php
/**
 * Project Join API
 * Handles direct join/leave for internal projects
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../includes/models/Project.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';

header('Content-Type: application/json');

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
    $csrfToken = $input['csrf_token'] ?? '';
    CSRFHandler::verifyToken($csrfToken);

    $action = $input['action'] ?? null;
    $projectId = intval($input['project_id'] ?? 0);

    if (!$projectId || !in_array($action, ['join', 'leave'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ungültige Anfrage']);
        exit;
    }

    $user = Auth::user();
    $userId = $user['id'];
    $userRole = $_SESSION['user_role'] ?? 'mitglied';

    if ($userRole === 'alumni') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Alumni können nicht an Projekten teilnehmen']);
        exit;
    }

    // Load project and verify it is internal
    $project = Project::getById($projectId);
    if (!$project) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Projekt nicht gefunden']);
        exit;
    }

    if (($project['type'] ?? 'internal') !== 'internal') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Direktes Beitreten ist nur bei internen Projekten möglich']);
        exit;
    }

    if (!in_array($project['status'] ?? '', ['open', 'applying', 'running'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Teilnahme ist für dieses Projekt derzeit nicht möglich']);
        exit;
    }

    if ($action === 'join') {
        Project::joinProject($projectId, $userId);
        $teamSize = Project::getTeamSize($projectId);
        $maxConsultants = intval($project['max_consultants'] ?? 1);
        echo json_encode([
            'success' => true,
            'message' => 'Du nimmst jetzt an diesem Projekt teil',
            'team_size' => $teamSize,
            'max_consultants' => $maxConsultants,
        ]);
    } else {
        $removed = Project::leaveProject($projectId, $userId);
        if (!$removed) {
            echo json_encode(['success' => false, 'message' => 'Du bist kein einfaches Mitglied dieses Projekts']);
        } else {
            $teamSize = Project::getTeamSize($projectId);
            $maxConsultants = intval($project['max_consultants'] ?? 1);
            echo json_encode([
                'success' => true,
                'message' => 'Du hast das Projekt verlassen',
                'team_size' => $teamSize,
                'max_consultants' => $maxConsultants,
            ]);
        }
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
