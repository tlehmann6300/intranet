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

    public function view(array $vars = []): void
    {
        $this->requireAuth();
        $user     = \Auth::user();
        $pollId   = isset($vars['id']) ? (int)$vars['id'] : 0;

        if ($pollId <= 0) {
            $this->redirect(\BASE_URL . '/polls');
        }

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
        $stmt = $db->prepare('SELECT * FROM polls WHERE id = ? AND is_active = 1');
        $stmt->execute([$pollId]);
        $poll = $stmt->fetch();

        if (! $poll || ! \isPollVisibleForUser($poll, $userAzureRoles, $user['id'])) {
            $this->redirect(\BASE_URL . '/polls');
        }

        $userVote = null;
        if (empty($poll['microsoft_forms_url'])) {
            $stmt = $db->prepare('SELECT * FROM poll_votes WHERE poll_id = ? AND user_id = ?');
            $stmt->execute([$pollId, $user['id']]);
            $userVote = $stmt->fetch() ?: null;
        }

        $this->render('polls/view.twig', [
            'user'       => $user,
            'poll'       => $poll,
            'userVote'   => $userVote,
            'csrfToken'  => \CSRFHandler::getToken(),
        ]);
    }

    public function create(array $vars = []): void
    {
        $this->requireAuth();
        $user = \Auth::user();

        if (! \Auth::canCreatePolls()) {
            $this->redirect(\BASE_URL . '/polls');
        }

        $errors       = [];
        $successMessage = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_poll'])) {
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

            $title             = trim($_POST['title'] ?? '');
            $description       = trim($_POST['description'] ?? '');
            $microsoftFormsUrl = trim($_POST['microsoft_forms_url'] ?? '');
            $targetRoles       = $_POST['target_roles'] ?? [];
            $visibleToAll      = isset($_POST['visible_to_all']) ? 1 : 0;
            $isInternal        = isset($_POST['is_internal']) ? 1 : 0;

            if (empty($title)) {
                $errors[] = 'Bitte geben Sie einen Titel ein.';
            } elseif (empty($microsoftFormsUrl)) {
                $errors[] = 'Bitte geben Sie die Microsoft Forms URL ein.';
            } elseif (! $visibleToAll && empty($targetRoles)) {
                $errors[] = 'Bitte wählen Sie mindestens eine Zielgruppe aus oder aktivieren Sie "Für alle sichtbar".';
            } else {
                try {
                    $db = \Database::getContentDB();

                    if ($visibleToAll) {
                        $targetGroupsValue = 'all';
                        $targetRolesJson   = null;
                    } else {
                        $targetGroupsValue = null;
                        if (in_array('board_roles', $targetRoles, true)) {
                            $targetRoles = array_filter($targetRoles, fn ($r) => $r !== 'board_roles');
                            $targetRoles = array_merge(array_values($targetRoles), ['vorstand_finanzen', 'vorstand_intern', 'vorstand_extern']);
                        }
                        $targetRolesJson = ! empty($targetRoles) ? json_encode(array_values(array_unique($targetRoles))) : null;
                    }

                    $stmt = $db->prepare('
                        INSERT INTO polls (title, description, created_by, microsoft_forms_url,
                                           target_groups, allowed_roles, target_roles,
                                           visible_to_all, is_internal, is_active, end_date)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, DATE_ADD(NOW(), INTERVAL 30 DAY))
                    ');
                    $stmt->execute([
                        $title,
                        $description,
                        $user['id'],
                        $microsoftFormsUrl,
                        $targetGroupsValue,
                        null,
                        $targetRolesJson,
                        $visibleToAll,
                        $isInternal,
                    ]);

                    $this->redirect(\BASE_URL . '/polls');
                } catch (\Exception $e) {
                    error_log('create_poll: ' . $e->getMessage());
                    $errors[] = 'Ein Fehler ist aufgetreten. Bitte versuchen Sie es später erneut.';
                }
            }
        }

        $this->render('polls/create.twig', [
            'user'       => $user,
            'errors'     => $errors,
            'post'       => $_POST,
            'csrfToken'  => \CSRFHandler::getToken(),
        ]);
    }
}
