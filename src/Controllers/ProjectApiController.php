<?php
declare(strict_types=1);
namespace App\Controllers;

use Twig\Environment;

class ProjectApiController extends BaseController
{
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
    }

    public function projectJoin(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                http_response_code(400);
                $this->json(['success' => false, 'message' => 'Ungültiges JSON-Format']);
            }

            \CSRFHandler::verifyToken($input['csrf_token'] ?? '');

            $action    = $input['action'] ?? null;
            $projectId = intval($input['project_id'] ?? 0);

            if (!$projectId || !in_array($action, ['join', 'leave'])) {
                http_response_code(400);
                $this->json(['success' => false, 'message' => 'Ungültige Anfrage']);
            }

            $user     = \Auth::user();
            $userId   = $user['id'];
            $userRole = $_SESSION['user_role'] ?? 'mitglied';

            if ($userRole === 'alumni') {
                http_response_code(403);
                $this->json(['success' => false, 'message' => 'Alumni können nicht an Projekten teilnehmen']);
            }

            $project = \Project::getById($projectId);
            if (!$project) {
                http_response_code(404);
                $this->json(['success' => false, 'message' => 'Projekt nicht gefunden']);
            }

            if (($project['type'] ?? 'internal') !== 'internal') {
                http_response_code(403);
                $this->json(['success' => false, 'message' => 'Direktes Beitreten ist nur bei internen Projekten möglich']);
            }

            if (!in_array($project['status'] ?? '', ['open', 'applying', 'running'])) {
                http_response_code(403);
                $this->json(['success' => false, 'message' => 'Teilnahme ist für dieses Projekt derzeit nicht möglich']);
            }

            if ($action === 'join') {
                \Project::joinProject($projectId, $userId);
                $teamSize       = \Project::getTeamSize($projectId);
                $maxConsultants = intval($project['max_consultants'] ?? 1);
                $this->json([
                    'success'     => true,
                    'action'      => 'joined',
                    'message'     => 'Du bist dem Projekt beigetreten',
                    'team_size'   => $teamSize,
                    'max_consultants' => $maxConsultants,
                ]);
            } else {
                \Project::leaveProject($projectId, $userId);
                $teamSize = \Project::getTeamSize($projectId);
                $this->json([
                    'success'   => true,
                    'action'    => 'left',
                    'message'   => 'Du hast das Projekt verlassen',
                    'team_size' => $teamSize,
                ]);
            }
        } catch (\Exception $e) {
            error_log('projectJoin: ' . $e->getMessage());
            http_response_code(500);
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Set or remove the feedback contact for a project.
     *
     * Only alumni roles may volunteer.  Auth is enforced by AuthMiddleware.
     */
    public function setFeedbackContact(array $vars = []): void
    {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Ungültiges JSON-Format']);
            return;
        }

        \CSRFHandler::verifyToken($input['csrf_token'] ?? '');

        $user     = \Auth::user();
        $userRole = $_SESSION['user_role'] ?? '';

        $allowedRoles = ['alumni', 'alumni_vorstand', 'alumni_finanz', 'ehrenmitglied'];
        if (!in_array($userRole, $allowedRoles, true)) {
            http_response_code(403);
            $this->json(['success' => false, 'message' => 'Nur Alumni-Rollen können Feedback-Ansprechpartner werden']);
            return;
        }

        $projectId = intval($input['id'] ?? 0);
        $action    = $input['action'] ?? 'set';

        if (!$projectId || !in_array($action, ['set', 'remove'], true)) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Ungültige Anfrage']);
            return;
        }

        $project = \Project::getById($projectId);
        if (!$project) {
            http_response_code(404);
            $this->json(['success' => false, 'message' => 'Projekt nicht gefunden']);
            return;
        }

        if ($action === 'remove' && intval($project['feedback_contact_user_id'] ?? 0) !== $user['id']) {
            http_response_code(403);
            $this->json(['success' => false, 'message' => 'Du bist nicht der Ansprechpartner dieses Projekts']);
            return;
        }

        $userId = $action === 'set' ? $user['id'] : null;
        \Project::setFeedbackContact($projectId, $userId);

        $message = $action === 'set'
            ? 'Du bist jetzt Feedback-Ansprechpartner'
            : 'Du bist nicht mehr Feedback-Ansprechpartner';

        $this->json(['success' => true, 'message' => $message]);
    }
}
