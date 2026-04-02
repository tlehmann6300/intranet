<?php
declare(strict_types=1);
namespace App\Controllers;

use Twig\Environment;

class PollController extends BaseController
{
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
    }

    public function index(array $vars = []): void
    {
        $this->requireAuth();
        $user     = \Auth::user();
        $userRole = $user['role'] ?? '';

        $userAzureRoles = [];
        if (!empty($user['entra_roles'])) {
            $decoded = json_decode($user['entra_roles'], true);
            if (is_array($decoded)) {
                $userAzureRoles = $decoded;
            }
        }
        if (empty($userAzureRoles) && !empty($user['azure_roles'])) {
            $decoded = json_decode($user['azure_roles'], true);
            if (is_array($decoded)) {
                $userAzureRoles = $decoded;
            }
        }

        $db   = \Database::getContentDB();
        $stmt = $db->prepare("
            SELECT p.*,
                   (SELECT COUNT(*) FROM poll_votes WHERE poll_id = p.id AND user_id = ?) as user_has_voted,
                   (SELECT COUNT(*) FROM poll_votes WHERE poll_id = p.id) as total_votes,
                   (SELECT COUNT(*) FROM poll_hidden_by_user WHERE poll_id = p.id AND user_id = ?) as user_has_hidden
            FROM polls p
            WHERE p.is_active = 1 AND p.end_date > NOW()
            ORDER BY p.created_at DESC
        ");
        $stmt->execute([$user['id'], $user['id']]);
        $polls = $stmt->fetchAll();

        $filteredPolls = \filterPollsForUser($polls, $userRole, $userAzureRoles, $user['id']);

        $voteFilter        = $_GET['vote'] ?? 'all';
        $validVoteFilters  = ['all', 'open', 'voted'];
        if (!in_array($voteFilter, $validVoteFilters)) {
            $voteFilter = 'all';
        }

        $this->render('polls/index.twig', [
            'user'          => $user,
            'userRole'      => $userRole,
            'polls'         => array_values($filteredPolls),
            'voteFilter'    => $voteFilter,
            'csrfToken'     => \CSRFHandler::getToken(),
        ]);
    }

    public function hide(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'message' => 'Only POST requests allowed']);
        }

        $user  = \Auth::user();
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Ungültiges JSON-Format']);
        }

        $pollId = isset($input['poll_id']) ? (int)$input['poll_id'] : null;
        \CSRFHandler::verifyToken($input['csrf_token'] ?? '');

        if (!$pollId) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Poll ID is required']);
        }

        try {
            $db   = \Database::getContentDB();
            $stmt = $db->prepare("SELECT id FROM polls WHERE id = ?");
            $stmt->execute([$pollId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                $this->json(['success' => false, 'message' => 'Poll not found']);
            }

            $stmt = $db->prepare("INSERT IGNORE INTO poll_hidden_by_user (poll_id, user_id, hidden_at) VALUES (?, ?, NOW())");
            $stmt->execute([$pollId, $user['id']]);
            $this->json(['success' => true]);
        } catch (\Exception $e) {
            error_log('hide_poll: ' . $e->getMessage());
            http_response_code(500);
            $this->json(['success' => false, 'message' => 'Server error']);
        }
    }
}
